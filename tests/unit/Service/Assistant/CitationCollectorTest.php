<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Tests\Unit\Service\Assistant;

use OCA\Astrolabe\Service\Assistant\CitationCollector;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Citations exist to be checkable. A model can write "Source: note #78" whether
 * or not it read anything, so these tests pin that a citation appears only when
 * a tool genuinely returned that document, and that its link resolves to the
 * passage rather than merely the app.
 */
final class CitationCollectorTest extends TestCase {
	private function collector(): CitationCollector {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturn('/apps/astrolabe/');
		$urlGenerator->method('getAbsoluteURL')
			->willReturnCallback(static fn (string $path): string => 'https://cloud.example' . $path);
		return new CitationCollector($urlGenerator);
	}

	private function searchResult(array ...$rows): string {
		return json_encode(['success' => true, 'results' => $rows], JSON_THROW_ON_ERROR);
	}

	public function testCitesADocumentReturnedByASearch(): void {
		$collector = $this->collector();
		$collector->collect('nc_semantic_search', $this->searchResult([
			'id' => 78,
			'doc_type' => 'note',
			'title' => 'Kubernetes Cluster Architecture',
			'chunk_start_offset' => 120,
			'chunk_end_offset' => 460,
		]));

		$this->assertSame(['Kubernetes Cluster Architecture'], $collector->titles());

		$markdown = $collector->markdown();
		$this->assertStringContainsString('[Kubernetes Cluster Architecture](', $markdown);
		$this->assertStringContainsString('doc_id=78', $markdown);
		$this->assertStringContainsString('doc_type=note', $markdown);
		// The offsets are what open the viewer at the passage rather than the top.
		$this->assertStringContainsString('chunk_start=120', $markdown);
		$this->assertStringContainsString('chunk_end=460', $markdown);
	}

	/**
	 * The whole point: prose from the model is not a citation. Only a structured
	 * tool result produces one.
	 */
	public function testProseYieldsNoCitations(): void {
		$collector = $this->collector();
		$collector->collect('nc_semantic_search', 'According to your note "Kubernetes Cluster Architecture" (note #78), ...');

		$this->assertSame([], $collector->titles());
		$this->assertSame('', $collector->markdown());
	}

	public function testAlsoReadsTheAnswerToolsSourcesShape(): void {
		$collector = $this->collector();
		$collector->collect('nc_semantic_search_answer', json_encode([
			'generated_answer' => 'It uses Cilium.',
			'sources' => [['id' => 78, 'doc_type' => 'note', 'title' => 'Kubernetes Cluster Architecture']],
		], JSON_THROW_ON_ERROR));

		$this->assertSame(['Kubernetes Cluster Architecture'], $collector->titles());
	}

	public function testTheSameDocumentIsCitedOnceAcrossSeveralResults(): void {
		$collector = $this->collector();
		$row = ['id' => 78, 'doc_type' => 'note', 'title' => 'Kubernetes Cluster Architecture'];
		$collector->collect('nc_semantic_search', $this->searchResult($row, $row));
		$collector->collect('nc_semantic_search', $this->searchResult($row));

		$this->assertCount(1, $collector->titles());
	}

	public function testDistinguishesDocumentsThatShareAnIdAcrossTypes(): void {
		$collector = $this->collector();
		$collector->collect('nc_semantic_search', $this->searchResult(
			['id' => 78, 'doc_type' => 'note', 'title' => 'A note'],
			['id' => 78, 'doc_type' => 'file', 'title' => 'A file'],
		));

		$this->assertSame(['A note', 'A file'], $collector->titles());
	}

	/**
	 * A row that cannot produce a working link is worse than no citation: it
	 * looks authoritative and goes nowhere.
	 *
	 * @dataProvider unlinkableRows
	 */
	public function testSkipsRowsThatCannotBeLinked(array $row): void {
		$collector = $this->collector();
		$collector->collect('nc_semantic_search', $this->searchResult($row));

		$this->assertSame([], $collector->titles());
	}

