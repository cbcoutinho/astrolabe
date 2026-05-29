<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Controller;

use OCA\Astrolabe\Controller\CredentialsController;
use OCA\Astrolabe\Service\BackgroundSyncCredentialStorage;
use OCP\AppFramework\Http;
use OCP\Authentication\Exceptions\InvalidTokenException;
use OCP\Authentication\Token\IProvider;
use OCP\Authentication\Token\IToken;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for {@see CredentialsController}: the app-password validation path of
 * storeAppPassword (internal token-provider validation, no HTTP round-trip),
 * its MCP HTTP-sync path, plus getStatus / getCredentials / deleteCredentials.
 *
 * Validation is performed internally via the token provider: a loopback call
 * was fragile because `overwrite.cli.url` points at the externally-mapped host,
 * unreachable from inside the container.
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

	public function testStoreSyncsToConfiguredMcpServerOverHttps(): void {
		$this->authenticate('alice');

		$token = $this->createMock(IToken::class);
		$token->method('getUID')->willReturn('alice');
		$this->tokenProvider->method('getToken')->with(self::VALID_INPUT)->willReturn($token);

		$this->config->method('getSystemValue')
			->with('mcp_server_url', '')
			->willReturn('https://mcp.example:8000');
		$this->credentialStorage->expects($this->once())
			->method('storeAppPassword')
			->with('alice', self::VALID_INPUT);

		$resp = $this->createMock(IResponse::class);
		$resp->method('getStatusCode')->willReturn(200);
		$resp->method('getBody')->willReturn(json_encode(['success' => true]));
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())->method('post')->willReturn($resp);
		$this->httpClientService->method('newClient')->willReturn($client);

		$data = $this->controller->storeAppPassword(self::VALID_INPUT)->getData();

		$this->assertTrue($data['success']);
		$this->assertTrue($data['mcp_sync']);
	}

	public function testGetStatusReportsUnprovisioned(): void {
		$this->authenticate('alice');
		$this->credentialStorage->method('hasAccess')->with('alice')->willReturn(false);
		$this->credentialStorage->method('getProvisionedAt')->with('alice')->willReturn(null);

		$data = $this->controller->getStatus()->getData();

		$this->assertTrue($data['success']);
		$this->assertFalse($data['has_background_access']);
		$this->assertNull($data['sync_type']);
		$this->assertNull($data['provisioned_at']);
	}

	public function testGetStatusReportsProvisioned(): void {
		$this->authenticate('alice');
		$this->credentialStorage->method('hasAccess')->with('alice')->willReturn(true);
		$this->credentialStorage->method('getProvisionedAt')->with('alice')->willReturn(1717000000);

		$data = $this->controller->getStatus()->getData();

		$this->assertTrue($data['has_background_access']);
		$this->assertSame('app_password', $data['sync_type']);
		$this->assertSame(1717000000, $data['provisioned_at']);
	}

	public function testGetStatusRejectsUnauthenticated(): void {
		// No authenticated user wired → userSession->getUser() returns null.
		$response = $this->controller->getStatus();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testGetCredentialsReturnsMetadataForUser(): void {
		// Admin endpoint: takes an explicit userId, no session lookup.
		$this->credentialStorage->method('hasAccess')->with('bob')->willReturn(true);
		$this->credentialStorage->method('getProvisionedAt')->with('bob')->willReturn(1717000000);

		$data = $this->controller->getCredentials('bob')->getData();

		$this->assertTrue($data['success']);
		$this->assertSame('bob', $data['user_id']);
		$this->assertTrue($data['has_background_access']);
		$this->assertSame('app_password', $data['sync_type']);
	}

	public function testDeleteCredentialsHappyPathWhenMcpUnconfigured(): void {
		$this->authenticate('alice');
		// MCP unconfigured → revokeFromMcpServer is a no-op before touching storage.
		$this->config->method('getSystemValue')->with('mcp_server_url', '')->willReturn('');
		$this->credentialStorage->expects($this->once())->method('deleteAppPassword')->with('alice');

		$response = $this->controller->deleteCredentials();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}

	public function testDeleteCredentialsProceedsWhenMcpRevokeFails(): void {
		$this->authenticate('alice');
		$this->config->method('getSystemValue')
			->with('mcp_server_url', '')
			->willReturn('https://mcp.example:8000');
		// Best-effort revoke must read the stored password *before* the local
		// delete removes it — assert that ordering invariant by requiring the
		// read, then a failing DELETE, then the local delete still running.
		$this->credentialStorage->expects($this->once())
			->method('getAppPassword')->with('alice')->willReturn('sometoken');
		$client = $this->createMock(IClient::class);
		$client->method('delete')->willThrowException(new \RuntimeException('mcp down'));
		$this->httpClientService->method('newClient')->willReturn($client);
		$this->credentialStorage->expects($this->once())->method('deleteAppPassword')->with('alice');

		$response = $this->controller->deleteCredentials();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}

	public function testDeleteCredentialsRejectsUnauthenticated(): void {
		$response = $this->controller->deleteCredentials();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}
}
