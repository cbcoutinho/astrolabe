<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Service\Access;

use OCA\Astrolabe\Service\Access\AccessDecision;
use OCA\Astrolabe\Service\Access\DeckAccessVerifier;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

final class DeckAccessVerifierTest extends TestCase {
	private ContainerInterface&MockObject $container;
	private DeckAccessVerifier $verifier;

	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->verifier = new DeckAccessVerifier($this->container, $this->createMock(LoggerInterface::class));
	}

	/** A PermissionService stand-in whose getPermissions() returns a fixed map. */
	private function permissionService(array $permissions, ?\Throwable $throw = null): object {
		return new class($permissions, $throw) {
			public function __construct(
				private array $permissions,
				private ?\Throwable $throw,
			) {
			}

			public function getPermissions(int $boardId, ?string $userId = null): array {
				if ($this->throw !== null) {
					throw $this->throw;
				}
				return $this->permissions;
			}
		};
	}

	public function testDelegatesWhenBoardIdMissing(): void {
		$this->container->expects($this->never())->method('get');
		$this->assertSame(AccessDecision::DELEGATE, $this->verifier->verify('alice', ['doc_type' => 'deck_card', 'id' => 5]));
	}

	public function testAllowedWhenReadPermissionTrue(): void {
		$this->container->method('get')->willReturn($this->permissionService([0 => true]));
		$doc = ['doc_type' => 'deck_card', 'id' => 5, 'metadata' => ['board_id' => 3]];
		$this->assertSame(AccessDecision::ALLOWED, $this->verifier->verify('alice', $doc));
	}

	public function testDeniedWhenReadPermissionFalse(): void {
		$this->container->method('get')->willReturn($this->permissionService([0 => false]));
		$doc = ['doc_type' => 'deck_card', 'id' => 5, 'metadata' => ['board_id' => 3]];
		$this->assertSame(AccessDecision::DENIED, $this->verifier->verify('alice', $doc));
	}

	public function testDelegatesWhenServiceUnresolvable(): void {
		$this->container->method('get')->willThrowException(new \RuntimeException('deck not installed'));
		$doc = ['doc_type' => 'deck_card', 'id' => 5, 'metadata' => ['board_id' => 3]];
		$this->assertSame(AccessDecision::DELEGATE, $this->verifier->verify('alice', $doc));
	}

	public function testDelegatesWhenGetPermissionsThrows(): void {
		$this->container->method('get')->willReturn($this->permissionService([], new \RuntimeException('board gone')));
		$doc = ['doc_type' => 'deck_card', 'id' => 5, 'metadata' => ['board_id' => 3]];
		$this->assertSame(AccessDecision::DELEGATE, $this->verifier->verify('alice', $doc));
	}
}
