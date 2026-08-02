<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Search;

use OCA\Astrolabe\Search\SemanticSearchProvider;
use OCA\Astrolabe\Service\Access\CalendarAccessVerifier;
use OCA\Astrolabe\Service\Access\DeckAccessVerifier;
use OCA\Astrolabe\Service\Access\DocumentAccessService;
use OCA\Astrolabe\Service\Access\FileAccessVerifier;
use OCA\Astrolabe\Service\Access\MailAccessVerifier;
use OCA\Astrolabe\Service\McpServerClient;
use OCA\Astrolabe\Service\McpTokenMinter;
use OCA\Astrolabe\Service\SearchSources;
use OCA\Astrolabe\Settings\Admin as AdminSettings;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\IRootFolder;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IPreview;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\ISearchQuery;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for SemanticSearchProvider's algorithm handling on the Unified
 * Search path: a supported admin-configured algorithm is forwarded to the MCP
 * server untouched. (The provider also carries a defensive clamp to a serviceable
 * type, but that branch is unreachable in live behavior — the server advertises
 * all three algorithms when vector sync is on and [] when off, the latter caught
 * by the vector_sync_enabled early-return.)
 */
final class SemanticSearchProviderTest extends TestCase {
	private McpServerClient&MockObject $client;
	private IConfig&MockObject $config;
	private SearchSources&MockObject $searchSources;

	protected function setUp(): void {
		parent::setUp();
		$this->client = $this->createMock(McpServerClient::class);
		$this->config = $this->createMock(IConfig::class);
		$this->searchSources = $this->createMock(SearchSources::class);
	}

	/**
	 * Build a provider whose collaborators are stubbed just enough to reach the
	 * searchForUnifiedSearch() call, with the MCP status advertising $supported.
	 *
	 * @param list<string> $supported
	 */
	private function providerAdvertising(array $supported): SemanticSearchProvider {
		$this->client->method('getStatus')->willReturn([
			'vector_sync_enabled' => true,
			'supported_search_types' => $supported,
		]);

		$tokenMinter = $this->createMock(McpTokenMinter::class);
		$tokenMinter->method('mintForUser')->willReturn('mint-token');

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn(null); // force the live status fetch
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		$this->searchSources->method('effectiveEnabledDocTypes')->willReturn(['note']);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		// Real DocumentAccessService (final) wired to mocked leaf deps. These
		// tests return empty result sets, so the access filter never runs on real
		// items; default SearchSources::isInstalled=false ⇒ DELEGATE if it did.
		$logger = $this->createMock(LoggerInterface::class);
		$documentAccess = new DocumentAccessService(
			new FileAccessVerifier($this->createMock(IRootFolder::class), $logger),
			new DeckAccessVerifier(),
			new MailAccessVerifier(),
			new CalendarAccessVerifier(),
			$this->searchSources,
		);

		return new SemanticSearchProvider(
			$this->client,
			$tokenMinter,
			$this->config,
			$l10n,
			$this->createMock(IURLGenerator::class),
			$this->createMock(IMimeTypeDetector::class),
			$this->createMock(IPreview::class),
			$logger,
			$cacheFactory,
			$this->searchSources,
			$documentAccess,
		);
	}

	private function stubStoredAlgorithm(string $algorithm, ?string $scoreThreshold = null): void {
		$this->config->method('getAppValue')->willReturnCallback(
			function (string $app, string $key, string $default) use ($algorithm, $scoreThreshold): string {
				if ($key === AdminSettings::SETTING_SEARCH_ALGORITHM) {
					return $algorithm;
				}
				if ($scoreThreshold !== null && $key === AdminSettings::SETTING_SEARCH_SCORE_THRESHOLD) {
					return $scoreThreshold;
				}
				return $default;
			},
		);
	}

	private function query(): ISearchQuery&MockObject {
		$query = $this->createMock(ISearchQuery::class);
		$query->method('getTerm')->willReturn('leadership award');
		$query->method('getLimit')->willReturn(10);
		$query->method('getCursor')->willReturn(null);
		return $query;
	}

	private function user(): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		return $user;
	}

	/**
	 * Run one search and return the args sent to searchForUnifiedSearch.
	 *
	 * Named args arrive positionally in declaration order:
	 * (query, token, limit, offset, algorithm, fusion, scoreThreshold, docTypes)
	 *
	 * @return list<mixed>
	 */
	private function capturedCall(SemanticSearchProvider $provider): array {
		$captured = null;
		$this->client->method('searchForUnifiedSearch')->willReturnCallback(
			function (...$args) use (&$captured): array {
				$captured = $args;
				return ['results' => [], 'total_found' => 0];
			},
		);
		$provider->search($this->user(), $this->query());
		return $captured;
	}

	/** @return string the algorithm actually sent to searchForUnifiedSearch */
	private function capturedAlgorithm(SemanticSearchProvider $provider): string {
		return $this->capturedCall($provider)[4];
	}

	/** @return float the score threshold actually sent to searchForUnifiedSearch */
	private function capturedScoreThreshold(SemanticSearchProvider $provider): float {
		return $this->capturedCall($provider)[6];
	}

	public function testLeavesSupportedAlgorithmUntouchedInHybridMode(): void {
		$this->stubStoredAlgorithm('semantic');
		$provider = $this->providerAdvertising(['semantic', 'bm25', 'hybrid']);
		$this->assertSame('semantic', $this->capturedAlgorithm($provider));
	}

	/**
	 * Dense-only search scores by cosine similarity, which genuinely is in
	 * [0, 1], so the admin's percentage converts to a meaningful cut.
	 */
	public function testSendsAdminScoreThresholdForSemanticSearch(): void {
		$this->stubStoredAlgorithm('semantic', '25');
		$provider = $this->providerAdvertising(['semantic', 'bm25', 'hybrid']);
		$this->assertEqualsWithDelta(0.25, $this->capturedScoreThreshold($provider), 0.0001);
	}

	/**
	 * The fused-score algorithms must NOT receive it. Their score is an RRF rank
	 * artifact bounded by 2/VECTOR_SEARCH_RRF_K — about 0.033 at the server's
	 * default k=60 — so forwarding an admin setting of 25% would mean 0.25
	 * against a 0.033 ceiling and return ZERO results for every query, with no
	 * error to explain it. Regression guard for a silent, total search outage.
	 *
	 * @dataProvider fusedAlgorithms
	 */
	public function testSuppressesAdminScoreThresholdForFusedAlgorithms(string $algorithm): void {
		$this->stubStoredAlgorithm($algorithm, '25');
		$provider = $this->providerAdvertising(['semantic', 'bm25', 'hybrid']);
		$this->assertSame(0.0, $this->capturedScoreThreshold($provider));
	}

	/** @return iterable<string, list<string>> */
	public static function fusedAlgorithms(): iterable {
		// Both route to the server's BM25HybridSearchAlgorithm and return a
		// fused score — `bm25` is not a sparse-only path.
		yield 'hybrid' => ['hybrid'];
		yield 'bm25' => ['bm25'];
	}
}
