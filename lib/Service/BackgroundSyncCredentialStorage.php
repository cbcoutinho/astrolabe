<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Service;

use OCP\IConfig;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Storage for the Nextcloud app password used by the MCP server to
 * fetch a user's files via WebDAV for background indexing.
 *
 * This is the ONLY long-lived credential Astrolabe persists per user.
 * MCP-server access tokens are minted on demand via McpTokenMinter and
 * never written to disk.
 *
 * Storage keys (unchanged from the legacy McpTokenStorage to preserve
 * existing rows across the auth refactor):
 *   - background_sync_password — encrypted app password
 *   - background_sync_type     — 'app_password'
 *   - background_sync_provisioned_at — unix timestamp
 */
class BackgroundSyncCredentialStorage {
	/** @psalm-suppress PossiblyUnusedMethod — instantiated by the Nextcloud DI container. */
	public function __construct(
		private IConfig $config,
		private ICrypto $crypto,
		private LoggerInterface $logger,
	) {
	}

	public function storeAppPassword(string $userId, string $appPassword): void {
		try {
			$encrypted = $this->crypto->encrypt($appPassword);

			$this->config->setUserValue($userId, 'astrolabe', 'background_sync_password', $encrypted);
			$this->config->setUserValue($userId, 'astrolabe', 'background_sync_type', 'app_password');
			$this->config->setUserValue($userId, 'astrolabe', 'background_sync_provisioned_at', (string)time());

			$this->logger->info("Stored background sync app password for user: $userId");
		} catch (\Exception $e) {
			$this->logger->error("Failed to store app password for user $userId", [
				'error' => $e->getMessage(),
			]);
			throw $e;
		}
	}

	public function getAppPassword(string $userId): ?string {
		try {
			$encrypted = $this->config->getUserValue($userId, 'astrolabe', 'background_sync_password', '');
			if ($encrypted === '') {
				return null;
			}
			return $this->crypto->decrypt($encrypted);
		} catch (\Exception $e) {
			$this->logger->error("Failed to retrieve app password for user $userId", [
				'error' => $e->getMessage(),
			]);
			return null;
		}
	}

	public function deleteAppPassword(string $userId): void {
		try {
			$this->config->deleteUserValue($userId, 'astrolabe', 'background_sync_password');
			$this->config->deleteUserValue($userId, 'astrolabe', 'background_sync_type');
			$this->config->deleteUserValue($userId, 'astrolabe', 'background_sync_provisioned_at');
			$this->logger->info("Deleted background sync app password for user: $userId");
		} catch (\Exception $e) {
			$this->logger->error("Failed to delete app password for user $userId", [
				'error' => $e->getMessage(),
			]);
			throw $e;
		}
	}

	public function hasAccess(string $userId): bool {
		return $this->getAppPassword($userId) !== null;
	}

	public function getProvisionedAt(string $userId): ?int {
		$timestamp = $this->config->getUserValue($userId, 'astrolabe', 'background_sync_provisioned_at', '');
		return $timestamp === '' ? null : (int)$timestamp;
	}
}
