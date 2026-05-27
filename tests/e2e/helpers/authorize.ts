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
 * Provision background indexing by minting an app password via the OCS
 * Users API, then submitting it through Astrolabe's app-password form.
 *
 * Kept compatible with the old `completeAuthorization` name so the
 * search.spec.ts call site does not need to change.
 */
export async function completeAuthorization(page: Page): Promise<void> {
	await navigateToSettings(page)

	if (await isAlreadyProvisioned(page)) {
		return
	}

	// Mint an app password via OCS so the test does not have to scrape one
	// from the Security settings UI. The endpoint requires the user's
	// session, which Playwright already has.
	const ocsResponse = await page.request.get(
		'/ocs/v2.php/core/getapppassword?format=json',
		{ headers: { 'OCS-APIRequest': 'true', 'Accept': 'application/json' } },
	)
	if (!ocsResponse.ok()) {
		throw new Error(`Failed to mint OCS app password: HTTP ${ocsResponse.status()}`)
	}
	const ocsBody = await ocsResponse.json()
	const appPassword = ocsBody?.ocs?.data?.apppassword
	if (typeof appPassword !== 'string' || appPassword === '') {
		throw new Error('OCS getapppassword returned no apppassword field')
	}

	// Submit to Astrolabe's provisioning endpoint (the same form the user
	// would fill in via the UI).
	await page.locator('#mcp-app-password-input').fill(appPassword)
	await page.locator('#mcp-save-app-password-button').click()
	await page.waitForURL(/settings\/user\/astrolabe/, { timeout: 30000 })
}
