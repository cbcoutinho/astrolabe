<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Controller;

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
		//        modifiedAfter, modifiedBefore, pathPrefix) — trimmed.
		$this->assertSame('/Projects/Reports', $captured[8]);
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
