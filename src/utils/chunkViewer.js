/**
 * SPDX-FileCopyrightText: 2025 Astrolabe contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Pure helpers behind the document viewer (DocumentViewer.vue), kept free of
 * DOM-bound imports so they can be tested under `node --test`.
 */

/**
 * Query parameters for the chunk-context endpoint.
 *
 * @param {object} result The search result to open.
 * @return {object} Parameters for GET /apps/astrolabe/api/chunk-context.
 */
export function chunkContextParams(result) {
	const params = {
		doc_type: result.doc_type,
		doc_id: result.id,
		start: result.chunk_start_offset,
		end: result.chunk_end_offset,
	}
	// Pass chunk_index/total_chunks when known so the MCP server can
	// look up the chunk by the always-indexed chunk_index field
	// (faster and more robust than offset-based filtering).
	if (result.chunk_index !== undefined && result.chunk_index !== null) {
		params.chunk_index = result.chunk_index
	}
	if (result.total_chunks !== undefined && result.total_chunks !== null) {
		params.total_chunks = result.total_chunks
	}
	// Identifiers the astrolabe-side access check needs for non-file
	// doc types (files/notes are keyed by doc_id). Absent values just
	// fall through to the MCP server's verify-on-read backstop.
	const meta = result.metadata || {}
	if (meta.board_id !== undefined && meta.board_id !== null) {
		params.board_id = meta.board_id
	}
	if (meta.mailbox_id !== undefined && meta.mailbox_id !== null) {
		params.mailbox_id = meta.mailbox_id
	}
	if (meta.calendar_uri) {
		params.calendar_uri = meta.calendar_uri
	}
	if (meta.path) {
		params.path = meta.path
	}
	return params
}

/**
 * Whether a result is a CSV file, shown as a table rather than as markdown.
 *
 * @param {object} result The search result.
 * @return {boolean} True for CSV files.
 */
export function isCsvResult(result) {
	return result.doc_type === 'file'
		&& (result.metadata?.mime_type === 'text/csv'
			|| /\.csv$/i.test(result.metadata?.path || ''))
}

/**
 * Combine before/chunk/after context into a single markdown string, the chunk
 * set off by horizontal rules.
 *
 * @param {{before?: string, chunk?: string, after?: string}} context Chunk context.
 * @return {string} Markdown.
 */
export function chunkMarkdown(context) {
	let content = ''

	if (context.before) {
		content += context.before + '\n\n'
	}

	if (context.chunk) {
		// Highlight the main chunk with a separator
		content += '---\n\n'
		content += context.chunk
		content += '\n\n---'
	}

	if (context.after) {
		content += '\n\n' + context.after
	}

	return content
}

/**
 * An image document is already the picture a vision model needs, so it is
 * passed by file id with nothing staged.
 *
 * @param {object} result The search result currently open.
 * @return {number[]} File ids to send, if any.
 */
export function imageFileIdsForSummary(result) {
	const isImage = result.doc_type === 'file'
		&& typeof result.metadata?.mime_type === 'string'
		&& result.metadata.mime_type.startsWith('image/')
	const fileId = Number(result.id)
	return isImage && Number.isInteger(fileId) && fileId > 0 ? [fileId] : []
}

/**
 * The PDF pages to rasterize for a summary: up to `size` pages starting one
 * before the page being viewed, clamped to the document.
 *
 * A window rather than the whole document, because vision tokens dominate the
 * cost of these calls and the backend caps the count anyway. Centring on the
 * current page keeps the summary about the part the user is looking at.
 *
 * @param {number} current The page being viewed (1-based).
 * @param {number} total Total pages; falsy is treated as 1.
 * @param {number} size Maximum pages in the window.
 * @return {number[]} Page numbers, ascending.
 */
export function summaryPageWindow(current, total, size) {
	const last = total || 1
	const first = Math.max(1, Math.min(current, last) - 1)
	const pages = []
	for (let page = first; page <= last && pages.length < size; page++) {
		pages.push(page)
	}
	return pages
}

/**
 * The Nextcloud fileId of a file result, as an integer, or null.
 *
 * @param {number|string} id The result's id (the fileId for file doc types).
 * @return {number|null} The fileId.
 */
export function pdfDocId(id) {
	return Number.isInteger(id) ? id : (Number.parseInt(id, 10) || null)
}

/**
 * The viewer state for a loaded chunk-context response: a PDF page with its
 * bounding box when the server located the chunk on a page, otherwise the
 * text (or CSV) passage with its surrounding context.
 *
 * @param {object} result The search result being opened.
 * @param {object} data The chunk-context response body.
 * @return {object} Viewer data fields to assign.
 */
export function chunkViewerState(result, data) {
	if (result.doc_type === 'file' && data.page_number) {
		return {
			viewerType: 'pdf',
			currentPdfPath: result.metadata?.path || '',
			currentPdfDocId: pdfDocId(result.id),
			viewerPage: data.page_number,
			currentBbox: data.chunk_bbox || [],
			currentBboxPage: data.page_number,
		}
	}
	return {
		viewerType: isCsvResult(result) ? 'csv' : 'text',
		viewerContext: {
			chunk: data.chunk_text || '',
			before: data.before_context || '',
			after: data.after_context || '',
			// Absent flag = unknown: assume truncated, so no
			// partial line is shown and no bogus header guessed.
			hasMoreBefore: data.has_more_before !== false,
			hasMoreAfter: data.has_more_after !== false,
		},
	}
}

/**
 * The text fields of a summary request for the passage around `result`.
 * Optional fields are omitted when absent; the access-check identifiers let
 * the backend re-verify this user still reaches the document before anything
 * is generated.
 *
 * @param {object} result The search result currently open.
 * @return {[string, string][]} Form fields, in order.
 */
export function summaryFormFields(result) {
	const fields = [
		['doc_type', result.doc_type ?? ''],
		['doc_id', String(result.id ?? '')],
		['start', String(result.chunk_start_offset ?? 0)],
		['end', String(result.chunk_end_offset ?? 0)],
	]
	if (result.chunk_index !== undefined && result.chunk_index !== null) {
		fields.push(['chunk_index', String(result.chunk_index)])
	}
	if (result.total_chunks !== undefined && result.total_chunks !== null) {
		fields.push(['total_chunks', String(result.total_chunks)])
	}
	for (const key of ['board_id', 'mailbox_id', 'calendar_uri', 'path']) {
		const value = result.metadata?.[key]
		if (value !== undefined && value !== null && value !== '') {
			fields.push([key, String(value)])
		}
	}
	return fields
}
