<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Settings;

/**
 * The MCP connection settings (system config) and the rules a value must meet
 * before it is stored.
 *
 * The usual failure is a URL or client id pasted with a stray space at either
 * end: stored verbatim, it makes every request to the MCP server fail with an
 * error that says nothing about whitespace. The admin form applies the same
 * rules as the admin types (src/components/admin/connectionValidation.js);
 * this is the server-side gate, so a direct API call cannot store one either.
 */
final class ConnectionSettings {
	public const SERVER_URL = 'mcp_server_url';
	public const PUBLIC_URL = 'mcp_server_public_url';
	public const CLIENT_ID = 'astrolabe_client_id';
	public const WEBHOOK_SECRET = 'mcp_webhook_secret';

	// Whitespace (incl. Unicode spaces such as NBSP) and invisible control/format
	// characters (incl. zero-width space), none of which belong in a URL, client
	// id or secret.
	private const INVALID_CHAR = '[\s\p{Z}\p{C}]';

	/**
	 * Validate the submitted values, keyed by config key.
	 *
	 * An empty value is allowed everywhere (the setting is simply unconfigured;
	 * for the webhook secret it means "keep the stored one").
	 *
	 * @param array<string, string> $values
	 * @return array<string, string> config key => error message, empty when valid
	 */
	public static function validate(array $values): array {
		$errors = [];
		foreach ($values as $key => $value) {
			$error = self::characterError($value);
			if ($error === null && $value !== '' && in_array($key, [self::SERVER_URL, self::PUBLIC_URL], true)) {
				$error = self::urlError($value);
			}
			if ($error !== null) {
				$errors[$key] = $error;
			}
		}
		return $errors;
	}

	private static function characterError(string $value): ?string {
		$edge = preg_match('/^' . self::INVALID_CHAR . '|' . self::INVALID_CHAR . '$/u', $value);
		if ($edge === false) {
			return 'Contains invalid characters.';
		}
		if ($edge === 1) {
			return 'Remove the space or invisible character at the start or end.';
		}
		if (preg_match('/' . self::INVALID_CHAR . '/u', $value) === 1) {
			return 'Must not contain spaces or invisible characters.';
		}
		return null;
	}

	private static function urlError(string $value): ?string {
		$parts = parse_url($value);
		if (!is_array($parts)
			|| !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
			|| ($parts['host'] ?? '') === '') {
			return 'Must be a full http:// or https:// URL.';
		}
		return null;
	}
}
