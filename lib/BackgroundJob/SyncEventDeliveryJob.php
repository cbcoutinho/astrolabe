<?php

declare(strict_types=1);

namespace OCA\Astrolabe\BackgroundJob;

use OCA\Astrolabe\Service\McpServerClient;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Delivers a single Nextcloud change-event envelope to the MCP server's webhook
 * ingress, off the request/filesystem-operation path.
 *
 * Enqueued by {@see \OCA\Astrolabe\Listener\SyncEventListener} (mirrors the shape
 * of core's `webhook_listeners` `WebhookCall` job: async delivery via `IJobList`
 * so a file operation never blocks on an outbound HTTP call). Delivery failures
 * are logged, not thrown — the MCP polling scanner reconciles anything that
 * doesn't land.
 */
final class SyncEventDeliveryJob extends QueuedJob {
	/** @psalm-suppress PossiblyUnusedMethod — constructed via DI / IJobList. */
	public function __construct(
		ITimeFactory $time,
		private McpServerClient $client,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	/**
	 * @param mixed $argument ``[array $envelope, string $nonce]`` — the nonce only
	 *                        keeps `IJobList` from de-duplicating two legitimately
	 *                        identical events; it is not used at delivery time.
	 * @psalm-suppress MixedArgument, MixedAssignment $argument comes from the job store untyped.
	 */
	#[\Override]
	protected function run($argument): void {
		if (!is_array($argument) || !isset($argument[0]) || !is_array($argument[0])) {
			$this->logger->warning('SyncEventDeliveryJob received a malformed argument; skipping');
			return;
		}

		/** @var array<string, mixed> $envelope */
		$envelope = $argument[0];
		$result = $this->client->sendSyncEvent($envelope);

		if (isset($result['error'])) {
			$this->logger->warning('Sync event delivery failed', [
				'error' => $result['error'],
				'event_class' => $this->eventClassOf($envelope),
			]);
		}
	}

	/**
	 * @param array<string, mixed> $envelope
	 * @psalm-suppress MixedAssignment $event is read from an untyped envelope, narrowed below.
	 */
	private function eventClassOf(array $envelope): string {
		$event = $envelope['event'] ?? null;
		if (is_array($event) && isset($event['class']) && is_string($event['class'])) {
			return $event['class'];
		}
		return 'unknown';
	}
}
