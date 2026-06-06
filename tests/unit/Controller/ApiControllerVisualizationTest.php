<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Controller;

use OCA\Astrolabe\Settings\Admin as AdminSettings;
use OCP\AppFramework\Http;

/**
 * Tests for the admin "show visualization" toggle as enforced by ApiController:
 *
 *  - saveSearchSettings() persists the flag via IAppConfig::setValueBool and
 *    echoes it back in the response.
 *  - search() reads the flag and forces include_pca off when the admin has
 *    disabled the panel, regardless of the user-supplied include_pca parameter
 *    (server-side enforcement, not just the well-behaved client path).
 */
final class ApiControllerVisualizationTest extends AbstractApiControllerTestCase {
	public function testSaveSearchSettingsPersistsShowVisualization(): void {
		$this->appConfig->expects($this->once())
			->method('setValueBool')
			->with('astrolabe', AdminSettings::SETTING_SHOW_VISUALIZATION, false);

		$response = $this->controller->saveSearchSettings(showVisualization: false);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertFalse($data['settings']['showVisualization']);
	}

	public function testSaveSearchSettingsDefaultsShowVisualizationOn(): void {
		$this->appConfig->expects($this->once())
			->method('setValueBool')
			->with('astrolabe', AdminSettings::SETTING_SHOW_VISUALIZATION, true);

		$response = $this->controller->saveSearchSettings();

		$this->assertTrue($response->getData()['settings']['showVisualization']);
	}

	public function testSearchForcesPcaOffWhenVisualizationDisabled(): void {
		$this->authenticateUserWithToken();
		$this->appConfig->method('getValueBool')
			->with('astrolabe', AdminSettings::SETTING_SHOW_VISUALIZATION, AdminSettings::DEFAULT_SHOW_VISUALIZATION)
			->willReturn(false);

		$captured = [];
		$this->client->expects($this->once())
			->method('search')
			->willReturnCallback(function (...$args) use (&$captured): array {
				$captured = $args;
				return ['results' => [], 'algorithm_used' => 'hybrid'];
			});

		// Hostile/direct caller still asks for PCA — the server must override it.
		$response = $this->controller->search(query: 'spec', include_pca: 'true');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		// search(query, algorithm, limit, includePca, ...) — includePca is arg 3.
		$this->assertFalse($captured[3]);
	}

	public function testSearchKeepsPcaWhenVisualizationEnabled(): void {
		$this->authenticateUserWithToken();
		$this->appConfig->method('getValueBool')
			->with('astrolabe', AdminSettings::SETTING_SHOW_VISUALIZATION, AdminSettings::DEFAULT_SHOW_VISUALIZATION)
			->willReturn(true);

		$captured = [];
		$this->client->expects($this->once())
			->method('search')
			->willReturnCallback(function (...$args) use (&$captured): array {
				$captured = $args;
				return ['results' => [], 'algorithm_used' => 'hybrid'];
			});

		$this->controller->search(query: 'spec', include_pca: 'true');

		$this->assertTrue($captured[3]);
	}
}
