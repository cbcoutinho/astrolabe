import { translate as t } from '@nextcloud/l10n'

// Mirrors lib/Settings/ConnectionSettings.php, which re-checks on save — keep
// the two in step. Whitespace (incl. Unicode spaces such as NBSP) and invisible
// control/format characters (incl. zero-width space) never belong in these values.
const INVALID_CHAR = /[\s\p{Z}\p{C}]/u
const INVALID_EDGE = /^[\s\p{Z}\p{C}]|[\s\p{Z}\p{C}]$/u

/**
 * Validate one connection setting.
 *
 * @param {string} value the value as typed
 * @param {{ url?: boolean }} options `url` also requires a full http(s) URL
 * @return {string} the error message, or '' when valid (empty is valid: unconfigured)
 */
export function connectionFieldError(value, { url = false } = {}) {
	if (value === '') {
		return ''
	}
	if (INVALID_EDGE.test(value)) {
		return t('astrolabe', 'Remove the space or invisible character at the start or end.')
	}
	if (INVALID_CHAR.test(value)) {
		return t('astrolabe', 'Must not contain spaces or invisible characters.')
	}
	if (!url) {
		return ''
	}
	const parsed = parseHttpUrl(value)
	if (parsed === null) {
		return t('astrolabe', 'Must be a full http:// or https:// URL.')
	}
	// Credentials in the URL would end up in config.php and in log lines.
	if (parsed.username !== '' || parsed.password !== '') {
		return t('astrolabe', 'Must not contain a username or password.')
	}
	return ''
}

/**
 * @param {string} value candidate URL
 * @return {URL|null} the parsed http(s) URL with a host, or null
 */
function parseHttpUrl(value) {
	// `new URL` collapses extra slashes after the scheme ("https:///path" gets
	// host "path"); PHP's parse_url() rejects that, so require the host to follow
	// "//" directly, as the backend does.
	if (!/^https?:\/\/[^/]/i.test(value)) {
		return null
	}
	try {
		const parsed = new URL(value)
		return parsed.hostname !== '' ? parsed : null
	} catch {
		return null
	}
}
