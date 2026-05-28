<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Controller;

use OCA\Astrolabe\Service\BackgroundSyncCredentialStorage;
use OCA\Astrolabe\Service\McpServerClient;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for managing background sync credentials (app passwords).
 *
 * Handles storing and validating app passwords for multi-user BasicAuth mode.
 */
class CredentialsController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private BackgroundSyncCredentialStorage $credentialStorage,
		private IUserSession $userSession,
		private LoggerInterface $logger,
		private IConfig $config,
		private McpServerClient $client,
		private IClientService $httpClientService,
		private IURLGenerator $urlGenerator,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Store app password for background sync.
	 *
	 * Validates the app password by making a test request to Nextcloud,
	 * then stores it encrypted if valid.
	 *
	 * @param string $appPassword Nextcloud app password
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function storeAppPassword(string $appPassword): JSONResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			$this->logger->error('storeAppPassword called without authenticated user');
			return new JSONResponse([
				'success' => false,
				'error' => 'User not authenticated'
			], Http::STATUS_UNAUTHORIZED);
		}

		$userId = $user->getUID();

		// Sanity-check the shape only — the authoritative validation is
		// validateAppPassword() below (a real auth against Nextcloud). Accept
		// both the dashed format a user copies from Security settings
		// (xxxxx-xxxxx-xxxxx-xxxxx-xxxxx) AND the raw token returned by the
		// one-click `core/getapppassword` flow (a long alphanumeric string).
		if (!preg_match('/^[a-zA-Z0-9-]{20,256}$/', $appPassword)) {
			$this->logger->warning("Invalid app password format for user: $userId");
			return new JSONResponse([
				'success' => false,
				'error' => 'Invalid app password format'
			], Http::STATUS_BAD_REQUEST);
		}

		// Validate app password with Nextcloud
		$isValid = $this->validateAppPassword($userId, $appPassword);

		if (!$isValid) {
			$this->logger->warning("App password validation failed for user: $userId");
			return new JSONResponse([
				'success' => false,
				'error' => 'Invalid app password. Please check the password and try again.'
			], Http::STATUS_UNAUTHORIZED);
		}

		// Store encrypted app password locally in Nextcloud
		try {
			$this->credentialStorage->storeAppPassword($userId, $appPassword);
			$this->logger->info("Stored app password locally for user: $userId");
		} catch (\Exception $e) {
			$this->logger->error("Failed to store app password locally for user $userId", [
				'error' => $e->getMessage()
			]);
			return new JSONResponse([
				'success' => false,
				'error' => 'Failed to save app password locally'
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		// Send app password to MCP server for background sync
		// Get MCP server URL from system config (set in config.php)
		$mcpServerUrl = $this->config->getSystemValue('mcp_server_url', '');
		if (empty($mcpServerUrl)) {
			$this->logger->warning('MCP server URL not configured, app password stored locally only');
			return new JSONResponse([
				'success' => true,
				'partial_success' => true,
				'local_storage' => true,
				'mcp_sync' => false,
				'message' => 'App password saved locally (MCP server not configured)'
			], Http::STATUS_OK);
		}

		try {
			$httpClient = $this->httpClientService->newClient();

			// Send to MCP server with BasicAuth (user proves ownership of password)
			$mcpEndpoint = rtrim($mcpServerUrl, '/') . '/api/v1/users/' . urlencode($userId) . '/app-password';

			$this->logger->debug("Sending app password to MCP server: $mcpEndpoint");

			$response = $httpClient->post($mcpEndpoint, [
				'auth' => [$userId, $appPassword],
				'body' => json_encode(['username' => $userId], JSON_THROW_ON_ERROR),
				'headers' => [
					'Content-Type' => 'application/json',
					'Accept' => 'application/json',
				],
				'timeout' => 10,
			]);

			$statusCode = $response->getStatusCode();
			$body = json_decode($response->getBody(), true);

			if ($statusCode === 200 && ($body['success'] ?? false)) {
				$this->logger->info("Successfully provisioned app password to MCP server for user: $userId");
				return new JSONResponse([
					'success' => true,
					'partial_success' => false,
					'local_storage' => true,
					'mcp_sync' => true,
					'message' => 'App password saved successfully'
				], Http::STATUS_OK);
			} else {
				$error = $body['error'] ?? 'Unknown error';
				$this->logger->error("MCP server rejected app password for user $userId: $error");
				// Return partial success since it was stored locally but MCP sync failed
				return new JSONResponse([
					'success' => true,
					'partial_success' => true,
					'local_storage' => true,
					'mcp_sync' => false,
					'message' => 'App password saved locally (MCP server sync failed)',
					'mcp_error' => $error
				], Http::STATUS_OK);
			}
		} catch (\Exception $e) {
			$this->logger->error("Failed to send app password to MCP server for user $userId", [
				'error' => $e->getMessage()
			]);
			// Return partial success since it was stored locally but MCP was unreachable
			return new JSONResponse([
				'success' => true,
				'partial_success' => true,
				'local_storage' => true,
				'mcp_sync' => false,
				'message' => 'App password saved locally (MCP server unreachable)',
				'mcp_error' => $e->getMessage()
			], Http::STATUS_OK);
		}
	}

	/**
	 * Validate app password by making a test request to Nextcloud.
	 *
	 * @param string $userId User ID
	 * @param string $appPassword App password to validate
	 * @return bool True if valid, false otherwise
	 */
	private function validateAppPassword(string $userId, string $appPassword): bool {
		try {
			// Use 127.0.0.1 for internal validation (we're running inside Nextcloud container)
			// Using IP address instead of 'localhost' to avoid Nextcloud's overwrite.cli.url rewriting
			// getAbsoluteURL() returns the external URL which isn't accessible from inside the container
			$baseUrl = 'http://127.0.0.1';

			// Make a test request to Nextcloud API with BasicAuth
			// Using OCS API user endpoint as a lightweight test
			$testUrl = $baseUrl . '/ocs/v1.php/cloud/user?format=json';

			$this->logger->debug("Validating app password for user: $userId against $testUrl");

			// Use Nextcloud's HTTP client
			$httpClient = $this->httpClientService->newClient();

			$response = $httpClient->get($testUrl, [
				'auth' => [$userId, $appPassword],
				'headers' => [
					'OCS-APIRequest' => 'true',
					'Accept' => 'application/json',
				],
				'timeout' => 10,
			]);

			$statusCode = $response->getStatusCode();

			// Success is 200 OK
			if ($statusCode === 200) {
				$this->logger->debug("App password validation successful for user: $userId");
				return true;
			}

			$this->logger->warning("App password validation failed for user: $userId (HTTP $statusCode)");
			return false;
		} catch (\Exception $e) {
			$this->logger->error("Exception during app password validation for user $userId", [
				'error' => $e->getMessage()
			]);
			return false;
		}
	}

	/**
	 * Get background sync credentials status for the current user.
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function getStatus(): JSONResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new JSONResponse([
				'success' => false,
				'error' => 'User not authenticated'
			], Http::STATUS_UNAUTHORIZED);
		}

		$userId = $user->getUID();

		$hasAccess = $this->credentialStorage->hasAccess($userId);
		$provisionedAt = $this->credentialStorage->getProvisionedAt($userId);

		return new JSONResponse([
			'success' => true,
			'has_background_access' => $hasAccess,
			'sync_type' => $hasAccess ? 'app_password' : null,
			'provisioned_at' => $provisionedAt,
		], Http::STATUS_OK);
	}

	/**
	 * Get credentials metadata for a specific user (admin only).
	 *
	 * Returns presence/timestamps; never the credential itself.
	 *
	 * @param string $userId User ID to check
	 * @return JSONResponse
	 */
	public function getCredentials(string $userId): JSONResponse {
		$hasAccess = $this->credentialStorage->hasAccess($userId);
		$provisionedAt = $this->credentialStorage->getProvisionedAt($userId);

		return new JSONResponse([
			'success' => true,
			'user_id' => $userId,
			'has_background_access' => $hasAccess,
			'sync_type' => $hasAccess ? 'app_password' : null,
			'provisioned_at' => $provisionedAt,
		], Http::STATUS_OK);
	}

	/**
	 * Delete background sync credentials for the current user.
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function deleteCredentials(): JSONResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new JSONResponse([
				'success' => false,
				'error' => 'User not authenticated'
			], Http::STATUS_UNAUTHORIZED);
		}

		$userId = $user->getUID();

		try {
			$this->credentialStorage->deleteAppPassword($userId);
			$this->logger->info("Deleted background sync credentials for user: $userId");

			return new JSONResponse([
				'success' => true,
				'message' => 'Credentials deleted successfully'
			], Http::STATUS_OK);
		} catch (\Exception $e) {
			$this->logger->error("Failed to delete credentials for user $userId", [
				'error' => $e->getMessage()
			]);
			return new JSONResponse([
				'success' => false,
				'error' => 'Failed to delete credentials'
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
