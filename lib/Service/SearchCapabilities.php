<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Service;

use OCP\ICacheFactory;

/**
 * Reads the MCP server's advertised `supported_search_types` and gates
 * search-algorithm requests against it.
 *
 * The server advertises which query types it can actually serve on
 * GET /api/v1/status:
 *   - vector sync enabled  → ["semantic", "bm25", "hybrid"] (all three; the
 *     keyword-vs-hybrid choice is per-document, driven by the `keyword-index`
 *     tag, not a server-wide mode)
 *   - vector sync disabled → [] (nothing is searchable)
 *
 * This service is the client-side guard so astrolabe never sends — nor offers in
 * its UI — a query type the server can't serve. The MCP server enforces the same
 * set (HTTP 422 `unsupported_search_type`) as the authoritative backstop; if the
 * status endpoint is unreachable we stay permissive (fall back to {@see ALL}) and
 * let that 422 be the final word, rather than hard-failing search on a blip.
 *
 * @psalm-suppress ClassMustBeFinal — kept non-final so it can be mocked in the
 *   controller unit tests, mirroring the other Service classes.
 */
class SearchCapabilities {
	/**
	 * The full algorithm vocabulary. Also the permissive fallback used when the
	 * status endpoint can't be reached (the server's 422 remains the backstop).
	 *
	 * @var list<string>
	 */
	public const ALL = ['semantic', 'bm25', 'hybrid'];

	private const CACHE_TTL = 30;
	private const CACHE_KEY = 'supported_search_types';

	/** @psalm-suppress PossiblyUnusedMethod — constructed via DI. */
	public function __construct(
		private McpServerClient $client,
		private ICacheFactory $cacheFactory,
	) {
	}

	/**
	 * The search algorithms the MCP server currently advertises.
	 *
	 * Short-TTL cached like SemanticSearchProvider's status (the settings UI and
	 * search bar poll this). Errors/absent field are NOT cached and fall back to
	 * {@see ALL} so the guard resumes the instant the server recovers or starts
	 * advertising the field.
	 *
	 * @return list<string>
	 */
	public function getSupportedSearchTypes(): array {
		$cache = $this->cacheFactory->createDistributed('astrolabe_search_caps');
		/** @var mixed $cached */
		$cached = $cache->get(self::CACHE_KEY);
		if (is_array($cached)) {
			return array_values(array_filter($cached, 'is_string'));
		}

		$status = $this->client->getStatus();
		/** @var mixed $advertised — runtime JSON, not the declared status shape. */
		$advertised = $status['supported_search_types'] ?? null;
		// Stay permissive when the server can't be reached or predates the field:
		// the server's own 422 is the authoritative backstop, so we must not
		// block search just because status blipped or is unavailable.
		if (isset($status['error']) || !is_array($advertised)) {
			return self::ALL;
		}

		$types = array_values(array_filter($advertised, 'is_string'));
		$cache->set(self::CACHE_KEY, $types, self::CACHE_TTL);
		return $types;
	}

	/**
	 * Assert the algorithm is one the server can serve, else throw.
	 *
	 * @throws UnsupportedSearchTypeException
	 */
	public function assertSupported(string $algorithm): void {
		// Fetch once and reuse: calling getSupportedSearchTypes() again for the
		// exception would re-evaluate against a possibly-expired cache, so the
		// thrown set could disagree with the set we actually gated on.
		$supported = $this->getSupportedSearchTypes();
		if (!in_array($algorithm, $supported, true)) {
			throw new UnsupportedSearchTypeException($algorithm, $supported);
		}
	}
}
