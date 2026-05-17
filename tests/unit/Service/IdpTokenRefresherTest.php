<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Service;

use OCA\Astrolabe\Service\IdpTokenRefresher;
use OCA\Astrolabe\Service\McpServerClient;
use OCA\Astrolabe\Service\NcInternalUrlResolver;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for IdpTokenRefresher.
 *
 * Tests the internal URL resolution logic and token refresh flows.
 */
final class IdpTokenRefresherTest extends TestCase {
	private IConfig&MockObject $config;
	private IClientService&MockObject $clientService;
	private IClient&MockObject $httpClient;
	private LoggerInterface&MockObject $logger;
	private McpServerClient&MockObject $mcpServerClient;
	private NcInternalUrlResolver&MockObject $urlResolver;
	private IdpTokenRefresher $refresher;

	protected function setUp(): void {
		parent::setUp();

		$this->config = $this->createMock(IConfig::class);
		$this->clientService = $this->createMock(IClientService::class);
		$this->httpClient = $this->createMock(IClient::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->mcpServerClient = $this->createMock(McpServerClient::class);
		$this->urlResolver = $this->createMock(NcInternalUrlResolver::class);

		$this->clientService->method('newClient')->willReturn($this->httpClient);

		$this->refresher = new IdpTokenRefresher(
			$this->config,
			$this->clientService,
			$this->logger,
			$this->mcpServerClient,
			$this->urlResolver,
		);
	}

	// =========================================================================
	// refreshAccessToken() tests
	//
	// Internal-URL resolution logic itself is exercised in
	// NcInternalUrlResolverTest. Here we only stub the resolver's return
	// value for tests that hit the Nextcloud-OIDC branch.
	// =========================================================================

	public function testRefreshAccessTokenFailsWithoutClientSecret(): void {
		$this->config->method('getSystemValue')
			->willReturnMap([
				['astrolabe_client_secret', '', ''],
			]);

		$this->logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('no client secret configured'));

		$result = $this->refresher->refreshAccessToken('test-refresh-token');

		$this->assertNull($result);
	}

	public function testRefreshAccessTokenFailsWithoutMcpServerUrl(): void {
		$this->config->method('getSystemValue')
			->willReturnMap([
				['astrolabe_client_secret', '', 'test-secret'],
				['mcp_server_url', '', ''],
			]);

		$this->logger->expects($this->once())
			->method('error')
			->with(
				$this->stringContains('Token refresh failed'),
				$this->callback(fn ($ctx) => str_contains($ctx['error'], 'MCP server URL not configured'))
			);

		$result = $this->refresher->refreshAccessToken('test-refresh-token');

		$this->assertNull($result);
	}

	public function testRefreshAccessTokenWithInternalNextcloudOidc(): void {
		// Setup config
		$this->config->method('getSystemValue')
			->willReturnMap([
				['astrolabe_client_secret', '', 'test-secret'],
				['mcp_server_url', '', 'http://mcp-server:8000'], // NOSONAR
			]);

		// Resolver returns default (localhost) for this test.
		$this->urlResolver->method('resolve')->willReturn('http://localhost'); // NOSONAR

		$this->mcpServerClient->method('getClientId')
			->willReturn('test-client-id');

		// Mock MCP server status response (no external IdP configured)
		$statusResponse = $this->createMock(IResponse::class);
		$statusResponse->method('getBody')
			->willReturn(json_encode([
				'version' => '1.0.0',
				'auth_mode' => 'multi_user_oauth',
				// No 'oidc.discovery_url' = use internal Nextcloud OIDC
			]));

		// Mock token endpoint response
		$tokenResponse = $this->createMock(IResponse::class);
		$tokenResponse->method('getBody')
			->willReturn(json_encode([
				'access_token' => 'new-access-token',
				'refresh_token' => 'new-refresh-token',
				'expires_in' => 3600,
				'token_type' => 'Bearer',
			]));

		// Setup HTTP client to return appropriate responses
		$this->httpClient->method('get')
			->with('http://mcp-server:8000/api/v1/status') // NOSONAR
			->willReturn($statusResponse);

		$this->httpClient->method('post')
			->with(
				'http://localhost/apps/oidc/token', // NOSONAR
				$this->callback(function ($options) {
					// Verify the POST body contains expected parameters
					$body = $options['body'] ?? '';
					return str_contains($body, 'grant_type=refresh_token')
						&& str_contains($body, 'client_id=test-client-id')
						&& str_contains($body, 'client_secret=test-secret')
						&& str_contains($body, 'refresh_token=test-refresh-token');
				})
			)
			->willReturn($tokenResponse);

		$result = $this->refresher->refreshAccessToken('test-refresh-token');

		$this->assertNotNull($result);
		$this->assertEquals('new-access-token', $result['access_token']);
		$this->assertEquals('new-refresh-token', $result['refresh_token']);
		$this->assertEquals(3600, $result['expires_in']);
	}

