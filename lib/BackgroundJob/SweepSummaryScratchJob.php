<?php

declare(strict_types=1);

namespace OCA\Astrolabe\BackgroundJob;

use OCA\Astrolabe\AppInfo\Application;
use OCA\Astrolabe\Service\Assistant\ScratchImageStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Removes summary scratch folders left behind by tasks that never settled.
 *
 * {@see \OCA\Astrolabe\Listener\SummaryCleanupListener} handles the normal path,
 * firing the moment a task succeeds or fails. This is the backstop for tasks that
 * reach neither — cancelled mid-flight, orphaned by a provider that went away, or
 * lost to a crash — whose pages would otherwise sit in the user's own files
 * indefinitely.
 *
 * @psalm-suppress UnusedClass — scheduled from appinfo/info.xml.
 */
final class SweepSummaryScratchJob extends TimedJob {
	/**
	 * Old enough that no in-flight task could still be waiting on these pages: a
	 * queued task behind a slow provider may legitimately take a long while to
	 * start, and deleting its input early would fail the task rather than tidy up.
	 */
	public const MAX_AGE = 24 * 3600;

	/** @psalm-suppress PossiblyUnusedMethod — constructed via DI / IJobList. */
	public function __construct(
		ITimeFactory $time,
		private IUserManager $userManager,
		private ScratchImageStore $scratchImages,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(6 * 3600);
	}

	#[\Override]
	protected function run(mixed $argument): void {
		$removed = 0;
		$this->userManager->callForSeenUsers(function (IUser $user) use (&$removed): void {
			$removed += $this->scratchImages->sweep($user->getUID(), self::MAX_AGE);
		});

		if ($removed > 0) {
			// Only worth a line when it actually found something: a steady non-zero
			// count here means tasks are not reaching a terminal state, which is a
			// provider problem rather than a cleanup one.
			$this->logger->info('Swept {count} orphaned summary scratch folders', [
				'count' => $removed,
				'app' => Application::APP_ID,
			]);
		}
	}
}
