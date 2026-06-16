<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit;

use OCA\Astrolabe\Capabilities;
use OCA\Astrolabe\Service\SearchSources;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the astrolabe.semantic_search capability — the cross-repo
 * contract the MCP server consumes for admin consent.
 */
final class CapabilitiesTest extends TestCase {
	private SearchSources&MockObject $searchSources;

	protected function setUp(): void {
		parent::setUp();
		$this->searchSources = $this->createMock(SearchSources::class);
	}

	public function testExposesEnabledDocTypesAndSources(): void {
		$this->searchSources->method('sourcesWithEnabledDocTypes')->willReturn([
			'sources' => [
				['app' => 'notes', 'docTypes' => ['note'], 'label' => 'Notes', 'enabled' => true],
				['app' => 'files', 'docTypes' => ['file'], 'label' => 'Files', 'enabled' => true],
				['app' => 'deck', 'docTypes' => ['deck_card'], 'label' => 'Deck', 'enabled' => false],
			],
			'enabledDocTypes' => ['note', 'file'],
		]);

		$caps = (new Capabilities($this->searchSources))->getCapabilities();

		$this->assertArrayHasKey('astrolabe', $caps);
		$semantic = $caps['astrolabe']['semantic_search'];
		$this->assertSame(['note', 'file'], $semantic['enabled_doc_types']);

		// sources are re-keyed to snake_case doc_types and carry enabled flags.
		$this->assertSame(
			[
				['app' => 'notes', 'doc_types' => ['note'], 'enabled' => true],
				['app' => 'files', 'doc_types' => ['file'], 'enabled' => true],
				['app' => 'deck', 'doc_types' => ['deck_card'], 'enabled' => false],
			],
			$semantic['sources'],
		);
	}

	public function testEmptyWhenNothingEnabled(): void {
		$this->searchSources->method('sourcesWithEnabledDocTypes')->willReturn([
			'sources' => [],
			'enabledDocTypes' => [],
		]);

		$caps = (new Capabilities($this->searchSources))->getCapabilities();

		$this->assertSame([], $caps['astrolabe']['semantic_search']['enabled_doc_types']);
		$this->assertSame([], $caps['astrolabe']['semantic_search']['sources']);
	}
}
