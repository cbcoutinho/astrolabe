<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Service\Access;

/**
 * Outcome of an access check for a single document.
 *
 * DELEGATE means the local verifier can't authoritatively decide (the source
 * app isn't installed, an identifier is missing, or no verifier is registered
 * for the doc type) — the caller should fall through to the MCP server's
 * verify-on-read backstop rather than allow or deny outright.
 */
enum AccessDecision {
	case ALLOWED;
	case DENIED;
	case DELEGATE;
}
