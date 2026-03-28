/**
 * SPDX-FileCopyrightText: 2025 Astrolabe contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Starts the docker-compose E2E test environment and waits for all services
 * to be healthy before signaling readiness to Playwright.
 *
 * Handles SIGTERM/SIGINT to gracefully tear down containers.
 */

import { execSync } from 'node:child_process'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = dirname(fileURLToPath(import.meta.url))
const composeFile = join(__dirname, 'docker-compose.yml')

const NEXTCLOUD_URL = 'http://localhost:8080'
const MCP_HEALTH_URL = 'http://localhost:8000/health'
const OIDC_DISCOVERY_URL = `${NEXTCLOUD_URL}/.well-known/openid-configuration`

/**
 * Run docker compose with the given arguments.
 */
function compose(args, options = {}) {
	return execSync(`docker compose -f ${composeFile} ${args}`, {
		stdio: options.quiet ? 'pipe' : 'inherit',
		encoding: 'utf-8',
		...options,
	})
}

function startServices() {
	process.stderr.write('Starting docker-compose services...\n')
	compose('up -d')
	process.stderr.write('Docker-compose services started.\n')
}

function stopServices() {
	process.stderr.write('Stopping docker-compose services...\n')
	try {
		compose('down -v --remove-orphans', { quiet: true })
	} catch {
		// Best-effort cleanup
	}
	process.stderr.write('Docker-compose services stopped.\n')
}

/**
 * Poll a URL until it returns an expected response.
 */
async function waitForService(name, url, checkFn, { maxAttempts = 60, delayMs = 5000, fetchOptions = {} } = {}) {
	for (let attempt = 1; attempt <= maxAttempts; attempt++) {
		try {
			const response = await fetch(url, fetchOptions)
			if (await checkFn(response)) {
				process.stderr.write(`${name} is ready.\n`)
				return true
			}
		} catch {
			// Service not up yet
		}

		if (attempt % 6 === 0) {
			process.stderr.write(`Waiting for ${name}... (attempt ${attempt}/${maxAttempts})\n`)
		}

		await new Promise((resolve) => setTimeout(resolve, delayMs))
	}

	throw new Error(`${name} did not become ready in time.`)
}

/**
 * Wait for Nextcloud to be installed and ready.
 */
async function waitForNextcloud() {
	await waitForService(
		'Nextcloud',
		`${NEXTCLOUD_URL}/status.php`,
		async (response) => {
			if (!response.ok) return false
			const data = await response.json()
			return data.installed === true
		},
	)
}

/**
 * Wait for OIDC discovery endpoint to be available.
 * This confirms the OIDC app is installed and configured correctly,
 * which is required for login-flow mode DCR.
 */
async function waitForOidc() {
	await waitForService(
		'OIDC discovery',
		OIDC_DISCOVERY_URL,
		async (response) => {
			if (!response.ok) return false
			try {
				const data = await response.json()
				// Verify it has the required OIDC fields
				return !!(data.issuer && data.authorization_endpoint && data.token_endpoint)
			} catch {
				return false
			}
		},
		{ maxAttempts: 20, delayMs: 3000 },
	)
}

/**
 * Wait for the MCP server health endpoint.
 */
async function waitForMcp() {
	await waitForService(
		'MCP server',
		MCP_HEALTH_URL,
		async (response) => [200, 404, 405].includes(response.status),
		{ maxAttempts: 30 },
	)
}

// Signal handlers for graceful shutdown
process.on('SIGTERM', () => {
	stopServices()
	process.exit(0)
})

process.on('SIGINT', () => {
	stopServices()
	process.exit(0)
})

// Main execution
try {
	startServices()
	await waitForNextcloud()
	await waitForOidc()
	await waitForMcp()

	// Signal to Playwright that services are ready
	process.stdout.write('Services are ready\n')

	// Idle until shutdown signal
	await new Promise(() => {})
} catch (error) {
	process.stderr.write(`Error: ${error.message}\n`)

	// Dump logs for debugging
	try {
		process.stderr.write('\n--- Docker Compose Logs ---\n')
		compose('logs --tail=100')
	} catch {
		// Best effort
	}

	stopServices()
	process.exit(1)
}
