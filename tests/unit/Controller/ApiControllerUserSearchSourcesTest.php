<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Controller;

use OCA\Astrolabe\Service\SearchSources;
use OCP\AppFramework\Http;

/**
 * Tests for the per-user search-source endpoint (saveUserSearchSources).
 *
 * Unlike the admin endpoint this is NoAdminRequired, persists a per-user
 * IConfig value, and does NOT trigger an eager purge (the MCP scanner's
 * per-user consent backstop deletes the user's points on the next sync).
 */
final class ApiControllerUserSearchSourcesTest extends AbstractApiControllerTestCase {
	public function testUnauthenticatedIsRejected(): void {
		// No user on the session.
		$this->userSession->method('getUser')->willReturn(null);
		// We must bail before touching config or the source list.
		$this->config->expects($this->never())->method('setUserValue');
		$this->searchSources->expects($this->never())->method('userConfigurableSources');

		$response = $this->controller->saveUserSearchSources(['notes']);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testEmptyDisabledSourcesReEnablesAll(): void {
		$this->authenticateUserWithToken('alice');

		// Re-enabling everything persists an empty JSON array.
		$this->config->expects($this->once())
			->method('setUserValue')
			->with(
				'alice',
				'astrolabe',
				SearchSources::USER_SETTING_DISABLED_SEARCH_SOURCES,
				json_encode([]),
			);
		$this->client->expects($this->never())->method('purgeDocTypes');
		$this->searchSources->method('userConfigurableSources')->willReturn([
			['app' => 'notes', 'docTypes' => ['note'], 'label' => 'Notes', 'tenantEnabled' => true, 'userEnabled' => true],
		]);

		$response = $this->controller->saveUserSearchSources([]);

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertTrue($data['searchSources'][0]['userEnabled']);
	}

	public function testPersistsNormalizedUserValueAndDoesNotPurge(): void {
		$this->authenticateUserWithToken('alice');

		// The normalized (catalog-valid, deduped) set is persisted per-user.
		$this->config->expects($this->once())
			->method('setUserValue')
			->with(
				'alice',
				'astrolabe',
				SearchSources::USER_SETTING_DISABLED_SEARCH_SOURCES,
				json_encode(['notes', 'deck']),
			);
		// No eager purge on the per-user path.
		$this->client->expects($this->never())->method('purgeDocTypes');
		$this->searchSources->method('userConfigurableSources')->willReturn([
			['app' => 'notes', 'docTypes' => ['note'], 'label' => 'Notes', 'tenantEnabled' => true, 'userEnabled' => false],
		]);

		// 'bogus' is dropped by normalizeSourceIds; order/dedupe preserved.
		$response = $this->controller->saveUserSearchSources(['notes', 'deck', 'bogus', 'notes']);

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame('notes', $data['searchSources'][0]['app']);
		$this->assertFalse($data['searchSources'][0]['userEnabled']);
	}
}
