<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\BackgroundJob;

use OCA\Astrolabe\BackgroundJob\SyncEventDeliveryJob;
use OCA\Astrolabe\Service\McpServerClient;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for SyncEventDeliveryJob.
 *
 * `run()` is protected on QueuedJob, so we invoke it via reflection with the
 * job argument directly (the framework would otherwise call it through
 * start()/setArgument()).
 */
final class SyncEventDeliveryJobTest extends TestCase {
	private ITimeFactory&MockObject $time;
	private McpServerClient&MockObject $client;
	private LoggerInterface&MockObject $logger;
	private SyncEventDeliveryJob $job;

	protected function setUp(): void {
		parent::setUp();
		$this->time = $this->createMock(ITimeFactory::class);
		$this->client = $this->createMock(McpServerClient::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->job = new SyncEventDeliveryJob($this->time, $this->client, $this->logger);
	}

	private function runJob(mixed $argument): void {
		$method = new \ReflectionMethod($this->job, 'run');
		$method->invoke($this->job, $argument);
	}

	private function envelope(): array {
		return [
			'event' => ['class' => 'OCP\\Files\\Events\\Node\\NodeWrittenEvent', 'node' => ['id' => 42]],
			'user' => ['uid' => 'admin', 'displayName' => 'admin'],
			'time' => 1720000000,
		];
	}

	public function testForwardsEnvelopeToClient(): void {
		$envelope = $this->envelope();
		$this->client->expects($this->once())
			->method('sendSyncEvent')
			->with($envelope)
			->willReturn(['status' => 'queued']);
		$this->logger->expects($this->never())->method('warning');

		$this->runJob([$envelope, 'nonce123']);
	}

	public function testLogsWarningOnDeliveryError(): void {
		$this->client->method('sendSyncEvent')->willReturn(['error' => 'boom']);
		$this->logger->expects($this->once())
			->method('warning')
			->with('Sync event delivery failed', $this->callback(
				fn (array $ctx): bool => ($ctx['error'] ?? null) === 'boom'
					&& ($ctx['event_class'] ?? null) === 'OCP\\Files\\Events\\Node\\NodeWrittenEvent'
			));

		$this->runJob([$this->envelope(), 'nonce123']);
	}

	public function testIgnoresNonceAndDeliversRegardless(): void {
		$this->client->expects($this->once())->method('sendSyncEvent')->willReturn(['status' => 'queued']);
		// A different nonce for the same envelope still delivers.
		$this->runJob([$this->envelope(), 'a-different-nonce']);
	}

	public function testMalformedArgumentIsSkipped(): void {
		$this->client->expects($this->never())->method('sendSyncEvent');
		$this->logger->expects($this->once())->method('warning');

		$this->runJob(['not-an-envelope']);
	}
}
