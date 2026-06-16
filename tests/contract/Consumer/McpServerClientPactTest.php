<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Contract\Consumer;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use OCA\Astrolabe\Service\McpServerClient;
use OCP\App\IAppManager;
use OCP\IConfig;
use PhpPact\Consumer\InteractionBuilder;
use PhpPact\Consumer\Matcher\Matcher;
use PhpPact\Consumer\Model\ConsumerRequest;
use PhpPact\Consumer\Model\ProviderResponse;
use PhpPact\Standalone\MockService\MockServerConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Consumer contract: astrolabe (McpServerClient) -> nextcloud-mcp-server /api/v1.
 *
 * This is the other half of the bidirectional contract in ADR-029. astrolabe's
 * McpServerClient consumes the MCP server's management API; this test pins the
 * request shape and response contract it depends on, producing a pact with
 * consumer=astrolabe, provider=nextcloud-mcp-server that the MCP server verifies.
 *
 * Scope: the public, stateless ``GET /api/v1/status`` call, plus the
 * bearer-authenticated ``POST /api/v1/vector-sync/purge`` consent-purge call
 * (with a provider state). Full provider verification of the authenticated
 * surface (search, webhooks, apps, chunk-context, pdf-preview, purge) needs
 * provider-state + token handling stood up on the MCP side — the deferred
 * follow-up (see tests/contract/README.md); the published pact rides the
 * broker's pending flow until then.
 *
 * It is an INTEGRATION test: pact-php boots its bundled Rust mock server (needs
 * ext-ffi), so it runs in the contract suite, not ``composer test:unit``.
 */
final class McpServerClientPactTest extends TestCase {
	private function mockServerConfig(): MockServerConfig {
		$config = new MockServerConfig();
		$config
			->setConsumer('astrolabe')
			->setProvider('nextcloud-mcp-server')
			->setPactDir(__DIR__ . '/../pacts');
		if ($logLevel = getenv('PACT_LOGLEVEL')) {
			$config->setLogLevel($logLevel);
		}
		return $config;
	}

	/**
	 * Build McpServerClient with a real PSR-18 client pointed at the mock server.
	 */
	private function clientFor(MockServerConfig $config): McpServerClient {
		$ncConfig = $this->createMock(IConfig::class);
		$ncConfig->method('getSystemValue')
			->willReturnCallback(function (string $key, $default) use ($config) {
				if ($key === 'mcp_server_url') {
					return (string)$config->getBaseUri();
				}
				return $default;
			});

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('0.0.0');

		$factory = new HttpFactory();
		return new McpServerClient(
			new GuzzleClient(['http_errors' => false]),
			$factory,
			$factory,
			$ncConfig,
			$this->createMock(LoggerInterface::class),
			$appManager,
		);
	}

	public function testGetStatusHonoursTheManagementContract(): void {
		$matcher = new Matcher();
		$config = $this->mockServerConfig();

		$request = (new ConsumerRequest())
			->setMethod('GET')
			->setPath('/api/v1/status');

		// The five fields McpServerClient::getStatus() relies on; matched by type
		// so the contract pins the shape, not the MCP server's exact values.
		$response = (new ProviderResponse())
			->setStatus(200)
			->addHeader('Content-Type', 'application/json')
			->setBody([
				'version' => $matcher->like('0.1.0'),
				'auth_mode' => $matcher->like('basic'),
				'vector_sync_enabled' => $matcher->boolean(false),
				'uptime_seconds' => $matcher->integer(123),
				'management_api_version' => $matcher->like('1.0'),
			]);

		$builder = new InteractionBuilder($config);
		$builder
			->uponReceiving('a request for MCP server status')
			->with($request)
			->willRespondWith($response);

		$status = $this->clientFor($config)->getStatus();

		// The mock server echoes the matcher example values, so these assertions
		// are intentionally coupled to the `$matcher->like(...)` examples above.
		$this->assertTrue($builder->verify(), 'Pact consumer verification failed');
		$this->assertArrayNotHasKey('error', $status);
		$this->assertSame('0.1.0', $status['version'] ?? null);
		$this->assertSame('basic', $status['auth_mode'] ?? null);
		$this->assertFalse($status['vector_sync_enabled'] ?? null);
		$this->assertSame(123, $status['uptime_seconds'] ?? null);
		$this->assertSame('1.0', $status['management_api_version'] ?? null);
	}

	/**
	 * Consent-purge contract: when an admin disables a source for semantic
	 * search, McpServerClient::purgeDocTypes() asks the MCP server to delete the
	 * already-indexed content for that source's doc type(s). This pins the
	 * request shape (bearer-authenticated POST with a doc_types array) and the
	 * ``purged`` map the client reads back.
	 *
	 * The provider state names the precondition the MCP server sets up before
	 * replaying this interaction (an admin caller authorised to purge). Provider
	 * verification of this authenticated endpoint is the deferred follow-up
	 * (ADR-029); the published pact rides the broker's pending flow until then.
	 */
	public function testPurgeDocTypesHonoursTheContract(): void {
		$matcher = new Matcher();
		$config = $this->mockServerConfig();

		$request = (new ConsumerRequest())
			->setMethod('POST')
			->setPath('/api/v1/vector-sync/purge')
			->addHeader('Authorization', $matcher->like('Bearer mint-token'))
			->addHeader('Content-Type', 'application/json')
			->setBody([
				// Any non-empty array of doc-type strings; the gate sends the
				// disabled source's catalog doc types (e.g. files -> "file").
				'doc_types' => $matcher->eachLike('file'),
			]);

		// 200 with a per-doc-type deleted count. ``failed`` is omitted on full
		// success (the MCP server only includes it on partial failure), so the
		// contract pins only the ``purged`` map the client returns to the admin.
		$response = (new ProviderResponse())
			->setStatus(200)
			->addHeader('Content-Type', 'application/json')
			->setBody([
				'purged' => [
					'file' => $matcher->integer(4),
				],
			]);

		$builder = new InteractionBuilder($config);
		$builder
			->given('an admin can purge indexed documents')
			->uponReceiving('a request to purge a disabled source\'s doc types')
			->with($request)
			->willRespondWith($response);

		$result = $this->clientFor($config)->purgeDocTypes(['file'], 'mint-token');

		$this->assertTrue($builder->verify(), 'Pact consumer verification failed');
		$this->assertArrayNotHasKey('error', $result);
		$this->assertSame(4, $result['purged']['file'] ?? null);
	}
}
