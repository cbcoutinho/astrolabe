<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Listener;

use OCA\Astrolabe\BackgroundJob\SyncEventDeliveryJob;
use OCA\Astrolabe\Listener\SyncEventListener;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\Node;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for SyncEventListener.
 *
 * The listener matches the fired event's class against enabled presets, so the
 * tests use the real OCP node event classes with a mocked Node (only getId() /
 * getPath() / getOwner() are exercised by getWebhookSerializable() + owner
 * resolution).
 */
final class SyncEventListenerTest extends TestCase {
	private IJobList&MockObject $jobList;
	private IUserSession&MockObject $userSession;
	private LoggerInterface&MockObject $logger;

	private const NOTE_PATH = '/admin/files/Notes/todo.md';
	private const NON_NOTE_PATH = '/admin/files/Documents/report.md';

	protected function setUp(): void {
		parent::setUp();
		$this->jobList = $this->createMock(IJobList::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	/** Build a listener with the given master-switch + enabled-preset config. */
	private function makeListener(bool $enabled, array $presets): SyncEventListener {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturn($enabled);
		$appConfig->method('getValueString')->willReturn(json_encode($presets));
		return new SyncEventListener($this->jobList, $this->userSession, $appConfig, $this->logger);
	}

	private function nodeMock(int $id, string $path, ?string $ownerUid): Node&MockObject {
		$node = $this->createMock(Node::class);
		$node->method('getId')->willReturn($id);
		$node->method('getPath')->willReturn($path);
		if ($ownerUid !== null) {
			$owner = $this->createMock(IUser::class);
			$owner->method('getUID')->willReturn($ownerUid);
			$owner->method('getDisplayName')->willReturn($ownerUid);
			$node->method('getOwner')->willReturn($owner);
		} else {
			$node->method('getOwner')->willReturn(null);
		}
		return $node;
	}

	private function writtenEvent(string $path, ?string $ownerUid): NodeWrittenEvent {
		return new NodeWrittenEvent($this->nodeMock(42, $path, $ownerUid));
	}

	public function testIgnoresNonWebhookCompatibleEvent(): void {
		$this->jobList->expects($this->never())->method('add');
		$this->makeListener(true, ['notes_sync'])->handle($this->createMock(Event::class));
	}

	public function testDoesNothingWhenNativeSyncDisabled(): void {
		$this->jobList->expects($this->never())->method('add');
		$this->makeListener(false, ['notes_sync'])->handle($this->writtenEvent(self::NOTE_PATH, 'admin'));
	}

	public function testDoesNothingWhenNoPresetsEnabled(): void {
		$this->jobList->expects($this->never())->method('add');
		$this->makeListener(true, [])->handle($this->writtenEvent(self::NOTE_PATH, 'admin'));
	}

	public function testEnqueueFailureIsSwallowedNotPropagated(): void {
		// A failure enqueuing the delivery job must NOT bubble up into the
		// triggering file operation (that would turn a file save into a 500).
		$this->jobList->method('add')->willThrowException(new \RuntimeException('job store down'));
		$this->logger->expects($this->once())->method('warning');

		// The absence of an exception here IS the assertion.
		$this->makeListener(true, ['notes_sync'])->handle($this->writtenEvent(self::NOTE_PATH, 'admin'));
		$this->addToAssertionCount(1);
	}

	public function testEnqueuesWhenNotesFilterMatches(): void {
		$captured = null;
		$this->jobList->expects($this->once())
			->method('add')
			->with(SyncEventDeliveryJob::class, $this->callback(function ($arg) use (&$captured): bool {
				$captured = $arg;
				return true;
			}));

		$this->makeListener(true, ['notes_sync'])->handle($this->writtenEvent(self::NOTE_PATH, 'admin'));

		$this->assertIsArray($captured);
		$envelope = $captured[0];
		$this->assertSame(NodeWrittenEvent::class, $envelope['event']['class']);
		$this->assertSame(42, $envelope['event']['node']['id']);
		$this->assertSame('admin', $envelope['user']['uid']);
		$this->assertIsInt($envelope['time']);
		$this->assertNotSame($captured[1], ''); // a nonce is present
	}

	public function testDoesNotEnqueueWhenNotesFilterRejectsPath(): void {
		$this->jobList->expects($this->never())->method('add');
		$this->makeListener(true, ['notes_sync'])->handle($this->writtenEvent(self::NON_NOTE_PATH, 'admin'));
	}

	public function testAllFilesPresetMatchesAnyPath(): void {
		$this->jobList->expects($this->once())->method('add');
		// files_sync has empty filters ⇒ matches a non-Notes path too.
		$this->makeListener(true, ['files_sync'])->handle($this->writtenEvent(self::NON_NOTE_PATH, 'admin'));
	}

	public function testEnqueuesOnlyOnceWhenPresetsOverlap(): void {
		// Both notes_sync and files_sync cover NodeWrittenEvent; a Notes path
		// matches both, but only one delivery must be enqueued.
		$this->jobList->expects($this->once())->method('add');
		$this->makeListener(true, ['notes_sync', 'files_sync'])->handle($this->writtenEvent(self::NOTE_PATH, 'admin'));
	}

	public function testPrefersNodeOwnerOverSessionUser(): void {
		$sessionUser = $this->createMock(IUser::class);
		$sessionUser->method('getUID')->willReturn('admin');
		$this->userSession->method('getUser')->willReturn($sessionUser);

		$captured = null;
		$this->jobList->method('add')->willReturnCallback(function ($job, $arg) use (&$captured): void {
			$captured = $arg;
		});

		// Node owned by bob, edited in admin's session ⇒ envelope must attribute bob.
		$this->makeListener(true, ['files_sync'])->handle($this->writtenEvent(self::NON_NOTE_PATH, 'bob'));

		$this->assertSame('bob', $captured[0]['user']['uid']);
	}

	public function testFallsBackToPathUidWhenNoOwnerOrSession(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$captured = null;
		$this->jobList->method('add')->willReturnCallback(function ($job, $arg) use (&$captured): void {
			$captured = $arg;
		});

		$this->makeListener(true, ['files_sync'])->handle($this->writtenEvent('/carol/files/x.md', null));

		$this->assertSame('carol', $captured[0]['user']['uid']);
	}

	public function testSkipsWhenNoUserResolvable(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->jobList->expects($this->never())->method('add');
		// No owner, no session, and a path with no uid segment.
		$this->makeListener(true, ['files_sync'])->handle($this->writtenEvent('', null));
	}

	public function testBeforeDeleteEnvelopeCarriesNodeId(): void {
		$captured = null;
		$this->jobList->method('add')->willReturnCallback(function ($job, $arg) use (&$captured): void {
			$captured = $arg;
		});

		$event = new BeforeNodeDeletedEvent($this->nodeMock(99, self::NOTE_PATH, 'admin'));
		$this->makeListener(true, ['notes_sync'])->handle($event);

		$this->assertSame(BeforeNodeDeletedEvent::class, $captured[0]['event']['class']);
		$this->assertSame(99, $captured[0]['event']['node']['id']);
	}
}
