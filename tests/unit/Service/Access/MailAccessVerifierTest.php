<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Service\Access;

use OCA\Astrolabe\Service\Access\AccessDecision;
use OCA\Astrolabe\Service\Access\MailAccessVerifier;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Local stand-in whose short class name is exactly "ClientException" — the
 * verifier treats that (Mail's not-found/not-owned error) as a definitive DENY.
 */
final class ClientException extends \Exception {
}

final class MailAccessVerifierTest extends TestCase {
	private ContainerInterface&MockObject $container;
	private MailAccessVerifier $verifier;

	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->verifier = new MailAccessVerifier($this->container, $this->createMock(LoggerInterface::class));
	}

	private function mailManager(?\Throwable $throw): object {
		return new class($throw) {
			public function __construct(
				private ?\Throwable $throw,
			) {
			}

			public function getMailbox(string $uid, int $id): object {
				if ($this->throw !== null) {
					throw $this->throw;
				}
				return (object)['id' => $id];
			}
		};
	}

	public function testDelegatesWhenMailboxIdMissing(): void {
		$this->container->expects($this->never())->method('get');
		$this->assertSame(AccessDecision::DELEGATE, $this->verifier->verify('alice', ['doc_type' => 'mail_message', 'id' => 9]));
	}

	public function testAllowedWhenMailboxOwned(): void {
		$this->container->method('get')->willReturn($this->mailManager(null));
		$doc = ['doc_type' => 'mail_message', 'id' => 9, 'metadata' => ['mailbox_id' => 2]];
		$this->assertSame(AccessDecision::ALLOWED, $this->verifier->verify('alice', $doc));
	}

	public function testDeniedOnClientException(): void {
		$this->container->method('get')->willReturn($this->mailManager(new ClientException('not your mailbox')));
		$doc = ['doc_type' => 'mail_message', 'id' => 9, 'metadata' => ['mailbox_id' => 2]];
		$this->assertSame(AccessDecision::DENIED, $this->verifier->verify('alice', $doc));
	}

	public function testDelegatesOnTransientError(): void {
		$this->container->method('get')->willReturn($this->mailManager(new \RuntimeException('db down')));
		$doc = ['doc_type' => 'mail_message', 'id' => 9, 'metadata' => ['mailbox_id' => 2]];
		$this->assertSame(AccessDecision::DELEGATE, $this->verifier->verify('alice', $doc));
	}

	public function testDelegatesWhenManagerUnresolvable(): void {
		$this->container->method('get')->willThrowException(new \RuntimeException('mail not installed'));
		$doc = ['doc_type' => 'mail_message', 'id' => 9, 'metadata' => ['mailbox_id' => 2]];
		$this->assertSame(AccessDecision::DELEGATE, $this->verifier->verify('alice', $doc));
	}
}
