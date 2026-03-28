/**
 * SPDX-FileCopyrightText: 2025 Astrolabe contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Helpers for completing the Astrolabe authorization flow in login-flow mode.
 *
 * In login-flow mode, the personal settings page shows an "Enable Semantic Search"
 * link that initiates OAuth authorization. After the OIDC consent screen, the user
 * is redirected back to settings with authorization complete.
 *
 * All navigation uses relative paths so Playwright prepends baseURL automatically.
 */

import type { Page } from '@playwright/test'

/**
 * Navigate to Astrolabe personal settings page.
 */
export async function navigateToSettings(page: Page): Promise<void> {
	await page.goto('/settings/user/astrolabe', { waitUntil: 'domcontentloaded' })
	await page.getByRole('heading', { level: 1, name: /Astrolabe/i }).waitFor({ timeout: 15000 })
}

/**
 * Check if authorization is already complete.
 * When authorized, the "Enable Semantic Search" link is no longer shown.
 */
async function isAlreadyAuthorized(page: Page): Promise<boolean> {
	const enableLink = page.getByRole('link', { name: 'Enable Semantic Search' })
	return (await enableLink.count()) === 0
}

/**
 * Complete the Astrolabe authorization flow.
 *
 * 1. Navigate to personal settings
 * 2. Click "Enable Semantic Search" (OAuth link)
 * 3. Handle OIDC consent screen if present
 * 4. Wait for redirect back to settings
 */
export async function completeAuthorization(page: Page): Promise<void> {
	await navigateToSettings(page)

	if (await isAlreadyAuthorized(page)) {
		return
	}

	// Click the "Enable Semantic Search" OAuth link
	const enableLink = page.getByRole('link', { name: 'Enable Semantic Search' })
	await enableLink.waitFor({ timeout: 10000, state: 'visible' })
	await enableLink.click()

	// Handle OIDC consent screen if present — some flows auto-approve
	const consentButton = page.locator('input[type="submit"][value="Authorize"], button:has-text("Authorize")')
	if (await consentButton.isVisible({ timeout: 5000 }).catch(() => false)) {
		await consentButton.click()
	}

	// Wait for redirect back to Astrolabe settings
	await page.waitForURL(/settings\/user\/astrolabe/, { timeout: 30000 })
}
