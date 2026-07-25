<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Listener;

use OCA\Astrolabe\AppInfo\Application;
use OCA\Astrolabe\Service\Assistant\ScratchImageStore;
use OCA\Astrolabe\Service\Assistant\SummaryService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\TaskProcessing\Events\AbstractTaskProcessingEvent;

/**
 * Deletes the pages staged for a multimodal summary once its task settles.
 *
 * Those pages only exist because TaskProcessing image inputs must be real files
 * the user can read (see {@see ScratchImageStore}). They are useless the moment
 * the task reaches a terminal state, and they sit in the user's own storage, so
 * leaving them would be both clutter and a slow leak.
 *
 * The scratch token travels in the task's customId, which means no mapping table
 * has to be kept in sync with task lifecycles.
 *
 * @template-implements IEventListener<AbstractTaskProcessingEvent|Event>
 */
final class SummaryCleanupListener implements IEventListener {
	/** @psalm-suppress PossiblyUnusedMethod — registered as an event listener. */
	public function __construct(
		private ScratchImageStore $scratchImages,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof AbstractTaskProcessingEvent) {
			return;
		}

		$task = $event->getTask();
		if ($task->getAppId() !== Application::APP_ID) {
			return;
		}

		$customId = $task->getCustomId();
		if ($customId === null) {
			return;
		}
		if (!str_starts_with($customId, SummaryService::CUSTOM_ID_SCRATCH_PREFIX)) {
			return;
		}
		$token = substr($customId, strlen(SummaryService::CUSTOM_ID_SCRATCH_PREFIX));

		$userId = $task->getUserId();
		if ($userId === null) {
			return;
		}

		$this->scratchImages->discard($userId, $token);
	}
}
