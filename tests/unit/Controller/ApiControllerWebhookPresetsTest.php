<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Controller;

use OCA\Astrolabe\AppInfo\Application;
use OCA\Astrolabe\Settings\Admin;
use OCP\AppFramework\Http;

/**
 * Controller-level tests for the sync-preset admin endpoints after the native
 * sync migration: presets are listed from locally installed apps + the
 * enabled-presets app-config, and enable/disable mutate that app-config. No MCP
 * round-trip, token, or 428 provisioning path is involved anymore.
 */
final class ApiControllerWebhookPresetsTest extends AbstractApiControllerTestCase {
	public function testGetWebhookPresetsNeedsNoTokenAndMarksEnabledFromConfig(): void {
		// Deliberately NOT authenticated: the endpoint must not mint a token.
		$this->tokenMinter->expects($this->never())->method('mintForUser');
		$this->appConfig->method('getValueString')->willReturn('["files_sync"]');

		$response = $this->controller->getWebhookPresets();
		$data = $response->getData();

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('files_sync', $data['presets']);
		$this->assertTrue($data['presets']['files_sync']['enabled']);
	}

	public function testGetWebhookPresetsMarksDisabledWhenNotInConfig(): void {
		$this->appConfig->method('getValueString')->willReturn('[]');

		$data = $this->controller->getWebhookPresets()->getData();

		$this->assertFalse($data['presets']['files_sync']['enabled']);
	}

	public function testUninstalledAppPresetIsNotListed(): void {
		// Base fixture installs only "files"; the Notes preset (app "notes") must
		// be filtered out entirely.
		$this->appConfig->method('getValueString')->willReturn('[]');

		$data = $this->controller->getWebhookPresets()->getData();

		$this->assertArrayHasKey('files_sync', $data['presets']);
		$this->assertArrayNotHasKey('notes_sync', $data['presets']);
	}

	public function testEnablePresetAddsIdToConfig(): void {
		$this->appConfig->method('getValueString')->willReturn('[]');
		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with(
				Application::APP_ID,
				Admin::SETTING_ENABLED_SYNC_PRESETS,
				$this->callback(function (string $json): bool {
					return json_decode($json, true) === ['notes_sync'];
				}),
			);

		$response = $this->controller->enableWebhookPreset('notes_sync');
		$data = $response->getData();

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($data['success']);
	}

	public function testEnableAlreadyEnabledPresetIsIdempotent(): void {
		$this->appConfig->method('getValueString')->willReturn('["notes_sync"]');
		$this->appConfig->expects($this->never())->method('setValueString');

		$data = $this->controller->enableWebhookPreset('notes_sync')->getData();

		$this->assertTrue($data['success']);
	}

	public function testEnableUnknownPresetReturns400(): void {
		$this->appConfig->expects($this->never())->method('setValueString');

		$response = $this->controller->enableWebhookPreset('does_not_exist');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testDisablePresetRemovesIdFromConfig(): void {
		$this->appConfig->method('getValueString')->willReturn('["notes_sync","files_sync"]');
		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with(
				Application::APP_ID,
				Admin::SETTING_ENABLED_SYNC_PRESETS,
				$this->callback(function (string $json): bool {
					return json_decode($json, true) === ['files_sync'];
				}),
			);

		$data = $this->controller->disableWebhookPreset('notes_sync')->getData();

		$this->assertTrue($data['success']);
	}

	public function testDisableNotEnabledPresetDoesNotWriteConfig(): void {
		$this->appConfig->method('getValueString')->willReturn('["files_sync"]');
		$this->appConfig->expects($this->never())->method('setValueString');

		$data = $this->controller->disableWebhookPreset('notes_sync')->getData();

		$this->assertTrue($data['success']);
	}

	public function testDisableUnknownPresetReturns400(): void {
		$response = $this->controller->disableWebhookPreset('does_not_exist');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}
}
