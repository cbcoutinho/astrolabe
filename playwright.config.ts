/**
 * SPDX-FileCopyrightText: 2025 Astrolabe contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineConfig, devices } from '@playwright/test'

/**
 * Playwright configuration for Astrolabe E2E tests.
 *
 * Uses docker-compose to start Nextcloud + MCP server infrastructure.
 * See tests/e2e/start-server.js for the server lifecycle management.
 */
export default defineConfig({
	testDir: './tests/e2e',
	testMatch: '**/*.spec.ts',

	// Prevent test.only from landing in CI
	forbidOnly: !!process.env.CI,

	// Retry on CI only
	retries: process.env.CI ? 1 : 0,

	// Serial on CI (single docker-compose stack), parallel locally
	workers: process.env.CI ? 1 : undefined,

	// CI: blob (mergeable), dot (quick logs), github (PR annotations)
	// Local: html report with traces
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
			use: {
				...devices['Desktop Chrome'],
			},
		},
	],

	webServer: {
		// Starts docker-compose with Nextcloud + MCP server
		command: 'node tests/e2e/start-server.js',
		// Poll MCP health endpoint — this is the last service to start
		// (depends on Nextcloud being healthy first)
		url: 'http://localhost:8000/health/ready',
		reuseExistingServer: !process.env.CI,
		stderr: 'pipe',
		stdout: 'pipe',
		// Allow up to 5 minutes for containers to start
		timeout: 5 * 60 * 1000,
	},
})
