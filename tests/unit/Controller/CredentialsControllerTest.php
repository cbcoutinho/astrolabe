<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Controller;

use OCA\Astrolabe\Controller\CredentialsController;
use OCA\Astrolabe\Service\AppPasswordProvisioningService;
use OCA\Astrolabe\Service\BackgroundSyncCredentialStorage;
use OCP\AppFramework\Http;
use OCP\Authentication\Exceptions\InvalidTokenException;
use OCP\Authentication\Token\IProvider;
use OCP\Authentication\Token\IToken;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for {@see CredentialsController}.
 *
 * The controller owns request/session handling and the self-provisioning
 * gate; minting and the MCP HTTP wire contract live in
 * {@see AppPasswordProvisioningService} (covered by its own test), which is
 * mocked here. App-password validation is performed internally via the public
 * token provider (no HTTP round-trip): a loopback call was fragile because
 * `overwrite.cli.url` points at the externally-mapped host, unreachable from
 * inside the container.
 */
class CredentialsControllerTest extends TestCase {
	// A well-formed value matching the store endpoint's ^[a-zA-Z0-9-]{20,256}$
	// shape check. It is a test fixture, not a credential: the token provider
	// is mocked, so this never authenticates anything.
	private const VALID_INPUT = 'aaaaaaaaaaaaaaaaaaaaaa';

	private BackgroundSyncCredentialStorage&MockObject $credentialStorage;
	private IUserSession&MockObject $userSession;
	private LoggerInterface&MockObject $logger;
	private IAppConfig&MockObject $appConfig;
	private IProvider&MockObject $tokenProvider;
	private AppPasswordProvisioningService&MockObject $provisioning;
	private IUserManager&MockObject $userManager;
	private CredentialsController $controller;

