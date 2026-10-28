/**
 * SPDX-FileCopyrightText: 2025 Astrolabe contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Search options the page sends to the MCP server (result shape, server-side
 * relevance cut, reranking) and the Nextcloud global search provider. Deck
 * board 11, card #1419.
 *
 * The stack's MCP server has no reranker, so the "without a reranker" case is
 * real. The reranked case fakes astrolabe's own response, the way the timeout
 * tests in search.spec.ts do, since no reranker can be staged here. The
 * reranked request itself is pinned by the consumer pacts.
 */

import type { Page, Response } from '@playwright/test'

import { expect, test } from './fixtures.ts'
import { completeAuthorization } from './helpers/authorize.ts'
import { waitForIndexing } from './helpers/indexing.ts'

// Seeded by app-hooks/post-installation/30-seed-test-data.sh: long enough to
// index as several passages that all match QUERY.
const LONG_NOTE = 'Kubernetes Cluster Operations Runbook'
const QUERY = 'kubernetes cluster upgrade scaling networking storage'

async function openSearch(page: Page): Promise<void> {
	await page.goto('/apps/astrolabe')
	await expect(page.getByRole('textbox', { name: 'Search query' })).toBeVisible({ timeout: 15_000 })
	await page.getByRole('button', { name: 'Advanced options' }).click()
}

async function search(page: Page, query = QUERY): Promise<Response> {
	const input = page.getByRole('textbox', { name: 'Search query' })
	await input.fill(query)
	const response = page.waitForResponse((r) => r.url().includes('/apps/astrolabe/api/search?'))
	await input.press('Control+Enter')
	const res = await response
	expect(res.status()).toBe(200)
	await expect(page.locator('.mcp-result-item').first()).toBeVisible({ timeout: 30_000 })
	return res
}

// The note is a file in the user's Notes folder too, so it is indexed once as
// a note and once as a file: count the rows of one document, the note.
function longNoteRows(page: Page) {
	return page.locator('.mcp-result-item.mcp-doc-type-note', { hasText: LONG_NOTE })
}

function resultLimit(page: Page) {
	return page.getByRole('main').locator('.mcp-option-group', { hasText: 'Result Limit' }).locator('input[type="number"]')
}

test.describe('Astrolabe search options', () => {
	test('"One row per document" is disabled for semantic search', async ({ authenticatedPage: page }) => {
		await openSearch(page)
		const shape = page.getByLabel('Results per document')
		const perDocument = shape.locator('option[value="document"]')

		await expect(perDocument).toBeEnabled()
		await shape.selectOption('document')

		await page.locator('.mcp-algorithm-select').click()
		await page.getByRole('option', { name: 'Semantic', exact: true }).click()

		await expect(perDocument).toBeDisabled()
		// Switching to semantic drops the choice rather than leaving a
		// selection the server would answer with a 422.
		await expect(shape).toHaveValue('chunk')
	})

	test('"One row per document" lists a long document once', async ({ authenticatedPage: page }) => {
		test.setTimeout(360_000)
		await completeAuthorization(page)
		await waitForIndexing()
		await openSearch(page)

		// Best passages: the long note matches in several of its chunks. This
		// relies on the server's defaults (no relevance cut, no rerank) keeping
		// at least two of them in a page of 20.
		await resultLimit(page).fill('20')
		await search(page)
		await expect.poll(() => longNoteRows(page).count()).toBeGreaterThan(1)

		await page.getByLabel('Results per document').selectOption('document')
		const res = await search(page)
		expect(new URL(res.url()).searchParams.get('granularity')).toBe('document')

		await expect(longNoteRows(page)).toHaveCount(1)
		const rows: { doc_type: string, id: unknown }[] = (await res.json()).results
		const docs = rows.map((r) => `${r.doc_type}:${String(r.id)}`)
		expect(new Set(docs).size).toBe(docs.length)
	})

	test('a server relevance cut still returns a full page', async ({ authenticatedPage: page }) => {
		test.setTimeout(360_000)
		await completeAuthorization(page)
		await waitForIndexing()
		await openSearch(page)

		await resultLimit(page).fill('2')
		await page.getByLabel(/^Server relevance cut/).fill('5')
		const res = await search(page)

		expect(new URL(res.url()).searchParams.get('min_relevance')).toBe('0.05')
		// Cut before the page is trimmed to the limit, so it is still full.
		await expect(page.locator('.mcp-result-item')).toHaveCount(2)
		const body = await res.json()
		for (const row of body.results) {
			expect(typeof row.relevance).toBe('number')
			expect(row.relevance).toBeGreaterThanOrEqual(0.05)
		}
	})

	test('without a reranker, search is not reranked and still succeeds', async ({ authenticatedPage: page }) => {
		test.setTimeout(360_000)
		const status = await (await fetch('http://localhost:8000/api/v1/status')).json()
		// The precondition: this stack's MCP server has no reranker.
		expect(status.rerank_available ?? false).toBe(false)

		await completeAuthorization(page)
		await waitForIndexing()
		await openSearch(page)
		const res = await search(page)

		expect((await res.json()).reranked ?? false).toBe(false)
		await expect(page.locator('.mcp-results-header')).not.toContainText('reranked')
	})

	test('a reranked search says so and shows calibrated relevance', async ({ authenticatedPage: page }) => {
		// Mirrors ApiController::search()'s success body with the fields a
		// reranked MCP response adds (see testRerankedSearchHonoursTheContract).
		await page.route(/\/apps\/astrolabe\/api\/search\?/, (route) => route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				success: true,
				algorithm_used: 'hybrid',
				reranked: true,
				results: [{
					id: 1,
					doc_type: 'note',
					title: 'Award nominations',
					excerpt: 'Nominations for the leadership award.',
					score: 0.0328,
					rerank_score: 0.91,
					relevance: 0.87,
					relevance_source: 'cross_encoder_calibrated',
				}],
			}),
		}))
		await openSearch(page)
		await search(page, 'leadership award')

		await expect(page.locator('.mcp-results-header')).toContainText('· reranked')
		await expect(page.locator('.mcp-relevance-value')).toHaveText('87%')
		await expect(page.getByRole('meter')).toHaveAttribute('aria-valuenow', '87')
	})

	test('the global search bar returns Astrolabe results', async ({ authenticatedPage: page }) => {
		test.setTimeout(360_000)
		await completeAuthorization(page)
		await waitForIndexing()

		// The request the Nextcloud global search bar makes for this provider,
		// in the logged-in session. Driving the header widget itself is left
		// out: its markup differs across the Nextcloud majors e2e.yml covers.
		const res = await page.request.get('/ocs/v2.php/search/providers/astrolabe_semantic/search', {
			params: { term: QUERY },
			headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
		})
		expect(res.status()).toBe(200)
		const data = (await res.json()).ocs.data
		expect(data.name).toBe('Astrolabe')
		const entryTitles: string[] = data.entries.map((e: { title: string }) => e.title)
		expect(entryTitles.some((t) => t.includes(LONG_NOTE))).toBe(true)
	})
})
