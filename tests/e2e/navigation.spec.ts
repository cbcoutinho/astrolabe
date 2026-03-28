/**
 * SPDX-FileCopyrightText: 2025 Astrolabe contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { test, expect } from './fixtures.ts'

test.describe('Astrolabe navigation', () => {
	test('app loads at /apps/astrolabe', async ({ authenticatedPage: page }) => {
		await page.goto('/apps/astrolabe')

		// Verify the app content area is present
		await expect(page.locator('[data-app="astrolabe"]')).toBeVisible({ timeout: 15000 })
	})

	test('navigation items are visible', async ({ authenticatedPage: page }) => {
		await page.goto('/apps/astrolabe')

		// Wait for nav to render
		const nav = page.locator('.app-navigation')
		await expect(nav).toBeVisible({ timeout: 15000 })

		// Check both navigation items
		await expect(page.getByText('Semantic Search')).toBeVisible()
		await expect(page.getByText('Index Status')).toBeVisible()
	})

	test('can switch between sections', async ({ authenticatedPage: page }) => {
		await page.goto('/apps/astrolabe')

		// Default section is search — verify search input is visible
		const searchInput = page.locator('.mcp-search-input input')
		await expect(searchInput).toBeVisible({ timeout: 15000 })

		// Click "Index Status" nav item
		await page.getByText('Index Status').click()

		// Verify status section is visible (either loading indicator or status cards)
		const statusSection = page.locator('.mcp-section').filter({ hasText: 'Index Status' })
		await expect(statusSection).toBeVisible()

		// Switch back to search
		await page.getByText('Semantic Search').first().click()
		await expect(searchInput).toBeVisible()
	})

	test('settings link is present in navigation footer', async ({ authenticatedPage: page }) => {
		await page.goto('/apps/astrolabe')

		const settingsLink = page.getByText('Settings')
		await expect(settingsLink).toBeVisible({ timeout: 15000 })
	})
})
