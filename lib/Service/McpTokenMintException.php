<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Service;

/**
 * Thrown by McpTokenMinter when an MCP access token cannot be issued
 * for the current Nextcloud user (no client configured, the `oidc` app
 * is not installed, or the listener returned no token).
 *
 * Distinct exception type so controllers can map it to a 503 / 412
 * with a clear remediation message instead of leaking the generic
 * Exception path.
 */
class McpTokenMintException extends \RuntimeException {
}
