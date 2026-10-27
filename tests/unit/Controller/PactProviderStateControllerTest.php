<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Controller;

use OCA\Astrolabe\Controller\PactProviderStateController;
use OCA\Astrolabe\Service\BackgroundSyncCredentialStorage;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PactProviderStateControllerTest extends TestCase {
	private IConfig&MockObject $config;
	private IAppConfig&MockObject $appConfig;
	private BackgroundSyncCredentialStorage&MockObject $credentialStorage;
	private PactProviderStateController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->credentialStorage = $this->createMock(BackgroundSyncCredentialStorage::class);
		$this->controller = new PactProviderStateController(
			'astrolabe',
			$this->createMock(IRequest::class),
			$this->config,
			$this->appConfig,
			$this->credentialStorage,
		);
	}

	private function enable(bool $enabled = true): void {
		$this->config->method('getSystemValueBool')
			->with(PactProviderStateController::SYSTEM_FLAG, false)
			->willReturn($enabled);
	}

	public function testIsNotFoundWithoutTheSystemFlag(): void {
		$this->enable(false);
		$this->credentialStorage->expects($this->never())->method($this->anything());
		$this->appConfig->expects($this->never())->method($this->anything());

		$response = $this->controller->handle('user alice has provisioned background-sync credentials');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testRejectsAnUnknownState(): void {
		$this->enable();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->handle('no such state')->getStatus());
	}

	public function testProvisionsAliceAndRemovesHerOnTeardown(): void {
		$this->enable();
		$state = 'user alice has provisioned background-sync credentials';
		$this->credentialStorage->expects($this->once())->method('storeAppPassword')
			->with('alice', $this->callback(fn (string $p): bool => strlen($p) >= 20));
		$this->credentialStorage->expects($this->once())->method('deleteAppPassword')->with('alice');

		$this->assertSame(Http::STATUS_OK, $this->controller->handle($state, 'setup')->getStatus());
		$this->assertSame(Http::STATUS_OK, $this->controller->handle($state, 'teardown')->getStatus());
	}

	public function testEnsuresBobHasNoCredentials(): void {
		$this->enable();
		$state = 'user bob has no background-sync credentials';
		$this->credentialStorage->expects($this->once())->method('deleteAppPassword')->with('bob');

		$this->controller->handle($state, 'setup');
		$this->controller->handle($state, 'teardown');
	}

	/** @return array<string, array{string}> */
	public static function actions(): array {
		return ['setup' => ['setup'], 'teardown' => ['teardown']];
	}

	#[DataProvider('actions')]
	public function testApprovedSourcesClearTheAdminDisabledSet(string $action): void {
		$this->enable();
		$this->appConfig->expects($this->once())->method('deleteKey')->with('astrolabe', 'disabled_search_sources');

		$this->controller->handle('astrolabe has approved file and note for semantic search', $action);
	}

	public function testDisablesEverySourceAndResetsOnTeardown(): void {
		$this->enable();
		$state = 'astrolabe has disabled all sources for semantic search';
		$this->appConfig->expects($this->once())->method('setValueString')
			->with('astrolabe', 'disabled_search_sources', '["notes","files","deck","news","mail","calendar","contacts"]');
		$this->appConfig->expects($this->once())->method('deleteKey')->with('astrolabe', 'disabled_search_sources');

		$this->controller->handle($state, 'setup');
		$this->controller->handle($state, 'teardown');
	}

	public function testNarrowsTheVerifierToFilesAndResetsOnTeardown(): void {
		$this->enable();
		$state = 'astrolabe reports effective sources for a user who disabled notes';
		$this->config->expects($this->once())->method('setUserValue')
			->with('admin', 'astrolabe', 'user_disabled_search_sources', '["notes","deck","news","mail","calendar","contacts"]');
		$this->config->expects($this->once())->method('deleteUserValue')
			->with('admin', 'astrolabe', 'user_disabled_search_sources');

		$this->controller->handle($state, 'setup');
		$this->controller->handle($state, 'teardown');
	}
}
