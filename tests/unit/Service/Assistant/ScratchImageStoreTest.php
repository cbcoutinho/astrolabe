<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Service\Assistant;

use Eris\Generators;
use Eris\TestTrait;
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
	use TestTrait;

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
	 * Wiring: discard() goes through the token guard before touching storage.
	 * The guard itself is covered by the properties below.
	 */
	public function testDiscardRefusesAMalformedToken(): void {
		$scratchRoot = $this->createMock(Folder::class);
		// Reached only if the guard let the token through.
		$scratchRoot->expects($this->never())->method('get');
		$scratchRoot->expects($this->never())->method('newFolder');
		$this->withScratchRoot($scratchRoot);

		// discard() swallows failures by design; the assertion is that it never
		// resolves a node for a token it should have rejected.
		$this->store->discard('alice', '../../../etc');
	}

	/**
	 * For any string, a token the guard accepts is a single, plain path segment:
	 * no separator, no dot, no NUL, nothing a filesystem treats specially.
	 */
	public function testAnAcceptedTokenIsAlwaysASafePathSegment(): void {
		// Mostly valid characters, so a fair share of tokens is accepted and the
		// property is not vacuous; the rest are the ones a traversal would use.
		$valid = Generators::elements('a', 'z', '0', '9');
		$junk = Generators::elements('A', '.', '/', '\\', "\0", "\n", ' ', '-', 'é');
		$mixed = Generators::map(
			static fn (array $c): string => implode('', $c),
			Generators::seq(Generators::frequency([9, $valid], [1, $junk])),
		);
		// A valid token with one bad character at an edge or in the middle: the
		// shape an anchoring bug lets through, and one random mixing rarely hits.
		$nearMiss = Generators::map(
			static function (array $t): string {
				[$core, $bad, $where] = $t;
				$core = str_repeat('a', 8) . implode('', $core);
				return match ($where) {
					'start' => $bad . $core,
					'end' => $core . $bad,
					'middle' => substr($core, 0, 4) . $bad . substr($core, 4),
				};
			},
			Generators::tuple(Generators::seq($valid), $junk, Generators::elements('start', 'end', 'middle')),
		);
		// Valid characters only, any length: the only shape that probes the
		// length bound, since everything else is rejected for its characters.
		$anyLength = Generators::map(
			static fn (array $c): string => implode('', $c),
			Generators::seq($valid),
		);
		$this->forAll(Generators::oneOf($mixed, $nearMiss, $anyLength))
			->then(function (string $token): void {
				try {
					ScratchImageStore::assertToken($token);
				} catch (NotFoundException) {
					$this->addToAssertionCount(1);
					return;
				}
				$this->assertSame(0, preg_match('/[\/\\\\.\0\s]/', $token), $token);
				// Redundant with today's regex, which is the point: it fails if
				// the length bound is ever loosened.
				$this->assertLessThanOrEqual(64, strlen($token));
			});
	}

	/**
	 * Found in review, then by the property above once `\n` joined its alphabet:
	 * PCRE's `$` also matches before a final newline, so the guard let
	 * "abcdefgh\n" through as a path segment.
	 */
	public function testATrailingNewlineIsNotAToken(): void {
		$this->expectException(NotFoundException::class);
		ScratchImageStore::assertToken("abcdefgh\n");
	}

	/** Every token newToken() can produce passes the guard that later reads it back. */
	public function testEveryGeneratableTokenIsAccepted(): void {
		$alphabet = str_split(ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);
		$this->forAll(Generators::vector(32, Generators::elements($alphabet)))
			->then(function (array $chars): void {
				$token = implode('', $chars);
				$this->assertSame($token, ScratchImageStore::assertToken($token));
			});
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
