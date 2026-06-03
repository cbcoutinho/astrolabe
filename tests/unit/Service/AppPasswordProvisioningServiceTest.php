<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Service;

use OC\Authentication\Token\IProvider;
use OCA\Astrolabe\Service\AppPasswordProvisioningService;
use OCP\Authentication\Token\IToken;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for {@see AppPasswordProvisioningService}: minting via the
 * server-internal token provider, revoking, and the MCP HTTP wire contract
 * shared by self-service and admin provisioning.
 */
class AppPasswordProvisioningServiceTest extends TestCase {
	private IProvider&MockObject $tokenProvider;
	private ISecureRandom&MockObject $random;
	private IConfig&MockObject $config;
	private IClientService&MockObject $httpClientService;
	private LoggerInterface&MockObject $logger;
	private AppPasswordProvisioningService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->tokenProvider = $this->createMock(IProvider::class);
		$this->random = $this->createMock(ISecureRandom::class);
		$this->config = $this->createMock(IConfig::class);
		$this->httpClientService = $this->createMock(IClientService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new AppPasswordProvisioningService(
			$this->tokenProvider,
			$this->random,
			$this->config,
			$this->httpClientService,
			$this->logger,
		);
	}

	public function testMintGeneratesPasswordlessPermanentTokenAndReturnsIt(): void {
		$this->random->method('generate')->willReturn('RANDOM-72-CHAR-TOKEN');

		$this->tokenProvider->expects($this->once())
			->method('generateToken')
			->with(
				'RANDOM-72-CHAR-TOKEN',
				'bob',
				'bob',
				null,
				'Astrolabe background sync (admin)',
				IToken::PERMANENT_TOKEN,
				IToken::DO_NOT_REMEMBER,
			);

		$result = $this->service->mintAppPasswordForUser('bob', 'bob', 'Astrolabe background sync (admin)');

		$this->assertSame('RANDOM-72-CHAR-TOKEN', $result);
	}

	public function testRevokeTokenInvalidates(): void {
		$this->tokenProvider->expects($this->once())
			->method('invalidateToken')
			->with('tok');

		$this->service->revokeToken('tok');
	}

	public function testRevokeTokenSwallowsFailure(): void {
		$this->tokenProvider->method('invalidateToken')
			->willThrowException(new \RuntimeException('boom'));

		// Must not propagate — deprovision is best-effort on this step.
		$this->service->revokeToken('tok');
		$this->addToAssertionCount(1);
	}

	public function testSyncToMcpReturnsPartialWhenUnconfigured(): void {
		$this->config->method('getSystemValue')->with('mcp_server_url', '')->willReturn('');
		$this->httpClientService->expects($this->never())->method('newClient');

		$result = $this->service->syncToMcp('alice', 'alice', 'tok');

		$this->assertFalse($result['mcp_sync']);
		$this->assertTrue($result['partial_success']);
	}

	public function testSyncToMcpSucceedsOverHttps(): void {
		$this->config->method('getSystemValue')
			->with('mcp_server_url', '')
			->willReturn('https://mcp.example:8000');

		$resp = $this->createMock(IResponse::class);
		$resp->method('getStatusCode')->willReturn(200);
		$resp->method('getBody')->willReturn(json_encode(['success' => true]));
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())->method('post')->willReturn($resp);
		$this->httpClientService->method('newClient')->willReturn($client);

		$result = $this->service->syncToMcp('alice', 'alice', 'tok');

		$this->assertTrue($result['mcp_sync']);
	}

	/**
	 * For OIDC-provisioned users the UID (display name) differs from the
	 * loginName. Nextcloud keys app-password BasicAuth on the loginName, so the
	 * MCP server must receive the loginName in the body — while the BasicAuth
	 * user and URL path stay the UID (the identity key). Regression guard for
	 * the "App password validation failed: HTTP 401" provisioning failure.
	 */
	public function testSyncToMcpSendsLoginNameNotUid(): void {
		$this->config->method('getSystemValue')
			->with('mcp_server_url', '')
			->willReturn('https://mcp.example:8000');

		$resp = $this->createMock(IResponse::class);
		$resp->method('getStatusCode')->willReturn(200);
		$resp->method('getBody')->willReturn(json_encode(['success' => true]));
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('post')
			->with(
				// Path segment is the URL-encoded UID, not the loginName.
				$this->stringContains('/api/v1/users/Chris%20Coutinho/app-password'),
				$this->callback(function (array $opts): bool {
					// BasicAuth user is the UID; body username is the loginName.
					$this->assertSame(['Chris Coutinho', 'tok'], $opts['auth']);
					$this->assertSame(
						['username' => 'chris@coutinho.io'],
						json_decode($opts['body'], true),
					);
					return true;
				}),
			)
			->willReturn($resp);
		$this->httpClientService->method('newClient')->willReturn($client);

		$result = $this->service->syncToMcp('Chris Coutinho', 'chris@coutinho.io', 'tok');

		$this->assertTrue($result['mcp_sync']);
	}

	public function testSyncToMcpReportsPartialOnRejection(): void {
		$this->config->method('getSystemValue')
			->with('mcp_server_url', '')
			->willReturn('https://mcp.example:8000');

		$resp = $this->createMock(IResponse::class);
		$resp->method('getStatusCode')->willReturn(401);
		$resp->method('getBody')->willReturn(json_encode(['error' => 'bad creds']));
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturn($resp);
		$this->httpClientService->method('newClient')->willReturn($client);

		$result = $this->service->syncToMcp('alice', 'alice', 'tok');

		$this->assertFalse($result['mcp_sync']);
		$this->assertTrue($result['partial_success']);
		$this->assertSame('bad creds', $result['mcp_error']);
	}

	public function testRevokeFromMcpDeletes(): void {
		$this->config->method('getSystemValue')
			->with('mcp_server_url', '')
			->willReturn('https://mcp.example:8000');

		$resp = $this->createMock(IResponse::class);
		$resp->method('getStatusCode')->willReturn(204);
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('delete')
			->with(
				$this->stringContains('/api/v1/users/alice/app-password'),
				$this->callback(function (array $opts): bool {
					$this->assertSame(['alice', 'tok'], $opts['auth']);
					return true;
				}),
			)
			->willReturn($resp);
		$this->httpClientService->method('newClient')->willReturn($client);

		$this->service->revokeFromMcp('alice', 'tok');
		$this->addToAssertionCount(1);
	}

	public function testRevokeFromMcpSwallowsFailure(): void {
		$this->config->method('getSystemValue')
			->with('mcp_server_url', '')
			->willReturn('https://mcp.example:8000');

		$client = $this->createMock(IClient::class);
		$client->method('delete')->willThrowException(new \RuntimeException('mcp down'));
		$this->httpClientService->method('newClient')->willReturn($client);

		// Best-effort: a failing remote delete must not throw.
		$this->service->revokeFromMcp('alice', 'tok');
		$this->addToAssertionCount(1);
	}

	public function testRevokeFromMcpSkipsWhenUnconfigured(): void {
		$this->config->method('getSystemValue')->with('mcp_server_url', '')->willReturn('');
		$this->httpClientService->expects($this->never())->method('newClient');

		$this->service->revokeFromMcp('alice', 'tok');
		$this->addToAssertionCount(1);
	}
}
