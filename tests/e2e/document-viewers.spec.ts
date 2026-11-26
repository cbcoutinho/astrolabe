/**
 * SPDX-FileCopyrightText: 2026 Astrolabe contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * A scanned PDF renders in both document viewers.
 *
 * Scanners write page images as JPEG 2000 (or JBIG2), and pdf.js 4+ only
 * decodes those with WebAssembly. Each viewer runs under its own CSP, and a CSP
 * that refuses wasm leaves every scanned page blank, while text PDFs still
 * look fine. That is why these tests use a scan. The page CSP of "Show Chunk"
 * is Nextcloud's own. "Open in App" runs the MCP server's viewer in a sandboxed
 * frame under the CSP from McpAppController::viewCsp(), which lacked
 * 'wasm-unsafe-eval' before this test was added.
 *
 * Search, chunk-context and the viewer's tool calls are faked: the stack has
 * no OCR, so a scan cannot be indexed. What stays real is what broke: the PDF
 * bytes, the viewer HTML served by the MCP server, the view route and its CSP
 * header, pdf.js and its decoders.
 *
 * fixtures/scanned-jpx.pdf is one 300x400 page with no text, holding a single
 * /JPXDecode image: a navy band on white. A page counts as rendered when its
 * canvas holds navy pixels.
 */

import type { Frame, Page } from '@playwright/test'

import { readFileSync } from 'node:fs'
import { expect, test } from './fixtures.ts'

const NC = 'http://localhost:8080'
const ADMIN_AUTH = 'Basic ' + Buffer.from('admin:admin').toString('base64')
const PDF_PATH = 'astrolabe-e2e-scanned.pdf'
const PDF = readFileSync(new URL('./fixtures/scanned-jpx.pdf', import.meta.url))

let fileId = 0

/** Navy pixels across every canvas in the document: the decoded JPX band. */
function navyPixels(): number {
	let count = 0
	for (const canvas of Array.from(document.querySelectorAll('canvas'))) {
		if (!canvas.width || !canvas.height) {
			continue
		}
		const ctx = canvas.getContext('2d')
		if (!ctx) {
			continue
		}
		const px = ctx.getImageData(0, 0, canvas.width, canvas.height).data
		for (let i = 0; i < px.length; i += 4) {
			if (px[i + 2] > 90 && px[i] < 60 && px[i + 1] < 60) {
				count++
			}
		}
	}
	return count
}

/** Fake one search hit for the uploaded scan, then run the search. */
async function searchForScan(page: Page): Promise<void> {
	await page.route('**/apps/astrolabe/api/search?**', async (route) => {
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				success: true,
				algorithm_used: 'hybrid',
				total_documents: 1,
				results: [{
					id: fileId,
					doc_type: 'file',
					title: PDF_PATH,
					excerpt: 'A scanned page.',
					score: 0.03,
					relevance: 0.9,
					relevance_source: 'fusion_ordinal',
					metadata: { path: `/${PDF_PATH}`, mime_type: 'application/pdf', chunk_index: 0, total_chunks: 1 },
					chunk_index: 0,
					total_chunks: 1,
					chunk_start_offset: 0,
					chunk_end_offset: 15,
					page_number: 1,
					page_count: 1,
				}],
			}),
		})
	})
	await page.goto('/apps/astrolabe')
	const input = page.getByRole('textbox', { name: 'Search query' })
	await input.fill('scanned page')
	await input.press('Control+Enter')
	await expect(page.locator('.mcp-result-item')).toHaveCount(1)
}

/** Collects pdf.js decoder failures, which are logged as console warnings. */
function watchDecoderFailures(page: Page): string[] {
	const failures: string[] = []
	page.on('console', (msg) => {
		const text = msg.text()
		if (/JpxError|OpenJPEG failed|WebAssembly\.instantiate|wasm-unsafe-eval/.test(text)) {
			failures.push(text)
		}
	})
	return failures
}

test.describe('a scanned PDF renders in both document viewers', () => {
	test.beforeAll(async () => {
		const put = await fetch(`${NC}/remote.php/dav/files/admin/${PDF_PATH}`, {
			method: 'PUT',
			headers: { Authorization: ADMIN_AUTH, 'Content-Type': 'application/pdf' },
			body: new Uint8Array(PDF),
		})
		expect([201, 204]).toContain(put.status)
		const propfind = await fetch(`${NC}/remote.php/dav/files/admin/${PDF_PATH}`, {
			method: 'PROPFIND',
			headers: { Authorization: ADMIN_AUTH, Depth: '0', 'Content-Type': 'application/xml' },
			body: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>',
		})
		fileId = Number(/<oc:fileid>(\d+)<\/oc:fileid>/.exec(await propfind.text())?.[1])
		expect(fileId).toBeGreaterThan(0)
	})

	test.afterAll(async () => {
		await fetch(`${NC}/remote.php/dav/files/admin/${PDF_PATH}`, {
			method: 'DELETE',
			headers: { Authorization: ADMIN_AUTH },
		})
	})

	test('Show Chunk draws the scanned page', async ({ authenticatedPage: page }) => {
		const failures = watchDecoderFailures(page)
		await page.route('**/apps/astrolabe/api/chunk-context?**', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ success: true, page_number: 1, chunk_text: 'A scanned page.', chunk_bbox: [] }),
			})
		})
		await searchForScan(page)

		await page.getByRole('button', { name: 'Show Chunk' }).click()

		await expect(page.locator('.pdf-viewer canvas.pdf-page-image')).toBeVisible()
		await expect.poll(() => page.evaluate(navyPixels), { timeout: 30_000 }).toBeGreaterThan(500)
		expect(failures).toEqual([])
	})

	test('Open in App draws the scanned page inside the sandboxed viewer', async ({ authenticatedPage: page }) => {
		const failures = watchDecoderFailures(page)
		// The viewer's own tool calls, answered for the uploaded file. The view
		// route itself is not faked: it serves the MCP server's HTML with the CSP
		// under test.
		await page.route('**/apps/astrolabe/api/v1/mcp-app/tools/call', async (route) => {
			const { name } = route.request().postDataJSON() as { name: string }
			const structuredContent = name === 'read_document_bytes'
				? { file_id: fileId, data_b64: PDF.toString('base64') }
				: { file_id: fileId, title: PDF_PATH, path: `/${PDF_PATH}`, mime_type: 'application/pdf', chunks: [] }
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ content: [{ type: 'text', text: `Opened ${PDF_PATH}` }], structuredContent, isError: false }),
			})
		})
		const view = page.waitForResponse((r) => r.url().includes('/apps/astrolabe/mcp-app/view'))
		await searchForScan(page)

		await page.getByRole('button', { name: 'Open in App' }).click()

		const viewResponse = await view
		expect(viewResponse.status()).toBe(200)
		expect(viewResponse.headers()['content-security-policy']).toMatch(/script-src[^;]*'wasm-unsafe-eval'/)

		let frame: Frame | undefined
		await expect.poll(() => {
			frame = page.frames().find((f) => f.url().includes('/apps/astrolabe/mcp-app/view'))
			return frame !== undefined
		}).toBe(true)
		await expect.poll(() => frame!.evaluate(navyPixels), { timeout: 30_000 }).toBeGreaterThan(500)
		expect(failures).toEqual([])
	})
})