	protected function setUp(): void {
		parent::setUp();

		$request = $this->createMock(IRequest::class);
		$this->credentialStorage = $this->createMock(BackgroundSyncCredentialStorage::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->tokenProvider = $this->createMock(IProvider::class);
		$this->provisioning = $this->createMock(AppPasswordProvisioningService::class);
		$this->userManager = $this->createMock(IUserManager::class);

		$this->controller = new CredentialsController(
			'astrolabe',
			$request,
			$this->credentialStorage,
			$this->userSession,
			$this->logger,
			$this->appConfig,
			$this->tokenProvider,
			$this->provisioning,
			$this->userManager,
		);
	}

	private function authenticate(string $uid = 'alice'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	/** Allow (default) or block self-service provisioning via the app config. */
	private function allowSelfProvision(bool $allowed = true): void {
		$this->appConfig->method('getValueBool')
			->with('astrolabe', 'allow_user_self_provision', true)
			->willReturn($allowed);
	}

	public function testStoreSucceedsAndDelegatesMcpSync(): void {
		$this->authenticate('alice');
		$this->allowSelfProvision();

		$token = $this->createMock(IToken::class);
		$token->method('getUID')->willReturn('alice');
		$token->method('getLoginName')->willReturn('alice');
		$this->tokenProvider->expects($this->once())
			->method('getToken')
			->with(self::VALID_INPUT)
			->willReturn($token);

		$this->credentialStorage->expects($this->once())
			->method('storeAppPassword')
			->with('alice', self::VALID_INPUT);

		// The MCP wire contract is the service's concern; the controller only
		// delegates and merges the structured result.
		$this->provisioning->expects($this->once())
			->method('syncToMcp')
			->with('alice', 'alice', self::VALID_INPUT)
			->willReturn(['mcp_sync' => true, 'partial_success' => false, 'message' => 'ok']);

		$response = $this->controller->storeAppPassword(self::VALID_INPUT);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
		$this->assertTrue($response->getData()['mcp_sync']);
	}

	public function testStorePassesValidatedLoginNameToService(): void {
		// OIDC user: UID (display name) differs from loginName. The loginName
		// resolved during validation must reach the service unchanged.
		$this->authenticate('Chris Coutinho');
		$this->allowSelfProvision();

		$token = $this->createMock(IToken::class);
		$token->method('getUID')->willReturn('Chris Coutinho');
		$token->method('getLoginName')->willReturn('chris@coutinho.io');
		$this->tokenProvider->method('getToken')->with(self::VALID_INPUT)->willReturn($token);

		$this->provisioning->expects($this->once())
			->method('syncToMcp')
			->with('Chris Coutinho', 'chris@coutinho.io', self::VALID_INPUT)
			->willReturn(['mcp_sync' => true, 'partial_success' => false, 'message' => 'ok']);

		$data = $this->controller->storeAppPassword(self::VALID_INPUT)->getData();

		$this->assertTrue($data['success']);
	}

	public function testStoreBlockedWhenSelfProvisionDisabled(): void {
		$this->authenticate('alice');
		$this->allowSelfProvision(false);

		// Neither validation nor storage nor MCP sync may run when disabled.
		$this->tokenProvider->expects($this->never())->method('getToken');
		$this->credentialStorage->expects($this->never())->method('storeAppPassword');
		$this->provisioning->expects($this->never())->method('syncToMcp');

		$response = $this->controller->storeAppPassword(self::VALID_INPUT);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testStoreRejectsTokenOwnedByDifferentUser(): void {
		$this->authenticate('alice');
		$this->allowSelfProvision();

		$token = $this->createMock(IToken::class);
		$token->method('getUID')->willReturn('mallory');
		$this->tokenProvider->method('getToken')->willReturn($token);

		$this->credentialStorage->expects($this->never())->method('storeAppPassword');
		$this->provisioning->expects($this->never())->method('syncToMcp');

		$response = $this->controller->storeAppPassword(self::VALID_INPUT);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testStoreRejectsUnrecognisedToken(): void {
		$this->authenticate('alice');
		$this->allowSelfProvision();

		$this->tokenProvider->method('getToken')
			->willThrowException(new InvalidTokenException('nope'));

		$this->credentialStorage->expects($this->never())->method('storeAppPassword');

		$response = $this->controller->storeAppPassword(self::VALID_INPUT);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testStoreRejectsMalformedPasswordWithoutTokenLookup(): void {
		$this->authenticate('alice');
		$this->allowSelfProvision();

		// Validation must never be attempted for an obviously malformed token.
		$this->tokenProvider->expects($this->never())->method('getToken');
		$this->credentialStorage->expects($this->never())->method('storeAppPassword');

		$response = $this->controller->storeAppPassword('short');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testStoreRejectsUnauthenticated(): void {
		$response = $this->controller->storeAppPassword(self::VALID_INPUT);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
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

	public function testDeleteCredentialsRevokesFromMcpThenClearsLocal(): void {
		$this->authenticate('alice');
		$this->allowSelfProvision();
		$this->credentialStorage->method('getAppPassword')->with('alice')->willReturn('sometoken');
		// Self-service delete revokes the MCP copy but does NOT invalidate the
		// Nextcloud token (the user owns it) — only an admin deprovision does.
		$this->provisioning->expects($this->once())->method('revokeFromMcp')->with('alice', 'sometoken');
		$this->provisioning->expects($this->never())->method('revokeToken');
		$this->credentialStorage->expects($this->once())->method('deleteAppPassword')->with('alice');

		$response = $this->controller->deleteCredentials();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}

	public function testDeleteCredentialsSkipsMcpWhenNoStoredPassword(): void {
		$this->authenticate('alice');
		$this->allowSelfProvision();
		$this->credentialStorage->method('getAppPassword')->with('alice')->willReturn(null);
		$this->provisioning->expects($this->never())->method('revokeFromMcp');
		$this->credentialStorage->expects($this->once())->method('deleteAppPassword')->with('alice');

		$response = $this->controller->deleteCredentials();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}

	public function testDeleteBlockedWhenSelfProvisionDisabled(): void {
		$this->authenticate('alice');
		$this->allowSelfProvision(false);

		// Symmetric with storeAppPassword: when an admin manages provisioning,
		// self-service revoke is blocked — neither the MCP copy nor the local
		// credential may be touched.
		$this->provisioning->expects($this->never())->method('revokeFromMcp');
		$this->credentialStorage->expects($this->never())->method('deleteAppPassword');

		$response = $this->controller->deleteCredentials();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testDeleteCredentialsRejectsUnauthenticated(): void {
		$response = $this->controller->deleteCredentials();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	// -----------------------------------------------------------------
	// Admin provisioning
	// -----------------------------------------------------------------

	public function testAdminListProvisioningReturnsAllUsers(): void {
		$alice = $this->createMock(IUser::class);
		$alice->method('getUID')->willReturn('alice');
		$alice->method('getDisplayName')->willReturn('Alice');
		$bob = $this->createMock(IUser::class);
		$bob->method('getUID')->willReturn('bob');
		$bob->method('getDisplayName')->willReturn('Bob');

		// callForAllUsers invokes the callback once per user.
		$this->userManager->method('callForAllUsers')
			->willReturnCallback(function (\Closure $cb) use ($alice, $bob): void {
				$cb($alice);
				$cb($bob);
			});
		// Presence is derived from the provisioned-at timestamp (no hasAccess /
		// decryption per user).
		$this->credentialStorage->method('getProvisionedAt')->willReturnMap([
			['alice', 1717000000],
			['bob', null],
		]);
		$this->appConfig->method('getValueBool')
			->with('astrolabe', 'allow_user_self_provision', true)
			->willReturn(false);

		$data = $this->controller->adminListProvisioning()->getData();

		$this->assertTrue($data['success']);
		$this->assertFalse($data['capped']);
		$this->assertFalse($data['self_provision_allowed']);
		$this->assertCount(2, $data['users']);
		$this->assertSame('alice', $data['users'][0]['uid']);
		$this->assertTrue($data['users'][0]['has_background_access']);
		$this->assertFalse($data['users'][1]['has_background_access']);
	}

	public function testAdminProvisionUserMintsStoresAndSyncs(): void {
		$user = $this->createMock(IUser::class);
		$this->userManager->method('get')->with('bob')->willReturn($user);
		// Fresh provision (no existing credential) → no pre-revoke / clear.
		$this->credentialStorage->method('getAppPassword')->with('bob')->willReturn(null);
		$this->provisioning->expects($this->never())->method('revokeFromMcp');
		$this->provisioning->expects($this->never())->method('revokeToken');

		$this->provisioning->expects($this->once())
			->method('mintAppPasswordForUser')
			->with('bob', 'bob', AppPasswordProvisioningService::ADMIN_TOKEN_NAME)
			->willReturn('minted-token');
		$this->credentialStorage->expects($this->once())
			->method('storeAppPassword')
			->with('bob', 'minted-token');
		$this->provisioning->expects($this->once())
			->method('syncToMcp')
			->with('bob', 'bob', 'minted-token')
			->willReturn(['mcp_sync' => true, 'partial_success' => false, 'message' => 'ok']);

		$data = $this->controller->adminProvisionUser('bob')->getData();

		$this->assertTrue($data['success']);
		$this->assertSame('bob', $data['user_id']);
		$this->assertTrue($data['mcp_sync']);
	}

	public function testAdminProvisionUserRevokesExistingBeforeMinting(): void {
		// Re-provisioning an already-provisioned user must revoke the previous
		// credential first, so the old Nextcloud token is not left orphaned.
		$user = $this->createMock(IUser::class);
		$this->userManager->method('get')->with('bob')->willReturn($user);
		$this->credentialStorage->method('getAppPassword')->with('bob')->willReturn('old-token');

		$this->provisioning->expects($this->once())->method('revokeFromMcp')->with('bob', 'old-token');
		$this->provisioning->expects($this->once())->method('revokeToken')->with('old-token');
		// The stale local record is cleared in the pre-revoke step.
		$this->credentialStorage->expects($this->once())->method('deleteAppPassword')->with('bob');
		$this->provisioning->expects($this->once())
			->method('mintAppPasswordForUser')
			->with('bob', 'bob', AppPasswordProvisioningService::ADMIN_TOKEN_NAME)
			->willReturn('new-token');
		$this->credentialStorage->expects($this->once())->method('storeAppPassword')->with('bob', 'new-token');
		$this->provisioning->method('syncToMcp')
			->willReturn(['mcp_sync' => true, 'partial_success' => false, 'message' => 'ok']);

		$data = $this->controller->adminProvisionUser('bob')->getData();

		$this->assertTrue($data['success']);
	}

	public function testAdminProvisionUserRevokesMintedTokenWhenStoreFails(): void {
		// If local storage fails after minting, the fresh Nextcloud token must
		// be revoked so it isn't orphaned (invisible to the admin UI).
		$user = $this->createMock(IUser::class);
		$this->userManager->method('get')->with('bob')->willReturn($user);
		$this->credentialStorage->method('getAppPassword')->with('bob')->willReturn(null);
		$this->provisioning->method('mintAppPasswordForUser')->willReturn('minted-token');
		$this->credentialStorage->method('storeAppPassword')
			->willThrowException(new \RuntimeException('disk full'));

		$this->provisioning->expects($this->once())->method('revokeToken')->with('minted-token');
		$this->provisioning->expects($this->never())->method('syncToMcp');

		$response = $this->controller->adminProvisionUser('bob');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testAdminProvisionUserRejectsUnknownUser(): void {
		$this->userManager->method('get')->with('ghost')->willReturn(null);
		$this->provisioning->expects($this->never())->method('mintAppPasswordForUser');

		$response = $this->controller->adminProvisionUser('ghost');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testAdminDeprovisionUserRevokesEverything(): void {
		$this->credentialStorage->method('getAppPassword')->with('bob')->willReturn('tok');
		$this->provisioning->expects($this->once())->method('revokeFromMcp')->with('bob', 'tok');
		$this->provisioning->expects($this->once())->method('revokeToken')->with('tok');
		$this->credentialStorage->expects($this->once())->method('deleteAppPassword')->with('bob');

		$data = $this->controller->adminDeprovisionUser('bob')->getData();

		$this->assertTrue($data['success']);
		$this->assertSame('bob', $data['user_id']);
	}

	public function testAdminSetSelfProvisionPersistsFlag(): void {
		$this->appConfig->expects($this->once())
			->method('setValueBool')
			->with('astrolabe', 'allow_user_self_provision', false);

		$data = $this->controller->adminSetSelfProvision(false)->getData();

		$this->assertTrue($data['success']);
		$this->assertFalse($data['self_provision_allowed']);
	}
}
