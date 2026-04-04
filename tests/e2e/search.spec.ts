/**
 * SPDX-FileCopyrightText: 2025 Astrolabe contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Integration tests for the Astrolabe semantic search UI.
 *
 * These tests require:
 * - Nextcloud with Astrolabe, OIDC, and Notes apps installed
 * - MCP server running in login-flow mode with semantic search enabled
 * - Test data seeded via app-hooks (notes created in admin's Notes folder)
 *
 * Vector sync only begins after the user completes the authorization flow,
 * so the search test handles auth → sync wait → search in sequence.
 */

import { test, expect } from './fixtures.ts'
import { completeAuthorization } from './helpers/authorize.ts'

test.describe('Astrolabe search', () => {
	test('search UI elements are visible', async ({ authenticatedPage: page }) => {
		await page.goto('/apps/astrolabe')

		// Search input
		await expect(page.getByRole('textbox', { name: 'Search query' })).toBeVisible({ timeout: 15000 })

		// Algorithm selector (combobox)
		await expect(page.getByRole('combobox', { name: 'Search for option' })).toBeVisible()

		// Search button
		await expect(page.getByRole('button', { name: 'Search', exact: true })).toBeVisible()

		// Advanced options toggle
		await expect(page.getByRole('button', { name: 'Advanced options' })).toBeVisible()
	})

	test('advanced options expand and show controls', async ({ authenticatedPage: page }) => {
		await page.goto('/apps/astrolabe')

		// Click advanced options
		await page.getByRole('button', { name: 'Advanced options' }).click()

		// Scope to the main content area to avoid matching nav items
		const mainContent = page.getByRole('main')

		// Verify document type filters are visible
		await expect(mainContent.getByText('Document Types')).toBeVisible()
		await expect(mainContent.getByText('Notes')).toBeVisible()
		await expect(mainContent.getByText('Deck Cards')).toBeVisible()

		// Result limit field
		await expect(mainContent.getByText('Result Limit')).toBeVisible()

		// Score threshold slider
		await expect(mainContent.getByText('Minimum Score')).toBeVisible()
	})

	test('complete authorization and perform search with Plotly visualization', async ({ authenticatedPage: page }) => {
		// This test covers auth + sync + search — needs extra time
		test.setTimeout(360_000)

		// Step 1: Complete the Astrolabe authorization flow
		await completeAuthorization(page)

		// Step 2: Wait for vector sync to index the seeded test data.
		// After auth, the MCP server discovers the user and begins indexing.
		// Poll the MCP server's public API directly (no CSRF token needed).
		// Use Node fetch (not page.request) to avoid sending browser cookies.
		let pollCount = 0
		await expect.poll(
			async () => {
				pollCount++
				try {
					const res = await fetch('http://localhost:8000/api/v1/vector-sync/status')
					if (!res.ok) {
						console.log(`vector-sync poll #${pollCount}: HTTP ${res.status}`)
						return false
					}
					const data = await res.json()
					// Log full response on first poll to capture API shape
					if (pollCount === 1) {
						console.log(`vector-sync poll #1 full response: ${JSON.stringify(data)}`)
					}
					const indexed = data.indexed_documents ?? data.indexed_count ?? 0
					const pending = data.pending_documents ?? data.pending_count ?? -1
					console.log(`vector-sync poll #${pollCount}: indexed=${indexed} pending=${pending}`)
					return indexed > 0 && pending === 0
				} catch (e) {
					console.log(`vector-sync poll #${pollCount} error: ${e}`)
					return false
				}
			},
			{ timeout: 240_000, intervals: [5_000] },
		).toBe(true)

		// Step 3: Navigate to the main search UI
		await page.goto('/apps/astrolabe')

		// Step 4: Perform a search for the seeded test data
		const searchInput = page.getByRole('textbox', { name: 'Search query' })
		await expect(searchInput).toBeVisible({ timeout: 15000 })
		await searchInput.fill('kubernetes cluster architecture')
		await searchInput.press('Enter')

		// Step 5: Wait for search results, error, or no-results state
		// Use .first() because the results count text also appears in the Plotly heading
		const resultsText = page.getByText(/\d+ results?/).first()
		const noResults = page.getByText('No results found')
		const errorNote = page.locator('.mcp-error')

		const resultOrError = resultsText.or(errorNote).or(noResults)
		await expect(resultOrError).toBeVisible({ timeout: 30000 })

		// Verify we got results (not error or empty)
		if ((await errorNote.count()) > 0) {
			const errorText = await errorNote.textContent()
			throw new Error(`Search failed with error: ${errorText}`)
		}
		await expect(resultsText).toBeVisible()

		// Step 6: Verify search results are displayed
		const resultItems = page.locator('.mcp-result-item')
		const resultCount = await resultItems.count()
		expect(resultCount).toBeGreaterThan(0)

		// Step 7: Verify Plotly 3D visualization rendered (wait for async SVG injection)
		await expect(page.locator('#viz-plot .main-svg').first()).toBeVisible({ timeout: 15000 })

		// Step 8: Verify a result item has expected structure
		const firstResult = resultItems.first()
		await expect(firstResult.locator('.mcp-result-type')).toBeVisible()
		await expect(firstResult.locator('.mcp-result-title')).toBeVisible()
		await expect(firstResult.locator('.mcp-result-score')).toBeVisible()
	})
})
