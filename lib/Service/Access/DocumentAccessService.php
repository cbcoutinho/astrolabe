<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Service\Access;

use OCA\Astrolabe\Service\SearchSources;

/**
 * Registry that routes a document to the right {@see AccessVerifierInterface}.
 *
 * Modelled on the MCP server's `_VERIFIERS` map. Crucially it stays **dynamic**:
 * a verifier is consulted only when its source app is installed for the user —
 * determined by {@see SearchSources} (the same installed-apps signal behind the
 * `astrolabe.semantic_search` capability that indexing and the MCP server's
 * verify-on-read already use). Anything else (uncovered doc type, uninstalled
 * source, verifier that can't decide) resolves to DELEGATE, so the MCP server's
 * verify-on-read remains the backstop.
 */
final class DocumentAccessService {
	/** @var array<string, AccessVerifierInterface> */
	private array $byDocType = [];

	/** @psalm-suppress PossiblyUnusedMethod — constructed via DI. */
	public function __construct(
		FileAccessVerifier $fileVerifier,
		DeckAccessVerifier $deckVerifier,
		MailAccessVerifier $mailVerifier,
		CalendarAccessVerifier $calendarVerifier,
		private SearchSources $searchSources,
	) {
		foreach ([$fileVerifier, $deckVerifier, $mailVerifier, $calendarVerifier] as $verifier) {
			foreach ($verifier->docTypes() as $docType) {
				$this->byDocType[$docType] = $verifier;
			}
		}
	}

	/**
	 * @param array{doc_type?: string, id?: mixed, metadata?: array<string, mixed>} $doc
	 */
	public function check(string $uid, array $doc): AccessDecision {
		$docType = $doc['doc_type'] ?? '';
		if ($docType === '') {
			return AccessDecision::DELEGATE;
		}

		// Dynamic gate: only verify doc types whose source app is installed for
		// this user. A not-installed source's content is never indexed/returned
		// anyway, so DELEGATE is the safe fallback.
		$sourceApp = SearchSources::sourceForDocType($docType);
		if ($sourceApp === null || !$this->searchSources->isInstalled($sourceApp)) {
			return AccessDecision::DELEGATE;
		}

		$verifier = $this->byDocType[$docType] ?? null;
		if ($verifier === null) {
			return AccessDecision::DELEGATE;
		}

		return $verifier->verify($uid, $doc);
	}
}
