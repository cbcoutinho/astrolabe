<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Service\Access;

use OCA\Astrolabe\Service\Access\AccessDecision;
use OCA\Astrolabe\Service\Access\CalendarAccessVerifier;
use OCA\Astrolabe\Service\Access\DeckAccessVerifier;
use OCA\Astrolabe\Service\Access\DocumentAccessService;
use OCA\Astrolabe\Service\Access\FileAccessVerifier;
use OCA\Astrolabe\Service\Access\MailAccessVerifier;
use OCA\Astrolabe\Service\SearchSources;
use OCP\Calendar\IManager;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the registry's routing + the dynamic installed-apps gate, using real
 * verifiers wired to mocked leaf dependencies (the verifiers are final and
 * can't be mocked; this doubles as light integration coverage of dispatch).
 */
final class DocumentAccessServiceTest extends TestCase {
	private IRootFolder&MockObject $rootFolder;
	private Folder&MockObject $userFolder;
	private SearchSources&MockObject $searchSources;
	private DocumentAccessService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->userFolder = $this->createMock(Folder::class);
		$this->rootFolder->method('getUserFolder')->willReturn($this->userFolder);
		$this->searchSources = $this->createMock(SearchSources::class);
		$logger = $this->createMock(LoggerInterface::class);

		$this->service = new DocumentAccessService(
			new FileAccessVerifier($this->rootFolder, $logger),
			new DeckAccessVerifier($this->createMock(ContainerInterface::class), $logger),
			new MailAccessVerifier($this->createMock(ContainerInterface::class), $logger),
			new CalendarAccessVerifier($this->createMock(IManager::class), $logger),
			$this->searchSources,
		);
	}

	public function testDispatchesToFileVerifierWhenSourceInstalled(): void {
		$this->searchSources->method('isInstalled')->with('files')->willReturn(true);
		$this->userFolder->method('getById')->with(42)->willReturn([$this->createMock(Node::class)]);

		$this->assertSame(
			AccessDecision::ALLOWED,
			$this->service->check('alice', ['doc_type' => 'file', 'id' => 42]),
		);
	}

	public function testNotInstalledSourceDelegatesWithoutTouchingVerifier(): void {
		$this->searchSources->method('isInstalled')->with('files')->willReturn(false);
		// The gate must short-circuit before the verifier resolves the filesystem.
		$this->rootFolder->expects($this->never())->method('getUserFolder');

		$this->assertSame(
			AccessDecision::DELEGATE,
			$this->service->check('alice', ['doc_type' => 'file', 'id' => 42]),
		);
	}

	public function testUnmappedDocTypeDelegates(): void {
		// contacts is a real source but has no verifier ⇒ delegate even if installed.
		$this->searchSources->method('isInstalled')->willReturn(true);
		$this->assertSame(
			AccessDecision::DELEGATE,
			$this->service->check('alice', ['doc_type' => 'contact', 'id' => 'abc']),
		);
	}

	public function testUnknownDocTypeDelegates(): void {
		$this->assertSame(
			AccessDecision::DELEGATE,
			$this->service->check('alice', ['doc_type' => 'totally_unknown', 'id' => 1]),
		);
	}

	public function testEmptyDocTypeDelegates(): void {
		$this->assertSame(AccessDecision::DELEGATE, $this->service->check('alice', ['id' => 1]));
	}
}
