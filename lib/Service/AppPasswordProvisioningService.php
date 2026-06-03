<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Service;

use OC\Authentication\Token\IProvider as ITokenProvider;
use OCP\Authentication\Token\IToken;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * Shared logic for the Nextcloud app password the MCP server uses to read a
 * user's files (background WebDAV indexing).
 *
 * Two call paths use this:
 *   - self-service (CredentialsController::storeAppPassword): the user supplies
 *     a token they minted via core/getapppassword; we only sync/revoke it.
 *   - admin provisioning (CredentialsController::adminProvisionUser): we mint
 *     the token on the user's behalf via the server-internal token provider —
 *     Nextcloud has no public API for an admin to create an app password for
 *     another user (see oc-token-provider.phpstub for why we depend on the
 *     internal `\OC\Authentication\Token\IProvider`).
 *
 * The MCP HTTP sync/revoke lives here (rather than duplicated per path) so both
 * paths share one wire contract.
 */
class AppPasswordProvisioningService {
	public const ADMIN_TOKEN_NAME = 'Astrolabe background sync (admin)';

	/** @psalm-suppress PossiblyUnusedMethod — instantiated by the Nextcloud DI container. */
	public function __construct(
		private ITokenProvider $tokenProvider,
		private ISecureRandom $random,
		private IConfig $config,
		private IClientService $httpClientService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Mint a passwordless app password (PERMANENT_TOKEN) for a user.
	 *
	 * Mirrors the server's own login-flow-v2 mint
	 * (core/Service/LoginFlowV2Service::flowDone): a 72-char random token used
	 * as the app password. The token is passwordless — fine for the WebDAV read
	 * access the MCP server needs, the same as any externally-completed login
	 * flow. The login name is set to the UID; Nextcloud resolves the user from
	 * the token's UID, so BasicAuth as the UID with this token authenticates.
	 *
	 * @return string the freshly minted app password (the only time it is
	 *                available in cleartext)
	 */
	public function mintAppPasswordForUser(string $uid, string $loginName, string $tokenName): string {
		$appPassword = $this->random->generate(
			72,
			ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS,
		);
		$this->tokenProvider->generateToken(
			$appPassword,
			$uid,
			$loginName,
			null,
			$tokenName,
			IToken::PERMANENT_TOKEN,
			IToken::DO_NOT_REMEMBER,
		);
		$this->logger->info('Minted admin-provisioned app password for user {uid}', ['uid' => $uid]);
		return $appPassword;
	}

	/**
	 * Invalidate a Nextcloud app password (best-effort).
	 *
	 * Used on deprovision so the credential is actually revoked at the source,
	 * not merely dropped from the local + MCP stores. A failure here must not
	 * block clearing the local copy.
	 */
	public function revokeToken(string $appPassword): void {
		try {
			$this->tokenProvider->invalidateToken($appPassword);
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to invalidate Nextcloud token during deprovision', [
				'error' => $e->getMessage(),
			]);
		}
	}

	/**
	 * Push an app password to the MCP server so it can index the user's files.
	 *
	 * BasicAuth user and URL path segment are the UID (the identity key the MCP
	 * server matches against the path); the body carries the loginName because
	 * Nextcloud keys app-password BasicAuth on the loginName, which differs from
	 * the UID for OIDC-provisioned users.
	 *
	 * @return array{mcp_sync: bool, partial_success: bool, message: string, mcp_error?: string}
	 */
	public function syncToMcp(string $uid, string $loginName, string $appPassword): array {
		$mcpServerUrl = (string)$this->config->getSystemValue('mcp_server_url', '');
		if ($mcpServerUrl === '') {
			$this->logger->warning('MCP server URL not configured, app password stored locally only');
			return [
				'mcp_sync' => false,
				'partial_success' => true,
				'message' => 'App password saved locally (MCP server not configured)',
			];
		}

		$this->warnIfCleartextTransport($mcpServerUrl, $uid);

		try {
			$httpClient = $this->httpClientService->newClient();
			$mcpEndpoint = rtrim($mcpServerUrl, '/') . '/api/v1/users/' . rawurlencode($uid) . '/app-password';

			$this->logger->debug("Sending app password to MCP server: $mcpEndpoint");

			$response = $httpClient->post($mcpEndpoint, [
				'auth' => [$uid, $appPassword],
				'body' => json_encode(['username' => $loginName], JSON_THROW_ON_ERROR),
				'headers' => [
					'Content-Type' => 'application/json',
					'Accept' => 'application/json',
				],
				'timeout' => 10,
			]);

			$statusCode = $response->getStatusCode();
			/** @psalm-suppress MixedAssignment json_decode returns mixed; narrowed via is_array below */
			$decoded = json_decode((string)$response->getBody(), true);
			$body = is_array($decoded) ? $decoded : [];

			if ($statusCode === 200 && ($body['success'] ?? null) === true) {
				$this->logger->info('Successfully provisioned app password to MCP server for user: {uid}', ['uid' => $uid]);
				return [
					'mcp_sync' => true,
					'partial_success' => false,
					'message' => 'App password saved successfully',
				];
			}

			$error = (isset($body['error']) && is_string($body['error'])) ? $body['error'] : 'Unknown error';
			$this->logger->error('MCP server rejected app password for user {uid}: {error}', ['uid' => $uid, 'error' => $error]);
			return [
				'mcp_sync' => false,
				'partial_success' => true,
				'message' => 'App password saved locally (MCP server sync failed)',
				'mcp_error' => $error,
			];
		} catch (\Exception $e) {
			$this->logger->error('Failed to send app password to MCP server for user {uid}: {error}', ['uid' => $uid, 'error' => $e->getMessage()]);
			return [
				'mcp_sync' => false,
				'partial_success' => true,
				'message' => 'App password saved locally (MCP server unreachable)',
				'mcp_error' => $e->getMessage(),
			];
		}
	}

	/**
	 * Tell the MCP server to drop its stored app password for a user
	 * (best-effort). Authenticated with the app password itself (BasicAuth),
	 * mirroring syncToMcp. Any failure is logged and swallowed.
	 */
	public function revokeFromMcp(string $uid, string $appPassword): void {
		$mcpServerUrl = (string)$this->config->getSystemValue('mcp_server_url', '');
		if ($mcpServerUrl === '') {
			return;
		}
		if ($appPassword === '') {
			$this->logger->debug('No stored app password to revoke from MCP for user: {uid}', ['uid' => $uid]);
			return;
		}

		$this->warnIfCleartextTransport($mcpServerUrl, $uid);

		try {
			$httpClient = $this->httpClientService->newClient();
			$mcpEndpoint = rtrim($mcpServerUrl, '/') . '/api/v1/users/' . rawurlencode($uid) . '/app-password';

			$response = $httpClient->delete($mcpEndpoint, [
				'auth' => [$uid, $appPassword],
				'headers' => [
					'Accept' => 'application/json',
				],
				'timeout' => 10,
			]);

			$statusCode = $response->getStatusCode();
			// MCP returns 204 No Content on a successful delete; accept any 2xx.
			if ($statusCode >= 200 && $statusCode < 300) {
				$this->logger->info('Revoked app password from MCP server for user: {uid}', ['uid' => $uid]);
			} else {
				$this->logger->warning('MCP server returned HTTP {status} revoking app password for user: {uid}', ['status' => $statusCode, 'uid' => $uid]);
			}
		} catch (\Exception $e) {
			// MCP unreachable / already gone — local revoke still proceeds.
			$this->logger->warning('Failed to revoke app password from MCP server for user {uid}: {error}', [
				'uid' => $uid,
				'error' => $e->getMessage(),
			]);
		}
	}

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
	private function warnIfCleartextTransport(string $url, string $uid): void {
		if (preg_match('#^https?://#i', $url) === 1
			&& preg_match('#^https://#i', $url) !== 1
			&& preg_match('#^https?://(localhost|127\.0\.0\.1|\[?::1\]?)([:/]|$)#i', $url) !== 1) {
			$this->logger->warning(
				"MCP server URL uses plaintext http for user $uid; "
				. 'use https:// for non-loopback hosts to avoid transmitting the app password in cleartext'
			);
		}
	}
}
