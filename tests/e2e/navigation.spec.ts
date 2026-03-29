/**
 * SPDX-FileCopyrightText: 2025 Astrolabe contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { test, expect } from './fixtures.ts'

test.describe('Astrolabe navigation', () => {
	test('app loads at /apps/astrolabe', async ({ authenticatedPage: page }) => {
		await page.goto('/apps/astrolabe')

		// Verify the main content area renders with the search heading
		await expect(page.getByRole('heading', { name: 'Semantic Search' })).toBeVisible({ timeout: 15000 })
	})

	test('navigation items are visible', async ({ authenticatedPage: page }) => {
		await page.goto('/apps/astrolabe')

		// Wait for nav to render
		await expect(page.getByRole('navigation').filter({ has: page.getByRole('link', { name: 'Semantic Search' }) })).toBeVisible({ timeout: 15000 })

		// Check both navigation items
		await expect(page.getByRole('link', { name: 'Semantic Search' })).toBeVisible()
		await expect(page.getByRole('link', { name: 'Index Status' })).toBeVisible()
	})

	test('can switch between sections', async ({ authenticatedPage: page }) => {
		await page.goto('/apps/astrolabe')

		// Default section is search — verify search input is visible
		const searchInput = page.getByRole('textbox', { name: 'Search query' })
		await expect(searchInput).toBeVisible({ timeout: 15000 })

		// Click "Index Status" nav item
		await page.getByRole('link', { name: 'Index Status' }).click()

		// Verify status section heading is visible
		await expect(page.getByRole('heading', { name: 'Index Status' })).toBeVisible()

		// Switch back to search
		await page.getByRole('link', { name: 'Semantic Search' }).click()
		await expect(searchInput).toBeVisible()
	})

	test('settings link is present in navigation footer', async ({ authenticatedPage: page }) => {
		await page.goto('/apps/astrolabe')

		await expect(page.getByRole('link', { name: 'Settings' })).toBeVisible({ timeout: 15000 })
	})
})
