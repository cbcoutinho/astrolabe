import { getCurrentUser, getRequestToken } from '@nextcloud/auth'
import { generateRemoteUrl } from '@nextcloud/router'
import { PDFDataRangeTransport } from 'pdfjs-dist'

/**
 * Byte-ranged PDF loading straight from Nextcloud's WebDAV endpoint.
 *
 * The MCP server used to rasterize pages for us, which meant it buffered the
 * whole document into the API pod (``read_file``) before it could render a
 * single page — a 251 MB scan OOMKilled the pod. The file already lives in
 * Nextcloud and the browser already holds the user's session, so we fetch the
 * bytes here instead and never involve the backend.
 *
 * Nextcloud *honours* Range requests (they come back 206 with a correct
 * Content-Range) but does **not** advertise ``Accept-Ranges``. PDF.js gates its
 * automatic range mode on that header, so left to its own detection it would
 * decide ranges are unsupported and download the entire file. Driving the
 * transport explicitly is what stops that.
 *
 * Measured cost is still substantial for these documents: one page of a 397 MB,
 * non-linearized scan takes ~793 range requests and ~50 MB, because PDF.js has
 * to seek to each object individually with no hint tables to guide it.
 * Linearizing at ingest is the fix for that and is tracked separately.
 */

/**
 * Build the WebDAV URL for a path inside the current user's home.
 *
 * Each segment is encoded separately so that slashes stay path separators
 * while spaces and other unsafe characters in filenames are escaped.
 *
 * @param {string} filePath Path relative to the user's home, e.g. "/dir/doc.pdf"
 * @return {string} Absolute same-origin WebDAV URL
 */
export function webdavUrlForPath(filePath) {
	const uid = getCurrentUser()?.uid
	if (!uid) {
		throw new Error('No current user; cannot build a WebDAV URL')
	}
	const encoded = String(filePath)
		.split('/')
		.filter((segment) => segment !== '')
		.map((segment) => encodeURIComponent(segment))
		.join('/')

	return `${generateRemoteUrl(`dav/files/${encodeURIComponent(uid)}`)}/${encoded}`
}

/**
 * Resolve a Nextcloud fileId to a WebDAV URL inside the current user's tree.
 *
 * Preferred over {@link webdavUrlForPath} whenever a fileId is known, because
 * the indexed path belongs to the file's *owner*: Nextcloud mounts a received
 * share at the recipient's root under a different path, so addressing a shared
 * file by the owner's path 404s. A SEARCH (RFC 5323) over the user's whole
 * tree filtered on oc:fileid resolves owned files, directly-shared files and
 * files reachable through a shared parent alike — the same mechanism the
 * backend's ``file_accessible_by_id`` relies on.
 *
 * @param {number} fileId Nextcloud internal file ID
 * @param {AbortSignal} [signal] Cancels the request
 * @return {Promise<string|null>} Absolute WebDAV URL, or null if not found
 */
export async function resolveWebdavUrlByFileId(fileId, signal) {
	const uid = getCurrentUser()?.uid
	if (!uid) {
		throw new Error('No current user; cannot resolve a file by ID')
	}
	const scope = `/files/${uid}`
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')

	const body = `<?xml version="1.0" encoding="UTF-8"?>
<d:searchrequest xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
	<d:basicsearch>
		<d:select><d:prop><oc:fileid/></d:prop></d:select>
		<d:from><d:scope><d:href>${scope}</d:href><d:depth>infinity</d:depth></d:scope></d:from>
		<d:where><d:eq><d:prop><oc:fileid/></d:prop><d:literal>${Number(fileId)}</d:literal></d:eq></d:where>
		<d:limit><d:nresults>1</d:nresults></d:limit>
	</d:basicsearch>
</d:searchrequest>`

	const response = await davFetch(generateRemoteUrl('dav') + '/', {
		method: 'SEARCH',
		headers: { 'Content-Type': 'text/xml' },
		body,
		signal,
	})
	if (!response.ok) {
		return null
	}

	const doc = new DOMParser().parseFromString(await response.text(), 'application/xml')
	// Sabre percent-encodes the href it returns, so it is used verbatim as a
	// URL rather than re-encoded.
	const href = doc.getElementsByTagNameNS('DAV:', 'href')[0]?.textContent
	return href ? new URL(href, window.location.origin).toString() : null
}

/**
 * Same-origin fetch carrying the session cookie and CSRF token.
 *
 * @param {string} url Target URL
 * @param {object} init Additional fetch options
 * @return {Promise<Response>} The fetch response
 */
function davFetch(url, init = {}) {
	return fetch(url, {
		credentials: 'same-origin',
		...init,
		headers: {
			requesttoken: getRequestToken() ?? '',
			...(init.headers ?? {}),
		},
	})
}

/**
 * Resolve a document's byte length without downloading it.
 *
 * @param {string} url WebDAV URL
 * @param {AbortSignal} [signal] Cancels the request
 * @return {Promise<number>} Size in bytes
 */
export async function fetchContentLength(url, signal) {
	const response = await davFetch(url, { method: 'HEAD', signal })
	if (!response.ok) {
		throw new Error(`HEAD ${response.status} while sizing PDF`)
	}
	const length = Number(response.headers.get('content-length'))
	if (!Number.isFinite(length) || length <= 0) {
		throw new Error('PDF has no usable Content-Length')
	}
	return length
}

// PDF.js range-chunk size, and the initial slice handed to the transport. The
// initial read must exceed RANGE_CHUNK_SIZE or it buys nothing (pdf.js's own
// test makes the same point), while staying far below the whole document —
// this is what a one-page view actually costs.
export const RANGE_CHUNK_SIZE = 65536
const INITIAL_BYTES = RANGE_CHUNK_SIZE * 2