	public function testRefreshAccessTokenWithExternalIdp(): void {
		// Setup config
		$this->config->method('getSystemValue')
			->willReturnMap([
				['astrolabe_client_secret', '', 'test-secret'],
				['mcp_server_url', '', 'http://mcp-server:8000'], // NOSONAR
			]);

		$this->mcpServerClient->method('getClientId')
			->willReturn('test-client-id');

		// Mock MCP server status response (external IdP configured)
		$statusResponse = $this->createMock(IResponse::class);
		$statusResponse->method('getBody')
			->willReturn(json_encode([
				'version' => '1.0.0',
				'auth_mode' => 'multi_user_oauth',
				'oidc' => [
					'discovery_url' => 'https://keycloak.example.com/realms/test/.well-known/openid-configuration',
				],
			]));

		// Mock OIDC discovery response
		$discoveryResponse = $this->createMock(IResponse::class);
		$discoveryResponse->method('getBody')
			->willReturn(json_encode([
				'issuer' => 'https://keycloak.example.com/realms/test',
				'token_endpoint' => 'https://keycloak.example.com/realms/test/protocol/openid-connect/token',
				'authorization_endpoint' => 'https://keycloak.example.com/realms/test/protocol/openid-connect/auth',
			]));

		// Mock token endpoint response
		$tokenResponse = $this->createMock(IResponse::class);
		$tokenResponse->method('getBody')
			->willReturn(json_encode([
				'access_token' => 'keycloak-access-token',
				'refresh_token' => 'keycloak-refresh-token',
				'expires_in' => 300,
				'token_type' => 'Bearer',
			]));

		// Setup HTTP client calls in order
		$this->httpClient->method('get')
			->willReturnCallback(function ($url) use ($statusResponse, $discoveryResponse) {
				if (str_contains($url, 'status')) {
					return $statusResponse;
				}
				if (str_contains($url, '.well-known/openid-configuration')) {
					return $discoveryResponse;
				}
				throw new \Exception("Unexpected URL: $url");
			});

		$this->httpClient->method('post')
			->with(
				'https://keycloak.example.com/realms/test/protocol/openid-connect/token',
				$this->anything()
			)
			->willReturn($tokenResponse);

		$result = $this->refresher->refreshAccessToken('test-refresh-token');

		$this->assertNotNull($result);
		$this->assertEquals('keycloak-access-token', $result['access_token']);
		$this->assertEquals('keycloak-refresh-token', $result['refresh_token']);
		$this->assertEquals(300, $result['expires_in']);
	}

