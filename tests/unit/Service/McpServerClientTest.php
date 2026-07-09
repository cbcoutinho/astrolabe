<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Service;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use OCA\Astrolabe\Service\McpServerClient;
use OCP\App\IAppManager;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for McpServerClient.
 *
 * Focused on the HTTP 428 (Precondition Required) provisioning path —
 * the MCP server returns 428 when the user has not completed Login Flow v2,
 * and McpServerClient surfaces that as a structured marker for the controller
 * to map to a "complete authorization" CTA.
 *
 * The client speaks PSR-18 (ADR-029): the constructor takes a
 * Psr\Http\Client\ClientInterface plus PSR-17 factories, so these tests stub
 * the client's sendRequest() and build requests with the real Guzzle factory.
 */
final class McpServerClientTest extends TestCase {
	private ClientInterface&MockObject $httpClient;
	private IConfig&MockObject $config;
	private LoggerInterface&MockObject $logger;
	private IAppManager&MockObject $appManager;
	private McpServerClient $client;

	protected function setUp(): void {
		parent::setUp();

		$this->httpClient = $this->createMock(ClientInterface::class);
		$this->config = $this->createMock(IConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->appManager = $this->createMock(IAppManager::class);

		$this->config->method('getSystemValue')
			->willReturnCallback(function (string $key, $default) {
				if ($key === 'mcp_server_url') {
					return 'http://mcp-server:8000'; // NOSONAR
				}
				return $default;
			});

		$this->appManager->method('getAppVersion')
			->with('astrolabe')
			->willReturn('0.14.0');

		$factory = new HttpFactory();
		$this->client = new McpServerClient(
			$this->httpClient,
			$factory,
			$factory,
			$this->config,
			$this->logger,
			$this->appManager,
		);
	}

	private function makeResponse(int $statusCode, string $body): ResponseInterface {
		return new Response($statusCode, [], $body);
	}

	// =========================================================================
	// listWebhooks() — 428 provisioning path
	// =========================================================================

	public function testListWebhooksReturnsProvisioningMarkerOn428(): void {
		$response = $this->makeResponse(428, json_encode([
			'message' => 'App password not provisioned',
		]));

		$this->httpClient->expects($this->once())
			->method('sendRequest')
			->willReturn($response);

		$result = $this->client->listWebhooks('access-token');

		$this->assertArrayHasKey('error', $result);
		$this->assertArrayHasKey('provisioning_required', $result);
		$this->assertTrue($result['provisioning_required']);
		$this->assertEquals('App password not provisioned', $result['error']);
	}

	public function testListWebhooksUsesFallbackMessageWhen428BodyHasNoMessage(): void {
		$response = $this->makeResponse(428, json_encode(['detail' => 'whatever']));

		$this->httpClient->method('sendRequest')->willReturn($response);

		$result = $this->client->listWebhooks('access-token');

		$this->assertTrue($result['provisioning_required'] ?? false);
		$this->assertStringContainsString(
			'Nextcloud access not provisioned',
			$result['error']
		);
	}

	public function testListWebhooksUsesFallbackMessageWhen428BodyIsNotJson(): void {
		$response = $this->makeResponse(428, 'not-json');

		$this->httpClient->method('sendRequest')->willReturn($response);

		$result = $this->client->listWebhooks('access-token');

		$this->assertTrue($result['provisioning_required'] ?? false);
		$this->assertStringContainsString(
			'Nextcloud access not provisioned',
			$result['error']
		);
	}

	// =========================================================================
	// listWebhooks() — non-2xx fallback (the issue raised by the reviewer:
	// without a general fallback, e.g. a 401 with no top-level "error" key
	// would silently be treated as success.)
	// =========================================================================

	public function testListWebhooksReturnsErrorOn401WithNoErrorKey(): void {
		$response = $this->makeResponse(401, json_encode(['detail' => 'Unauthorized']));

		$this->httpClient->method('sendRequest')->willReturn($response);

		$result = $this->client->listWebhooks('access-token');

		$this->assertArrayHasKey('error', $result);
		$this->assertStringContainsString('401', $result['error']);
		$this->assertArrayNotHasKey('provisioning_required', $result);
	}

	public function testListWebhooksReturnsErrorOn500(): void {
		$response = $this->makeResponse(500, '');

		$this->httpClient->method('sendRequest')->willReturn($response);

		$result = $this->client->listWebhooks('access-token');

		$this->assertArrayHasKey('error', $result);
		$this->assertStringContainsString('500', $result['error']);
	}

	public function testListWebhooksParsesSuccessfulResponse(): void {
		$response = $this->makeResponse(200, json_encode([
			'webhooks' => [
				['id' => 1, 'event' => 'OCA\\Files::postCreate'],
			],
		]));

		$this->httpClient->method('sendRequest')->willReturn($response);

		$result = $this->client->listWebhooks('access-token');

		$this->assertArrayNotHasKey('error', $result);
		$this->assertArrayNotHasKey('provisioning_required', $result);
		$this->assertCount(1, $result['webhooks'] ?? []);
	}

	// =========================================================================
	// User-Agent header — verifies outbound MCP calls identify the app/version
	// so backend access logs can attribute requests to a specific Astrolabe build.
	// =========================================================================

	public function testOutboundRequestsIncludeAstrolabeUserAgent(): void {
		$captured = null;
		$this->httpClient->expects($this->once())
			->method('sendRequest')
			->with($this->callback(function (RequestInterface $request) use (&$captured): bool {
				$captured = $request;
				return true;
			}))
			->willReturn($this->makeResponse(200, json_encode(['status' => 'ok'])));

		$this->client->getStatus();

		$this->assertInstanceOf(RequestInterface::class, $captured);
		$this->assertStringContainsString('/api/v1/status', (string)$captured->getUri());
		$this->assertSame('Nextcloud-Astrolabe/0.14.0', $captured->getHeaderLine('User-Agent'));
	}

	public function testUserAgentDoesNotClobberCallerHeaders(): void {
		$captured = null;
		$this->httpClient->expects($this->once())
			->method('sendRequest')
			->with($this->callback(function (RequestInterface $request) use (&$captured): bool {
				$captured = $request;
				return true;
			}))
			->willReturn($this->makeResponse(200, json_encode(['webhooks' => []])));

		$this->client->listWebhooks('access-token');

		$this->assertInstanceOf(RequestInterface::class, $captured);
		$this->assertSame('Bearer access-token', $captured->getHeaderLine('Authorization'));
		$this->assertSame('Nextcloud-Astrolabe/0.14.0', $captured->getHeaderLine('User-Agent'));
	}

	// =========================================================================
	// createWebhook() — 428 provisioning path
	// =========================================================================

	public function testCreateWebhookReturnsProvisioningMarkerOn428(): void {
		$response = $this->makeResponse(428, json_encode([
			'message' => 'Provision required',
		]));

		$this->httpClient->method('sendRequest')->willReturn($response);

		$result = $this->client->createWebhook(
			'OCA\\Files::postCreate',
			'http://mcp-server:8000/webhooks/nextcloud', // NOSONAR
			null,
			'access-token',
		);

		$this->assertTrue($result['provisioning_required'] ?? false);
		$this->assertEquals('Provision required', $result['error'] ?? null);
	}

	// =========================================================================
	// deleteWebhook() — 428 provisioning path
	// =========================================================================

	public function testDeleteWebhookReturnsProvisioningMarkerOn428(): void {
		$response = $this->makeResponse(428, json_encode([
			'message' => 'Provision required',
		]));

		$this->httpClient->method('sendRequest')->willReturn($response);

		$result = $this->client->deleteWebhook(42, 'access-token');

		$this->assertTrue($result['provisioning_required'] ?? false);
	}

	public function testDeleteWebhookReturnsSuccessOn204(): void {
		$response = $this->makeResponse(204, '');

		$this->httpClient->method('sendRequest')->willReturn($response);

		$result = $this->client->deleteWebhook(42, 'access-token');

		$this->assertTrue($result['success'] ?? false);
		$this->assertArrayNotHasKey('error', $result);
	}

	// =========================================================================
	// getInstalledApps() — 428 provisioning path
	// =========================================================================

	public function testGetInstalledAppsReturnsProvisioningMarkerOn428(): void {
		$response = $this->makeResponse(428, json_encode([
			'message' => 'Provision required',
		]));

		$this->httpClient->method('sendRequest')->willReturn($response);

		$result = $this->client->getInstalledApps('access-token');

		$this->assertTrue($result['provisioning_required'] ?? false);
	}

	// =========================================================================
	// sendSyncEvent() — native listener delivery to the webhook ingress
	// =========================================================================

	/**
	 * Build a client whose config also returns a webhook secret, so the
	 * constructor picks it up (the shared setUp() client has none configured).
	 */
	private function clientWithWebhookSecret(string $secret): McpServerClient {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')
			->willReturnCallback(function (string $key, $default) use ($secret) {
				if ($key === 'mcp_server_url') {
					return 'http://mcp-server:8000'; // NOSONAR
				}
				if ($key === 'mcp_webhook_secret') {
					return $secret;
				}
				return $default;
			});
		$factory = new HttpFactory();
		return new McpServerClient($this->httpClient, $factory, $factory, $config, $this->logger, $this->appManager);
	}

	private function sampleEnvelope(): array {
		return [
			'event' => [
				'node' => ['id' => 42, 'path' => '/admin/files/Notes/todo.md'],
				'class' => 'OCP\\Files\\Events\\Node\\NodeWrittenEvent',
			],
			'user' => ['uid' => 'admin', 'displayName' => 'admin'],
			'time' => 1720000000,
		];
	}

	public function testSendSyncEventRefusesWhenSecretUnset(): void {
		// The shared setUp() client has no mcp_webhook_secret configured.
		$this->httpClient->expects($this->never())->method('sendRequest');

		$result = $this->client->sendSyncEvent($this->sampleEnvelope());

		$this->assertArrayHasKey('error', $result);
		$this->assertStringContainsString('secret', $result['error']);
	}

	public function testSendSyncEventPostsEnvelopeWithBearerSecret(): void {
		$captured = null;
		$this->httpClient->expects($this->once())
			->method('sendRequest')
			->with($this->callback(function (RequestInterface $request) use (&$captured): bool {
				$captured = $request;
				return true;
			}))
			->willReturn($this->makeResponse(200, json_encode(['status' => 'queued'])));

		$result = $this->clientWithWebhookSecret('s3cr3t')->sendSyncEvent($this->sampleEnvelope());

		$this->assertArrayNotHasKey('error', $result);
		$this->assertSame('queued', $result['status'] ?? null);

		$this->assertInstanceOf(RequestInterface::class, $captured);
		$this->assertSame('POST', $captured->getMethod());
		$this->assertStringContainsString('/webhooks/nextcloud', (string)$captured->getUri());
		$this->assertStringNotContainsString('/api/v1/', (string)$captured->getUri());
		$this->assertSame('Bearer s3cr3t', $captured->getHeaderLine('Authorization'));
		$this->assertSame('Nextcloud-Astrolabe/0.14.0', $captured->getHeaderLine('User-Agent'));
		$decoded = json_decode((string)$captured->getBody(), true);
		$this->assertSame('OCP\\Files\\Events\\Node\\NodeWrittenEvent', $decoded['event']['class']);
		$this->assertSame('admin', $decoded['user']['uid']);
	}

	public function testSendSyncEventReturnsErrorOn401(): void {
		$this->httpClient->method('sendRequest')->willReturn($this->makeResponse(401, json_encode(['detail' => 'bad secret'])));

		$result = $this->clientWithWebhookSecret('wrong')->sendSyncEvent($this->sampleEnvelope());

		$this->assertArrayHasKey('error', $result);
		$this->assertStringContainsString('401', $result['error']);
	}

	public function testSendSyncEventReturnsErrorOn503(): void {
		$this->httpClient->method('sendRequest')->willReturn($this->makeResponse(503, ''));

		$result = $this->clientWithWebhookSecret('s3cr3t')->sendSyncEvent($this->sampleEnvelope());

		$this->assertArrayHasKey('error', $result);
		$this->assertStringContainsString('503', $result['error']);
	}
}
