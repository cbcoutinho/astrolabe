<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Controller;

use OCA\Astrolabe\Service\UnsupportedSearchTypeException;
use OCP\AppFramework\Http;

/**
 * Tests for the ADR-027 modified-date range filter on ApiController::search:
 * RFC 3339 bounds are validated client-of-MCP side (clean 400 instead of a
 * 500 from the MCP server) and forwarded to McpServerClient unchanged.
 */
final class ApiControllerSearchTest extends AbstractApiControllerTestCase {
	public function testRejectsInvertedDateRangeWith400(): void {
		// after > before — the MCP server would return zero results; we catch
		// it earlier and return a descriptive 400.
		$response = $this->controller->search(
			query: 'anything',
			modified_after: '2026-06-01T00:00:00Z',
			modified_before: '2026-01-01T00:00:00Z',
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('on or before', $data['error']);
	}

	public function testRejectsMalformedDateWith400(): void {
		$response = $this->controller->search(
			query: 'anything',
			modified_after: 'not-a-date',
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testRejectsUnsupportedAlgorithmWith422(): void {
		// When vector sync is off the server advertises [] and can serve nothing,
		// so the picker is empty — but a direct/stale client can still ask for an
		// algorithm. The guard rejects it with 422 (mirroring the MCP server's
		// backstop) and never calls the MCP client.
		$this->authenticateUserWithToken();
		$this->searchCapabilities->method('assertSupported')
			->with('semantic')
			->willThrowException(new UnsupportedSearchTypeException('semantic', []));
		$this->client->expects($this->never())->method('search');

		$response = $this->controller->search(query: 'anything', algorithm: 'semantic');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		// Human-readable message (shown verbatim in the UI) + machine-readable code.
		$this->assertSame('unsupported_search_type', $data['code']);
		$this->assertStringContainsString('semantic', $data['error']);
		$this->assertSame('semantic', $data['requested']);
		$this->assertSame([], $data['supported_search_types']);
	}

	public function testForwardsRfc3339BoundsToClient(): void {
		$this->authenticateUserWithToken();

		$captured = [];
		$this->client->expects($this->once())
			->method('search')
			->willReturnCallback(function (...$args) use (&$captured): array {
				$captured = $args;
				return ['results' => [], 'algorithm_used' => 'hybrid'];
			});

		$response = $this->controller->search(
			query: 'meeting notes',
			modified_after: '2026-01-01T00:00:00Z',
			modified_before: '2026-06-01T00:00:00Z',
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		// search(query, algorithm, limit, includePca, docTypes, token,
		//        modifiedAfter, modifiedBefore)
		$this->assertSame('2026-01-01T00:00:00Z', $captured[6]);
		$this->assertSame('2026-06-01T00:00:00Z', $captured[7]);
	}

	public function testForwardsPathPrefixToClient(): void {
		$this->authenticateUserWithToken();

		$captured = [];
		$this->client->expects($this->once())
			->method('search')
			->willReturnCallback(function (...$args) use (&$captured): array {
				$captured = $args;
				return ['results' => [], 'algorithm_used' => 'hybrid'];
			});

		$response = $this->controller->search(
			query: 'spec',
			path_prefix: '  /Projects/Reports  ',
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		// search(query, algorithm, limit, includePca, docTypes, token,
		//        modifiedAfter, modifiedBefore, pathPrefixes) — trimmed list.
		// The legacy single path_prefix is folded into the path_prefixes list.
		$this->assertSame(['/Projects/Reports'], $captured[8]);
	}

	public function testForwardsMultiplePathPrefixesToClient(): void {
		$this->authenticateUserWithToken();

		$captured = [];
		$this->client->expects($this->once())
			->method('search')
			->willReturnCallback(function (...$args) use (&$captured): array {
				$captured = $args;
				return ['results' => [], 'algorithm_used' => 'hybrid'];
			});

		$response = $this->controller->search(
			query: 'spec',
			path_prefix: '/Projects/Reports',
			path_prefixes: " /Archive \n /Projects/Reports \n  ",
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		// Legacy single + newline list merge, trim, drop blanks, and dedupe
		// (order-preserving) into one path_prefixes array.
		$this->assertSame(['/Projects/Reports', '/Archive'], $captured[8]);
	}

	public function testForwardsPrefixesOnlyToClient(): void {
		// The normal frontend flow: path_prefix left empty, folders supplied as
		// a newline-joined path_prefixes list from the folder picker.
		$this->authenticateUserWithToken();

		$captured = [];
		$this->client->expects($this->once())
			->method('search')
			->willReturnCallback(function (...$args) use (&$captured): array {
				$captured = $args;
				return ['results' => [], 'algorithm_used' => 'hybrid'];
			});

		$response = $this->controller->search(
			query: 'spec',
			path_prefixes: "/foo\n/bar",
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['/foo', '/bar'], $captured[8]);
	}

	public function testHandlesCrlfLineEndings(): void {
		// Windows clients may send CRLF; trim() strips the trailing \r so the
		// folders come through clean and identical to the LF case.
		$this->authenticateUserWithToken();

		$captured = [];
		$this->client->expects($this->once())
			->method('search')
			->willReturnCallback(function (...$args) use (&$captured): array {
				$captured = $args;
				return ['results' => [], 'algorithm_used' => 'hybrid'];
			});

		$this->controller->search(query: 'spec', path_prefixes: "/foo\r\n/bar");

		$this->assertSame(['/foo', '/bar'], $captured[8]);
	}

	public function testCapsPathPrefixesAtTwenty(): void {
		// A hostile/malformed client can't build an unbounded OR-filter: the
		// list is sliced to 20 entries before it reaches the MCP server.
		$this->authenticateUserWithToken();

		$captured = [];
		$this->client->expects($this->once())
			->method('search')
			->willReturnCallback(function (...$args) use (&$captured): array {
				$captured = $args;
				return ['results' => [], 'algorithm_used' => 'hybrid'];
			});

		$folders = [];
		for ($i = 0; $i < 50; $i++) {
			$folders[] = "/dir{$i}";
		}
		$this->controller->search(
			query: 'spec',
			path_prefixes: implode("\n", $folders),
		);

		$this->assertCount(20, $captured[8]);
		$this->assertSame('/dir0', $captured[8][0]);
		$this->assertSame('/dir19', $captured[8][19]);
	}

	public function testPassesNullPathWhenNoFoldersGiven(): void {
		$this->authenticateUserWithToken();

		$captured = [];
		$this->client->expects($this->once())
			->method('search')
			->willReturnCallback(function (...$args) use (&$captured): array {
				$captured = $args;
				return ['results' => [], 'algorithm_used' => 'hybrid'];
			});

		$this->controller->search(query: 'spec', path_prefixes: " \n \n  ");

		// Blank-only input ⇒ null, not an empty array, so the MCP server adds
		// no path condition.
		$this->assertNull($captured[8]);
	}

	public function testPassesNullBoundsWhenDatesOmitted(): void {
		$this->authenticateUserWithToken();

		$captured = [];
		$this->client->expects($this->once())
			->method('search')
			->willReturnCallback(function (...$args) use (&$captured): array {
				$captured = $args;
				return ['results' => [], 'algorithm_used' => 'hybrid'];
			});

		$this->controller->search(query: 'meeting notes');

		// Open bounds + absent path are passed as null, not empty string.
		$this->assertNull($captured[6]);
		$this->assertNull($captured[7]);
		$this->assertNull($captured[8]);
	}

	public function testTimedOutSearchIs504WithACode(): void {
		// The MCP server did not answer within Nextcloud's HTTP timeout, which
		// usually means it is waiting on the embedding service. The UI keys
		// its "search by keyword instead" offer off the code, not cURL's text.
		$this->authenticateUserWithToken();
		$this->client->method('search')->willReturn([
			'error' => 'cURL error 28: Operation timed out after 30001 milliseconds',
			'timeout' => true,
		]);

		$response = $this->controller->search(query: 'meeting notes');

		$this->assertSame(Http::STATUS_GATEWAY_TIMEOUT, $response->getStatus());
		$this->assertSame('search_timeout', $response->getData()['code'] ?? null);
		$this->assertStringNotContainsString('cURL', (string)($response->getData()['error'] ?? ''));
	}

	public function testOtherSearchFailuresStay500(): void {
		$this->authenticateUserWithToken();
		$this->client->method('search')->willReturn(['error' => 'Unexpected HTTP 500 from MCP server']);

		$response = $this->controller->search(query: 'meeting notes');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertArrayNotHasKey('code', $response->getData());
	}

	/**
	 * Reranking is not a user choice: the controller asks for it exactly when
	 * the server advertises it. search() arg 11 is $rerank (after sarCase, offset).
	 */
	public function testRequestsRerankIffServerAdvertisesIt(): void {
		$this->authenticateUserWithToken();
		$this->searchCapabilities->method('isRerankAvailable')
			->willReturnOnConsecutiveCalls(true, false);

		$captured = [];
		$this->client->expects($this->exactly(2))
			->method('search')
			->willReturnCallback(function (...$args) use (&$captured): array {
				$captured[] = $args[11];
				return ['results' => [], 'algorithm_used' => 'hybrid', 'reranked' => $args[11]];
			});

		$on = $this->controller->search(query: 'spec');
		$off = $this->controller->search(query: 'spec');

		$this->assertSame([true, false], $captured);
		$this->assertTrue($on->getData()['reranked']);
		$this->assertFalse($off->getData()['reranked']);
	}

	/** @return array<string, array{string, string, string}> */
	public static function invalidSearchParams(): array {
		return [
			'fusion' => ['fusion', 'borda', 'fusion must be one of'],
			'granularity' => ['granularity', 'page', 'granularity must be one of'],
			'min_relevance above 1' => ['min_relevance', '1.5', 'between 0 and 1'],
			'min_relevance not a number' => ['min_relevance', 'high', 'min_relevance'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('invalidSearchParams')]
	public function testRejectsInvalidSearchParamWith400(string $param, string $value, string $message): void {
		$this->authenticateUserWithToken();
		$this->client->expects($this->never())->method('search');

		$response = $this->controller->search(...['query' => 'spec', $param => $value]);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString($message, $response->getData()['error']);
	}

	/**
	 * An empty fusion comes from the admin setting. A bad stored value is the
	 * admin's config, not this request's fault, so it falls back to the
	 * default instead of failing every search. search() arg 12 is $fusion.
	 */
	public function testFusionFallsBackToAdminSettingThenToDefault(): void {
		$this->authenticateUserWithToken();
		$this->appConfig->method('getValueString')->willReturnOnConsecutiveCalls('DBSF', 'garbage');

		$captured = [];
		$this->client->method('search')
			->willReturnCallback(function (...$args) use (&$captured): array {
				$captured[] = $args[12];
				return ['results' => [], 'algorithm_used' => 'hybrid'];
			});

		$this->controller->search(query: 'spec');
		$this->controller->search(query: 'spec');

		$this->assertSame(['dbsf', 'rrf'], $captured);
	}

	/** A 422 from the MCP server is the caller's to correct: 422, not 500. */
	public function testUnsupportedSearchIsPassedThroughAs422(): void {
		$this->authenticateUserWithToken();
		$this->client->method('search')->willReturn([
			'error' => 'granularity_unsupported_for_algorithm',
			'unsupported' => true,
		]);

		$response = $this->controller->search(query: 'spec', granularity: 'document', algorithm: 'semantic');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(
			['success' => false, 'error' => 'granularity_unsupported_for_algorithm'],
			$response->getData(),
		);
	}

	/** A case search's 422 reaches the client as a 422 too, not a 500. */
	public function testUnsupportedCaseSearchIsPassedThroughAs422(): void {
		$this->authenticateUserWithToken();
		$this->searchCapabilities->method('isSarAvailable')->willReturn(true);
		$this->client->method('search')->willReturn([
			'error' => 'This server has no reranker.',
			'status' => 422,
		]);

		$response = $this->controller->search(query: 'spec', sar_case: 101);

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame('This server has no reranker.', $response->getData()['error']);
	}
}
