/**
 * SPDX-FileCopyrightText: 2025 Astrolabe contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineConfig, devices } from '@playwright/test'

/**
 * Playwright configuration for the **external-IdP** E2E lane (GH #324).
 *
 * Same stack as playwright.config.ts, but Nextcloud logs users in through
 * Keycloak via the `user_oidc` app and the `oidc` identity-provider app is
 * absent. Separate config (rather than a project) because the two lanes need
 * mutually exclusive docker-compose stacks.
 *
 *   npm run test:e2e:external-idp
 */
export default defineConfig({
	testDir: './tests/e2e-external-idp',
	testMatch: '**/*.spec.ts',

	forbidOnly: !!process.env.CI,
	retries: process.env.CI ? 1 : 0,
	workers: 1,

	reporter: process.env.CI
		? [['blob'], ['dot'], ['github']]
		: 'html',

	use: {
		baseURL: process.env.BASE_URL ?? 'http://localhost:8080/index.php/',
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
	},

	projects: [
		{
			name: 'chromium',
			use: { ...devices['Desktop Chrome'] },
		},
	],

	webServer: {
		command: 'node tests/e2e/start-server.js',
		env: { E2E_COMPOSE_OVERLAY: 'docker-compose.external-idp.yml' },
		url: 'http://localhost:8000/health/ready',
		reuseExistingServer: !process.env.CI,
		stderr: 'pipe',
		stdout: 'pipe',
		timeout: 15 * 60 * 1000,
	},
})
