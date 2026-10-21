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
	if (url && !isHttpUrl(value)) {
		return t('astrolabe', 'Must be a full http:// or https:// URL.')
	}
	return ''
}

/**
 * @param {string} value candidate URL
 * @return {boolean} whether it parses as an http(s) URL with a host
 */
function isHttpUrl(value) {
	try {
		const parsed = new URL(value)
		return ['http:', 'https:'].includes(parsed.protocol) && parsed.hostname !== ''
	} catch {
		return false
	}
}
