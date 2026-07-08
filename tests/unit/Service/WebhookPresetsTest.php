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
}
