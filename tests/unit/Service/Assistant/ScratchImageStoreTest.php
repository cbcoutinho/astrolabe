<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Service\Assistant;

use OCA\Astrolabe\Service\Assistant\ScratchImageStore;
use OCA\Astrolabe\Service\Assistant\SummaryException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * These pages are written into the user's own storage, and the token that names
 * their folder arrives from a task's stored customId rather than from anything
 * generated this request. That makes the token guard a path-traversal defense and
 * the rollback a leak defense, so both are tested directly rather than through
 * mocks of this class.
 */
final class ScratchImageStoreTest extends TestCase {
	private IRootFolder&MockObject $rootFolder;
	private Folder&MockObject $userFolder;
	private ISecureRandom&MockObject $random;
	private ScratchImageStore $store;

	protected function setUp(): void {
		parent::setUp();
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->userFolder = $this->createMock(Folder::class);
		$this->random = $this->createMock(ISecureRandom::class);
		$this->rootFolder->method('getUserFolder')->willReturn($this->userFolder);

		$this->store = new ScratchImageStore(
			$this->rootFolder,
			$this->random,
			$this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * Wire the user folder so that SCRATCH_DIR resolves to $scratchRoot.
	 */
	private function withScratchRoot(Folder&MockObject $scratchRoot): void {
		$this->userFolder->method('nodeExists')
			->with(ScratchImageStore::SCRATCH_DIR)
			->willReturn(true);
		$this->userFolder->method('get')
			->with(ScratchImageStore::SCRATCH_DIR)
			->willReturn($scratchRoot);
	}

	private function file(int $id): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		return $file;
	}

	public function testTokensAreLowercaseAlphanumeric(): void {
		$this->random->expects($this->once())
			->method('generate')
			->with(32, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS)
			->willReturn('abc123');

		$this->assertSame('abc123', $this->store->newToken());
	}

	public function testStoreWritesPagesInOrderAndReturnsFileIds(): void {
		$batch = $this->createMock(Folder::class);
		$scratchRoot = $this->createMock(Folder::class);
		$scratchRoot->method('nodeExists')->willReturn(false);
		$scratchRoot->method('newFolder')->with('tok123abc')->willReturn($batch);
		$this->withScratchRoot($scratchRoot);

		$names = [];
		$batch->method('newFile')->willReturnCallback(
			function (string $name) use (&$names): File {
				$names[] = $name;
				return $this->file(100 + count($names));
			},
		);

		$ids = $this->store->store('alice', 'tok123abc', ['png-1', 'png-2']);

		$this->assertSame([101, 102], $ids);
		// Zero-padded so the model receives the pages in page order rather than
		// lexicographic order.
		$this->assertSame(['page-001.png', 'page-002.png'], $names);
	}

	/**
	 * A half-written batch would summarize an arbitrary subset of the document and
	 * present it as the document, and would leave the earlier pages behind.
	 */
	public function testStoreRollsBackTheWholeBatchOnAPartialFailure(): void {
		$batch = $this->createMock(Folder::class);
		$scratchRoot = $this->createMock(Folder::class);
		$scratchRoot->method('nodeExists')->willReturn(true);
		$scratchRoot->method('get')->willReturn($batch);
		$this->withScratchRoot($scratchRoot);

		$calls = 0;
		$batch->method('newFile')->willReturnCallback(
			function () use (&$calls): File {
				$calls++;
				if ($calls === 2) {
					throw new \RuntimeException('disk full');
				}
				return $this->file(101);
			},
		);
		$batch->expects($this->once())->method('delete');

		$this->expectException(SummaryException::class);
		$this->store->store('alice', 'tok123abc', ['png-1', 'png-2']);
	}

	/**
	 * The token names a path segment and comes from stored task data, so anything
	 * outside the expected alphabet must be refused rather than normalised.
	 *
	 * @dataProvider malformedTokens
	 */
	public function testDiscardRefusesMalformedTokens(string $token): void {
		$scratchRoot = $this->createMock(Folder::class);
		// Reached only if the guard let the token through.
		$scratchRoot->expects($this->never())->method('get');
		$scratchRoot->expects($this->never())->method('newFolder');
		$this->withScratchRoot($scratchRoot);

		// discard() swallows failures by design; the assertion is that it never
		// resolves a node for a token it should have rejected.
		$this->store->discard('alice', $token);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function malformedTokens(): array {
		return [
			'parent traversal' => ['../../../etc'],
			'slash' => ['tok/123'],
			'dot segment' => ['..'],
			'uppercase' => ['TOK123ABC'],
			'too short' => ['abc'],
			'empty' => [''],
			'null byte' => ["tok123abc\0"],
			'leading dot' => ['.astrolabe'],
		];
	}

	public function testDiscardDeletesTheBatchFolder(): void {
		$batch = $this->createMock(Folder::class);
		$batch->expects($this->once())->method('delete');

		$scratchRoot = $this->createMock(Folder::class);
		$scratchRoot->method('nodeExists')->with('tok123abc')->willReturn(true);
		$scratchRoot->method('get')->with('tok123abc')->willReturn($batch);
		$this->withScratchRoot($scratchRoot);

		$this->store->discard('alice', 'tok123abc');
	}

	/**
	 * The sweeper races the completion listener by design, so an already-deleted
	 * batch is the expected path and must not surface as an error.
	 */
	public function testDiscardToleratesAnAlreadyDeletedBatch(): void {
		$scratchRoot = $this->createMock(Folder::class);
		$scratchRoot->method('nodeExists')->willReturn(false);
		$this->withScratchRoot($scratchRoot);

		$this->store->discard('alice', 'tok123abc');
		$this->addToAssertionCount(1);
	}

	/**
	 * Deleting a queued task's input would fail the task rather than tidy up, so
	 * only batches older than the cutoff are removed.
	 */
	public function testSweepOnlyRemovesBatchesPastTheCutoff(): void {
		$fresh = $this->createMock(Node::class);
		$fresh->method('getMTime')->willReturn(time() - 60);
		$fresh->expects($this->never())->method('delete');

		$stale = $this->createMock(Node::class);
		$stale->method('getMTime')->willReturn(time() - 90000);
		$stale->expects($this->once())->method('delete');

		$scratchRoot = $this->createMock(Folder::class);
		$scratchRoot->method('getDirectoryListing')->willReturn([$fresh, $stale]);
		$this->withScratchRoot($scratchRoot);

		$this->assertSame(1, $this->store->sweep('alice', 24 * 3600));
	}

	public function testSweepIsANoopWhenNothingWasEverStaged(): void {
		$this->userFolder->method('nodeExists')->willReturn(false);

		$this->assertSame(0, $this->store->sweep('alice', 24 * 3600));
	}

	/**
	 * A user whose storage is unavailable must not fail the whole sweep for
	 * everyone else.
	 */
	public function testSweepSwallowsStorageFailures(): void {
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')
			->willThrowException(new NotFoundException('no home'));

		$store = new ScratchImageStore(
			$rootFolder,
			$this->random,
			$this->createMock(LoggerInterface::class),
		);

		$this->assertSame(0, $store->sweep('alice', 24 * 3600));
	}
}
