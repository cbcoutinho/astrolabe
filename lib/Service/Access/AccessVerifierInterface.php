<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Service\Access;

/**
 * Decides whether a user may access a single search-result document, for one or
 * more doc types.
 *
 * Verifiers are registered with {@see DocumentAccessService}, which gates each
 * verifier on whether its source app is installed (via SearchSources) before
 * dispatching — so a verifier need not probe app availability itself, only make
 * the access decision for a document it understands.
 */
interface AccessVerifierInterface {
	/**
	 * The doc types this verifier handles (e.g. ``['file', 'note']``).
	 *
	 * @return list<string>
	 */
	public function docTypes(): array;

	/**
	 * @param string $uid The user whose access is being checked
	 * @param array{doc_type?: string, id?: mixed, metadata?: array<string, mixed>} $doc
	 *                                                                                   The search-result document (doc_type, id, and metadata identifiers)
	 */
	public function verify(string $uid, array $doc): AccessDecision;
}