	public function testRefreshAccessTokenRejectsInsecureExternalDiscoveryUrl(): void {
		$this->config->method('getSystemValue')
			->willReturnMap([
				['astrolabe_client_secret', '', 'test-secret'],
				['mcp_server_url', '', 'http://mcp-server:8000'], // NOSONAR
			]);

		// Status response pins discovery_url to plaintext http — must be rejected
		// before the httpClient->get is reached. This is the SSRF guard.
		$statusResponse = $this->createMock(IResponse::class);
		$statusResponse->method('getBody')
			->willReturn(json_encode([
				'oidc' => [
					'discovery_url' => 'http://keycloak.example.com/.well-known/openid-configuration', // NOSONAR
				],
			]));

		$this->httpClient->method('get')->willReturn($statusResponse);
		$this->httpClient->expects($this->never())->method('post');

		$this->logger->expects($this->once())
			->method('error')
			->with(
				$this->stringContains('Token refresh failed'),
				$this->callback(fn ($ctx) => str_contains((string)($ctx['error'] ?? ''), 'https'))
			);

		$this->assertNull($this->refresher->refreshAccessToken('test-refresh-token'));
	}

	public function testRefreshAccessTokenSucceedsWithoutRefreshTokenInResponse(): void {
		// Setup config
		$this->config->method('getSystemValue')
			->willReturnMap([
				['astrolabe_client_secret', '', 'test-secret'],
				['mcp_server_url', '', 'http://mcp-server:8000'], // NOSONAR
			]);

		$this->urlResolver->method('resolve')->willReturn('http://localhost'); // NOSONAR

		$this->mcpServerClient->method('getClientId')
			->willReturn('test-client-id');

		// Mock MCP server status response
		$statusResponse = $this->createMock(IResponse::class);
		$statusResponse->method('getBody')
			->willReturn(json_encode(['version' => '1.0.0']));

		// Mock token response WITHOUT refresh_token
		// (e.g., Cognito doesn't rotate refresh tokens - original remains valid)
		$tokenResponse = $this->createMock(IResponse::class);
		$tokenResponse->method('getBody')
			->willReturn(json_encode([
				'access_token' => 'new-access-token',
				'expires_in' => 3600,
			]));

		$this->httpClient->method('get')->willReturn($statusResponse);
		$this->httpClient->method('post')->willReturn($tokenResponse);

		$infoMessages = [];
		$this->logger->method('info')
			->willReturnCallback(function (string $message) use (&$infoMessages): void {
				$infoMessages[] = $message;
			});

		$result = $this->refresher->refreshAccessToken('test-refresh-token');

		$this->assertNotNull($result);
		$this->assertEquals('new-access-token', $result['access_token']);
		$this->assertArrayNotHasKey('refresh_token', $result);

		// Verify the info log about missing refresh token was emitted
		$this->assertContains(
			'IdpTokenRefresher: No refresh token in response - callers will reuse existing refresh token',
			$infoMessages,
		);
	}

	public function testRefreshAccessTokenHandlesHttpException(): void {
		// Setup config
		$this->config->method('getSystemValue')
			->willReturnMap([
				['astrolabe_client_secret', '', 'test-secret'],
				['mcp_server_url', '', 'http://mcp-server:8000'], // NOSONAR
			]);

		// HTTP client throws exception
		$this->httpClient->method('get')
			->willThrowException(new \Exception('Connection refused'));

		$this->logger->expects($this->once())
			->method('error')
			->with(
				$this->stringContains('Token refresh failed'),
				$this->callback(fn ($ctx) => str_contains($ctx['error'], 'Connection refused'))
			);

		$result = $this->refresher->refreshAccessToken('test-refresh-token');

		$this->assertNull($result);
	}

	public function testRefreshAccessTokenHandlesLocalServerException(): void {
		$this->config->method('getSystemValue')
			->willReturnMap([
				['astrolabe_client_secret', '', 'test-secret'],
				['mcp_server_url', '', 'http://mcp-server:8000'], // NOSONAR
			]);

		// Transient network failure — Nextcloud's HTTP client raises
		// LocalServerException for connection-level errors. The refresher
		// must log this as a warning, not an error, so callers don't
		// surface a fatal-looking signal for retryable problems.
		$this->httpClient->method('get')
			->willThrowException(new \OCP\Http\Client\LocalServerException('connect failed'));

		$this->logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains('Network error during refresh'),
				$this->callback(fn ($ctx) => str_contains($ctx['error'], 'connect failed'))
			);
		$this->logger->expects($this->never())->method('error');

