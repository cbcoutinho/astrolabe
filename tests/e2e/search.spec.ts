/**
 * SPDX-FileCopyrightText: 2025 Astrolabe contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Integration tests for the Astrolabe semantic search UI.
 *
 * These tests require:
 * - Nextcloud with Astrolabe, OIDC, and Notes apps installed
 * - MCP server running in login-flow mode with semantic search enabled
 * - Test data seeded and vector sync completed (handled by start-server.js)
 */

import { test, expect } from './fixtures.ts'
import { completeAuthorization, navigateToSettings, isAlreadyAuthorized } from './helpers/authorize.ts'

test.describe('Astrolabe search', () => {
	test.describe.configure({ timeout: 120_000 })

	test('search UI elements are visible', async ({ authenticatedPage: page }) => {
		await page.goto('/apps/astrolabe')

		// Search input
		const searchInput = page.locator('.mcp-search-input input')
		await expect(searchInput).toBeVisible({ timeout: 15000 })

		// Algorithm selector
		const algorithmSelect = page.locator('.mcp-algorithm-select')
		await expect(algorithmSelect).toBeVisible()

		// Search button
		const searchButton = page.getByRole('button', { name: 'Search' })
		await expect(searchButton).toBeVisible()

		// Advanced options toggle
		const advancedToggle = page.getByText('Advanced options')
		await expect(advancedToggle).toBeVisible()
	})

	test('advanced options expand and show controls', async ({ authenticatedPage: page }) => {
		await page.goto('/apps/astrolabe')

		// Click advanced options
		await page.getByText('Advanced options').click()

		// Verify document type filters are visible
		await expect(page.getByText('Document Types')).toBeVisible()
		await expect(page.getByText('Notes')).toBeVisible()
		await expect(page.getByText('Deck Cards')).toBeVisible()

		// Result limit field
		await expect(page.getByText('Result Limit')).toBeVisible()

		// Score threshold slider
		await expect(page.getByText('Minimum Score')).toBeVisible()
	})

	test('complete authorization and perform search with Plotly visualization', async ({ authenticatedPage: page }) => {
		// Step 1: Complete the Astrolabe authorization flow
		await completeAuthorization(page)

		// Step 2: Navigate to the main search UI
		await page.goto('/apps/astrolabe')

		// Step 3: Perform a search for the seeded test data
		const searchInput = page.locator('.mcp-search-input input')
		await expect(searchInput).toBeVisible({ timeout: 15000 })
		await searchInput.fill('kubernetes cluster architecture')
		await searchInput.press('Enter')

		// Step 4: Wait for loading to complete
		const loadingIndicator = page.locator('.mcp-loading')
		if ((await loadingIndicator.count()) > 0) {
			await loadingIndicator.waitFor({ state: 'hidden', timeout: 30000 })
		}

		// Step 5: Wait for results or error
		const resultsText = page.getByText(/\d+ results?/)
		const noResults = page.getByText('No results found')
		const errorNote = page.locator('.mcp-error')

		// Poll for results, error, or no-results state
		let resultState = 'pending'
		for (let i = 0; i < 60; i++) {
			if ((await errorNote.count()) > 0) {
				const errorText = await errorNote.textContent()
				resultState = `error: ${errorText}`
				break
			}
			if ((await noResults.count()) > 0) {
				resultState = 'no_results'
				break
			}
			if ((await resultsText.count()) > 0) {
				resultState = 'results'
				break
			}
			await page.waitForTimeout(500)
		}

		expect(resultState).toBe('results')

		// Step 6: Verify search results are displayed
		const resultItems = page.locator('.mcp-result-item')
		const resultCount = await resultItems.count()
		expect(resultCount).toBeGreaterThan(0)

		// Step 7: Verify Plotly 3D visualization rendered
		const vizPlot = page.locator('#viz-plot')
		await expect(vizPlot).toBeVisible({ timeout: 15000 })

		// Verify Plotly has actually rendered content (SVG/canvas elements)
		const hasVizContent = await page.evaluate(() => {
			const plot = document.getElementById('viz-plot')
			if (!plot) return false
			return plot.children.length > 0
				|| plot.querySelector('.plotly, canvas, svg, .main-svg') !== null
		})
		expect(hasVizContent).toBe(true)

		// Step 8: Verify a result item has expected structure
		const firstResult = resultItems.first()
		await expect(firstResult.locator('.mcp-result-type')).toBeVisible()
		await expect(firstResult.locator('.mcp-result-title')).toBeVisible()
		await expect(firstResult.locator('.mcp-result-score')).toBeVisible()
	})
})
