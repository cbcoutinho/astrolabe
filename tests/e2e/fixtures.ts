/**
 * SPDX-FileCopyrightText: 2025 Astrolabe contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { test as base, expect, type Page } from '@playwright/test'
import { login } from './helpers/login.ts'

/**
 * Custom Playwright fixtures for Astrolabe E2E tests.
 */
export const test = base.extend<{
	/** A page already authenticated as the admin user. */
	authenticatedPage: Page
}>({
	authenticatedPage: async ({ page }, use) => {
		await login(page)
		await use(page)
	},
})

export { expect }
