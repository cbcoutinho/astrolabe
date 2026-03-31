/**
 * SPDX-FileCopyrightText: 2025 Astrolabe contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Helpers for completing the Astrolabe authorization flow in login-flow mode.
 *
 * The full "Enable Semantic Search" flow chains two steps:
 * 1. OAuth/OIDC — Astrolabe obtains a bearer token (consent auto-approved)
 * 2. Login Flow v2 — MCP server provisions an app password via NC's login page
 *
 * After OAuth callback, Astrolabe redirects to the MCP server's /app/provision
 * endpoint which redirects to Nextcloud's Login Flow v2 login page. The user
 * grants access, and the MCP server stores the app password in the background.
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
 * Check if vector sync is active (meaning the MCP server has a working
 * app password and is indexing content).
 */
async function isVectorSyncActive(page: Page): Promise<boolean> {
	try {
		const res = await page.request.get('http://localhost:8000/api/v1/vector-sync/status')
		if (!res.ok()) return false
		const data = await res.json()
		return (data?.indexed_documents ?? 0) > 0 || (data?.pending_documents ?? 0) > 0
	} catch {
		return false
	}
}

/**
 * Disconnect from Astrolabe by clicking "Disconnect" on the settings page.
 */
async function disconnect(page: Page): Promise<void> {
	const disconnectBtn = page.getByRole('button', { name: 'Disconnect' })
	if ((await disconnectBtn.count()) > 0 && (await disconnectBtn.isVisible())) {
		await disconnectBtn.click()
		await page.waitForURL(/settings\/user\/astrolabe/, { timeout: 15000 })
		await page.waitForTimeout(1000)
	}
}

/**
 * Complete the Astrolabe authorization flow.
 *
 * 1. Navigate to personal settings
 * 2. If already authorized but vector sync not active → disconnect first
 * 3. Click "Enable Semantic Search" (starts OAuth flow)
 * 4. OIDC consent is auto-approved (allow_user_settings=no)
 * 5. OAuth callback redirects to /oauth/provision (same-origin)
 * 6. Provision action redirects to MCP server's /app/provision
 * 7. MCP server redirects to Nextcloud's Login Flow v2 login page
 * 8. Click "Grant access" on Login Flow v2 page
 * 9. MCP server background task stores app password
 * 10. Navigate back to settings
 */
export async function completeAuthorization(page: Page): Promise<void> {
	await navigateToSettings(page)

	const enableLink = page.getByRole('link', { name: 'Enable Semantic Search' })
	const isAlreadyAuthorized = (await enableLink.count()) === 0

	if (isAlreadyAuthorized) {
		// Check if vector sync is actually working (app password provisioned).
		// If not, disconnect and re-authorize to trigger the full
		// OAuth + Login Flow v2 chain.
		if (await isVectorSyncActive(page)) {
			return // Fully provisioned and working
		}

		await disconnect(page)
		await navigateToSettings(page)
	}

	// Click the "Enable Semantic Search" OAuth link.
	// With auto-consent enabled, the OIDC consent page is skipped entirely.
	// The flow redirects through: OAuth authorize → OIDC → callback → provision → NC Login Flow v2.
	const link = page.getByRole('link', { name: 'Enable Semantic Search' })
	await link.waitFor({ timeout: 10000, state: 'visible' })
	await link.click()

	// After the OAuth + provision redirect chain, we land on Nextcloud's
	// Login Flow v2 "Connect to your account" page with a "Log in" button.
	// Click it — since the user is already logged in (authenticatedPage
	// fixture), this redirects straight to the "Grant access" page.
	const lfv2LoginButton = page.getByRole('button', { name: 'Log in' })
	await lfv2LoginButton.waitFor({ timeout: 30000, state: 'visible' })
	await lfv2LoginButton.click()

	// Now on the "Grant access" page — click to approve the app password
	const grantButton = page.getByRole('button', { name: /Grant access/i })
	await grantButton.waitFor({ timeout: 15000, state: 'visible' })
	await grantButton.click()

	// After granting, Nextcloud shows a completion page.
	// Give the MCP server a moment to poll and store the app password,
	// then navigate back to Astrolabe settings.
	await page.waitForTimeout(3000)
	await navigateToSettings(page)
}
