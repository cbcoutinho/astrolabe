<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Service\Assistant;

use OCA\Astrolabe\AppInfo\Application;
use OCA\Astrolabe\Service\Access\AccessDecision;
use OCA\Astrolabe\Service\Access\DocumentAccessService;
use OCA\Astrolabe\Service\McpServerClient;
use OCA\Astrolabe\Service\McpTokenMinter;
use OCA\Astrolabe\Service\McpTokenMintException;
use OCP\AppFramework\Http;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\AnalyzeImages;
use OCP\TaskProcessing\TaskTypes\TextToTextSummary;
use Psr\Log\LoggerInterface;

/**
 * Schedules a summary of the document behind a chunk, picking the richest tier
 * the instance can serve.
 *
 * Two tiers, because fidelity and availability pull in opposite directions:
 *
 *  - **analyze-images** sends rendered pages to a multimodal model, so tables,
 *    figures, stamps and handwriting survive — none of which reach a text model,
 *    because they are lost before it ever sees the document.
 *  - **text2text:summary** works from text the MCP server already extracted. It
 *    is the fallback, and on most instances it is the only tier available.
 *
 * The caller supplies the page images: rendering PDFs server-side is what
 * previously OOMKilled the MCP server's API pod, so rasterization stays in the
 * browser (see PDFViewer.vue) and this service only ever receives file ids.
 * Nextcloud validates the user's access to each one when it prepares the task
 * input, so a forged id cannot widen access.
 *
 * @psalm-suppress ClassMustBeFinal — kept non-final so it can be mocked in the
 *   controller unit tests, mirroring the other Service classes.
 */
class SummaryService {
	/**
	 * Upper bound on pages sent to a multimodal model in one request.
	 *
	 * Vision tokens dominate the cost of these calls and the useful signal for a
	 * summary saturates quickly, so this is a deliberate spend cap rather than a
	 * technical limit.
	 */
	public const MAX_IMAGES = 8;

	private const PROMPT_IMAGES = 'Summarize this document. Preserve the structure of any tables, '
		. 'figures, headings and forms rather than flattening them into prose, and note anything '
		. 'handwritten, stamped or annotated. If the pages are only part of a longer document, say so.';

	private const PROMPT_TEXT_SUFFIX = "\n\nSummarize the document excerpt above.";

