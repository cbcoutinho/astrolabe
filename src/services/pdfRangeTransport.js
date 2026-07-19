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
 * transport explicitly is what keeps a one-page render to a few hundred KB.
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
 * @return {Promise<string|null>} Absolute WebDAV URL, or null if not found
 */
export async function resolveWebdavUrlByFileId(fileId) {
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
 * @return {Promise<number>} Size in bytes
 */
export async function fetchContentLength(url) {
	const response = await davFetch(url, { method: 'HEAD' })
	if (!response.ok) {
		throw new Error(`HEAD ${response.status} while sizing PDF`)
	}
	const length = Number(response.headers.get('content-length'))
	if (!Number.isFinite(length) || length <= 0) {
		throw new Error('PDF has no usable Content-Length')
	}
	return length
}

/**
 * PDF.js transport that satisfies range requests from Nextcloud WebDAV.
 */
export class WebDavRangeTransport extends PDFDataRangeTransport {
	/**
	 * @param {number} length Total size of the document in bytes
	 * @param {string} url WebDAV URL to read ranges from
	 */
	constructor(length, url) {
		// No initial data and not progressive: every byte arrives through
		// requestDataRange, which is what keeps the transfer proportional to
		// the pages actually viewed rather than to the file size.
		super(length, null, false)
		this.url = url
		this.controllers = new Set()
	}

	/**
	 * Fetch [begin, end) and hand it to PDF.js.
	 *
	 * PDF.js treats a missing range as a fatal document error, so a failed
	 * fetch is reported rather than silently dropped.
	 *
	 * @param {number} begin First byte offset (inclusive)
	 * @param {number} end Last byte offset (exclusive)
	 */
	requestDataRange(begin, end) {
		const controller = new AbortController()
		this.controllers.add(controller)

		davFetch(this.url, {
			// end is exclusive for PDF.js, inclusive for HTTP.
			headers: { Range: `bytes=${begin}-${end - 1}` },
			signal: controller.signal,
		})
			.then(async (response) => {
				if (response.status !== 206) {
					throw new Error(`Expected 206 for range request, got ${response.status}`)
				}
				const buffer = await response.arrayBuffer()
				this.onDataRange(begin, new Uint8Array(buffer))
			})
			.catch((error) => {
				if (error.name === 'AbortError') {
					return
				}
				console.error('PDF range request failed', { begin, end, error })
				this.onDataRange(begin, null)
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
