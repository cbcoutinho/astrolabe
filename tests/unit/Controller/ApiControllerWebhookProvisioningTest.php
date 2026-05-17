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
 * Controller-level test for the 428 (Precondition Required) provisioning
 * path on the webhook admin endpoints.
 *
 * Asserts that when McpServerClient signals
 * ``provisioning_required => true`` (because the MCP server returned 428),
 * the controller responds with HTTP 428 and the same flag in the body so
 * AdminSettings.vue can render the authorization CTA.
 */
final class ApiControllerWebhookProvisioningTest extends TestCase {
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

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$this->userSession->method('getUser')->willReturn($user);

		// getAccessToken always returns a token so we can reach the MCP call.
		$this->tokenStorage->method('getAccessToken')->willReturn('access-token');

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

	public function testGetWebhookPresetsReturns428WhenProvisioningRequired(): void {
		$this->client->method('getInstalledApps')->willReturn([
			'error' => 'Nextcloud access not provisioned',
			'provisioning_required' => true,
		]);

		$response = $this->controller->getWebhookPresets();
		$data = $response->getData();

		$this->assertEquals(Http::STATUS_PRECONDITION_REQUIRED, $response->getStatus());
		$this->assertIsArray($data);
		$this->assertFalse($data['success']);
		$this->assertTrue($data['provisioning_required']);
		$this->assertEquals('Nextcloud access not provisioned', $data['error']);
	}

	public function testGetWebhookPresetsReturns428WhenListWebhooksReportsProvisioning(): void {
		// getInstalledApps succeeds, listWebhooks returns 428 marker.
		$this->client->method('getInstalledApps')->willReturn(['apps' => ['notes']]);
		$this->client->method('listWebhooks')->willReturn([
			'error' => 'Provisioning required',
			'provisioning_required' => true,
		]);

		$response = $this->controller->getWebhookPresets();
		$data = $response->getData();

		$this->assertEquals(Http::STATUS_PRECONDITION_REQUIRED, $response->getStatus());
		$this->assertTrue($data['provisioning_required']);
	}

	public function testGetWebhookPresetsReturns500OnGenericError(): void {
		$this->client->method('getInstalledApps')->willReturn([
			'error' => 'Connection refused',
		]);

		$response = $this->controller->getWebhookPresets();
		$data = $response->getData();

		$this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertArrayNotHasKey('provisioning_required', $data);
	}
}
