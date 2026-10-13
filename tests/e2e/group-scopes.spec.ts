/**
 * SPDX-FileCopyrightText: 2026 Astrolabe contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Per-group scope limits in the oidc app, as seen through Astrolabe's own
 * token minting (TokenGenerationRequestEvent).
 *
 * Astrolabe does not inspect scopes: it asks for what it wants and the MCP
 * server hides every tool the token does not grant. So the limit shows up as
 * the tool catalogue `occ astrolabe:mcp-probe` reports for a user. carol is in
 * group `scoped`, limited to files.read + semantic.read
 * (app-hooks/32-configure-group-scopes.sh); admin is in no limited group.
 *
 * Skipped when the installed oidc app has no group scope limits.
 */

import { execFileSync } from 'node:child_process'
import { test, expect } from './fixtures.ts'

const COMPOSE = ['compose', '-f', 'tests/e2e/docker-compose.yml', 'exec', '-T', 'app', 'php', '/var/www/html/occ']

function occ(...args: string[]): string {
	return execFileSync('docker', [...COMPOSE, ...args], { encoding: 'utf-8' })
}

/** Tool names visible to `user` through a token minted for these scopes. */
function probeTools(user: string): string[] {
	return occ('astrolabe:mcp-probe', user, '--scopes', 'notes.read files.read')
		.split('\n')
		.filter((line) => line.startsWith('  nc_'))
		.map((line) => line.trim().split(/\s+/)[0])
}

function groupScopesConfigured(): boolean {
	try {
		return occ('oidc:group-scopes:list').includes('Group: scoped,')
	} catch {
		return false
	}
}

test.describe('oidc group scope limits', () => {
	// Checked at run time, not collection time: the stack is not up yet when
	// Playwright collects the spec.
	test.beforeAll(() => {
		test.skip(!groupScopesConfigured(), 'oidc app without group scope limits')
	})

	test('a limited user gets only the tools their group allows', () => {
		const tools = probeTools('carol')

		expect(tools.some((t) => t.startsWith('nc_webdav_'))).toBe(true)
		expect(tools.filter((t) => t.startsWith('nc_notes_'))).toEqual([])
	})

	test('a user in no limited group is unaffected', () => {
		expect(probeTools('admin').some((t) => t.startsWith('nc_notes_'))).toBe(true)
	})
})
