<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Service\Access;

use OCA\Astrolabe\Service\Access\AccessDecision;
use OCA\Astrolabe\Service\Access\FileAccessVerifier;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class FileAccessVerifierTest extends TestCase {
	private IRootFolder&MockObject $rootFolder;
	private Folder&MockObject $userFolder;
	private FileAccessVerifier $verifier;

	protected function setUp(): void {
		parent::setUp();
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->userFolder = $this->createMock(Folder::class);
		$this->rootFolder->method('getUserFolder')->with('alice')->willReturn($this->userFolder);
		$this->verifier = new FileAccessVerifier($this->rootFolder, $this->createMock(LoggerInterface::class));
	}

	public function testHandlesFileAndNote(): void {
		$this->assertSame(['file', 'note'], $this->verifier->docTypes());
	}

	public function testAllowedWhenGetByIdReturnsNode(): void {
		$this->userFolder->method('getById')->with(42)->willReturn([$this->createMock(Node::class)]);
		$this->assertSame(AccessDecision::ALLOWED, $this->verifier->verify('alice', ['doc_type' => 'file', 'id' => 42]));
	}

	public function testDeniedWhenGetByIdEmpty(): void {
		// The staleness case: shared at index time, unshared since ⇒ no node ⇒ deny.
		$this->userFolder->method('getById')->with(42)->willReturn([]);
		$this->assertSame(AccessDecision::DENIED, $this->verifier->verify('alice', ['doc_type' => 'file', 'id' => 42]));
	}

	public function testNoteUsesFileIdToo(): void {
		$this->userFolder->method('getById')->with(7)->willReturn([$this->createMock(Node::class)]);
		$this->assertSame(AccessDecision::ALLOWED, $this->verifier->verify('alice', ['doc_type' => 'note', 'id' => '7']));
	}

	/**
	 * @dataProvider provideBadIds
	 */
	public function testDeniedForInvalidFileIdWithoutPath(mixed $id): void {
		$this->assertSame(AccessDecision::DENIED, $this->verifier->verify('alice', ['doc_type' => 'file', 'id' => $id]));
	}

	public static function provideBadIds(): array {
		return ['zero' => [0], 'negative' => [-1], 'non-numeric' => ['abc'], 'null' => [null]];
	}

	public function testFailsClosedWhenGetByIdThrows(): void {
		$this->userFolder->method('getById')->willThrowException(new \RuntimeException('storage offline'));
		$this->assertSame(AccessDecision::DENIED, $this->verifier->verify('alice', ['doc_type' => 'file', 'id' => 42]));
	}

	public function testPathFallbackAllowedWhenNodeResolves(): void {
		$this->userFolder->expects($this->once())
			->method('get')
			->with('Documents/report.pdf')
			->willReturn($this->createMock(Node::class));
		$doc = ['doc_type' => 'file', 'metadata' => ['path' => '/remote.php/dav/files/alice/Documents/report.pdf']];
		$this->assertSame(AccessDecision::ALLOWED, $this->verifier->verify('alice', $doc));
	}

	public function testPathFallbackDeniedOnNotFound(): void {
		$this->userFolder->method('get')->willThrowException(new NotFoundException());
		$doc = ['doc_type' => 'file', 'metadata' => ['path' => 'files/alice/secret.pdf']];
		$this->assertSame(AccessDecision::DENIED, $this->verifier->verify('alice', $doc));
	}

	public function testDeniedWhenNeitherIdNorPath(): void {
		$this->assertSame(AccessDecision::DENIED, $this->verifier->verify('alice', ['doc_type' => 'file']));
	}

	public function testCanAccessPathNormalizesUidFilesPrefix(): void {
		$this->userFolder->expects($this->once())->method('get')->with('Notes/todo.md')->willReturn($this->createMock(Node::class));
		$this->assertSame(AccessDecision::ALLOWED, $this->verifier->canAccessPath('alice', '/alice/files/Notes/todo.md'));
	}

	public function testBothIdAndPathAllowedResolvesAllowed(): void {
		$this->userFolder->method('getById')->with(42)->willReturn([$this->createMock(Node::class)]);
		$this->userFolder->method('get')->willReturn($this->createMock(Node::class));
		$doc = ['doc_type' => 'file', 'id' => 42, 'metadata' => ['path' => '/alice/files/report.pdf']];
		$this->assertSame(AccessDecision::ALLOWED, $this->verifier->verify('alice', $doc));
	}

	public function testOwnedIdWithForeignPathIsDenied(): void {
		// The pdf-preview mismatch: caller pairs a fileId they own with someone
		// else's path (the identifier actually served). Both must resolve ⇒ deny.
		$this->userFolder->method('getById')->with(42)->willReturn([$this->createMock(Node::class)]);
		$this->userFolder->method('get')->willThrowException(new NotFoundException());
		$doc = ['doc_type' => 'file', 'id' => 42, 'metadata' => ['path' => '/alice/files/someone-elses.pdf']];
		$this->assertSame(AccessDecision::DENIED, $this->verifier->verify('alice', $doc));
	}

	public function testForeignIdWithOwnedPathIsDenied(): void {
		$this->userFolder->method('getById')->with(99)->willReturn([]); // not accessible
		$this->userFolder->method('get')->willReturn($this->createMock(Node::class));
		$doc = ['doc_type' => 'file', 'id' => 99, 'metadata' => ['path' => '/alice/files/mine.pdf']];
		$this->assertSame(AccessDecision::DENIED, $this->verifier->verify('alice', $doc));
	}
}
