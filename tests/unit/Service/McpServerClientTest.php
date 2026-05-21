<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Service;

use OCA\Astrolabe\Service\McpServerClient;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for McpServerClient.
 *
 * Focused on the HTTP 428 (Precondition Required) provisioning path —
 * the MCP server returns 428 when the user has not completed Login Flow v2,
 * and McpServerClient surfaces that as a structured marker for the controller
 * to map to a "complete authorization" CTA.
 */
final class McpServerClientTest extends TestCase {
	private IClientService&MockObject $clientService;
	private IClient&MockObject $httpClient;
	private IConfig&MockObject $config;
	private LoggerInterface&MockObject $logger;
	private IAppManager&MockObject $appManager;
	private McpServerClient $client;

	protected function setUp(): void {
		parent::setUp();

		$this->clientService = $this->createMock(IClientService::class);
		$this->httpClient = $this->createMock(IClient::class);
		$this->config = $this->createMock(IConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->appManager = $this->createMock(IAppManager::class);

		$this->clientService->method('newClient')->willReturn($this->httpClient);

		$this->config->method('getSystemValue')
			->willReturnCallback(function (string $key, $default) {
				if ($key === 'mcp_server_url') {
					return 'http://mcp-server:8000';
				}
				return $default;
			});

		$this->appManager->method('getAppVersion')
			->with('astrolabe')
			->willReturn('0.14.0');

		$this->client = new McpServerClient(
			$this->clientService,
			$this->config,
			$this->logger,
			$this->appManager,
		);
	}

	private function makeResponse(int $statusCode, string $body): IResponse&MockObject {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($statusCode);
		$response->method('getBody')->willReturn($body);
		return $response;
	}

	// =========================================================================
	// listWebhooks() — 428 provisioning path
	// =========================================================================

	public function testListWebhooksReturnsProvisioningMarkerOn428(): void {
		$response = $this->makeResponse(428, json_encode([
			'message' => 'App password not provisioned',
		]));

		$this->httpClient->expects($this->once())
			->method('get')
			->willReturn($response);

		$result = $this->client->listWebhooks('access-token');

		$this->assertArrayHasKey('error', $result);
		$this->assertArrayHasKey('provisioning_required', $result);
		$this->assertTrue($result['provisioning_required']);
		$this->assertEquals('App password not provisioned', $result['error']);
	}

	public function testListWebhooksUsesFallbackMessageWhen428BodyHasNoMessage(): void {
		$response = $this->makeResponse(428, json_encode(['detail' => 'whatever']));

		$this->httpClient->method('get')->willReturn($response);

		$result = $this->client->listWebhooks('access-token');

		$this->assertTrue($result['provisioning_required'] ?? false);
		$this->assertStringContainsString(
			'Nextcloud access not provisioned',
			$result['error']
		);
	}

	public function testListWebhooksUsesFallbackMessageWhen428BodyIsNotJson(): void {
		$response = $this->makeResponse(428, 'not-json');

		$this->httpClient->method('get')->willReturn($response);

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

		$this->httpClient->method('get')->willReturn($response);

		$result = $this->client->listWebhooks('access-token');

		$this->assertArrayHasKey('error', $result);
		$this->assertStringContainsString('401', $result['error']);
		$this->assertArrayNotHasKey('provisioning_required', $result);
	}

	public function testListWebhooksReturnsErrorOn500(): void {
		$response = $this->makeResponse(500, '');

		$this->httpClient->method('get')->willReturn($response);

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

		$this->httpClient->method('get')->willReturn($response);

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
		$response = $this->makeResponse(200, json_encode(['status' => 'ok']));

		$this->httpClient->expects($this->once())
			->method('get')
			->with(
				$this->stringContains('/api/v1/status'),
				$this->callback(function ($options) {
					return isset($options['headers']['User-Agent'])
						&& $options['headers']['User-Agent'] === 'Nextcloud-Astrolabe/0.14.0';
				}),
			)
			->willReturn($response);

		$this->client->getStatus();
	}

	public function testUserAgentDoesNotClobberCallerHeaders(): void {
		$response = $this->makeResponse(200, json_encode(['webhooks' => []]));

		$this->httpClient->expects($this->once())
			->method('get')
			->with(
				$this->anything(),
				$this->callback(function ($options) {
					$headers = $options['headers'] ?? [];
					return ($headers['Authorization'] ?? null) === 'Bearer access-token'
						&& ($headers['User-Agent'] ?? null) === 'Nextcloud-Astrolabe/0.14.0';
				}),
			)
			->willReturn($response);

		$this->client->listWebhooks('access-token');
	}

	// =========================================================================
	// createWebhook() — 428 provisioning path
	// =========================================================================

	public function testCreateWebhookReturnsProvisioningMarkerOn428(): void {
		$response = $this->makeResponse(428, json_encode([
			'message' => 'Provision required',
		]));

		$this->httpClient->method('post')->willReturn($response);

		$result = $this->client->createWebhook(
			'OCA\\Files::postCreate',
			'http://mcp-server:8000/webhooks/nextcloud',
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

		$this->httpClient->method('delete')->willReturn($response);

		$result = $this->client->deleteWebhook(42, 'access-token');

		$this->assertTrue($result['provisioning_required'] ?? false);
	}

	public function testDeleteWebhookReturnsSuccessOn204(): void {
		$response = $this->makeResponse(204, '');

		$this->httpClient->method('delete')->willReturn($response);

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

		$this->httpClient->method('get')->willReturn($response);

		$result = $this->client->getInstalledApps('access-token');

		$this->assertTrue($result['provisioning_required'] ?? false);
	}
}
