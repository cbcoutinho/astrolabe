<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Service\Access;

use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Access verifier for file-backed doc types (`file`, `note` — notes are markdown
 * files with real Nextcloud fileIds).
 *
 * Uses the canonical Nextcloud idiom: ``getUserFolder($uid)->getById($fileId)``
 * returning a non-empty array means the user can see that file (respecting
 * ownership and shares). This is authoritative and real-time — it reflects a
 * share revoked *after* indexing, which is the staleness window the check
 * guards. Fails **closed** (DENIED) on any error, since these back the
 * content-fetch endpoints where over-disclosure is the risk.
 */
final class FileAccessVerifier implements AccessVerifierInterface {
	/** @psalm-suppress PossiblyUnusedMethod — constructed via DI. */
	public function __construct(
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function docTypes(): array {
		return ['file', 'note'];
	}

	#[\Override]
	public function verify(string $uid, array $doc): AccessDecision {
		/** @var mixed $id */
		$id = $doc['id'] ?? null;
		$fileId = is_numeric($id) ? (int)$id : 0;
		if ($fileId > 0) {
			return $this->canAccessFile($uid, $fileId);
		}

		// Fall back to the WebDAV path when no usable fileId is present (e.g. the
		// pdf-preview endpoint, which is keyed by file_path).
		$metadata = $doc['metadata'] ?? [];
		$path = isset($metadata['path']) && is_string($metadata['path'])
			? $metadata['path']
			: null;
		if ($path !== null && $path !== '') {
			return $this->canAccessPath($uid, $path);
		}

		return AccessDecision::DENIED;
	}

	public function canAccessFile(string $uid, int $fileId): AccessDecision {
		if ($fileId <= 0) {
			return AccessDecision::DENIED;
		}
		try {
			$nodes = $this->rootFolder->getUserFolder($uid)->getById($fileId);
			return $nodes !== [] ? AccessDecision::ALLOWED : AccessDecision::DENIED;
		} catch (\Throwable $e) {
			$this->logger->debug('File access check failed; denying', ['file_id' => $fileId, 'error' => $e->getMessage()]);
			return AccessDecision::DENIED;
		}
	}

	public function canAccessPath(string $uid, string $path): AccessDecision {
		$relative = $this->toUserRelativePath($uid, $path);
		try {
			$this->rootFolder->getUserFolder($uid)->get($relative);
			return AccessDecision::ALLOWED;
		} catch (NotFoundException) {
			return AccessDecision::DENIED;
		} catch (\Throwable $e) {
			$this->logger->debug('Path access check failed; denying', ['error' => $e->getMessage()]);
			return AccessDecision::DENIED;
		}
	}

	/**
	 * Normalize a possible WebDAV prefix (``/remote.php/dav/files/{uid}/…`` or
	 * ``files/{uid}/…``) to a path relative to the user's files root, which is
	 * what {@see \OCP\Files\Folder::get()} expects.
	 */
	private function toUserRelativePath(string $uid, string $path): string {
		$path = ltrim($path, '/');
		foreach (["remote.php/dav/files/$uid/", "files/$uid/", "$uid/files/"] as $prefix) {
			if (str_starts_with($path, $prefix)) {
				return substr($path, strlen($prefix));
			}
		}
		return $path;
	}
}
