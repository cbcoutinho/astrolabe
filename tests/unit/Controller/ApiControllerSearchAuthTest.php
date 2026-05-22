<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Controller;

use OCA\Astrolabe\Controller\ApiController;
use OCA\Astrolabe\Service\IdpTokenRefresher;
use OCA\Astrolabe\Service\McpServerClient;
use OCA\Astrolabe\Service\McpTokenStorage;
use OCP\AppFramework\Http;
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
 * Pin the admin-only nature of `refresh_error` exposure on the search
 * endpoint. authRequiredBody() in ApiController gates the field on
 * IGroupManager::isAdmin; this test guards against regressions that
 * would leak IdP response detail to a regular user via search's 401
 * body.
 */
final class ApiControllerSearchAuthTest extends TestCase {
	private McpServerClient&MockObject $client;
	private IUserSession&MockObject $userSession;
	private IURLGenerator&MockObject $urlGenerator;
	private LoggerInterface&MockObject $logger;
	private McpTokenStorage&MockObject $tokenStorage;
	private IConfig&MockObject $config;
	private IdpTokenRefresher&MockObject $tokenRefresher;
	private IGroupManager&MockObject $groupManager;
	private ApiController $controller;

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

	public function testSearchOmitsRefreshErrorForNonAdmin(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with('alice')->willReturn(false);

		// Token resolution fails (refresh path exhausted).
		$this->tokenStorage->method('getAccessToken')->willReturn(null);

		// authRequiredBody() must short-circuit before consulting
		// getLastError() for a non-admin user. Assert by making any
		// call to it fail the test outright.
		$this->tokenRefresher->expects($this->never())
			->method('getLastError');

		$response = $this->controller->search('hello world');
		$data = $response->getData();

		$this->assertEquals(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertIsArray($data);
		$this->assertFalse($data['success']);
		$this->assertArrayNotHasKey('refresh_error', $data);
		$this->assertNotEmpty($data['error']);
	}

	public function testSearchIncludesRefreshErrorForAdmin(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with('admin')->willReturn(true);

		$this->tokenStorage->method('getAccessToken')->willReturn(null);
		$this->tokenRefresher->method('getLastError')
			->willReturn('IdP rejected refresh_token (HTTP 401). Detail: invalid_grant');

		$response = $this->controller->search('hello world');
		$data = $response->getData();

		$this->assertEquals(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertIsArray($data);
		$this->assertFalse($data['success']);
		$this->assertArrayHasKey('refresh_error', $data);
		$this->assertStringContainsString('invalid_grant', $data['refresh_error']);
	}
}
