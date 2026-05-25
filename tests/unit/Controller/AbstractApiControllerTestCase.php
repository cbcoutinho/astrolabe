<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Controller;

use OCA\Astrolabe\Controller\ApiController;
use OCA\Astrolabe\Service\IdpTokenRefresher;
use OCA\Astrolabe\Service\McpServerClient;
use OCA\Astrolabe\Service\McpTokenStorage;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Common scaffolding for ApiController tests: the controller's
 * dependency mocks, a constructed controller instance, and two helper
 * methods that recur across the per-concern test slices.
 *
 * Concrete tests should extend this class and only override setUp() if
 * they need additional fixture wiring on top of the shared baseline
 * (e.g. WebhookProvisioningTest assumes an already-authenticated admin
 * with a working access token).
 */
abstract class AbstractApiControllerTestCase extends TestCase {
	protected McpServerClient&MockObject $client;
	protected IUserSession&MockObject $userSession;
	protected IURLGenerator&MockObject $urlGenerator;
	protected LoggerInterface&MockObject $logger;
	protected McpTokenStorage&MockObject $tokenStorage;
	protected IConfig&MockObject $config;
	protected IdpTokenRefresher&MockObject $tokenRefresher;
	protected IGroupManager&MockObject $groupManager;
	protected ApiController $controller;

	protected function setUp(): void {
		parent::setUp();

		$request = $this->createMock(IRequest::class);
		$this->client = $this->createMock(McpServerClient::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->tokenStorage = $this->createMock(McpTokenStorage::class);
		$this->config = $this->createMock(IConfig::class);
		$this->tokenRefresher = $this->createMock(IdpTokenRefresher::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		$this->controller = new ApiController(
			'astrolabe',
			$request,
			$this->client,
			$this->userSession,
			$this->urlGenerator,
			$this->logger,
			$this->tokenStorage,
			$this->config,
			$this->tokenRefresher,
			$this->groupManager,
		);
	}

	/**
	 * Wire an authenticated, admin user. Tests needing a non-admin or
	 * unauthenticated context skip this and mock IUserSession /
	 * IGroupManager directly.
	 */
	protected function authenticateAdmin(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with('admin')->willReturn(true);
	}

	/**
	 * Pass-through withTokenLock that just invokes the callable so
	 * tests exercise the locked region without needing a real locking
	 * provider.
	 */
	protected function passthroughLock(): void {
		$this->tokenStorage->method('withTokenLock')
			->willReturnCallback(fn ($userId, $callback) => $callback());
	}
}
