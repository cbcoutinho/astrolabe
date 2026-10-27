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
	private ICache&MockObject $statusCache;

	protected function setUp(): void {
		parent::setUp();
		$this->client = $this->createMock(McpServerClient::class);
		$this->config = $this->createMock(IConfig::class);
		$this->searchSources = $this->createMock(SearchSources::class);
		$this->statusCache = $this->createMock(ICache::class);
	}

	/**
	 * Build a provider whose collaborators are stubbed just enough to reach the
	 * searchForUnifiedSearch() call, with the MCP status advertising $supported.
	 *
	 * @param list<string> $supported
	 * @param array<string, mixed> $status extra status fields
	 */
	private function providerAdvertising(array $supported, array $status = []): SemanticSearchProvider {
		$this->client->method('getStatus')->willReturn([
			'vector_sync_enabled' => true,
			'supported_search_types' => $supported,
		] + $status);

		$tokenMinter = $this->createMock(McpTokenMinter::class);
		$tokenMinter->method('mintForUser')->willReturn('mint-token');

		$this->statusCache->method('get')->willReturn(null); // force the live status fetch
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->statusCache);

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
	 * (query, token, limit, offset, algorithm, fusion, scoreThreshold, docTypes, rerank)
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

	/** @return iterable<string, array{array<string, mixed>, bool}> */
	public static function rerankStatuses(): iterable {
		yield 'advertised' => [['rerank_available' => true], true];
		yield 'not advertised' => [['rerank_available' => false], false];
		yield 'older server' => [[], false];
	}

	/**
	 * The search bar reranks exactly when the server advertises a reranker.
	 *
	 * @dataProvider rerankStatuses
	 * @param array<string, mixed> $status
	 */
	public function testReranksOnlyWhenTheServerAdvertisesIt(array $status, bool $expected): void {
		$this->stubStoredAlgorithm('hybrid');
		$provider = $this->providerAdvertising(['semantic', 'bm25', 'hybrid'], $status);
		$this->assertSame($expected, $this->capturedCall($provider)[8]);
	}

	/**
	 * Run one reranked search whose first attempt returns $firstAttempt, and
	 * return the rerank flag of each call made.
	 *
	 * @param array<string, mixed> $firstAttempt
	 * @return list<bool>
	 */
	private function rerankAttempts(array $firstAttempt): array {
		$this->stubStoredAlgorithm('hybrid');
		$provider = $this->providerAdvertising(['semantic', 'bm25', 'hybrid'], ['rerank_available' => true]);
		$calls = [];
		$this->client->method('searchForUnifiedSearch')->willReturnCallback(
			function (...$args) use (&$calls, $firstAttempt): array {
				$calls[] = $args[8];
				return count($calls) === 1 ? $firstAttempt : ['results' => [], 'total_found' => 0];
			},
		);
		$provider->search($this->user(), $this->query());
		return $calls;
	}

	/**
	 * The status is cached, so the reranker may be gone by the time the search
	 * runs. The server's 422 for that is retried without rerank, and the stale
	 * status is dropped so the next keystroke does not pay for it again.
	 */
	public function testRetriesWithoutRerankWhenTheServerRejectsIt(): void {
		$this->statusCache->expects($this->once())->method('remove')->with('mcp_status');
		$this->assertSame([true, false], $this->rerankAttempts(['error' => 'rerank unavailable', 'unsupported' => true]));
	}

	/** A timeout or outage would only fail again, doubling the wait. */
	public function testDoesNotRetryOtherFailuresOfARerankedSearch(): void {
		$this->assertSame([true], $this->rerankAttempts(['error' => 'timed out']));
	}

	/**
	 * The subline (shown when a row has no chunk/page metadata) reports the
	 * calibrated relevance, not the raw fused score near 0.033.
	 *
	 * @dataProvider sublineScores
	 * @param array<string, float> $scores
	 */
	public function testSublineReportsRelevanceOverRawScore(array $scores, string $expected): void {
		$this->stubStoredAlgorithm('hybrid');
		$provider = $this->providerAdvertising(['semantic', 'bm25', 'hybrid']);
		$this->client->method('searchForUnifiedSearch')
			->willReturn(['results' => [['id' => 1, 'doc_type' => 'note', 'title' => 'T'] + $scores], 'total_found' => 1]);
		$entries = $provider->search($this->user(), $this->query())->jsonSerialize()['entries'];
		$this->assertStringContainsString($expected, $entries[0]->jsonSerialize()['subline']);
	}

	/** @return iterable<string, array{array<string, float>, string}> */
	public static function sublineScores(): iterable {
		yield 'relevance present' => [['relevance' => 0.8, 'score' => 0.03], '80%'];
		yield 'relevance zero is kept' => [['relevance' => 0.0, 'score' => 0.03], '0%'];
		yield 'relevance absent' => [['score' => 0.5], '50%'];
	}

	public function testDoesNotRetryAFailedSearchThatWasNotReranked(): void {
		$this->stubStoredAlgorithm('hybrid');
		$provider = $this->providerAdvertising(['semantic', 'bm25', 'hybrid']);
		$this->client->expects($this->once())->method('searchForUnifiedSearch')
			->willReturn(['error' => 'down']);
		$provider->search($this->user(), $this->query());
	}
}
