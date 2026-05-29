<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Controller;

use OCA\Astrolabe\Controller\CredentialsController;
use OCA\Astrolabe\Service\BackgroundSyncCredentialStorage;
use OCP\AppFramework\Http;
use OCP\Authentication\Exceptions\InvalidTokenException;
use OCP\Authentication\Token\IProvider;
use OCP\Authentication\Token\IToken;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for {@see CredentialsController::storeAppPassword}, focusing on the
 * app-password validation path.
 *
 * Validation is performed internally via the token provider (no HTTP
 * round-trip): a loopback call was fragile because `overwrite.cli.url` points
 * at the externally-mapped host, unreachable from inside the container.
 */
class CredentialsControllerTest extends TestCase {
	// A well-formed value matching the store endpoint's ^[a-zA-Z0-9-]{20,256}$
	// shape check. It is a test fixture, not a credential: the token provider
	// is mocked, so this never authenticates anything.
	private const VALID_INPUT = 'aaaaaaaaaaaaaaaaaaaaaa';

	private BackgroundSyncCredentialStorage&MockObject $credentialStorage;
	private IUserSession&MockObject $userSession;
	private LoggerInterface&MockObject $logger;
	private IConfig&MockObject $config;
	private IClientService&MockObject $httpClientService;
	private IProvider&MockObject $tokenProvider;
	private CredentialsController $controller;

	protected function setUp(): void {
		parent::setUp();

		$request = $this->createMock(IRequest::class);
		$this->credentialStorage = $this->createMock(BackgroundSyncCredentialStorage::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->config = $this->createMock(IConfig::class);
		$this->httpClientService = $this->createMock(IClientService::class);
		$this->tokenProvider = $this->createMock(IProvider::class);

		$this->controller = new CredentialsController(
			'astrolabe',
			$request,
			$this->credentialStorage,
			$this->userSession,
			$this->logger,
			$this->config,
			$this->httpClientService,
			$this->tokenProvider,
		);
	}

	private function authenticate(string $uid = 'alice'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	public function testStoreSucceedsWhenTokenBelongsToUser(): void {
		$this->authenticate('alice');

		$token = $this->createMock(IToken::class);
		$token->method('getUID')->willReturn('alice');
		$this->tokenProvider->expects($this->once())
			->method('getToken')
			->with(self::VALID_INPUT)
			->willReturn($token);

		// MCP server unconfigured → stores locally and returns success.
		$this->config->method('getSystemValue')->with('mcp_server_url', '')->willReturn('');
		$this->credentialStorage->expects($this->once())
			->method('storeAppPassword')
			->with('alice', self::VALID_INPUT);
		// No HTTP round-trip is made for validation.
		$this->httpClientService->expects($this->never())->method('newClient');

		$response = $this->controller->storeAppPassword(self::VALID_INPUT);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}

	public function testStoreRejectsTokenOwnedByDifferentUser(): void {
		$this->authenticate('alice');

		$token = $this->createMock(IToken::class);
		$token->method('getUID')->willReturn('mallory');
		$this->tokenProvider->method('getToken')->willReturn($token);

		$this->credentialStorage->expects($this->never())->method('storeAppPassword');

		$response = $this->controller->storeAppPassword(self::VALID_INPUT);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testStoreRejectsUnrecognisedToken(): void {
		$this->authenticate('alice');

		$this->tokenProvider->method('getToken')
			->willThrowException(new InvalidTokenException('nope'));

		$this->credentialStorage->expects($this->never())->method('storeAppPassword');

		$response = $this->controller->storeAppPassword(self::VALID_INPUT);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testStoreRejectsMalformedPasswordWithoutTokenLookup(): void {
		$this->authenticate('alice');

		// Validation must never be attempted for an obviously malformed token.
		$this->tokenProvider->expects($this->never())->method('getToken');
		$this->credentialStorage->expects($this->never())->method('storeAppPassword');

		$response = $this->controller->storeAppPassword('short');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}
}