	/** @psalm-suppress PossiblyUnusedMethod — constructed via DI. */
	public function __construct(
		private IManager $taskProcessing,
		private AssistantCapabilities $capabilities,
		private DocumentAccessService $documentAccess,
		private McpServerClient $client,
		private McpTokenMinter $tokenMinter,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Schedule a summary and return the task id plus the tier that was chosen.
	 *
	 * @param array<string, scalar|null> $identifiers Access-check identifiers carried
	 *                                                on the search result (board_id, mailbox_id, calendar_uri, path).
	 * @param list<int> $imageFileIds Rendered page images, when the caller has them.
	 *
	 * @return array{task_id: int, mode: string}
	 * @throws SummaryException
	 */
	public function schedule(
		string $userId,
		string $docType,
		string $docId,
		int $start,
		int $end,
		?int $chunkIndex,
		?int $totalChunks,
		array $identifiers,
		array $imageFileIds,
	): array {
		// Local, authoritative access check BEFORE minting a token or calling the
		// MCP server, matching ApiController::chunkContext(). Guards the staleness
		// window (access revoked since indexing) and stale deep-links.
		$doc = ['doc_type' => $docType, 'id' => $docId, 'metadata' => $this->cleanIdentifiers($identifiers)];
		if ($this->documentAccess->check($userId, $doc) === AccessDecision::DENIED) {
			throw new SummaryException('You no longer have access to this document', Http::STATUS_FORBIDDEN);
		}

		$modes = $this->capabilities->getSummaryModes();

		$images = $this->boundImages($imageFileIds);
		if ($images !== [] && in_array(AssistantCapabilities::SUMMARY_MODE_IMAGES, $modes, true)) {
			return [
				'task_id' => $this->scheduleImageSummary($userId, $docType, $docId, $images),
				'mode' => AssistantCapabilities::SUMMARY_MODE_IMAGES,
			];
		}

		if (in_array(AssistantCapabilities::SUMMARY_MODE_TEXT, $modes, true)) {
			return [
				'task_id' => $this->scheduleTextSummary(
					$userId, $docType, $docId, $start, $end, $chunkIndex, $totalChunks,
				),
				'mode' => AssistantCapabilities::SUMMARY_MODE_TEXT,
			];
		}

		// Reached when the admin installed no text-generation provider at all. The
		// UI hides the action in that case, so this is the direct-call backstop.
		throw new SummaryException(
			'No summarization provider is available on this server',
			Http::STATUS_SERVICE_UNAVAILABLE,
		);
	}

	/**
	 * @param list<int> $imageFileIds
	 */
	private function scheduleImageSummary(
		string $userId,
		string $docType,
		string $docId,
		array $imageFileIds,
	): int {
		// File ids are passed straight through: Manager::prepareInputData() runs
		// validateFileId() and validateUserAccessToFile() against this same userId
		// for every image slot, so core is the authority on whether the caller may
		// read them.
		return $this->scheduleTask(
			AnalyzeImages::ID,
			['images' => $imageFileIds, 'input' => self::PROMPT_IMAGES],
			$userId,
			$docType,
			$docId,
		);
	}

	private function scheduleTextSummary(
		string $userId,
		string $docType,
		string $docId,
		int $start,
		int $end,
		?int $chunkIndex,
		?int $totalChunks,
	): int {
		try {
			$token = $this->tokenMinter->mintForUser($userId);
		} catch (McpTokenMintException $e) {
			throw new SummaryException($e->getMessage(), Http::STATUS_SERVICE_UNAVAILABLE, $e);
		}

		// Widest window the server will serve. This is a bounded excerpt, not the
		// whole document: the MCP server exposes no document-listing endpoint, so
		// there is no way to walk every chunk and reduce over them. Long documents
		// are therefore summarized from the neighbourhood of the selected chunk —
		// whole-document summarization needs a new server endpoint first.
		$context = $this->client->getChunkContext(
			$docType,
			$docId,
			$start,
			$end,
			$token,
			$chunkIndex,
			$totalChunks,
			McpServerClient::MAX_CHUNK_CONTEXT,
		);

		if (isset($context['error'])) {
			throw new SummaryException(
				'Could not read the document text: ' . (is_string($context['error']) ? $context['error'] : 'unknown error'),
				Http::STATUS_BAD_GATEWAY,
			);
		}

		$text = $this->joinContext($context);
		if (trim($text) === '') {
			throw new SummaryException('There is no text to summarize', Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return $this->scheduleTask(
			TextToTextSummary::ID,
			['input' => $text . self::PROMPT_TEXT_SUFFIX],
			$userId,
			$docType,
			$docId,
		);
	}

	/**
	 * @param array<string, list<int>|string> $input
	 */
	private function scheduleTask(
		string $taskTypeId,
		array $input,
		string $userId,
		string $docType,
		string $docId,
	): int {
		$task = new Task(
			$taskTypeId,
			$input,
			Application::APP_ID,
			$userId,
			// Lets the frontend correlate a poll with the document it asked about,
			// and makes stray tasks attributable in the admin task list.
			'astrolabe-summary:' . $docType . ':' . $docId,
		);

		try {
			$this->taskProcessing->scheduleTask($task);
		} catch (\Throwable $e) {
			$this->logger->error('Failed to schedule a summary task', [
				'exception' => $e,
				'task_type' => $taskTypeId,
				'doc_type' => $docType,
				'app' => Application::APP_ID,
			]);
			throw new SummaryException('Could not schedule the summary', Http::STATUS_INTERNAL_SERVER_ERROR, $e);
		}

		$id = $task->getId();
		if ($id === null) {
			throw new SummaryException('Could not schedule the summary', Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		return $id;
	}

	/**
	 * Stitch the chunk and its surrounding text back into one excerpt.
	 *
	 * @param array<array-key, mixed> $context
	 */
	private function joinContext(array $context): string {
		$parts = [];
		foreach (['before_context', 'chunk_text', 'after_context'] as $key) {
			/** @var mixed $value */
			$value = $context[$key] ?? null;
			if (is_string($value) && $value !== '') {
				$parts[] = $value;
			}
		}
		return implode('', $parts);
	}

	/**
	 * @param list<int> $imageFileIds
	 * @return list<int>
	 */
	private function boundImages(array $imageFileIds): array {
		$ids = array_values(array_unique(array_filter($imageFileIds, static fn (int $id): bool => $id > 0)));
		return array_slice($ids, 0, self::MAX_IMAGES);
	}

	/**
	 * @param array<string, scalar|null> $identifiers
	 * @return array<string, scalar>
	 */
	private function cleanIdentifiers(array $identifiers): array {
		$metadata = [];
		foreach ($identifiers as $key => $value) {
			if ($value !== null && $value !== '') {
				$metadata[$key] = $value;
			}
		}
		return $metadata;
	}
}