		$result = $this->refresher->refreshAccessToken('test-refresh-token');

		$this->assertNull($result);
	}

	public function testRefreshAccessTokenHandlesInvalidStatusResponse(): void {
		// Setup config
		$this->config->method('getSystemValue')
			->willReturnMap([
				['astrolabe_client_secret', '', 'test-secret'],
				['mcp_server_url', '', 'http://mcp-server:8000'], // NOSONAR
			]);

		// Mock invalid JSON response
		$statusResponse = $this->createMock(IResponse::class);
		$statusResponse->method('getBody')
			->willReturn('not valid json');

		$this->httpClient->method('get')->willReturn($statusResponse);

		$this->logger->expects($this->once())
			->method('error')
			->with(
				$this->stringContains('Token refresh failed'),
				$this->callback(fn ($ctx) => str_contains($ctx['error'], 'Invalid status response'))
			);

		$result = $this->refresher->refreshAccessToken('test-refresh-token');

		$this->assertNull($result);
	}

	public function testRefreshAccessTokenHandlesInvalidDiscoveryResponse(): void {
		// Setup config
		$this->config->method('getSystemValue')
			->willReturnMap([
				['astrolabe_client_secret', '', 'test-secret'],
				['mcp_server_url', '', 'http://mcp-server:8000'], // NOSONAR
			]);

		$this->mcpServerClient->method('getClientId')
			->willReturn('test-client-id');

		// Mock MCP server status response with external IdP
		$statusResponse = $this->createMock(IResponse::class);
		$statusResponse->method('getBody')
			->willReturn(json_encode([
				'oidc' => [
					'discovery_url' => 'https://keycloak.example.com/.well-known/openid-configuration',
				],
			]));

		// Mock invalid discovery response (missing token_endpoint)
		$discoveryResponse = $this->createMock(IResponse::class);
		$discoveryResponse->method('getBody')
			->willReturn(json_encode([
				'issuer' => 'https://keycloak.example.com',
				// Missing token_endpoint!
			]));

		$this->httpClient->method('get')
			->willReturnCallback(function ($url) use ($statusResponse, $discoveryResponse) {
				if (str_contains($url, 'status')) {
					return $statusResponse;
				}
				return $discoveryResponse;
			});

		$this->logger->expects($this->once())
			->method('error')
			->with(
				$this->stringContains('Token refresh failed'),
				$this->callback(fn ($ctx) => str_contains($ctx['error'], 'Invalid OIDC discovery response'))
			);

		$result = $this->refresher->refreshAccessToken('test-refresh-token');

		$this->assertNull($result);
	}

	public function testRefreshAccessTokenHandlesInvalidTokenResponse(): void {
		// Setup config
		$this->config->method('getSystemValue')
			->willReturnMap([
				['astrolabe_client_secret', '', 'test-secret'],
				['mcp_server_url', '', 'http://mcp-server:8000'], // NOSONAR
			]);

		$this->urlResolver->method('resolve')->willReturn('http://localhost'); // NOSONAR

		$this->mcpServerClient->method('getClientId')
			->willReturn('test-client-id');

		// Mock MCP server status response
		$statusResponse = $this->createMock(IResponse::class);
		$statusResponse->method('getBody')
			->willReturn(json_encode(['version' => '1.0.0']));

		// Mock token response without access_token
		$tokenResponse = $this->createMock(IResponse::class);
		$tokenResponse->method('getBody')
			->willReturn(json_encode([
				'error' => 'invalid_grant',
				'error_description' => 'Refresh token expired',
			]));

		$this->httpClient->method('get')->willReturn($statusResponse);
		$this->httpClient->method('post')->willReturn($tokenResponse);

		$this->logger->expects($this->once())
			->method('error')
			->with(
				$this->stringContains('Token refresh failed'),
				$this->callback(fn ($ctx) => str_contains($ctx['error'], 'Invalid token response'))
			);

		$result = $this->refresher->refreshAccessToken('test-refresh-token');

		$this->assertNull($result);
	}

	// =========================================================================
	// getLastError() tests — surface refresh-failure reason to admin callers
	// =========================================================================

	public function testGetLastErrorIsNullBeforeAnyAttempt(): void {
		$this->assertNull($this->refresher->getLastError());
	}

	public function testGetLastErrorReportsEmptyRefreshToken(): void {
		// No mock setup needed — empty refresh_token short-circuits before
		// any config or HTTP access.
		$result = $this->refresher->refreshAccessToken('');

		$this->assertNull($result);
		$error = $this->refresher->getLastError();
		$this->assertNotNull($error);
		$this->assertStringContainsString('No refresh token stored', $error);
	}

	public function testGetLastErrorReportsMissingClientSecret(): void {
		$this->config->method('getSystemValue')
			->willReturnMap([
				['astrolabe_client_secret', '', ''],
			]);

		$result = $this->refresher->refreshAccessToken('test-refresh-token');

		$this->assertNull($result);
		$error = $this->refresher->getLastError();
		$this->assertNotNull($error);
		$this->assertStringContainsString('astrolabe_client_secret is not configured', $error);
	}

	public function testGetLastErrorReportsHttpFailure(): void {
		$this->config->method('getSystemValue')
			->willReturnMap([
				['astrolabe_client_secret', '', 'test-secret'],
				['mcp_server_url', '', 'http://mcp-server:8000'],
			]);

		// Simulate an unauthorized response from the IdP via an exception
		// carrying status 401 in its code (mirroring Guzzle's behavior).
		$this->httpClient->method('get')
			->willThrowException(new \Exception('IdP rejected request', 401));

		$result = $this->refresher->refreshAccessToken('test-refresh-token');

		$this->assertNull($result);
		$error = $this->refresher->getLastError();
		$this->assertNotNull($error);
		$this->assertStringContainsString('HTTP 401', $error);
		$this->assertStringContainsString('refresh token likely expired or revoked', strtolower($error));
	}

	public function testGetLastErrorIsClearedOnSuccess(): void {
		// First, fail a refresh so lastError is set.
		$failingConfig = $this->createMock(IConfig::class);
		$failingConfig->method('getSystemValue')
			->willReturn('');
		$failingRefresher = new IdpTokenRefresher(
			$failingConfig,
			$this->clientService,
			$this->logger,
			$this->mcpServerClient,
			$this->urlResolver,
		);
		$failingRefresher->refreshAccessToken('test-refresh-token');
		$this->assertNotNull($failingRefresher->getLastError());

		// Now arrange a successful refresh and ensure lastError is reset.
		$this->config->method('getSystemValue')
			->willReturnMap([
				['astrolabe_client_secret', '', 'test-secret'],
				['mcp_server_url', '', 'http://mcp-server:8000'],
				['astrolabe_internal_url', '', ''],
			]);
		$this->mcpServerClient->method('getClientId')
			->willReturn('test-client-id');

		$statusResponse = $this->createMock(IResponse::class);
		$statusResponse->method('getBody')->willReturn(json_encode(['version' => '1.0.0']));
		$tokenResponse = $this->createMock(IResponse::class);
		$tokenResponse->method('getBody')->willReturn(json_encode([
			'access_token' => 'new-access-token',
			'refresh_token' => 'new-refresh-token',
			'expires_in' => 3600,
		]));
		$this->httpClient->method('get')->willReturn($statusResponse);
		$this->httpClient->method('post')->willReturn($tokenResponse);

		$result = $this->refresher->refreshAccessToken('test-refresh-token');

		$this->assertNotNull($result);
		$this->assertNull($this->refresher->getLastError());
	}
}
