/**
 * SPDX-FileCopyrightText: 2025 Astrolabe contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Page } from '@playwright/test'

/**
 * Log in to Nextcloud as the given user.
 *
 * Navigates to the login page, fills credentials, and waits for the
 * dashboard or apps page to load.
 */
export async function login(
	page: Page,
	username = 'admin',
	password = 'admin',
): Promise<void> {
	await page.goto('/login')

	// Fill login form
	await page.getByLabel('Username or email').fill(username)
	await page.getByLabel('Password', { exact: true }).fill(password)
	await page.getByRole('button', { name: 'Log in' }).click()

	// Wait for login to complete — Nextcloud redirects to dashboard or files
	await page.waitForURL(/\/(apps|index\.php)/, { timeout: 30000 })
}
