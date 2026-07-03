<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Exception;

/**
 * A search algorithm was requested that the MCP server does not advertise as
 * supported (ADR-030) — e.g. "semantic" while the server runs SEARCH_MODE=keyword
 * and only offers ["bm25"].
 *
 * Astrolabe reads the advertised set from GET /api/v1/status and gates requests
 * with {@see \OCA\Astrolabe\Service\SearchCapabilities}. The MCP server enforces
 * the same set (HTTP 422 `unsupported_search_type`) as the authoritative
 * backstop; this exception is the client-side half so we never send — nor offer
 * in the UI — a query type the server can't serve.
 */
final class UnsupportedSearchTypeException extends \RuntimeException {
	/**
	 * @param string $requested The algorithm the caller asked for.
	 * @param list<string> $supported The algorithms the server currently advertises.
	 */
	public function __construct(
		private readonly string $requested,
		private readonly array $supported,
	) {
		parent::__construct(sprintf(
			'Search algorithm "%s" is not supported by the MCP server (supported: %s)',
			$requested,
			$supported === [] ? 'none' : implode(', ', $supported),
		));
	}

	public function getRequested(): string {
		return $this->requested;
	}

	/**
	 * @return list<string>
	 */
	public function getSupported(): array {
		return $this->supported;
	}
}
