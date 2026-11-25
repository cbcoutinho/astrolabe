<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Reference;

use OCA\Astrolabe\Service\McpApp\RedactionProposals;
use OCP\Collaboration\Reference\IReference;
use OCP\Collaboration\Reference\IReferenceProvider;
use OCP\Collaboration\Reference\Reference;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Turns an Astrolabe "open in MCP App" link (…/apps/astrolabe/?app_file_id=N
 * [&chunk_index=M]) into a rich object the astrolabe_mcp_app widget mounts the
 * document viewer for (exploratory POC). Only ids travel: the viewer's own
 * open_document call does the access check with the viewing user's token.
 */
final class McpAppReferenceProvider implements IReferenceProvider {
	public const RICH_OBJECT_TYPE = 'astrolabe_mcp_app';

	/** @psalm-suppress PossiblyUnusedMethod — constructed via DI. */
	public function __construct(
		private IURLGenerator $urlGenerator,
		private IL10N $l10n,
	) {
	}

	#[\Override]
	public function matchReference(string $referenceText): bool {
		return $this->parse($referenceText) !== null;
	}

	#[\Override]
	public function resolveReference(string $referenceText): ?IReference {
		$ids = $this->parse($referenceText);
		if ($ids === null) {
			return null;
		}
		$reference = new Reference($referenceText);
		$reference->setTitle($this->l10n->t('Document %d', [$ids['file_id']]));
		$reference->setRichObject(self::RICH_OBJECT_TYPE, $ids);
		return $reference;
	}

	/** @return array{file_id: int, chunk_index: ?int, proposal: ?string}|null */
	private function parse(string $text): ?array {
		if (!str_starts_with($text, $this->urlGenerator->getAbsoluteURL('/'))
			|| preg_match('#/apps/astrolabe/?\?#', $text) !== 1) {
			return null;
		}
		parse_str((string)parse_url($text, PHP_URL_QUERY), $q);
		$str = static fn (string $key): string => isset($q[$key]) && is_string($q[$key]) ? $q[$key] : '';
		$fileId = $str('app_file_id');
		$chunk = $str('chunk_index');
		$proposal = $str('proposal');
		if (!ctype_digit($fileId)) {
			return null;
		}
		return [
			'file_id' => (int)$fileId,
			'chunk_index' => ctype_digit($chunk) ? (int)$chunk : null,
			// Opaque id only; McpAppController resolves it per viewing user.
			'proposal' => preg_match(RedactionProposals::ID_PATTERN, $proposal) === 1 ? $proposal : null,
		];
	}

	// Per-URL cache entry; user-independent, since it carries ids only.
	#[\Override]
	public function getCachePrefix(string $referenceId): string {
		return $referenceId;
	}

	#[\Override]
	public function getCacheKey(string $referenceId): ?string {
		return null;
	}
}
