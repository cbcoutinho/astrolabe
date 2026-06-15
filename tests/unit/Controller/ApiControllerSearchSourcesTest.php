<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Controller;

use OCA\Astrolabe\Controller\ApiController;
use OCA\Astrolabe\Service\SearchSources;
use OCA\Astrolabe\Settings\Admin;
use OCP\AppFramework\Http;
use OCP\IRequest;

/**
 * Tests for the admin search-sources endpoint and the search consent gate.
 *
 * Disabling a source must persist the choice AND purge the source's
 * already-indexed content (consent is binding on data-at-rest).
 */
final class ApiControllerSearchSourcesTest extends AbstractApiControllerTestCase {
	public function testDisablingSourcePersistsAndPurges(): void {
		$this->authenticateUserWithToken('admin', 'tok');
		// Nothing disabled before this save.
		$this->searchSources->method('getDisabledSources')->willReturn([]);
		$this->searchSources->method('installedSources')->willReturn([
			['app' => 'files', 'docTypes' => ['file'], 'label' => 'Files', 'enabled' => false],
		]);

		// Persists the new disabled-set as JSON.
		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('astrolabe', Admin::SETTING_DISABLED_SEARCH_SOURCES, '["files"]');

		// Eagerly purges the newly-disabled source's doc type(s).
		$this->client->expects($this->once())
			->method('purgeDocTypes')
			->with(['file'], 'tok')
			->willReturn(['purged' => ['file' => 4]]);

		$response = $this->controller->saveSearchSources(['files']);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame(['file' => 4], $data['purge']['result']);
	}

	public function testNoPurgeWhenNothingNewlyDisabled(): void {
		$this->authenticateUserWithToken('admin', 'tok');
		$this->searchSources->method('getDisabledSources')->willReturn([]);
		$this->searchSources->method('installedSources')->willReturn([]);

		$this->client->expects($this->never())->method('purgeDocTypes');

		$response = $this->controller->saveSearchSources([]);

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertNull($data['purge']);
	}

	public function testReEnablingSourceDoesNotPurge(): void {
		$this->authenticateUserWithToken('admin', 'tok');
		// "files" was disabled; this save re-enables it (empty disabled-set).
		$this->searchSources->method('getDisabledSources')->willReturn(['files']);
		$this->searchSources->method('installedSources')->willReturn([]);

		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('astrolabe', Admin::SETTING_DISABLED_SEARCH_SOURCES, '[]');
		$this->client->expects($this->never())->method('purgeDocTypes');

		$response = $this->controller->saveSearchSources([]);
		$this->assertTrue($response->getData()['success']);
	}

	public function testInvalidSourceIdsAreIgnored(): void {
		$this->authenticateUserWithToken('admin', 'tok');
		$this->searchSources->method('getDisabledSources')->willReturn([]);
		$this->searchSources->method('installedSources')->willReturn([]);

		// "bogus" is dropped; only the valid "deck" persists and purges.
		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('astrolabe', Admin::SETTING_DISABLED_SEARCH_SOURCES, '["deck"]');
		$this->client->expects($this->once())
			->method('purgeDocTypes')
			->with(['deck_card'], 'tok')
			->willReturn(['purged' => ['deck_card' => 0]]);

		$this->controller->saveSearchSources(['deck', 'bogus']);
	}

	public function testPurgeWarningWhenMcpUnreachable(): void {
		// No authenticated user → tokenForCurrentUser() returns a JSONResponse,
		// so the eager purge can't run. Config must still be saved (consent),
		// and the response surfaces a warning rather than failing.
		$this->userSession->method('getUser')->willReturn(null);
		$this->searchSources->method('getDisabledSources')->willReturn([]);
		$this->searchSources->method('installedSources')->willReturn([]);

		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('astrolabe', Admin::SETTING_DISABLED_SEARCH_SOURCES, '["files"]');
		$this->client->expects($this->never())->method('purgeDocTypes');

		$response = $this->controller->saveSearchSources(['files']);

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('warning', $data['purge']);
		$this->assertStringContainsString('Could not reach', $data['purge']['warning']);
	}

	/** Build a controller whose effective enabled doc types are exactly $enabled. */
	private function controllerWithEnabled(array $enabled): ApiController {
		$searchSources = $this->createMock(SearchSources::class);
		$searchSources->method('effectiveEnabledDocTypes')->willReturn($enabled);
		return new ApiController(
			'astrolabe',
			$this->createMock(IRequest::class),
			$this->client,
			$this->userSession,
			$this->logger,
			$this->tokenMinter,
			$this->config,
			$this->appConfig,
			$searchSources,
		);
	}

	public function testSearchIntersectsRequestedWithEnabled(): void {
		$this->authenticateUserWithToken('admin', 'tok');
		$controller = $this->controllerWithEnabled(['note']);

		$captured = [];
		$this->client->expects($this->once())
			->method('search')
			->willReturnCallback(function (...$args) use (&$captured): array {
				$captured = $args;
				return ['results' => [], 'algorithm_used' => 'hybrid'];
			});

		// Caller asks for note + file, but only note is enabled.
		$controller->search(query: 'x', doc_types: 'note,file', include_pca: 'false');

		// 5th positional arg (index 4) is the doc_types array sent to the MCP server.
		$this->assertSame(['note'], $captured[4]);
	}

	public function testSearchShortCircuitsWhenNoSourceEnabled(): void {
		$this->authenticateUserWithToken('admin', 'tok');
		$controller = $this->controllerWithEnabled([]);

		$this->client->expects($this->never())->method('search');

		$response = $controller->search(query: 'x', include_pca: 'false');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame([], $data['results']);
	}
}
