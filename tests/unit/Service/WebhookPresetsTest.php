<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Service;

use OCA\Astrolabe\Service\WebhookPresets;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WebhookPresets::decodeEnabledPresetIds — the shared decode
 * routine used by the boot subscription, the runtime listener, and the admin
 * controller (extracted so those three call sites can't drift).
 */
final class WebhookPresetsTest extends TestCase {
	public function testDecodesValidJsonList(): void {
		$this->assertSame(
			['notes_sync', 'files_sync'],
			WebhookPresets::decodeEnabledPresetIds('["notes_sync","files_sync"]'),
		);
	}

	public function testEmptyOrDefaultYieldsEmptyList(): void {
		$this->assertSame([], WebhookPresets::decodeEnabledPresetIds('[]'));
		$this->assertSame([], WebhookPresets::decodeEnabledPresetIds(''));
	}

	public function testNonArrayJsonYieldsEmptyList(): void {
		$this->assertSame([], WebhookPresets::decodeEnabledPresetIds('not json'));
		$this->assertSame([], WebhookPresets::decodeEnabledPresetIds('42'));
		$this->assertSame([], WebhookPresets::decodeEnabledPresetIds('"just-a-string"'));
	}

	public function testNonStringEntriesAreDropped(): void {
		$this->assertSame(
			['notes_sync'],
			WebhookPresets::decodeEnabledPresetIds('["notes_sync", 42, null, {"x":1}]'),
		);
	}

	/**
	 * Guard: a preset must map to content the MCP server indexes, otherwise
	 * enabling it only burns a background job per change — the server drops the
	 * envelope on arrival (see its ``vector/webhook_parser.py``). Calendar,
	 * Tables and Forms presets were removed for exactly that reason; re-adding
	 * one needs a parser on the other side first.
	 */
	public function testCatalogueOnlyOffersIndexedContent(): void {
		$this->assertSame(
			['notes_sync', 'files_sync', 'deck_sync'],
			array_keys(WebhookPresets::getPresets()),
		);
	}

	public function testEveryPresetDeclaresAtLeastOneEvent(): void {
		foreach (array_keys(WebhookPresets::getPresets()) as $presetId) {
			$this->assertNotEmpty(
				WebhookPresets::getPresetEvents($presetId),
				"Preset $presetId declares no events",
			);
		}
	}
}
