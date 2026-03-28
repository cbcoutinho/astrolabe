/**
 * SPDX-FileCopyrightText: 2025 Astrolabe contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Starts the docker-compose E2E test environment and waits for all services
 * to be healthy before signaling readiness to Playwright.
 *
 * Handles SIGTERM/SIGINT to gracefully tear down containers.
 */

import { randomBytes } from 'node:crypto'
import { execSync } from 'node:child_process'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = dirname(fileURLToPath(import.meta.url))
const composeFile = join(__dirname, 'docker-compose.yml')

const NEXTCLOUD_URL = 'http://localhost:8080'
const MCP_HEALTH_URL = 'http://localhost:8000/health/ready'
const OIDC_DISCOVERY_URL = `${NEXTCLOUD_URL}/.well-known/openid-configuration`

function log(msg) {
	process.stderr.write(`[start-server] ${msg}\n`)
}

/**
 * Run docker compose with the given arguments.
 * All output is captured and written to stderr so Playwright can display it.
 */
function compose(args) {
	try {
		const output = execSync(`docker compose -f "${composeFile}" ${args}`, {
			stdio: 'pipe',
			encoding: 'utf-8',
		})
		if (output.trim()) {
			process.stderr.write(output)
		}
		return output
	} catch (error) {
		if (error.stderr) process.stderr.write(error.stderr)
		if (error.stdout) process.stderr.write(error.stdout)
		throw error
	}
}

function startServices() {
	// Generate a fresh Fernet-compatible encryption key for token storage (test-only).
	// Fernet requires a 32-byte key encoded as URL-safe base64 with padding.
	const tokenEncryptionKey = randomBytes(32).toString('base64')
	process.env.TOKEN_ENCRYPTION_KEY = tokenEncryptionKey

	log('Starting docker-compose services...')
	compose('up -d')
	log('Docker-compose services started.')
}

function stopServices() {
	log('Stopping docker-compose services...')
	try {
		execSync(`docker compose -f "${composeFile}" down -v --remove-orphans --timeout 30`, {
			stdio: 'pipe',
		})
	} catch {
		// Best-effort cleanup
	}
	log('Docker-compose services stopped.')
}

/**
 * Poll a URL until it returns an expected response.
 */
async function waitForService(name, url, checkFn, { maxAttempts = 60, delayMs = 5000 } = {}) {
	log(`Waiting for ${name} at ${url}...`)

	for (let attempt = 1; attempt <= maxAttempts; attempt++) {
		try {
			const response = await fetch(url)
			if (await checkFn(response)) {
				log(`${name} is ready.`)
				return true
			}
		} catch {
			// Service not up yet
		}

		if (attempt % 3 === 0) {
			log(`Still waiting for ${name}... (attempt ${attempt}/${maxAttempts})`)
		}

		await new Promise((resolve) => setTimeout(resolve, delayMs))
	}

	throw new Error(`${name} did not become ready in time.`)
}

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

async function waitForOidc() {
	await waitForService(
		'OIDC discovery',
		OIDC_DISCOVERY_URL,
		async (response) => {
			if (!response.ok) return false
			try {
				const data = await response.json()
				return !!(data.issuer && data.authorization_endpoint && data.token_endpoint)
			} catch {
				return false
			}
		},
		{ maxAttempts: 20, delayMs: 3000 },
	)
}

async function waitForMcp() {
	await waitForService(
		'MCP server',
		MCP_HEALTH_URL,
		async (response) => response.ok,
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
	log('All services healthy — signaling ready.')
	process.stdout.write('Services are ready\n')

	// Idle until shutdown signal
	await new Promise(() => {})
} catch (error) {
	log(`Error: ${error.message}`)

	// Dump logs for debugging
	try {
		log('--- Docker Compose Logs ---')
		compose('logs --tail=100')
	} catch {
		// Best effort
	}

	stopServices()
	process.exit(1)
}
