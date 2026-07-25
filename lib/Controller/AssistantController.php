<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Controller;

use OCA\Astrolabe\Service\Assistant\SummaryException;
use OCA\Astrolabe\Service\Assistant\SummaryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Assistant-backed features over Astrolabe's indexed content.
 *
 * Scheduling only: the response carries a TaskProcessing task id, and the client
 * polls core's own OCS endpoint (/ocs/v2.php/taskprocessing/task/{id}) for the
 * result. Nothing here proxies model output, so there is no polling route of ours
 * to secure or rate-limit.
 *
 * @psalm-suppress UnusedClass — resolved by route name through the DI container.
 */
final class AssistantController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private SummaryService $summaryService,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Schedule a summary of the document behind a chunk.
	 *
	 * Rate-limited because each call costs model inference on the admin's
	 * provider, which is a real budget rather than just server load.
	 *
	 * Pages rendered in the browser arrive as uploaded files under `pages[]`;
	 * rasterization is client-side because server-side PDF rendering is what
	 * previously OOMKilled the MCP server's API pod.
	 *
	 * @param list<mixed> $image_file_ids Images already in Nextcloud — an image
	 *                                    document summarizes itself, with nothing to stage. Nextcloud re-validates
	 *                                    the caller's access to every id when it prepares the task input, so these
	 *                                    are not trusted here beyond shape.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 60)]
	public function summarize(
		string $doc_type,
		string $doc_id,
		int $start,
		int $end,
		?int $chunk_index = null,
		?int $total_chunks = null,
		?int $board_id = null,
		?int $mailbox_id = null,
		?string $calendar_uri = null,
		?string $path = null,
		array $image_file_ids = [],
	): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['success' => false, 'error' => 'User not authenticated'],
				Http::STATUS_UNAUTHORIZED,
			);
		}

		try {
			$result = $this->summaryService->schedule(
				$user->getUID(),
				$doc_type,
				$doc_id,
				$start,
				$end,
				$chunk_index,
				$total_chunks,
				[
					'board_id' => $board_id,
					'mailbox_id' => $mailbox_id,
					'calendar_uri' => $calendar_uri,
					'path' => $path,
				],
				$this->intIds($image_file_ids),
				$this->uploadedPages(),
			);
		} catch (SummaryException $e) {
			return new JSONResponse(
				['success' => false, 'error' => $e->getMessage()],
				$e->getStatusCode(),
			);
		}

		return new JSONResponse([
			'success' => true,
			'task_id' => $result['task_id'],
			// Which tier answered, so the UI can say whether the summary read the
			// pages or only the extracted text.
			'mode' => $result['mode'],
		]);
	}

	/**
	 * Read the rendered pages out of the multipart upload, in page order.
	 *
	 * Only PNG is accepted: it is what the browser canvas produces, and pinning the
	 * type keeps arbitrary uploads from being staged into the user's storage under
	 * the guise of a summary.
	 *
	 * @return list<string>
	 */
	private function uploadedPages(): array {
		/** @var mixed $upload */
		$upload = $this->request->getUploadedFile('pages');
		if (!is_array($upload)) {
			return [];
		}

		/** @var mixed $tmpNames */
		$tmpNames = $upload['tmp_name'] ?? null;
		if (!is_array($tmpNames)) {
			return [];
		}
		/** @var mixed $rawTypes */
		$rawTypes = $upload['type'] ?? null;
		$types = is_array($rawTypes) ? $rawTypes : [];
		/** @var mixed $rawErrors */
		$rawErrors = $upload['error'] ?? null;
		$errors = is_array($rawErrors) ? $rawErrors : [];

		$pages = [];
		/** @var mixed $tmpName */
		foreach ($tmpNames as $i => $tmpName) {
			/** @var mixed $error */
			$error = $errors[$i] ?? UPLOAD_ERR_NO_FILE;
			/** @var mixed $type */
			$type = $types[$i] ?? '';
			if ($error !== UPLOAD_ERR_OK || $type !== 'image/png') {
				continue;
			}
			if (!is_string($tmpName) || !is_uploaded_file($tmpName)) {
				continue;
			}
			$bytes = file_get_contents($tmpName);
			if ($bytes !== false && $bytes !== '') {
				$pages[] = $bytes;
			}
		}
		return $pages;
	}

	/**
	 * @param list<mixed> $ids
	 * @return list<int>
	 */
	private function intIds(array $ids): array {
		$clean = [];
		/** @var mixed $id */
		foreach ($ids as $id) {
			if (is_int($id)) {
				$clean[] = $id;
			} elseif (is_string($id) && ctype_digit($id)) {
				$clean[] = (int)$id;
			}
		}
		return $clean;
	}
}
