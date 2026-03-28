/**
 * SPDX-FileCopyrightText: 2025 Astrolabe contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Helpers for completing the Astrolabe two-step authorization flow
 * (OAuth + app password) in login-flow mode.
 *
 * All navigation uses relative paths so Playwright prepends baseURL
 * from playwright.config.ts automatically.
 */

import type { Page } from '@playwright/test'

/**
 * Navigate to Astrolabe personal settings page.
 */
export async function navigateToSettings(page: Page): Promise<void> {
	await page.goto('/settings/user/astrolabe', { waitUntil: 'domcontentloaded' })
	// Wait for the settings page content to render
	await page.getByRole('heading', { name: /Astrolabe/i }).waitFor({ timeout: 15000 })
}

/**
 * Check if authorization is already complete.
 * Looks for "Active" badge on the personal settings page.
 */
export async function isAlreadyAuthorized(page: Page): Promise<boolean> {
	const activeBadge = page.locator('.badge-success:has-text("Active")')
	return (await activeBadge.count()) > 0 && (await activeBadge.isVisible())
}

/**
 * Complete Step 1: OAuth Authorization.
 *
 * Clicks the "Authorize" link, handles the OIDC consent screen,
 * and waits for redirect back to settings.
 */
export async function authorizeOAuth(page: Page): Promise<void> {
	// Check if already complete
	const step1Complete = page.locator('h4:has-text("Step 1")').locator('..').getByText('Complete', { exact: true })
	if ((await step1Complete.count()) > 0 && (await step1Complete.isVisible())) {
		return
	}

	// Click the "Authorize" button/link
	const authorizeLink = page.locator('a.button.primary:has-text("Authorize")')
	await authorizeLink.waitFor({ timeout: 10000, state: 'visible' })
	await authorizeLink.click()

	// Handle OIDC consent screen — click "Authorize" on the consent page
	try {
		const consentButton = page.locator('input[type="submit"][value="Authorize"], button:has-text("Authorize")')
		await consentButton.waitFor({ timeout: 15000, state: 'visible' })
		await consentButton.click()
	} catch {
		// Some flows skip the consent screen
	}

	// Wait for redirect back to Astrolabe settings
	await page.waitForURL(/settings\/user\/astrolabe/, { timeout: 30000 })
}

/**
 * Generate an app password from Nextcloud Security settings.
 *
 * Returns the generated app password string.
 */
export async function generateAppPassword(page: Page): Promise<string> {
	await page.goto('/settings/user/security', { waitUntil: 'domcontentloaded' })

	// Find the app password input and create one
	const appNameInput = page.locator('#app-password-name')
	await appNameInput.waitFor({ timeout: 10000, state: 'visible' })
	await appNameInput.fill('astrolabe-e2e-test')

	// Click "Create new app password" button
	const createButton = page.locator('#add-app-password')
	await createButton.click()

	// Wait for the password to appear
	const passwordField = page.locator('#app-password-value, .app-password-value input, #app-password')
	await passwordField.waitFor({ timeout: 15000, state: 'visible' })

	// Get the generated password
	const password = await passwordField.inputValue()
	if (!password) {
		throw new Error('Failed to generate app password')
	}

	return password
}

/**
 * Complete Step 2: Enter app password in Astrolabe settings.
 */
export async function submitAppPassword(page: Page, appPassword: string): Promise<void> {
	await navigateToSettings(page)

	// Find the app password input field
	const passwordInput = page.locator('#mcp-app-password, input[name="app_password"]')
	await passwordInput.waitFor({ timeout: 10000, state: 'visible' })
	await passwordInput.fill(appPassword)

	// Submit the form
	const saveButton = page.locator('#mcp-save-app-password-button')
	await saveButton.click()

	// Wait for success — page should reload showing "Active" status
	await page.locator('.badge-success:has-text("Active")').waitFor({ timeout: 15000 })
}

/**
 * Complete the full Astrolabe authorization flow.
 *
 * 1. Navigate to settings
 * 2. Complete OAuth (Step 1) if needed
 * 3. Generate app password
 * 4. Submit app password (Step 2) if needed
 */
export async function completeAuthorization(page: Page): Promise<void> {
	await navigateToSettings(page)

	// Check if already fully authorized
	if (await isAlreadyAuthorized(page)) {
		return
	}

	// Step 1: OAuth
	await authorizeOAuth(page)

	// Step 2: App password
	const appPassword = await generateAppPassword(page)
	await submitAppPassword(page, appPassword)
}
