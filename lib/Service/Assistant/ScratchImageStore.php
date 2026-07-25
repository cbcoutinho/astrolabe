<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Service\Assistant;

use OCP\AppFramework\Http;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * Short-lived storage for rendered PDF pages on their way to a multimodal model.
 *
 * TaskProcessing image slots take Nextcloud file ids and nothing else — core has
 * no endpoint for uploading task *inputs*, only for reading outputs — and
 * `Manager::prepareInputData()` calls `validateUserAccessToFile()` on every id.
 * App data is therefore unusable here: the file has to be one the user can read,
 * which means it has to live in the user's own storage.
 *
 * So pages land in a hidden per-user folder, keyed by a random token that is also
 * carried in the task's customId. That is what lets the completion listener find
 * and delete them without a mapping table: the task itself remembers where its
 * pages went.
 *
 * @psalm-suppress ClassMustBeFinal — kept non-final so it can be mocked in the
 *   controller unit tests, mirroring the other Service classes.
 */
class ScratchImageStore {
	/**
	 * Hidden so it stays out of the way in the Files UI. Not a guarantee of
	 * invisibility — some clients show dotfiles — which is part of why these are
	 * deleted as soon as the task settles.
	 */
	public const SCRATCH_DIR = '.astrolabe/summaries';

	private const TOKEN_LENGTH = 32;

	/** @psalm-suppress PossiblyUnusedMethod — constructed via DI. */
	public function __construct(
		private IRootFolder $rootFolder,
		private ISecureRandom $random,
		private LoggerInterface $logger,
	) {
	}

	public function newToken(): string {
		return $this->random->generate(
			self::TOKEN_LENGTH,
			ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS,
		);
	}

	/**
	 * Write rendered pages into the user's storage and return their file ids.
	 *
	 * @param list<string> $pages Raw image bytes, in page order.
	 * @return list<int>
	 * @throws SummaryException
	 */
	public function store(string $userId, string $token, array $pages): array {
		try {
			$folder = $this->folderFor($userId, $token, create: true);
		} catch (\Throwable $e) {
			$this->logger->error('Could not create the summary scratch folder', [
				'exception' => $e,
				'user_id' => $userId,
			]);
			throw new SummaryException('Could not stage the document pages', Http::STATUS_INTERNAL_SERVER_ERROR, $e);
		}

		$ids = [];
		foreach ($pages as $index => $bytes) {
			try {
				$file = $folder->newFile(sprintf('page-%03d.png', $index + 1), $bytes);
				$ids[] = $file->getId();
			} catch (\Throwable $e) {
				// Partial failure still leaves earlier pages on disk, so drop the
				// whole batch rather than summarizing an arbitrary subset of the
				// document and presenting it as the document.
				$this->discard($userId, $token);
				$this->logger->error('Could not write a summary scratch page', [
					'exception' => $e,
					'user_id' => $userId,
				]);
				throw new SummaryException('Could not stage the document pages', Http::STATUS_INTERNAL_SERVER_ERROR, $e);
			}
		}

		return $ids;
	}

	/**
	 * Delete a batch's folder. Safe to call for a token that never existed.
	 */
	public function discard(string $userId, string $token): void {
		try {
			$this->folderFor($userId, $token, create: false)->delete();
		} catch (NotFoundException) {
			// Already gone — the expected path when the sweeper and the completion
			// listener race, so not worth logging.
		} catch (\Throwable $e) {
			$this->logger->warning('Could not delete a summary scratch folder', [
				'exception' => $e,
				'user_id' => $userId,
			]);
		}
	}

	/**
	 * Delete batches older than $maxAge seconds for one user.
	 *
	 * The completion listener is the primary cleanup path; this is the backstop for
	 * tasks that never reached a terminal state at all, whose folders would
	 * otherwise sit in the user's files forever.
	 */
	public function sweep(string $userId, int $maxAge): int {
		try {
			$root = $this->scratchRoot($userId, create: false);
		} catch (\Throwable) {
			return 0;
		}

		$cutoff = time() - $maxAge;
		$removed = 0;
		foreach ($root->getDirectoryListing() as $node) {
			if ($node->getMTime() >= $cutoff) {
				continue;
			}
			try {
				$node->delete();
				$removed++;
			} catch (\Throwable $e) {
				$this->logger->warning('Could not sweep a summary scratch folder', [
					'exception' => $e,
					'user_id' => $userId,
				]);
			}
		}
		return $removed;
	}

	private function folderFor(string $userId, string $token, bool $create): Folder {
		$safeToken = $this->assertToken($token);
		$root = $this->scratchRoot($userId, $create);

		if ($root->nodeExists($safeToken)) {
			$folder = $root->get($safeToken);
			if (!$folder instanceof Folder) {
				throw new NotFoundException('scratch path is not a folder');
			}
			return $folder;
		}
		if (!$create) {
			throw new NotFoundException('no scratch folder for this token');
		}
		return $root->newFolder($safeToken);
	}

	private function scratchRoot(string $userId, bool $create): Folder {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		if ($userFolder->nodeExists(self::SCRATCH_DIR)) {
			$root = $userFolder->get(self::SCRATCH_DIR);
			if (!$root instanceof Folder) {
				throw new NotFoundException('scratch root is not a folder');
			}
			return $root;
		}
		if (!$create) {
			throw new NotFoundException('no scratch root');
		}
		return $userFolder->newFolder(self::SCRATCH_DIR);
	}

	/**
	 * Tokens reach this class from a task's customId, which is stored data rather
	 * than something we generated this request — so it is re-validated as
	 * lowercase alphanumerics before ever being used as a path segment.
	 */
	private function assertToken(string $token): string {
		if (preg_match('/^[a-z0-9]{8,64}$/', $token) !== 1) {
			throw new NotFoundException('malformed scratch token');
		}
		return $token;
	}
}
