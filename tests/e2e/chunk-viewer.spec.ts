/**
 * SPDX-FileCopyrightText: 2026 Astrolabe contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * The chunk viewer's notice for file types without a preview.
 *
 * Only PDFs render as the document itself; every other file type falls back to
 * the extracted text. The viewer must say so, or the text reads as the preview.
 *
 * The viewer is opened through its deep link, and chunk-context is stubbed: what
 * is asserted is the viewer's choice, which depends only on that response and the
 * result's file type, not on an indexed document existing.
 */

import { expect, test } from './fixtures.ts'

const NOTICE = 'Previews are not yet supported for this file type'

async function openChunk(page, query: Record<string, string>) {
	await page.route('**/apps/astrolabe/api/chunk-context**', (route) => route.fulfill({
		json: {
			success: true,
			chunk_text: 'Quarterly revenue was 42.',
			before_context: '',
			after_context: '',
			page_number: null,
		},
	}))
	const params = new URLSearchParams({ chunk_start: '0', chunk_end: '25', doc_id: '42', ...query })
	await page.goto(`/apps/astrolabe/?${params}`)
	await expect(page.locator('.mcp-modal')).toBeVisible()
	await expect(page.locator('.mcp-modal')).toContainText('Quarterly revenue was 42.')
}

test.describe('chunk viewer preview notice', () => {
	test('a non-PDF file says its preview is not supported', async ({ authenticatedPage: page }) => {
		await openChunk(page, { doc_type: 'file', path: '/admin/files/report.docx' })

		await expect(page.locator('.mcp-modal')).toContainText(NOTICE)
	})

	test('a note is text by nature and gets no notice', async ({ authenticatedPage: page }) => {
		await openChunk(page, { doc_type: 'note' })

		await expect(page.locator('.mcp-modal')).not.toContainText(NOTICE)
	})
})
