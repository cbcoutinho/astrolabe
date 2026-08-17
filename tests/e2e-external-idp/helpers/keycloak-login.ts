/**
 * SPDX-FileCopyrightText: 2025 Astrolabe contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, type Page } from '@playwright/test'

/**
 * Log in to Nextcloud through Keycloak.
 *
 * `user_oidc` adds a "Log in with keycloak" alternative to the Nextcloud login
 * page; that link starts the authorization-code flow, Keycloak authenticates
 * the user, and Nextcloud provisions/refreshes the account on the callback.
 * Only a session created this way carries the IdP token that `user_oidc` can
 * hand out (or exchange) later — an app-password or password login cannot.
 */
export async function loginViaKeycloak(
	page: Page,
	username = 'alice',
	password = 'alice',
): Promise<void> {
	await page.goto('/login')

	await page.getByRole('link', { name: /keycloak/i }).click()

	// Keycloak's own login form.
	await page.waitForURL(/\/realms\/astrolabe-e2e\//, { timeout: 30000 })
	await page.locator('#username').fill(username)
	await page.locator('#password').fill(password)
	await page.locator('#kc-login').click()

	// Back on Nextcloud, logged in.
	await page.waitForURL(/localhost:8080/, { timeout: 30000 })
	await expect(page.locator('#user-menu, .header-right')).toBeVisible({ timeout: 30000 })
}
