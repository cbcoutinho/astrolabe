<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Controller;

use OCA\Astrolabe\Service\BackgroundSyncCredentialStorage;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Authentication\Exceptions\InvalidTokenException;
use OCP\Authentication\Token\IProvider;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IRequest;
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
		private IClientService $httpClientService,
		private IProvider $tokenProvider,
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

		$this->warnIfCleartextTransport($mcpServerUrl, $userId);

		try {
			$httpClient = $this->httpClientService->newClient();

			// Send to MCP server with BasicAuth (user proves ownership of password)
			$mcpEndpoint = rtrim($mcpServerUrl, '/') . '/api/v1/users/' . rawurlencode($userId) . '/app-password';

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
		// Validate the app password internally via Nextcloud's token provider —
		// no HTTP round-trip. An outbound loopback call is fragile across
		// deployments: `overwrite.cli.url` points at the *external* host (e.g.
		// localhost:8080, the host-mapped port) which is unreachable from
		// inside the container, and trusted-domain checks can reject raw-IP
		// hosts. getToken() hashes the supplied token and looks it up directly
		// in the auth backend.
		try {
			$token = $this->tokenProvider->getToken($appPassword);
		} catch (InvalidTokenException) {
			// Covers expired and wiped tokens too — both extend
			// InvalidTokenException — all of which are invalid for our purposes.
			$this->logger->warning("App password not recognised for user: $userId");
			return false;
		}

		if ($token->getUID() !== $userId) {
			$this->logger->warning("App password belongs to a different user than: $userId");
			return false;
		}

		$this->logger->debug("App password validation successful for user: $userId");
		return true;
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
			// Tell the MCP server to drop its copy first, while we still hold
			// the app password it needs to authenticate the request. Best-effort:
			// a failure here must not block clearing the local credential.
			$this->revokeFromMcpServer($userId);

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

	/**
	 * Best-effort: tell the MCP server to delete its stored app password for
	 * this user, so revoking background indexing actually drops the server's
	 * WebDAV access (it otherwise retains the credential). Authenticated with
	 * the app password itself (BasicAuth), mirroring storeAppPassword. Any
	 * failure is logged and swallowed — the local credential is still removed.
	 */
	/**
	 * Warn (without blocking) when about to send BasicAuth credentials to a
	 * non-loopback plaintext-HTTP endpoint.
	 *
	 * The app password is transmitted with BasicAuth; over TLS or to a loopback
	 * host this is fine, but plaintext to any other host puts it on the wire in
	 * the clear. We only warn — internal deployments legitimately reach the MCP
	 * server over plain HTTP on a private network (e.g. an in-cluster service
	 * name like mcp:8000), and hard-refusing there would break background-sync
	 * provisioning. The pattern uses an `https?` regex rather than a plaintext
	 * scheme literal so it is not itself flagged as insecure-protocol usage.
	 */
	private function warnIfCleartextTransport(string $url, string $userId): void {
		if (preg_match('#^https?://#i', $url) === 1
			&& preg_match('#^https://#i', $url) !== 1
			&& preg_match('#^https?://(localhost|127\.0\.0\.1|\[?::1\]?)([:/]|$)#i', $url) !== 1) {
			$this->logger->warning(
				"MCP server URL uses plaintext http for user $userId; "
				. 'use https:// for non-loopback hosts to avoid transmitting the app password in cleartext'
			);
		}
	}

	private function revokeFromMcpServer(string $userId): void {
		$mcpServerUrl = (string)$this->config->getSystemValue('mcp_server_url', '');
		if ($mcpServerUrl === '') {
			return;
		}

		$appPassword = $this->credentialStorage->getAppPassword($userId);
		if ($appPassword === null || $appPassword === '') {
			$this->logger->debug("No stored app password to revoke from MCP for user: $userId");
			return;
		}

		$this->warnIfCleartextTransport($mcpServerUrl, $userId);

		try {
			$httpClient = $this->httpClientService->newClient();
			$mcpEndpoint = rtrim($mcpServerUrl, '/') . '/api/v1/users/' . rawurlencode($userId) . '/app-password';

			$response = $httpClient->delete($mcpEndpoint, [
				'auth' => [$userId, $appPassword],
				'headers' => [
					'Accept' => 'application/json',
				],
				'timeout' => 10,
			]);

			$statusCode = $response->getStatusCode();
			// MCP returns 204 No Content on a successful delete; accept any 2xx.
			if ($statusCode >= 200 && $statusCode < 300) {
				$this->logger->info("Revoked app password from MCP server for user: $userId");
			} else {
				$this->logger->warning("MCP server returned HTTP $statusCode revoking app password for user: $userId");
			}
		} catch (\Exception $e) {
			// MCP unreachable / already gone — local revoke still proceeds.
			$this->logger->warning("Failed to revoke app password from MCP server for user $userId", [
				'error' => $e->getMessage(),
			]);
		}
	}
}
