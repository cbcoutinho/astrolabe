<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Service;

use OCA\Astrolabe\Service\McpServerClient;
use OCA\Astrolabe\Service\SearchCapabilities;
use OCA\Astrolabe\Service\UnsupportedSearchTypeException;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SearchCapabilities: it reads supported_search_types from the
 * MCP server's /api/v1/status and gates algorithm requests, staying permissive
 * when status is unavailable so the server's 422 remains the backstop.
 */
final class SearchCapabilitiesTest extends TestCase {
	private McpServerClient&MockObject $client;
	private ICache&MockObject $cache;

	protected function setUp(): void {
		parent::setUp();
		$this->client = $this->createMock(McpServerClient::class);
		$this->cache = $this->createMock(ICache::class);
		// Default: cache miss, so every test exercises the live status fetch
		// unless it overrides ->get() to return a cached array.
		$this->cache->method('get')->willReturn(null);
	}

	private function subject(): SearchCapabilities {
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->cache);
		return new SearchCapabilities($this->client, $cacheFactory);
	}

	public function testReturnsAdvertisedSetVerbatim(): void {
		// The advertised set is passed through verbatim (order and contents
		// preserved), whatever subset the server reports.
		$this->client->method('getStatus')->willReturn([
			'vector_sync_enabled' => true,
			'supported_search_types' => ['bm25', 'hybrid'],
		]);
		$this->cache->expects($this->once())->method('set')
			->with('supported_search_types', ['bm25', 'hybrid'], $this->anything());

		$this->assertSame(['bm25', 'hybrid'], $this->subject()->getSupportedSearchTypes());
	}

	public function testReturnsAllThreeInHybridMode(): void {
		$this->client->method('getStatus')->willReturn([
			'supported_search_types' => ['semantic', 'bm25', 'hybrid'],
		]);
		$this->assertSame(
			['semantic', 'bm25', 'hybrid'],
			$this->subject()->getSupportedSearchTypes(),
		);
	}

	public function testStaysPermissiveWhenStatusErrors(): void {
		// A status blip must not block search — fall back to the full set and let
		// the server's 422 be the authoritative backstop. Nothing is cached.
		$this->client->method('getStatus')->willReturn(['error' => 'connection refused']);
		$this->cache->expects($this->never())->method('set');

		$this->assertSame(SearchCapabilities::ALL, $this->subject()->getSupportedSearchTypes());
	}

	public function testStaysPermissiveWhenFieldAbsent(): void {
		// An older MCP server that predates the field → treat as "all".
		$this->client->method('getStatus')->willReturn(['vector_sync_enabled' => true]);
		$this->assertSame(SearchCapabilities::ALL, $this->subject()->getSupportedSearchTypes());
	}

	public function testUsesCachedValueWithoutFetching(): void {
		$this->cache = $this->createMock(ICache::class);
		$this->cache->method('get')->willReturn(['semantic', 'bm25', 'hybrid']);
		$this->client->expects($this->never())->method('getStatus');

		$this->assertSame(['semantic', 'bm25', 'hybrid'], $this->subject()->getSupportedSearchTypes());
	}

	public function testAssertSupportedThrowsWhenNothingAdvertised(): void {
		// Vector sync off ⇒ the server advertises [] and can serve no algorithm,
		// so any requested type is rejected.
		$this->client->method('getStatus')->willReturn([
			'vector_sync_enabled' => false,
			'supported_search_types' => [],
		]);

		try {
			$this->subject()->assertSupported('hybrid');
			$this->fail('Expected UnsupportedSearchTypeException');
		} catch (UnsupportedSearchTypeException $e) {
			$this->assertSame('hybrid', $e->getRequested());
			$this->assertSame([], $e->getSupported());
		}
	}

	public function testAssertSupportedPassesForSupported(): void {
		$this->client->method('getStatus')->willReturn([
			'supported_search_types' => ['semantic', 'bm25', 'hybrid'],
		]);
		$this->subject()->assertSupported('hybrid');
		$this->addToAssertionCount(1); // no exception == pass
	}
}
