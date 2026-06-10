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
 * Scope (walking skeleton): the public, stateless ``GET /api/v1/status`` call.
 * The authenticated surface (search, webhooks, apps, chunk-context, pdf-preview)
 * needs provider states + bearer-token handling on the MCP side and is the
 * deferred follow-up (see tests/contract/README.md).
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
}
