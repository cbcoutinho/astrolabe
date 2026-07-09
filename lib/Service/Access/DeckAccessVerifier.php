<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Service\Access;

/**
 * Access verifier scaffold for Deck cards.
 *
 * Currently a **conservative no-op that always DELEGATEs** to the MCP
 * verify-on-read backstop. A sound local check must confirm the requested card
 * (`doc_id`) actually belongs to a board the user can READ — but the only board
 * identifier available here is `board_id` from the (client-supplied, on the
 * deep-link/content-fetch path) doc metadata, which is **not** cross-checked
 * against `doc_id`. Checking READ on a caller-supplied `board_id` alone would
 * let a user pair a private card's `doc_id` with a board they legitimately have
 * access to and get a false-ALLOW.
 *
 * Doing this correctly requires resolving the card's *real* board server-side
 * from `doc_id` (via Deck's own `CardService`/`PermissionService::checkPermission`
 * against the card mapper) — which depends on the confirmed `doc_id`/card-id
 * contract the MCP-side Deck verifier owns. Until that lands (coordinated
 * follow-up, Deck board 11), delegate to the MCP backstop, which already does
 * the membership check via `get_card(board_id, stack_id, card_id)`.
 */
final class DeckAccessVerifier implements AccessVerifierInterface {
	#[\Override]
	public function docTypes(): array {
		return ['deck_card'];
	}

	#[\Override]
	public function verify(string $uid, array $doc): AccessDecision {
		return AccessDecision::DELEGATE;
	}
}
