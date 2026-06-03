<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Controller;

use OCA\Astrolabe\AppInfo\Application;
use OCA\Astrolabe\Service\AppPasswordProvisioningService;
use OCA\Astrolabe\Service\BackgroundSyncCredentialStorage;
use OCA\Astrolabe\Settings\Admin as AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Authentication\Exceptions\InvalidTokenException;
use OCP\Authentication\Token\IProvider;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for managing background sync credentials (app passwords).
 *
 * Two provisioning paths share {@see AppPasswordProvisioningService}:
 *   - self-service ({@see storeAppPassword}): the user mints a token via
 *     core/getapppassword and hands it to us; we validate, store and sync it.
 *   - admin ({@see adminProvisionUser}): an admin mints a token on a user's
 *     behalf. Admin-only methods carry no #[NoAdminRequired] attribute, so
 *     Nextcloud's SecurityMiddleware restricts them to admins.
 */
class CredentialsController extends Controller {
	/**
	 * Cap the admin user listing so a huge instance can't build an unbounded
	 * response. Hitting the cap is logged and surfaced via `capped` so the UI
	 * can tell the admin the list is truncated.
	 */
	private const ADMIN_USER_LIST_LIMIT = 500;

	public function __construct(
		string $appName,
		IRequest $request,
		private BackgroundSyncCredentialStorage $credentialStorage,
		private IUserSession $userSession,
		private LoggerInterface $logger,
		private IAppConfig $appConfig,
		private IProvider $tokenProvider,
		private AppPasswordProvisioningService $provisioning,
		private IUserManager $userManager,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Whether users may self-provision background indexing. When an admin
	 * disables this, self-service provisioning is blocked (existing passwords
	 * keep working until an admin deprovisions them).
	 */
	private function selfProvisionAllowed(): bool {
		return $this->appConfig->getValueBool(
			Application::APP_ID,
			AdminSettings::SETTING_ALLOW_USER_SELF_PROVISION,
			AdminSettings::DEFAULT_ALLOW_USER_SELF_PROVISION,
		);
	}

	/**
	 * Store app password for background sync.
	 *
	 * Validates the app password against Nextcloud's token provider, then
	 * stores it encrypted and syncs it to the MCP server if valid.
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

		// Defense-in-depth backstop behind the hidden personal "Enable" button:
		// reject self-service provisioning when an admin has disabled it.
		if (!$this->selfProvisionAllowed()) {
			$this->logger->warning('Self-provisioning blocked (admin-disabled) for user: %s', [$userId]);
			return new JSONResponse([
				'success' => false,
				'error' => 'User self-provisioning is disabled by your administrator'
			], Http::STATUS_FORBIDDEN);
		}

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

		// Validate the app password and resolve the Nextcloud loginName it was
		// minted under. The loginName — not the UID — is what Nextcloud expects
		// for app-password BasicAuth, and the two differ for OIDC-provisioned
		// users whose UID is their display name (e.g. UID "Chris Coutinho",
		// loginName "chris@coutinho.io"). The MCP server needs the loginName to
		// validate the password over HTTP.
		$loginName = $this->validateAppPassword($userId, $appPassword);

		if ($loginName === null) {
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

		// Sync to the MCP server (proves ownership via BasicAuth). The local
		// copy is already saved, so any MCP failure is reported as a partial
		// success rather than failing the whole request.
		$mcpResult = $this->provisioning->syncToMcp($userId, $loginName, $appPassword);

		return new JSONResponse(
			array_merge(['success' => true, 'local_storage' => true], $mcpResult),
			Http::STATUS_OK,
		);
	}

	/**
	 * Validate an app password and resolve the loginName it was minted under.
	 *
	 * Validation is internal (token provider) — see the note below. The
	 * loginName is returned because Nextcloud keys app-password BasicAuth on
	 * the loginName, which can differ from the UID; the MCP server needs it to
	 * validate the password over HTTP.
	 *
	 * @param string $userId User ID
	 * @param string $appPassword App password to validate
	 * @return string|null The Nextcloud loginName when valid, null otherwise
	 */
	private function validateAppPassword(string $userId, string $appPassword): ?string {
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
			return null;
		}

		if ($token->getUID() !== $userId) {
			$this->logger->warning("App password belongs to a different user than: $userId");
			return null;
		}

		$this->logger->debug("App password validation successful for user: $userId");
		return $token->getLoginName();
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
			'self_provision_allowed' => $this->selfProvisionAllowed(),
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
			// a failure here must not block clearing the local credential. We do
			// not invalidate the Nextcloud token itself — the user owns it and
			// may use it elsewhere; only an admin deprovision revokes the token.
			$appPassword = $this->credentialStorage->getAppPassword($userId);
			if ($appPassword !== null && $appPassword !== '') {
				$this->provisioning->revokeFromMcp($userId, $appPassword);
			}

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

	// ---------------------------------------------------------------------
	// Admin provisioning (admin-only: no #[NoAdminRequired])
	// ---------------------------------------------------------------------

	/**
	 * List every user's background-sync provisioning status, plus whether
	 * user self-provisioning is currently allowed. Admin-only.
	 *
	 * @return JSONResponse
	 */
	public function adminListProvisioning(): JSONResponse {
		$users = [];
		$capped = false;
		$count = 0;

		foreach ($this->userManager->search('', self::ADMIN_USER_LIST_LIMIT + 1) as $user) {
			if ($count >= self::ADMIN_USER_LIST_LIMIT) {
				$capped = true;
				break;
			}
			$count++;
			$uid = $user->getUID();
			$users[] = [
				'uid' => $uid,
				'display_name' => $user->getDisplayName(),
				'has_background_access' => $this->credentialStorage->hasAccess($uid),
				'provisioned_at' => $this->credentialStorage->getProvisionedAt($uid),
			];
		}

		if ($capped) {
			$this->logger->warning(
				'Admin provisioning list truncated at %d users; not all users are shown',
				[self::ADMIN_USER_LIST_LIMIT],
			);
		}

		return new JSONResponse([
			'success' => true,
			'users' => $users,
			'capped' => $capped,
			'self_provision_allowed' => $this->selfProvisionAllowed(),
		], Http::STATUS_OK);
	}

	/**
	 * Provision a background-sync app password for an arbitrary user. Admin-only.
	 *
	 * Nextcloud has no public API for an admin to create an app password for
	 * another user, so we mint one via the server-internal token provider
	 * (see {@see AppPasswordProvisioningService}). The loginName is set to the
	 * UID; this works for standard users. For OIDC-provisioned users whose
	 * external login differs from the UID, BasicAuth against the MCP server may
	 * need the real loginName — surfaced as an MCP sync failure (the local
	 * credential is still stored).
	 *
	 * @param string $userId Target user ID
	 * @return JSONResponse
	 */
	public function adminProvisionUser(string $userId): JSONResponse {
		$user = $this->userManager->get($userId);
		if ($user === null) {
			return new JSONResponse([
				'success' => false,
				'error' => 'Unknown user'
			], Http::STATUS_NOT_FOUND);
		}

		$loginName = $userId;

		try {
			$appPassword = $this->provisioning->mintAppPasswordForUser(
				$userId,
				$loginName,
				AppPasswordProvisioningService::ADMIN_TOKEN_NAME,
			);
		} catch (\Throwable $e) {
			$this->logger->error('Failed to mint app password for user %s', [$userId, $e->getMessage()]);
			return new JSONResponse([
				'success' => false,
				'error' => 'Failed to mint app password'
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		try {
			$this->credentialStorage->storeAppPassword($userId, $appPassword);
		} catch (\Exception $e) {
			$this->logger->error('Failed to store admin-provisioned app password for user %s', [$userId, $e->getMessage()]);
			return new JSONResponse([
				'success' => false,
				'error' => 'Failed to save app password'
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$mcpResult = $this->provisioning->syncToMcp($userId, $loginName, $appPassword);
		$this->logger->info('Admin provisioned background sync for user: %s', [$userId]);

		return new JSONResponse(
			array_merge(['success' => true, 'user_id' => $userId, 'local_storage' => true], $mcpResult),
			Http::STATUS_OK,
		);
	}

	/**
	 * Deprovision a user: revoke the Nextcloud token, drop the MCP copy and
	 * clear the local credential. Admin-only.
	 *
	 * @param string $userId Target user ID
	 * @return JSONResponse
	 */
	public function adminDeprovisionUser(string $userId): JSONResponse {
		try {
			$appPassword = $this->credentialStorage->getAppPassword($userId);
			if ($appPassword !== null && $appPassword !== '') {
				// Best-effort remote cleanup before invalidating the token /
				// dropping the local copy; failures must not block deprovision.
				$this->provisioning->revokeFromMcp($userId, $appPassword);
				$this->provisioning->revokeToken($appPassword);
			}

			$this->credentialStorage->deleteAppPassword($userId);
			$this->logger->info('Admin deprovisioned background sync for user: %s', [$userId]);

			return new JSONResponse([
				'success' => true,
				'user_id' => $userId,
				'message' => 'User deprovisioned successfully'
			], Http::STATUS_OK);
		} catch (\Exception $e) {
			$this->logger->error('Failed to deprovision user %s', [$userId, $e->getMessage()]);
			return new JSONResponse([
				'success' => false,
				'error' => 'Failed to deprovision user'
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Enable or disable user-level self-provisioning instance-wide. Admin-only.
	 *
	 * @param bool $enabled Whether users may self-provision
	 * @return JSONResponse
	 */
	public function adminSetSelfProvision(bool $enabled): JSONResponse {
		$this->appConfig->setValueBool(
			Application::APP_ID,
			AdminSettings::SETTING_ALLOW_USER_SELF_PROVISION,
			$enabled,
		);
		$this->logger->info('Admin set user self-provisioning to %s', [$enabled ? 'enabled' : 'disabled']);

		return new JSONResponse([
			'success' => true,
			'self_provision_allowed' => $enabled,
		], Http::STATUS_OK);
	}
}
