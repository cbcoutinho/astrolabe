/**
 * SPDX-FileCopyrightText: 2025 Astrolabe contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Starts the docker-compose E2E test environment.
 *
 * Playwright polls the MCP health endpoint (configured in playwright.config.ts)
 * to determine when services are ready. This script just manages the
 * docker-compose lifecycle and stays alive until SIGTERM.
 */

import { execSync } from 'node:child_process'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = dirname(fileURLToPath(import.meta.url))

// Base stack, plus any overlays named in E2E_COMPOSE_OVERLAY (space-separated
// file names relative to this directory) — e.g. the external-IdP lane passes
// docker-compose.external-idp.yml.
const composeFiles = ['docker-compose.yml', ...(process.env.E2E_COMPOSE_OVERLAY ?? '').split(/\s+/).filter(Boolean)]
const fileArgs = composeFiles.map((f) => `-f "${join(__dirname, f)}"`).join(' ')

function log(msg) {
	process.stderr.write(`[start-server] ${msg}\n`)
}

/**
 * Run docker compose with the given arguments.
 * All output is captured and written to stderr so Playwright can display it.
 */
function compose(args) {
	try {
		const output = execSync(`docker compose ${fileArgs} ${args}`, {
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

function stopServices() {
	log('Stopping docker-compose services...')
	try {
		execSync(`docker compose ${fileArgs} down -v --remove-orphans --timeout 30`, {
			stdio: 'pipe',
		})
	} catch {
		// Best-effort cleanup
	}
	log('Docker-compose services stopped.')
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
	log('Starting docker-compose services...')
	compose('up -d')
	log('Docker-compose services started. Playwright will poll for readiness.')

	// Keep the process alive — Playwright handles readiness via url polling.
	// Use setInterval instead of an unsettled Promise to avoid Node warnings.
	setInterval(() => {}, 60_000)
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
