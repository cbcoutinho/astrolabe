<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Listener;

use OCA\Astrolabe\Listener\SummaryCleanupListener;
use OCA\Astrolabe\Service\Assistant\ScratchImageStore;
use OCA\Astrolabe\Service\Assistant\SummaryService;
use OCP\EventDispatcher\Event;
use OCP\TaskProcessing\Events\TaskFailedEvent;
use OCP\TaskProcessing\Events\TaskSuccessfulEvent;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\AnalyzeImages;
use OCP\TaskProcessing\TaskTypes\TextToTextSummary;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Staged pages live in the user's own storage, so failing to delete them is a
 * visible leak rather than an internal one. These tests pin both that cleanup
 * happens on either terminal outcome and that unrelated tasks are left alone.
 */
final class SummaryCleanupListenerTest extends TestCase {
	private ScratchImageStore&MockObject $scratchImages;
	private SummaryCleanupListener $listener;

	protected function setUp(): void {
		parent::setUp();
		$this->scratchImages = $this->createMock(ScratchImageStore::class);
		$this->listener = new SummaryCleanupListener($this->scratchImages);
	}

	private function task(string $appId, ?string $customId, ?string $userId = 'alice'): Task {
		return new Task(AnalyzeImages::ID, ['images' => [1]], $appId, $userId, $customId);
	}

	public function testDeletesStagedPagesWhenTheTaskSucceeds(): void {
		$this->scratchImages->expects($this->once())
			->method('discard')
			->with('alice', 'tok123');

		$this->listener->handle(new TaskSuccessfulEvent(
			$this->task('astrolabe', SummaryService::CUSTOM_ID_SCRATCH_PREFIX . 'tok123'),
		));
	}

	/**
	 * A failed task is exactly when cleanup matters most: nothing else will ever
	 * come back for those pages.
	 */
	public function testDeletesStagedPagesWhenTheTaskFails(): void {
		$this->scratchImages->expects($this->once())
			->method('discard')
			->with('alice', 'tok123');

		$this->listener->handle(new TaskFailedEvent(
			$this->task('astrolabe', SummaryService::CUSTOM_ID_SCRATCH_PREFIX . 'tok123'),
			'provider exploded',
		));
	}

	public function testIgnoresTasksFromOtherApps(): void {
		$this->scratchImages->expects($this->never())->method('discard');

		$this->listener->handle(new TaskSuccessfulEvent(
			$this->task('assistant', SummaryService::CUSTOM_ID_SCRATCH_PREFIX . 'tok123'),
		));
	}

	/**
	 * The text tier stages nothing, so its tasks must not trigger a delete against
	 * a token that was never issued.
	 */
	public function testIgnoresOurOwnTasksThatStagedNothing(): void {
		$this->scratchImages->expects($this->never())->method('discard');

		$this->listener->handle(new TaskSuccessfulEvent(
			new Task(TextToTextSummary::ID, ['input' => 'x'], 'astrolabe', 'alice', 'astrolabe-summary'),
		));
	}

	public function testIgnoresTasksWithoutACustomId(): void {
		$this->scratchImages->expects($this->never())->method('discard');

		$this->listener->handle(new TaskSuccessfulEvent($this->task('astrolabe', null)));
	}

	public function testIgnoresTasksWithoutAUser(): void {
		$this->scratchImages->expects($this->never())->method('discard');

		$this->listener->handle(new TaskSuccessfulEvent(
			$this->task('astrolabe', SummaryService::CUSTOM_ID_SCRATCH_PREFIX . 'tok123', null),
		));
	}

	public function testIgnoresUnrelatedEvents(): void {
		$this->scratchImages->expects($this->never())->method('discard');

		$this->listener->handle(new Event());
	}
}
