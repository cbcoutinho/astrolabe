/**
 * SPDX-FileCopyrightText: 2025 Astrolabe contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * External-IdP regression test for GH #324.
 *
 * Deployment under test: Nextcloud is an OIDC *client* of Keycloak
 * (`user_oidc`), and the `oidc` identity-provider app is NOT installed. In that
 * deployment every Astrolabe API call that mints a per-user MCP token dies with
 *
 *   Class "OCA\OIDCIdentityProvider\Event\TokenGenerationRequestEvent" not found
 *
 * because McpTokenMinter hard-references an `oidc`-app class. Search must work
 * against an external IdP, and must never fail with a PHP fatal.
 */

import { expect, test } from '@playwright/test'
import { loginViaKeycloak } from './helpers/keycloak-login.ts'

test.describe('Astrolabe search behind an external IdP', () => {
	test.beforeEach(async ({ page }) => {
		await loginViaKeycloak(page)
	})

	test('this lane really is running on user_oidc, not oidc', async ({ page }) => {
		// Guards the premise of the test below: if a future change flipped this
		// lane back to the `oidc` app, search would pass for the wrong reason.
		const userOidc = await page.request.get('/apps/user_oidc/api/v1/provider')
		expect(userOidc.status(), 'user_oidc must be installed in this lane').toBeLessThan(500)

		// Nextcloud 404s routes belonging to a disabled app. `GET
		// /apps/oidc/authorize` (LoginRedirector#authorize) is registered
		// whenever the oidc app is enabled — with no query parameters it
		// answers 4xx-but-not-404 — so a 404 here means the app is genuinely
		// absent rather than merely unhappy with the request.
		const oidc = await page.request.get('/apps/oidc/authorize')
		expect(oidc.status(), 'the oidc identity-provider app must be absent').toBe(404)
	})

	test('search returns a result set rather than a token-mint failure', async ({ page }) => {
		await page.goto('/apps/astrolabe')

		const searchBox = page.getByRole('textbox', { name: 'Search query' })
		await expect(searchBox).toBeVisible({ timeout: 15000 })
		await searchBox.fill('anything')

		const responsePromise = page.waitForResponse(
			(r) => r.url().includes('/apps/astrolabe/api/search'),
			{ timeout: 60000 },
		)
		await page.getByRole('button', { name: 'Search', exact: true }).click()
		const response = await responsePromise

		const body = await response.text()
		expect(
			response.status(),
			`search failed behind an external IdP: ${body.slice(0, 800)}`,
		).toBe(200)
		expect(JSON.parse(body)).toMatchObject({ success: true })
	})
})
