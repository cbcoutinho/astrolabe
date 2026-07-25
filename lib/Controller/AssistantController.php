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
	 * @param list<mixed> $image_file_ids Rendered page images for the multimodal
	 *                                    tier. Rasterization happens in the browser — see SummaryService for why —
	 *                                    and Nextcloud re-validates the caller's access to every id when it prepares
	 *                                    the task input, so these are not trusted here beyond shape.
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
