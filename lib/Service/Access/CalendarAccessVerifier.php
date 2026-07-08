<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Service\Access;

use OCP\Calendar\ICalendar;
use OCP\Calendar\IManager;
use Psr\Log\LoggerInterface;

/**
 * Access verifier for calendar objects.
 *
 * Uses the core {@see \OCP\Calendar\IManager} (no cross-app dependency) to
 * enumerate the user's own calendars and check the event's calendar is among
 * them. The MCP server has no calendar verifier yet, so the exact identifier a
 * calendar result carries is unconfirmed — when no recognizable calendar
 * identifier is present, or enumeration fails, this DELEGATEs rather than guess.
 */
final class CalendarAccessVerifier implements AccessVerifierInterface {
	/** @psalm-suppress PossiblyUnusedMethod — constructed via DI. */
	public function __construct(
		private IManager $calendarManager,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function docTypes(): array {
		return ['calendar', 'calendar_event'];
	}

	#[\Override]
	public function verify(string $uid, array $doc): AccessDecision {
		$identifier = $this->calendarIdentifier($doc);
		if ($identifier === null) {
			return AccessDecision::DELEGATE;
		}

		try {
			$calendars = $this->calendarManager->getCalendarsForPrincipal('principals/users/' . $uid);
			foreach ($calendars as $calendar) {
				if (!$calendar instanceof ICalendar) {
					continue;
				}
				if ($calendar->getUri() === $identifier || $calendar->getKey() === $identifier) {
					return AccessDecision::ALLOWED;
				}
			}
			// The user has calendars but none matches ⇒ the event belongs to a
			// calendar they can't see. If they have none at all this is also a
			// deny (there is nothing to match).
			return AccessDecision::DENIED;
		} catch (\Throwable $e) {
			$this->logger->debug('Calendar access check could not decide; delegating', ['error' => $e->getMessage()]);
			return AccessDecision::DELEGATE;
		}
	}

	/**
	 * The calendar uri/key identifying the result's calendar, if present.
	 *
	 * @param array{doc_type?: string, id?: mixed, metadata?: array<string, mixed>} $doc
	 */
	private function calendarIdentifier(array $doc): ?string {
		$metadata = $doc['metadata'] ?? [];
		foreach (['calendar_uri', 'calendar_id', 'calendar'] as $key) {
			if (isset($metadata[$key]) && is_string($metadata[$key]) && $metadata[$key] !== '') {
				return $metadata[$key];
			}
		}
		return null;
	}
}
