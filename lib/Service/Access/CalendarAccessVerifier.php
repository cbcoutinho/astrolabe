<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Service\Access;

/**
 * Access verifier scaffold for calendar objects.
 *
 * Currently a **conservative no-op that always DELEGATEs** to the MCP
 * verify-on-read backstop. A correct local check needs an *owner-scoped*,
 * globally-unambiguous calendar identifier, which a calendar search result does
 * not reliably carry today (the MCP server has no calendar verifier to confirm
 * the shape). Matching on the per-principal URI/key — the only identifiers
 * `OCP\Calendar\ICalendar` exposes — would risk a false-ALLOW during the
 * staleness re-check, because the default calendar is named `personal` for every
 * account: a user who still owns their own `personal` calendar would match a
 * revoked, someone-else's `personal` event.
 *
 * The registry keeps calendar wired here (rather than silently unmapped) so the
 * real check can be dropped in once the MCP-side calendar verifier lands and
 * pins the identifier contract (coordinated follow-up, Deck board 11).
 */
final class CalendarAccessVerifier implements AccessVerifierInterface {
	#[\Override]
	public function docTypes(): array {
		return ['calendar', 'calendar_event'];
	}

	#[\Override]
	public function verify(string $uid, array $doc): AccessDecision {
		return AccessDecision::DELEGATE;
	}
}
