<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Service\Assistant;

use OCA\Astrolabe\AppInfo\Application;
use OCP\IURLGenerator;

/**
 * Builds citations from what the tools actually returned.
 *
 * The alternative — asking the model to name its sources in prose — produces
 * text that reads like a citation but is unverifiable: nothing connects
 * "note #78" to a document that was really consulted, and a model that invents
 * one is indistinguishable from a model that read one. Everything here comes
 * from a tool's structured result, so a citation exists only if a document was
 * genuinely returned.
 *
 * Deep links reuse the query format the chunk viewer already parses, which is
 * the same one {@see \OCA\Astrolabe\Search\SemanticSearchProvider} emits for
 * Unified Search — so a citation lands on the passage, not merely the file.
 */
final class CitationCollector {
	/**
	 * Citations kept per turn. Enough to attribute an answer without turning the
	 * sources popover into a second search-results page.
	 */
	private const MAX_CITATIONS = 10;

	/** @var array<string, array{title: string, url: string}> keyed to dedupe */
	private array $citations = [];

	/**
	 * Tools that returned rows carrying no `doc_type`, and so could not be cited.
	 *
	 * Recorded rather than swallowed: an answer that quietly loses its citations
	 * looks the same as one that had no sources, and the difference matters.
	 *
	 * @var array<string, true>
	 */
	private array $uncitable = [];

	/**
	 * Constructed per agent turn, never shared.
	 *
	 * This is stateful, and a TaskProcessing worker is a long-running process
	 * serving many tasks: a container-shared instance would carry one user's
	 * citations into the next user's answer.
	 */
	public function __construct(
		private IURLGenerator $urlGenerator,
	) {
	}

	/**
	 * Harvest any documents named in a tool's result.
	 *
	 * Tool output is JSON in a text block, and its shape varies by tool, so this
	 * reads defensively: anything unrecognised yields no citations rather than an
	 * error. A tool that returns prose is simply not a source of citations.
	 *
	 * A row must carry its own `doc_type`. Deriving it from the tool name was
	 * tried and removed: it copies the server's tool taxonomy into this app,
	 * where it silently rots as tools are added or renamed, and a wrong guess
	 * produces a confident link into the wrong app. Tools that omit `doc_type`
	 * are simply not citable until they supply it — see the follow-up card
	 * against nextcloud-mcp-server.
	 */
	public function collect(string $toolName, string $toolResult): void {
		try {
			/** @var mixed $decoded */
			$decoded = json_decode($toolResult, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return;
		}

		if (!is_array($decoded)) {
			return;
		}

		// `results` is the semantic-search shape; `sources` is what the
		// answer-generating variant returns.
		/** @var mixed $rows */
		$rows = $decoded['results'] ?? $decoded['sources'] ?? null;
		if (!is_array($rows)) {
			return;
		}

		/** @var mixed $row */
		foreach ($rows as $row) {
			if (is_array($row)) {
				$this->add($row, $toolName);
			}
		}
	}

	/**
	 * Human-readable document names, for the Assistant's sources popover.
	 *
	 * Titles rather than tool names: the popover renders each entry as plain
	 * text, so "Kubernetes Cluster Architecture" tells the reader something and
	 * "nc_semantic_search" does not.
	 *
	 * @return list<string>
	 */
	public function titles(): array {
		return array_values(array_map(
			static fn (array $citation): string => $citation['title'],
			$this->citations,
		));
	}

	/**
	 * A markdown list of links to append to the answer.
	 *
	 * This goes in the message body because that is the only place Assistant
	 * renders markdown — the sources popover interpolates plain text, so a link
	 * there would show as its own source.
	 */
	/**
	 * Tools whose results could not be cited for want of a `doc_type`.
	 *
	 * @return list<string>
	 */
	public function uncitableTools(): array {
		return array_keys($this->uncitable);
	}

	public function markdown(): string {
		if ($this->citations === []) {
			return '';
		}

		$lines = [];
		foreach ($this->citations as $citation) {
			$lines[] = sprintf('- [%s](%s)', $this->escapeLabel($citation['title']), $citation['url']);
		}

		return "\n\n**Sources**\n" . implode("\n", $lines);
	}

	/**
	 * @param array<array-key, mixed> $row
	 */
	private function add(array $row, string $toolName): void {
		if (count($this->citations) >= self::MAX_CITATIONS) {
			return;
		}

		/** @var mixed $id */
		$id = $row['id'] ?? null;
		/** @var mixed $docType */
		$docType = $row['doc_type'] ?? null;
		if (($id === null || $id === '') || !is_string($docType) || $docType === '') {
			// Without both, the link would not resolve to anything. A tool that
			// omits doc_type yields no citation rather than a guessed one.
			$this->uncitable[$toolName] = true;
			return;
		}

		/** @var mixed $rawTitle */
		$rawTitle = $row['title'] ?? null;
		$title = is_string($rawTitle) && trim($rawTitle) !== ''
			? trim($rawTitle)
			: sprintf('%s %s', $docType, (string)$id);

		$key = $docType . ':' . (string)$id;
		if (isset($this->citations[$key])) {
			return;
		}

		$this->citations[$key] = [
			'title' => $title,
			'url' => $this->urlGenerator->getAbsoluteURL($this->deepLink($row, $docType, (string)$id, $title)),
		];
	}

	/**
	 * @param array<array-key, mixed> $row
	 */
	private function deepLink(array $row, string $docType, string $id, string $title): string {
		$params = [
			'doc_type' => $docType,
			'doc_id' => $id,
			'title' => $title,
		];

		// Offsets are what let the viewer open at the passage rather than the top
		// of the document; they are absent from some tools, which is not an error.
		foreach (['chunk_start_offset' => 'chunk_start', 'chunk_end_offset' => 'chunk_end'] as $from => $to) {
			/** @var mixed $value */
			$value = $row[$from] ?? null;
			if (is_int($value) || (is_string($value) && $value !== '')) {
				$params[$to] = (string)$value;
			}
		}

		// Identifiers the viewer's access check needs for non-file types.
		foreach (['path', 'page_number', 'board_id', 'mailbox_id'] as $key) {
			/** @var mixed $value */
			$value = $row[$key] ?? null;
			if (is_scalar($value) && (string)$value !== '') {
				$params[$key] = (string)$value;
			}
		}

		return $this->urlGenerator->linkToRoute(Application::APP_ID . '.page.index')
			. '?' . http_build_query($params);
	}

	/**
	 * Keep a title with brackets from breaking out of its own markdown link.
	 */
	private function escapeLabel(string $title): string {
		return str_replace(['[', ']'], ['\[', '\]'], $title);
	}
}
