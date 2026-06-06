<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Controller;

use OCA\Astrolabe\Controller\ApiController;
use OCA\Astrolabe\Service\McpServerClient;
use OCA\Astrolabe\Service\McpTokenMinter;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Common scaffolding for ApiController tests: the controller's
 * dependency mocks and a constructed instance.
 *
 * After the auth refactor, the controller mints session-derived JWTs
 * via McpTokenMinter on every request — there is no token storage and
 * no refresher to mock.
 */
abstract class AbstractApiControllerTestCase extends TestCase {
	protected McpServerClient&MockObject $client;
	protected IUserSession&MockObject $userSession;
	protected LoggerInterface&MockObject $logger;
	protected McpTokenMinter&MockObject $tokenMinter;
	protected IConfig&MockObject $config;
	protected IAppConfig&MockObject $appConfig;
	protected ApiController $controller;

	protected function setUp(): void {
		parent::setUp();

		$request = $this->createMock(IRequest::class);
		$this->client = $this->createMock(McpServerClient::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->tokenMinter = $this->createMock(McpTokenMinter::class);
		$this->config = $this->createMock(IConfig::class);
		$this->appConfig = $this->createMock(IAppConfig::class);

		$this->controller = new ApiController(
			'astrolabe',
			$request,
			$this->client,
			$this->userSession,
			$this->logger,
			$this->tokenMinter,
			$this->config,
			$this->appConfig,
		);
	}

	/**
	 * Wire an authenticated user (defaults to UID 'admin') and configure
	 * the minter to issue a fixed token for them.
	 */
	protected function authenticateUserWithToken(string $uid = 'admin', string $token = 'mint-token'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->tokenMinter->method('mintForUser')->with($uid)->willReturn($token);
	}
}
