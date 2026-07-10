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
}
