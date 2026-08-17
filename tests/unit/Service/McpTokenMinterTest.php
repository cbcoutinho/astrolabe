<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Service;

use OCA\Astrolabe\Service\McpTokenMinter;
use OCA\Astrolabe\Service\McpTokenMintException;
use OCA\OIDCIdentityProvider\Event\TokenGenerationRequestEvent;
use OCA\UserOIDC\Event\ExchangedTokenRequestedEvent;
use OCA\UserOIDC\Event\ExternalTokenRequestedEvent;
use OCA\UserOIDC\Model\Token;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class McpTokenMinterTest extends TestCase {
	private IEventDispatcher&MockObject $eventDispatcher;
	private IConfig&MockObject $config;
	private IAppManager&MockObject $appManager;
	private IUserManager&MockObject $userManager;
	private IUserSession&MockObject $userSession;
	private LoggerInterface&MockObject $logger;
	private McpTokenMinter $minter;

	protected function setUp(): void {
		parent::setUp();
		$this->eventDispatcher = $this->createMock(IEventDispatcher::class);
		$this->config = $this->createMock(IConfig::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		// Default deployment for the existing tests: Nextcloud is the IdP.
		$this->appManager->method('isEnabledForUser')
			->willReturnCallback(static fn (string $app): bool => $app === 'oidc');
		$this->minter = $this->buildMinter($this->appManager);
	}

	private function buildMinter(IAppManager&MockObject $appManager): McpTokenMinter {
		return new McpTokenMinter(
			$this->eventDispatcher,
			$this->config,
			$appManager,
			$this->userManager,
			$this->userSession,
			$this->logger,
		);
	}

	/**
	 * Rebuild the minter for the external-IdP deployment: `user_oidc` enabled,
	 * `oidc` absent, and `alice` signed in.
	 */
	private function useExternalIdp(?string $sessionUid = 'alice'): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')
			->willReturnCallback(static fn (string $app): bool => $app === 'user_oidc');

		if ($sessionUid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($sessionUid);
			$this->userSession->method('getUser')->willReturn($user);
		}

		$this->minter = $this->buildMinter($appManager);
	}

	private function configureClientId(string $clientId = 'astrolabe-client'): void {
		$this->config->method('getSystemValue')->willReturnMap([
			['astrolabe_client_id', '', $clientId],
			['mcp_server_public_url', '', 'https://mcp.example.com'],
			['mcp_server_url', '', ''],
		]);
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

	public function testExchangesTheLoginTokenWhenNextcloudIsAnOidcClient(): void {
		$this->useExternalIdp();
		$this->configureClientId('nextcloud-mcp-server');

		$this->eventDispatcher->expects($this->once())
			->method('dispatchTyped')
			->willReturnCallback(function (object $event): void {
				$this->assertInstanceOf(ExchangedTokenRequestedEvent::class, $event);
				/** @var ExchangedTokenRequestedEvent $event */
				$this->assertSame('nextcloud-mcp-server', $event->getTargetAudience());
				$this->assertSame(['semantic.read'], $event->getExtraScopes());
				$event->setToken(new Token('exchanged-token'));
			});

		$this->assertSame('exchanged-token', $this->minter->mintForUser('alice', 'semantic.read'));
	}

	public function testFallsBackToTheLoginTokenWhenExchangeIsUnavailable(): void {
		$this->useExternalIdp();
		$this->configureClientId('nextcloud-mcp-server');

		// IdPs that do not offer token exchange make user_oidc throw; the
		// user's own login token still authenticates them to the MCP server.
		$this->eventDispatcher->method('dispatchTyped')
			->willReturnCallback(function (object $event): void {
				if ($event instanceof ExchangedTokenRequestedEvent) {
					throw new \RuntimeException('token exchange not enabled');
				}
				$this->assertInstanceOf(ExternalTokenRequestedEvent::class, $event);
				/** @var ExternalTokenRequestedEvent $event */
				$event->setToken(new Token('login-token'));
			});

		$this->assertSame('login-token', $this->minter->mintForUser('alice'));
	}

	public function testExternalIdpRefusesToMintForSomeoneElse(): void {
		$this->useExternalIdp('bob');
		$this->configureClientId();

		// user_oidc only ever hands out the *session* user's token, so asking
		// for another user's must fail loudly rather than return bob's token.
		$this->eventDispatcher->expects($this->never())->method('dispatchTyped');

		$this->expectException(McpTokenMintException::class);
		$this->expectExceptionMessageMatches('/signed-in user/');
		$this->minter->mintForUser('alice');
	}

	public function testThrowsCleanlyWhenNeitherOidcAppIsEnabled(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturn(false);
		$this->minter = $this->buildMinter($appManager);
		$this->configureClientId();

		// GH #324: this used to be a PHP fatal ("Class ... not found"), which
		// surfaced as a 500 with no remediation.
		$this->expectException(McpTokenMintException::class);
		$this->expectExceptionMessageMatches('/user_oidc/');
		$this->minter->mintForUser('alice');
	}

	public function testResolvesAppEnablementAgainstTheTargetUserNotTheSession(): void {
		// Without an explicit IUser, IAppManager answers for the *session* user
		// — and with no session it only sees apps enabled for everyone. That
		// sends a group-restricted `oidc` install down the external-IdP branch
		// from sessionless callers like `occ astrolabe:mcp-probe`.
		$alice = $this->createMock(IUser::class);
		$this->userManager->method('get')->with('alice')->willReturn($alice);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->expects($this->once())
			->method('isEnabledForUser')
			->with('oidc', $alice)
			->willReturn(true);

		$this->minter = $this->buildMinter($appManager);
		$this->configureClientId();

		$this->eventDispatcher->expects($this->once())
			->method('dispatchTyped')
			->willReturnCallback(function (object $event): void {
				$this->assertInstanceOf(TokenGenerationRequestEvent::class, $event);
				/** @var TokenGenerationRequestEvent $event */
				$event->setAccessToken('minted-for-alice');
			});

		$this->assertSame('minted-for-alice', $this->minter->mintForUser('alice'));
	}
}
