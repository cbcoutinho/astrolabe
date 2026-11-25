<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Reference;

use OCA\Astrolabe\Reference\McpAppReferenceProvider;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Which links become the viewer widget, and that only well-formed ids reach
 * the rich object.
 */
final class McpAppReferenceProviderTest extends TestCase {
	private McpAppReferenceProvider $provider;

	protected function setUp(): void {
		parent::setUp();
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getAbsoluteURL')->willReturn('https://cloud.example/');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		$this->provider = new McpAppReferenceProvider($urlGenerator, $l10n);
	}

	public function testResolvesAViewerLinkToItsIds(): void {
		$url = 'https://cloud.example/index.php/apps/astrolabe/?app_file_id=42&chunk_index=3&proposal=abcdefghijklmnopqrstuvwx';

		$this->assertTrue($this->provider->matchReference($url));
		$reference = $this->provider->resolveReference($url);

		$this->assertNotNull($reference);
		$this->assertSame(McpAppReferenceProvider::RICH_OBJECT_TYPE, $reference->getRichObjectType());
		$this->assertSame(
			['file_id' => 42, 'chunk_index' => 3, 'proposal' => 'abcdefghijklmnopqrstuvwx'],
			$reference->getRichObject(),
		);
	}

	public function testOptionalPartsDegradeToNull(): void {
		$reference = $this->provider->resolveReference(
			'https://cloud.example/apps/astrolabe?app_file_id=42&chunk_index=x&proposal=../../etc',
		);

		$this->assertNotNull($reference);
		$this->assertSame(['file_id' => 42, 'chunk_index' => null, 'proposal' => null], $reference->getRichObject());
	}

	/** PCRE's `$` matches before a final newline, so the id pattern ends in \z. */
	public function testAProposalWithATrailingNewlineIsDropped(): void {
		$reference = $this->provider->resolveReference(
			'https://cloud.example/apps/astrolabe/?app_file_id=42&proposal=abcdefghijklmnopqrstuvwx%0A',
		);

		$this->assertNotNull($reference);
		$this->assertNull($reference->getRichObject()['proposal']);
	}

	/**
	 * @dataProvider notViewerLinks
	 */
	public function testIgnoresOtherLinks(string $url): void {
		$this->assertFalse($this->provider->matchReference($url));
		$this->assertNull($this->provider->resolveReference($url));
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function notViewerLinks(): array {
		return [
			'another instance' => ['https://evil.example/apps/astrolabe/?app_file_id=42'],
			'another app' => ['https://cloud.example/apps/files/?app_file_id=42'],
			'no file id' => ['https://cloud.example/apps/astrolabe/?chunk_index=1'],
			'non-numeric file id' => ['https://cloud.example/apps/astrolabe/?app_file_id=42abc'],
			'array file id' => ['https://cloud.example/apps/astrolabe/?app_file_id[]=42'],
			'not a url' => ['app_file_id=42'],
		];
	}
}
