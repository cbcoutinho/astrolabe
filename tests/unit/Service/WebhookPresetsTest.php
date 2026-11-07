<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Service;

use Eris\Generators;
use Eris\TestTrait;
use OCA\Astrolabe\Service\WebhookPresets;
use OCA\Astrolabe\Tests\Unit\PropertyGenerators;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WebhookPresets::decodeEnabledPresetIds — the shared decode
 * routine used by the boot subscription, the runtime listener, and the admin
 * controller (extracted so those three call sites can't drift).
 */
final class WebhookPresetsTest extends TestCase {
	use TestTrait;

	/**
	 * For any stored JSON list, decoding returns its string entries in order and
	 * drops everything else.
	 */
	public function testDecodeKeepsExactlyTheStringEntries(): void {
		$this->forAll(Generators::seq(Generators::oneOf(
			PropertyGenerators::text(),
			Generators::int(),
			Generators::bool(),
			Generators::constant(null),
			Generators::map(static fn (string $s): array => ['id' => $s], PropertyGenerators::text()),
		)))->then(function (array $stored): void {
			$this->assertSame(
				array_values(array_filter($stored, 'is_string')),
				WebhookPresets::decodeEnabledPresetIds(json_encode($stored, JSON_THROW_ON_ERROR)),
			);
		});
	}

	/** Whatever the stored text, decoding yields a list of strings rather than failing. */
	public function testDecodeNeverFailsOnArbitraryText(): void {
		$this->forAll(PropertyGenerators::text())->then(function (string $raw): void {
			$ids = WebhookPresets::decodeEnabledPresetIds($raw);
			$this->assertTrue(array_is_list($ids));
			$this->assertContainsOnly('string', $ids);
		});
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
