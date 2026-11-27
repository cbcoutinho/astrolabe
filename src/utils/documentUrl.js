/**
 * SPDX-FileCopyrightText: 2025 Astrolabe contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The URL that opens a search result in its source app.
 *
 * `toUrl` maps an app path to a full URL (`generateUrl` from
 * `@nextcloud/router` in the browser). It is a parameter so this module stays
 * free of DOM-bound imports and can be tested under `node --test`.
 *
 * @param {object} result A search result (doc_type, id/note_id, metadata).
 * @param {(path: string) => string} toUrl Maps an app path to a URL.
 * @return {string} The URL to open.
 */
export function documentUrl(result, toUrl) {
	const docType = result.doc_type || 'unknown'
	const id = result.id || result.note_id
	const metadata = result.metadata || {}

	switch (docType) {
		case 'note':
			return toUrl(`/apps/notes/#/note/${id}`)
		case 'file':
			if (id) {
				return toUrl(`/apps/files/files/${id}?dir=/&editing=false&openfile=true`)
			}
			return toUrl('/apps/files/')
		case 'deck_card':
			if (metadata.board_id && id) {
				return toUrl(`/apps/deck/board/${metadata.board_id}/card/${id}`)
			}
			return toUrl('/apps/deck/')
		case 'calendar':
		case 'calendar_event':
			return toUrl('/apps/calendar/')
		case 'news_item':
			// Use external article URL if available, otherwise fall back to News app
			if (metadata.url) {
				return metadata.url
			}
			return toUrl('/apps/news/')
		case 'mail_message':
			// The Mail app's per-message route is a client-side hash built
			// from account/mailbox/thread ids that aren't carried in the
			// indexed metadata, so there's no stable deep link to a single
			// message — fall back to the Mail app root.
			return toUrl('/apps/mail/')
		case 'contact':
			return toUrl('/apps/contacts/')
		default:
			return toUrl('/apps/astrolabe/')
	}
}