	/**
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function unlinkableRows(): array {
		return [
			'no id' => [['doc_type' => 'note', 'title' => 'Untethered']],
			// nc_semantic_search rows always carry their own type, so a row without
			// one is malformed rather than inferable.
			'no doc_type' => [['id' => 78, 'title' => 'Untethered']],
			'empty doc_type' => [['id' => 78, 'doc_type' => '', 'title' => 'Untethered']],
			'empty id' => [['id' => '', 'doc_type' => 'note', 'title' => 'Untethered']],
		];
	}

	/**
	 * Deriving the type from the tool name was tried and removed: it copies the
	 * server's tool taxonomy into this app, where it rots as tools change, and a
	 * wrong guess links confidently into the wrong app. Tools must supply
	 * doc_type; until they do, their results are not citable.
	 */
	public function testARowWithoutADocTypeIsNotCitedEvenWhenTheToolImpliesOne(): void {
		$collector = $this->collector();
		$collector->collect('nc_notes_search_notes', $this->searchResult([
			'id' => 78,
			'title' => 'Kubernetes Cluster Architecture',
		]));

		$this->assertSame([], $collector->titles());
	}

	/**
	 * The gap has to be visible: an answer that quietly loses its citations is
	 * indistinguishable from one that had no sources.
	 */
	public function testReportsWhichToolsCouldNotBeCited(): void {
		$collector = $this->collector();
		$collector->collect('nc_notes_search_notes', $this->searchResult(['id' => 78, 'title' => 'A note']));
		$collector->collect('nc_semantic_search', $this->searchResult([
			'id' => 9, 'doc_type' => 'file', 'title' => 'A file',
		]));

		$this->assertSame(['nc_notes_search_notes'], $collector->uncitableTools());
		$this->assertSame(['A file'], $collector->titles());
	}

	public function testReportsNothingWhenEveryRowWasCitable(): void {
		$collector = $this->collector();
		$collector->collect('nc_semantic_search', $this->searchResult([
			'id' => 9, 'doc_type' => 'file', 'title' => 'A file',
		]));

		$this->assertSame([], $collector->uncitableTools());
	}

	public function testFallsBackToAnIdentifierWhenATitleIsMissing(): void {
		$collector = $this->collector();
		$collector->collect('nc_semantic_search', $this->searchResult(['id' => 78, 'doc_type' => 'note']));

		$this->assertSame(['note 78'], $collector->titles());
	}

	/**
	 * A bracket in a filename would otherwise terminate the markdown link early
	 * and leave a broken URL in the answer.
	 */
	public function testEscapesBracketsInTitles(): void {
		$collector = $this->collector();
		$collector->collect('nc_semantic_search', $this->searchResult([
			'id' => 9,
			'doc_type' => 'file',
			'title' => 'Report [final]',
		]));

		$this->assertStringContainsString('[Report \[final\]](', $collector->markdown());
	}

	public function testCapsTheNumberOfCitations(): void {
		$collector = $this->collector();
		$rows = [];
		for ($i = 1; $i <= 40; $i++) {
			$rows[] = ['id' => $i, 'doc_type' => 'note', 'title' => 'Note ' . $i];
		}
		$collector->collect('nc_semantic_search', $this->searchResult(...$rows));

		$this->assertLessThanOrEqual(10, count($collector->titles()));
	}

	/**
	 * @dataProvider unusableOutput
	 */
	public function testToleratesToolOutputItCannotRead(string $raw): void {
		$collector = $this->collector();
		$collector->collect('nc_semantic_search', $raw);

		$this->assertSame([], $collector->titles());
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function unusableOutput(): array {
		return [
			'empty' => [''],
			'not json' => ['<html>nope</html>'],
			'json scalar' => ['42'],
			'no results key' => ['{"success":true}'],
			'results is not a list' => ['{"results":"none"}'],
			'row is not an object' => ['{"results":["note 78"]}'],
		];
	}

	public function testCarriesAccessIdentifiersIntoTheLink(): void {
		$collector = $this->collector();
		$collector->collect('nc_semantic_search', $this->searchResult([
			'id' => 12,
			'doc_type' => 'deck_card',
			'title' => 'Ship the agent',
			'board_id' => 5,
		]));

		// The viewer re-checks access using these before showing anything.
		$this->assertStringContainsString('board_id=5', $collector->markdown());
	}
}
