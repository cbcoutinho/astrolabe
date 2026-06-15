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

	public function testPurgeWarningWhenMcpReturnsError(): void {
		// MCP server reachable but the purge call returns an error payload —
		// config is still saved and the error surfaces as a warning.
		$this->authenticateUserWithToken('admin', 'tok');
		$this->searchSources->method('getDisabledSources')->willReturn([]);
		$this->searchSources->method('installedSources')->willReturn([]);

		$this->client->expects($this->once())
			->method('purgeDocTypes')
			->with(['file'], 'tok')
			->willReturn(['error' => 'qdrant unavailable']);

		$response = $this->controller->saveSearchSources(['files']);

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame('qdrant unavailable', $data['purge']['warning']);
		$this->assertArrayNotHasKey('result', $data['purge']);
	}

	public function testPurgeWarningWhenNoAuthenticatedUser(): void {
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

	public function testPurgeWarningWhenTokenMintFails(): void {
		// MCP token mint throws (e.g. oidc app broken) → purge can't run, but
		// the config is still persisted and a warning is surfaced.
		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('admin');
		$this->userSession->method('getUser')->willReturn($user);
		$this->tokenMinter->method('mintForUser')
			->willThrowException(new \OCA\Astrolabe\Service\McpTokenMintException('mint failed'));
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

		// Name the callback params after McpServerClient::search()'s signature so
		// the assertion is self-documenting and breaks loudly if the order drifts.
		$capturedDocTypes = null;
		$this->client->expects($this->once())
			->method('search')
			->willReturnCallback(function (
				string $query,
				string $algorithm,
				int $limit,
				bool $includePca,
				?array $docTypes,
			) use (&$capturedDocTypes): array {
				$capturedDocTypes = $docTypes;
				return ['results' => [], 'algorithm_used' => 'hybrid'];
			});

		// Caller asks for note + file, but only note is enabled.
		$controller->search(query: 'x', doc_types: 'note,file', include_pca: 'false');

		$this->assertSame(['note'], $capturedDocTypes);
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
