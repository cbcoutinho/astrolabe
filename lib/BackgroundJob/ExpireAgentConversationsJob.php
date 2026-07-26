<?php

declare(strict_types=1);

namespace OCA\Astrolabe\BackgroundJob;

use OCA\Astrolabe\AppInfo\Application;
use OCA\Astrolabe\Db\AgentConversationMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Drops agent conversations nobody has touched in a while.
 *
 * Nothing else deletes them: the Assistant hands a conversation token back and
 * forth but never signals that a chat has ended, so without this the table only
 * grows — and it holds the text of what users asked and what the model answered,
 * which is not something to keep indefinitely by accident.
 *
 * @psalm-suppress UnusedClass — scheduled from appinfo/info.xml.
 */
final class ExpireAgentConversationsJob extends TimedJob {
	/**
	 * Long enough to return to yesterday's chat, short enough that abandoned
	 * conversations do not accumulate. Losing history only costs context: the
	 * next message starts a fresh conversation rather than failing.
	 */
	public const MAX_AGE = 30 * 24 * 3600;

	/** @psalm-suppress PossiblyUnusedMethod — constructed via DI / IJobList. */
	public function __construct(
		ITimeFactory $time,
		private AgentConversationMapper $conversations,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 3600);
	}

	#[\Override]
	protected function run(mixed $argument): void {
		try {
			$removed = $this->conversations->deleteStale(self::MAX_AGE);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not expire agent conversations', [
				'exception' => $e,
				'app' => Application::APP_ID,
			]);
			return;
		}

		if ($removed > 0) {
			$this->logger->info('Expired {count} agent conversation(s)', [
				'count' => $removed,
				'app' => Application::APP_ID,
			]);
		}
	}
}
