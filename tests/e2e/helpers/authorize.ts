/**
 * SPDX-FileCopyrightText: 2025 Astrolabe contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Background-indexing provisioning helper for Astrolabe E2E tests.
 *
 * After the session-derived-JWT refactor, search itself requires no user
 * action — the Nextcloud session is enough. The MCP server still needs an
 * app password to read the user's files via WebDAV for indexing, so this
 * helper drives the personal-settings form that submits one.
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
 * True when the personal-settings page reports background indexing as
 * already enabled (the "Disable background indexing" form is shown
 * instead of the provisioning form).
 */
async function isAlreadyProvisioned(page: Page): Promise<boolean> {
	const disableForm = page.locator('#mcp-revoke-background-form')
	return (await disableForm.count()) > 0
}

/**
 * Provision background indexing via the one-click "Enable background indexing"
 * button on Astrolabe's personal-settings page. The page's own JS mints an app
 * password from the active Nextcloud session and submits it, then reloads into
 * the provisioned state (the revoke form replaces the enable button).
 *
 * Kept compatible with the old `completeAuthorization` name so the
 * search.spec.ts call site does not need to change.
 */
export async function completeAuthorization(page: Page): Promise<void> {
	await navigateToSettings(page)

	if (await isAlreadyProvisioned(page)) {
		return
	}

	// One click mints the app password from the current session and submits it;
	// on success the page reloads and shows the revoke form.
	await page.locator('#mcp-enable-background-button').click()
	await page.locator('#mcp-revoke-background-form').waitFor({ timeout: 30000 })
}
