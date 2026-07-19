/**
 * SPDX-FileCopyrightText: 2025 Astrolabe contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Integration tests for browser-side PDF rasterization.
 *
 * PDF pages used to be rendered by the MCP server, which had to buffer the
 * whole document into the API pod to do it — a 251 MB scan OOMKilled the pod,
 * and the resulting 503 surfaced as a 5xx and a blank viewer. Rendering now
 * happens in the browser against the copy already in Nextcloud.
 *
 * These tests pin the two things that decide whether that works, both of which
 * depend on real server behaviour rather than on our own code:
 *
 *   1. Nextcloud serves byte ranges (206 + Content-Range) for a WebDAV GET.
 *      It does NOT advertise `Accept-Ranges`, which is why the viewer drives
 *      PDFDataRangeTransport explicitly instead of letting PDF.js auto-detect
 *      range support — auto-detection would fall back to downloading the whole
 *      file, which is the regression this test guards against.
 *   2. The removed `/apps/astrolabe/api/pdf-preview` route is really gone, so
 *      nothing silently falls back to server-side rendering.
 *
 * Rendering a chunk through the UI needs an indexed PDF and is covered by the
 * search flow; what is asserted here is the transport contract underneath it.
 */

import { expect, test } from './fixtures.ts'

// Nextcloud's global, available inside page.evaluate but not to the Node-side
// type checker.
declare const OC: { getCurrentUser(): { uid: string }, requestToken: string }

const NC = 'http://localhost:8080'
const ADMIN_AUTH = 'Basic ' + Buffer.from('admin:admin').toString('base64')
const PDF_PATH = 'astrolabe-e2e-range.pdf'

/**
 * Smallest structurally valid PDF that pdf.js will parse, padded so that a
 * partial range is meaningfully smaller than the whole file.
 */
function tinyPdf(): Buffer {
	const body = [
		'%PDF-1.4',
		'1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj',
		'2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj',
		'3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj',
		// Padding keeps the file comfortably larger than the range we request.
		'%' + 'x'.repeat(4096),
		'trailer<</Root 1 0 R>>',
		'%%EOF',
	].join('\n')
	return Buffer.from(body, 'latin1')
}

test.describe('browser-side PDF rendering', () => {
	test.beforeAll(async () => {
		const response = await fetch(`${NC}/remote.php/dav/files/admin/${PDF_PATH}`, {
			method: 'PUT',
			headers: { Authorization: ADMIN_AUTH, 'Content-Type': 'application/pdf' },
			body: new Uint8Array(tinyPdf()),
		})
		expect([201, 204]).toContain(response.status)
	})

	test.afterAll(async () => {
		await fetch(`${NC}/remote.php/dav/files/admin/${PDF_PATH}`, {
			method: 'DELETE',
			headers: { Authorization: ADMIN_AUTH },
		})
	})

	test('Nextcloud serves byte ranges so one page does not pull the whole file', async ({ authenticatedPage: page }) => {
		await page.goto('/apps/astrolabe')

		const result = await page.evaluate(async (path) => {
			const uid = OC.getCurrentUser().uid
			const url = `/remote.php/dav/files/${uid}/${path}`
			const response = await fetch(url, {
				headers: { requesttoken: OC.requestToken, Range: 'bytes=0-63' },
			})
			const body = await response.arrayBuffer()
			return {
				status: response.status,
				contentRange: response.headers.get('content-range'),
				bytes: body.byteLength,
				magic: new TextDecoder().decode(new Uint8Array(body.slice(0, 5))),
			}
		}, PDF_PATH)

		// 206 (not 200) is the whole point: a 200 here means the server ignored
		// the Range header and the viewer would transfer entire documents.
		expect(result.status).toBe(206)
		expect(result.bytes).toBe(64)
		expect(result.contentRange).toMatch(/^bytes 0-63\/\d+$/)
		expect(result.magic).toBe('%PDF-')
	})

	test('the server-side pdf-preview route no longer exists', async ({ authenticatedPage: page }) => {
		const status = await page.evaluate(async () => {
			const response = await fetch(
				'/apps/astrolabe/api/pdf-preview?file_path=/x.pdf&page=1',
				{ headers: { requesttoken: OC.requestToken } },
			)
			return response.status
		})

		expect(status).toBe(404)
	})

	test('the app grants itself worker-src blob: so PDF.js can start its worker', async ({ authenticatedPage: page }) => {
		const response = await page.goto('/apps/astrolabe')
		const csp = response?.headers()['content-security-policy'] ?? ''

		// Without this the worker is blocked: Nextcloud's default policy has no
		// worker-src, so it falls back to a nonce-only script-src that a worker
		// URL can never satisfy.
		expect(csp).toContain('worker-src')
		expect(csp).toMatch(/worker-src[^;]*blob:/)
	})
})