/**
 * Read the leading bytes PDF.js needs to bootstrap the document.
 *
 * Takes a signal so a superseded load can stop this transfer rather than let it
 * complete and be discarded — every other range request is already cancellable
 * through WebDavRangeTransport.abort().
 *
 * @param {string} url WebDAV URL
 * @param {number} length Total document size
 * @param {AbortSignal} [signal] Cancels the request
 * @return {Promise<Uint8Array>} The opening slice of the file
 */
export async function fetchInitialData(url, length, signal) {
	const end = Math.min(INITIAL_BYTES, length) - 1
	const response = await davFetch(url, { headers: { Range: `bytes=0-${end}` }, signal })
	if (response.status !== 206 && response.status !== 200) {
		throw new Error(`Could not read the start of the PDF (HTTP ${response.status})`)
	}
	return new Uint8Array(await response.arrayBuffer())
}

/**
 * Resolve a search result to the WebDAV URL and byte length to read from.
 *
 * Tries the indexed path first and only falls back to a fileId SEARCH when
 * that misses. The path is correct for files the user owns — the common case —
 * and the HEAD needed to size the document doubles as the existence check, so
 * the happy path costs one cheap request. The fallback covers shares, which
 * Nextcloud mounts at a different path than the owner indexed; that SEARCH
 * walks the user's whole tree at depth infinity, so it is worth avoiding when
 * the path already resolves.
 *
 * @param {string} filePath Indexed path, relative to the owner's home
 * @param {number|null} fileId Nextcloud fileId, when known
 * @param {AbortSignal} [signal] Cancels the lookup requests
 * @return {Promise<{url: string, length: number}>} Where and how big
 */
export async function resolveDocument(filePath, fileId, signal) {
	if (filePath) {
		try {
			const url = webdavUrlForPath(filePath)
			return { url, length: await fetchContentLength(url, signal) }
		} catch (error) {
			// Deliberately broad: a miss here is the expected outcome for a file
			// shared with this user, and the fileId lookup below is the real
			// answer for that case. But it also swallows transient failures
			// (network, 5xx), which would otherwise surface to the user as a
			// bare "not found" — so the cause is logged rather than discarded.
			console.debug('PDF path lookup failed, falling back to fileId', {
				filePath,
				error,
			})
		}
	}

	if (fileId === null || fileId === undefined) {
		throw new Error(`PDF not found at ${filePath} and no fileId to fall back to`)
	}

	const url = await resolveWebdavUrlByFileId(fileId, signal)
	if (!url) {
		throw new Error(`PDF with fileId ${fileId} is not accessible`)
	}
	return { url, length: await fetchContentLength(url, signal) }
}

/**
 * PDF.js transport that satisfies range requests from Nextcloud WebDAV.
 */
export class WebDavRangeTransport extends PDFDataRangeTransport {
	/**
	 * @param {number} length Total size of the document in bytes
	 * @param {string} url WebDAV URL to read ranges from
	 * @param {object} [options] Optional settings
	 * @param {(error: Error) => void} [options.onError] Called when a range
	 *   cannot be delivered, so the viewer can surface a real message instead
	 *   of waiting forever on bytes that will never arrive.
	 */
	constructor(length, url, initialData, { onError } = {}) {
		// initialData is mandatory, not an optimisation: PDF.js bootstraps the
		// document from the full reader's first chunk and only then switches to
		// ranges. Constructed with null the load simply never settles, and the
		// viewer spins forever. pdf.js's own "fetch document info and page
		// using only ranges" test seeds it the same way.
		//
		// progressiveDone stays false — this transport does send more data,
		// just through requestDataRange rather than progressively.
		super(length, initialData, false)
		this.url = url
		this.onError = onError
		this.controllers = new Set()
	}

	/**
	 * Fetch [begin, end) and hand it to PDF.js.
	 *
	 * A failed range is NOT reported back through `onDataRange(begin, null)`:
	 * PDF.js funnels the chunk through `new Uint8Array(val).buffer`, so `null`
	 * arrives as a valid *zero-length* chunk rather than an error. That
	 * silently truncates the document instead of failing it. The failure is
	 * raised out-of-band via `onError` instead.
	 *
	 * One retry first, since these are large transfers over links where a
	 * single dropped range is more likely to be transient than fatal.
	 *
	 * @param {number} begin First byte offset (inclusive)
	 * @param {number} end Last byte offset (exclusive)
	 */
	requestDataRange(begin, end) {
		const controller = new AbortController()
		this.controllers.add(controller)

		const attempt = async () => {
			const response = await davFetch(this.url, {
				// end is exclusive for PDF.js, inclusive for HTTP.
				headers: { Range: `bytes=${begin}-${end - 1}` },
				signal: controller.signal,
			})
			if (response.status !== 206) {
				throw new Error(`Expected 206 for range request, got ${response.status}`)
			}
			return new Uint8Array(await response.arrayBuffer())
		}

		attempt()
			.catch((error) => {
				if (error.name === 'AbortError') {
					throw error
				}
				return attempt()
			})
			.then((chunk) => {
				this.onDataRange(begin, chunk)
			})
			.catch((error) => {
				if (error.name === 'AbortError') {
					return
				}
				console.error('PDF range request failed', { begin, end, error })
				this.onError?.(error)
			})
			.finally(() => {
				this.controllers.delete(controller)
			})
	}

	/**
	 * Cancel every in-flight range request.
	 *
	 * Called when the viewer closes or switches documents so that a large
	 * outstanding read does not keep streaming into a discarded document.
	 */
	abort() {
		for (const controller of this.controllers) {
			controller.abort()
		}
		this.controllers.clear()
	}
}
