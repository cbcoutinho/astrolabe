<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Service;

use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Refreshes OAuth tokens directly with the Identity Provider.
 *
 * Works with both Nextcloud OIDC and external IdPs like Keycloak.
 * Uses OIDC discovery to find the token endpoint automatically.
 *
 * This service is only used for confidential clients (with client_secret).
 * Public clients without client_secret cannot refresh tokens.
 */
class IdpTokenRefresher {
	private IConfig $config;
	private IClient $httpClient;
	private LoggerInterface $logger;
	private McpServerClient $mcpServerClient;
	private NcInternalUrlResolver $urlResolver;

	public function __construct(
		IConfig $config,
		IClientService $clientService,
		LoggerInterface $logger,
		McpServerClient $mcpServerClient,
		NcInternalUrlResolver $urlResolver,
	) {
		$this->config = $config;
		$this->httpClient = $clientService->newClient();
		$this->logger = $logger;
		$this->mcpServerClient = $mcpServerClient;
		$this->urlResolver = $urlResolver;
	}

	/**
	 * Refresh access token using refresh token.
	 *
	 * Calls IdP's token endpoint directly (NOT MCP server).
	 *
	 * @param string $refreshToken The refresh token
	 * @return array|null New token data or null on failure
	 */
	public function refreshAccessToken(string $refreshToken): ?array {
		// Check if confidential client secret is configured
		$clientSecret = $this->config->getSystemValue('astrolabe_client_secret', '');

		if (empty($clientSecret)) {
			$this->logger->warning('Cannot refresh: no client secret configured. Confidential client required for token refresh.');
			return null;
		}

		try {
			// Get MCP server URL
			$mcpServerUrl = $this->config->getSystemValue('mcp_server_url', '');
			if (empty($mcpServerUrl)) {
				throw new \Exception('MCP server URL not configured');
			}
			$mcpServerUrl = rtrim((string)$mcpServerUrl, '/');

			// Query MCP server to discover which IdP it's configured to use
			$statusResponse = $this->httpClient->get($mcpServerUrl . '/api/v1/status');
			$statusData = json_decode($statusResponse->getBody(), true);

			if (json_last_error() !== JSON_ERROR_NONE) {
				throw new \RuntimeException('Invalid status response from MCP server');
			}

			// Determine OIDC discovery URL and token endpoint
			$useInternalNextcloud = !isset($statusData['oidc']['discovery_url']);

			if (!$useInternalNextcloud) {
				// External IdP configured - use OIDC discovery.
				// Validate scheme before fetching: discovery_url comes from
				// the MCP status response verbatim, so without this check it
				// would be an SSRF vector controllable by the MCP operator.
				$discoveryUrl = $statusData['oidc']['discovery_url'];
				if (!is_string($discoveryUrl)
					|| !filter_var($discoveryUrl, FILTER_VALIDATE_URL)
					|| !str_starts_with($discoveryUrl, 'https://')
				) {
					throw new \RuntimeException(
						'External OIDC discovery_url must be a valid https:// URL'
					);
				}

				$this->logger->debug('IdpTokenRefresher: Using external IdP', [
					'discovery_url' => $discoveryUrl,
				]);

				$discoveryResponse = $this->httpClient->get($discoveryUrl);
				$discovery = json_decode($discoveryResponse->getBody(), true);

				if (json_last_error() !== JSON_ERROR_NONE || !isset($discovery['token_endpoint'])) {
					throw new \RuntimeException('Invalid OIDC discovery response');
				}

				$tokenEndpoint = $discovery['token_endpoint'];
			} else {
				// Nextcloud's OIDC app - use internal URL
				$tokenEndpoint = $this->urlResolver->resolve() . '/apps/oidc/token';

				$this->logger->debug('IdpTokenRefresher: Using Nextcloud OIDC app', [
					'token_endpoint' => $tokenEndpoint,
				]);
			}

			// Call IdP's token endpoint with refresh_token grant
			$postData = [
				'grant_type' => 'refresh_token',
				'refresh_token' => $refreshToken,
				'client_id' => $this->mcpServerClient->getClientId(),
				'client_secret' => $clientSecret,
			];

			$this->logger->info('IdpTokenRefresher: Requesting token refresh');

			$response = $this->httpClient->post($tokenEndpoint, [
				'body' => http_build_query($postData),
				'headers' => [
					'Content-Type' => 'application/x-www-form-urlencoded',
					'Accept' => 'application/json',
				],
			]);

			/** @var array<string, mixed>|null $tokenData */
			$tokenData = json_decode($response->getBody(), true);

			if (!is_array($tokenData) || !isset($tokenData['access_token'])) {
				throw new \RuntimeException('Invalid token response from IdP');
			}

			// Log if refresh token is absent (some IdPs like Cognito don't rotate
			// refresh tokens - the original token remains valid and callers will
			// reuse it)
			if (!isset($tokenData['refresh_token'])) {
				$this->logger->info(
					'IdpTokenRefresher: No refresh token in response - callers will reuse existing refresh token',
					[
						'response_keys' => array_keys($tokenData),
					]
				);
			}

			$this->logger->info('IdpTokenRefresher: Token refresh successful');

			return $tokenData;

		} catch (\OCP\Http\Client\LocalServerException $e) {
			// Network/connection error - may be transient
			$this->logger->warning('IdpTokenRefresher: Network error during refresh', [
				'error' => $e->getMessage(),
			]);
			return null;
		} catch (\Exception $e) {
			$statusCode = $e->getCode();

			// Log with appropriate level based on error type
			if ($statusCode === 401 || $statusCode === 403) {
				// Auth error - token is invalid, should be deleted
				$this->logger->error('IdpTokenRefresher: Auth error - token invalid', [
					'status_code' => $statusCode,
					'error' => $e->getMessage(),
				]);
			} elseif ($statusCode >= 500) {
				// Server error - may be transient
				$this->logger->warning('IdpTokenRefresher: Server error during refresh', [
					'status_code' => $statusCode,
					'error' => $e->getMessage(),
				]);
			} else {
				$this->logger->error('IdpTokenRefresher: Token refresh failed', [
					'status_code' => $statusCode,
					'error' => $e->getMessage(),
				]);
			}
			return null;
		}
	}
}
