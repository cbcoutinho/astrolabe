<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Controller;

use OC\Authentication\Token\IProvider as ITokenProvider;
use OCA\Astrolabe\Controller\OauthController;
use OCA\Astrolabe\Service\McpServerClient;
use OCA\Astrolabe\Service\McpTokenStorage;
use OCA\Astrolabe\Service\NcInternalUrlResolver;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for OauthController.
 *
 * Tests the authorization URL construction logic, specifically the
 * internal-to-external URL transformation for OIDC discovery.
 */
final class OauthControllerTest extends TestCase {
	private IConfig&MockObject $config;
	private ISession&MockObject $session;
	private IUserSession&MockObject $userSession;
	private IURLGenerator&MockObject $urlGenerator;
	private McpTokenStorage&MockObject $tokenStorage;
	private LoggerInterface&MockObject $logger;
	private IL10N&MockObject $l;
	private IClient&MockObject $httpClient;
	private McpServerClient&MockObject $mcpClient;
	private ITokenProvider&MockObject $tokenProvider;
	private ISecureRandom&MockObject $random;
	private NcInternalUrlResolver&MockObject $urlResolver;
	private OauthController $controller;

	protected function setUp(): void {
		parent::setUp();

		$request = $this->createMock(IRequest::class);
		$this->config = $this->createMock(IConfig::class);
		$this->session = $this->createMock(ISession::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->tokenStorage = $this->createMock(McpTokenStorage::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->l = $this->createMock(IL10N::class);
		$this->httpClient = $this->createMock(IClient::class);
		$this->mcpClient = $this->createMock(McpServerClient::class);
		$this->tokenProvider = $this->createMock(ITokenProvider::class);
		$this->random = $this->createMock(ISecureRandom::class);
		$this->urlResolver = $this->createMock(NcInternalUrlResolver::class);

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($this->httpClient);

		$this->controller = new OauthController(
			'astrolabe',
			$request,
			$this->config,
			$this->session,
			$this->userSession,
			$this->urlGenerator,
			$this->tokenStorage,
			$this->logger,
			$this->l,
			$clientService,
			$this->mcpClient,
			$this->tokenProvider,
			$this->random,
			$this->urlResolver,
		);
	}

	// =========================================================================
	// buildAuthorizationUrl() tests — URL transformation
	// =========================================================================

	/**
	 * @dataProvider provideUrlTransformationCases
	 */
	public function testBuildAuthorizationUrlTransformsInternalToExternal(
		string $resolverBaseUrl,
		string $discoveryAuthEndpoint,
		string $externalBaseUrl,
		string $expectedHostInResult,
	): void {
		$mcpServerUrl = 'http://mcp-server:8000';

		// Mock the shared resolver — replaces the previous raw-config mock.
		$this->urlResolver->method('resolve')->willReturn($resolverBaseUrl);

		// Mock MCP server status response (no external IdP → Nextcloud OIDC fallback)
		$statusResponse = $this->createMock(IResponse::class);
		$statusResponse->method('getBody')->willReturn(json_encode([
			'auth_mode' => 'multi_user_basic',
			'supports_app_passwords' => true,
		]));

		// Mock OIDC discovery response
		$discoveryResponse = $this->createMock(IResponse::class);
		$discoveryResponse->method('getBody')->willReturn(json_encode([
			'authorization_endpoint' => $discoveryAuthEndpoint,
			'token_endpoint' => str_replace('/authorize', '/token', $discoveryAuthEndpoint),
			'scopes_supported' => ['openid', 'profile', 'email', 'offline_access'],
			'grant_types_supported' => ['authorization_code'],
		]));
		$discoveryResponse->method('getStatusCode')->willReturn(200);

		// HTTP client returns different responses based on URL
		$this->httpClient->method('get')
			->willReturnCallback(function (string $url) use ($statusResponse, $discoveryResponse) {
				if (str_contains($url, '/api/v1/status')) {
					return $statusResponse;
				}
				return $discoveryResponse;
			});

		// Mock external URL generation
		$this->urlGenerator->method('getAbsoluteURL')
			->with('/')
			->willReturn($externalBaseUrl . '/');

		// Mock config for mcp_server_public_url
		$this->config->method('getSystemValue')
			->willReturnMap([
				['mcp_server_url', '', $mcpServerUrl],
				['mcp_server_public_url', $mcpServerUrl, $mcpServerUrl],
				['astrolabe_client_secret', '', ''],
			]);

		// Mock client ID
		$this->mcpClient->method('getClientId')->willReturn('test-client-id');

		// Mock redirect URI generation
		$this->urlGenerator->method('linkToRouteAbsolute')
			->willReturn($externalBaseUrl . '/apps/astrolabe/oauth/callback');

		// Call private method via reflection
		$reflection = new \ReflectionClass($this->controller);
		$method = $reflection->getMethod('buildAuthorizationUrl');
		$method->setAccessible(true);

		$result = $method->invoke($this->controller, $mcpServerUrl, 'test-state', 'test-challenge');

		// Verify the authorization URL points to the external host
		$this->assertStringStartsWith($expectedHostInResult . '/apps/oidc/authorize?', $result);
	}

	/**
	 * Provides test cases for URL transformation in buildAuthorizationUrl().
	 *
	 * Tuple: [resolver-returned base URL, discovery's authorization_endpoint,
	 *         external base URL from urlGenerator, expected host prefix in result].
	 *
	 * @return array<string, array{string, string, string, string}>
	 */
	public static function provideUrlTransformationCases(): array {
		return [
			'self-hosted: https discovery with overwriteprotocol (the bug)' => [
				'http://localhost',
				'https://localhost/apps/oidc/authorize',
				'https://cloud.example.com',
				'https://cloud.example.com',
			],
			'self-hosted: http discovery without overwriteprotocol' => [
				'http://localhost',
				'http://localhost/apps/oidc/authorize',
				'https://cloud.example.com',
				'https://cloud.example.com',
			],
			'self-hosted: http discovery with http external' => [
				'http://localhost',
				'http://localhost/apps/oidc/authorize',
				'http://localhost:8080',
				'http://localhost:8080',
			],
			'managed NC: resolver returns the public URL (idempotent preg_replace)' => [
				'https://cloud.example.com',
				'https://cloud.example.com/apps/oidc/authorize',
				'https://cloud.example.com',
				'https://cloud.example.com',
			],
		];
	}

	public function testBuildAuthorizationUrlUsesExternalDiscoveryUrlVerbatim(): void {
		$mcpServerUrl = 'http://mcp-server:8000';
		$externalDiscovery = 'https://keycloak.example.com/realms/x/.well-known/openid-configuration';
		$externalAuthEndpoint = 'https://keycloak.example.com/realms/x/auth';

		// Resolver must NOT be consulted on the external-IdP path.
		$this->urlResolver->expects($this->never())->method('resolve');

		$statusResponse = $this->createMock(IResponse::class);
		$statusResponse->method('getBody')->willReturn(json_encode([
			'auth_mode' => 'multi_user_oauth',
			'oidc' => ['discovery_url' => $externalDiscovery],
		]));

		$discoveryResponse = $this->createMock(IResponse::class);
		$discoveryResponse->method('getBody')->willReturn(json_encode([
			'authorization_endpoint' => $externalAuthEndpoint,
			'token_endpoint' => 'https://keycloak.example.com/realms/x/token',
			'scopes_supported' => ['openid', 'profile', 'email'],
			'grant_types_supported' => ['authorization_code'],
		]));
		$discoveryResponse->method('getStatusCode')->willReturn(200);

		$this->httpClient->method('get')
			->willReturnCallback(function (string $url) use ($statusResponse, $discoveryResponse) {
				return str_contains($url, '/api/v1/status') ? $statusResponse : $discoveryResponse;
			});

		$this->config->method('getSystemValue')
			->willReturnMap([
				['mcp_server_url', '', $mcpServerUrl],
				['mcp_server_public_url', $mcpServerUrl, $mcpServerUrl],
				['astrolabe_client_secret', '', ''],
			]);
		$this->mcpClient->method('getClientId')->willReturn('test-client-id');
		$this->urlGenerator->method('linkToRouteAbsolute')
			->willReturn('https://cloud.example.com/apps/astrolabe/oauth/callback');

		$reflection = new \ReflectionClass($this->controller);
		$method = $reflection->getMethod('buildAuthorizationUrl');
		$method->setAccessible(true);

		$result = $method->invoke($this->controller, $mcpServerUrl, 'test-state', 'test-challenge');

		$this->assertStringStartsWith($externalAuthEndpoint . '?', $result);
	}

	/**
	 * @dataProvider provideInsecureExternalDiscoveryUrls
	 */
	public function testBuildAuthorizationUrlRejectsInsecureExternalDiscoveryUrl(mixed $discoveryUrl): void {
		$mcpServerUrl = 'http://mcp-server:8000';

		$statusResponse = $this->createMock(IResponse::class);
		$statusResponse->method('getBody')->willReturn(json_encode([
			'auth_mode' => 'multi_user_oauth',
			'oidc' => ['discovery_url' => $discoveryUrl],
		]));

		$this->httpClient->method('get')->willReturn($statusResponse);
		// Discovery fetch must never happen — only the /api/v1/status call.
		$this->httpClient->expects($this->once())->method('get');

		$this->config->method('getSystemValue')
			->willReturnMap([
				['mcp_server_url', '', $mcpServerUrl],
				['astrolabe_client_secret', '', ''],
			]);

		$reflection = new \ReflectionClass($this->controller);
		$method = $reflection->getMethod('buildAuthorizationUrl');
		$method->setAccessible(true);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessageMatches('/https/i');

		$method->invoke($this->controller, $mcpServerUrl, 'test-state', 'test-challenge');
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function provideInsecureExternalDiscoveryUrls(): array {
		return [
			'plaintext http rejected' => ['http://keycloak.example.com/.well-known/openid-configuration'],
			'malformed URL rejected' => ['not-a-url'],
			'non-string discovery_url rejected' => [123],
		];
	}

	// =========================================================================
	// exchangeCodeForToken() tests — Nextcloud OIDC branch honors override
	// =========================================================================

	/**
	 * @dataProvider provideTokenEndpointCases
	 */
	public function testExchangeCodeForTokenHonorsInternalUrlOverride(
		string $resolverBaseUrl,
		string $expectedTokenEndpoint,
	): void {
		$mcpServerUrl = 'http://mcp-server:8000';

		// Mock the shared resolver — the controller no longer reads the raw
		// config value directly.
		$this->urlResolver->method('resolve')->willReturn($resolverBaseUrl);

		// Mock MCP server status response — no oidc.discovery_url forces
		// the Nextcloud-OIDC (internal URL) branch.
		$statusResponse = $this->createMock(IResponse::class);
		$statusResponse->method('getBody')->willReturn(json_encode([
			'auth_mode' => 'multi_user_basic',
			'supports_app_passwords' => true,
		]));

		// Mock token-endpoint POST response with a minimal valid payload.
		$tokenResponse = $this->createMock(IResponse::class);
		$tokenResponse->method('getBody')->willReturn(json_encode([
			'access_token' => 'fake-access-token',
			'refresh_token' => 'fake-refresh-token',
			'expires_in' => 3600,
		]));

		$this->httpClient->method('get')->willReturn($statusResponse);

		// Capture the URL the controller POSTs the token request to.
		$capturedTokenUrl = null;
		$this->httpClient->expects($this->once())
			->method('post')
			->with(
				$this->callback(function (string $url) use (&$capturedTokenUrl): bool {
					$capturedTokenUrl = $url;
					return true;
				}),
				$this->anything(),
			)
			->willReturn($tokenResponse);

		$this->config->method('getSystemValue')
			->willReturnMap([
				['astrolabe_client_secret', '', ''],
			]);

		$this->mcpClient->method('getClientId')->willReturn('test-client-id');
		$this->urlGenerator->method('linkToRouteAbsolute')
			->willReturn('https://cloud.example.com/apps/astrolabe/oauth/callback');

		// Call private method via reflection (same pattern used above).
		$reflection = new \ReflectionClass($this->controller);
		$method = $reflection->getMethod('exchangeCodeForToken');
		$method->setAccessible(true);

		$result = $method->invoke($this->controller, $mcpServerUrl, 'test-code', 'test-verifier');

		$this->assertSame($expectedTokenEndpoint, $capturedTokenUrl);
		$this->assertSame('fake-access-token', $result['access_token']);
	}

	/**
	 * Provides test cases for exchangeCodeForToken() internal-URL resolution.
	 *
	 * Tuple: [resolver-returned base URL, expected token endpoint URL].
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function provideTokenEndpointCases(): array {
		return [
			'default (resolver returns localhost) → localhost token endpoint' => [
				'http://localhost',
				'http://localhost/apps/oidc/token',
			],
			'managed NC (resolver returns public URL) → public token endpoint' => [
				'https://cloud.example.com',
				'https://cloud.example.com/apps/oidc/token',
			],
			'resolver already trimmed trailing slash' => [
				'https://cloud.example.com',
				'https://cloud.example.com/apps/oidc/token',
			],
		];
	}
}
