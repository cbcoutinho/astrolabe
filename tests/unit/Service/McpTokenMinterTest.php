<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Service;

use OCA\Astrolabe\Service\McpTokenMinter;
use OCA\Astrolabe\Service\McpTokenMintException;
use OCA\OIDCIdentityProvider\Event\TokenGenerationRequestEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class McpTokenMinterTest extends TestCase {
	private IEventDispatcher&MockObject $eventDispatcher;
	private IConfig&MockObject $config;
	private LoggerInterface&MockObject $logger;
	private McpTokenMinter $minter;

	protected function setUp(): void {
		parent::setUp();
		$this->eventDispatcher = $this->createMock(IEventDispatcher::class);
		$this->config = $this->createMock(IConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->minter = new McpTokenMinter(
			$this->eventDispatcher,
			$this->config,
			$this->logger,
		);
	}

	public function testMintsTokenViaEventDispatch(): void {
		$this->config->method('getSystemValue')->willReturnMap([
			['astrolabe_client_id', '', 'astrolabe-client'],
			['mcp_server_public_url', '', 'https://mcp.example.com'],
			['mcp_server_url', '', 'http://localhost:8000'],
		]);

		$this->eventDispatcher->expects($this->once())
			->method('dispatchTyped')
			->willReturnCallback(function (object $event): void {
				$this->assertInstanceOf(TokenGenerationRequestEvent::class, $event);
				/** @var TokenGenerationRequestEvent $event */
				$this->assertSame('astrolabe-client', $event->getClientIdentifier());
				$this->assertSame('alice', $event->getUserId());
				$this->assertSame('', $event->getExtraScopes());
				$this->assertSame('https://mcp.example.com', $event->getResource());
				$event->setAccessToken('eyJhbGciOi.JhcmJpdHJh.cnktdG9rZW4');
			});

		$token = $this->minter->mintForUser('alice');
		$this->assertSame('eyJhbGciOi.JhcmJpdHJh.cnktdG9rZW4', $token);
	}

	public function testFallsBackToInternalUrlWhenPublicMissing(): void {
		$this->config->method('getSystemValue')->willReturnMap([
			['astrolabe_client_id', '', 'astrolabe-client'],
			['mcp_server_public_url', '', ''],
			['mcp_server_url', '', 'https://mcp-internal:8000'],
		]);

		$this->eventDispatcher->expects($this->once())
			->method('dispatchTyped')
			->willReturnCallback(function (TokenGenerationRequestEvent $event): void {
				$this->assertSame('https://mcp-internal:8000', $event->getResource());
				$event->setAccessToken('tok');
			});

		$this->minter->mintForUser('alice');
	}

	public function testMemoizesPerRequest(): void {
		$this->config->method('getSystemValue')->willReturnMap([
			['astrolabe_client_id', '', 'astrolabe-client'],
			['mcp_server_public_url', '', 'https://mcp.example.com'],
			['mcp_server_url', '', ''],
		]);

		// Dispatch must happen at most once across both calls.
		$this->eventDispatcher->expects($this->once())
			->method('dispatchTyped')
			->willReturnCallback(fn (TokenGenerationRequestEvent $event) => $event->setAccessToken('cached'));

		$first = $this->minter->mintForUser('alice');
		$second = $this->minter->mintForUser('alice');
		$this->assertSame('cached', $first);
		$this->assertSame('cached', $second);
	}

	public function testThrowsWhenClientIdMissing(): void {
		$this->config->method('getSystemValue')->willReturnMap([
			['astrolabe_client_id', '', ''],
		]);

		// Should never get as far as dispatching the event.
		$this->eventDispatcher->expects($this->never())->method('dispatchTyped');

		$this->expectException(McpTokenMintException::class);
		$this->expectExceptionMessageMatches('/astrolabe_client_id/');
		$this->minter->mintForUser('alice');
	}

	public function testThrowsWhenListenerSetsNoToken(): void {
		$this->config->method('getSystemValue')->willReturnMap([
			['astrolabe_client_id', '', 'astrolabe-client'],
			['mcp_server_public_url', '', 'https://mcp.example.com'],
			['mcp_server_url', '', ''],
		]);

		// Listener does nothing — getAccessToken() returns null.
		$this->eventDispatcher->expects($this->once())->method('dispatchTyped');

		// Failure should be loud enough for an admin to diagnose.
		$this->logger->expects($this->once())->method('error');

		$this->expectException(McpTokenMintException::class);
		$this->expectExceptionMessageMatches("/'astrolabe-client'/");
		$this->minter->mintForUser('alice');
	}

	public function testForwardsExtraScopes(): void {
		$this->config->method('getSystemValue')->willReturnMap([
			['astrolabe_client_id', '', 'astrolabe-client'],
			['mcp_server_public_url', '', 'https://mcp.example.com'],
			['mcp_server_url', '', ''],
		]);

		$this->eventDispatcher->expects($this->once())
			->method('dispatchTyped')
			->willReturnCallback(function (TokenGenerationRequestEvent $event): void {
				$this->assertSame('mcp:webhooks', $event->getExtraScopes());
				$event->setAccessToken('scoped');
			});

		$this->minter->mintForUser('alice', 'mcp:webhooks');
	}
}
