/**
 * SPDX-FileCopyrightText: 2025 Astrolabe contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Integration test for native sync-event delivery.
 *
 * Astrolabe's own event listeners deliver Nextcloud change events straight to
 * the MCP server's webhook ingress (POST /webhooks/nextcloud), replacing the
 * previous "register webhooks via the MCP server / webhook_listeners app" path.
 * The webhook_listeners app is NOT installed in this environment, so this test
 * exercises the native path end-to-end: enable the Notes sync preset in the
 * admin UI, create a brand-new note, and assert it becomes searchable.
 *
 * Requires:
 * - Nextcloud with Astrolabe, OIDC, and Notes apps installed
 * - MCP server running with WEBHOOK_SECRET set (so the ingress is mounted) and
 *   mcp_webhook_secret configured on the Nextcloud side to the same value
 *   (both wired in docker-compose.yml + app-hooks/25-configure-mcp-server.sh)
 *
 * Note: the compose environment also runs the periodic polling scanner
 * (VECTOR_SYNC_SCAN_INTERVAL), so this test asserts the integrated outcome
 * (the note is delivered and indexed). The native delivery mechanism itself —
 * envelope shape, secret auth, filtering, enqueue — is covered in isolation by
 * the unit and consumer-pact suites.
 */

import { test, expect } from './fixtures.ts'
import { completeAuthorization } from './helpers/authorize.ts'

const NC_BASE = 'http://localhost:8080'
const NC_AUTH = 'Basic ' + Buffer.from('admin:admin').toString('base64')

test.describe('Astrolabe native sync', () => {
	test('enabling the Notes preset delivers a new note to the MCP server', async ({ authenticatedPage: page }) => {
		test.setTimeout(360_000)

		// Step 1: authorize so the MCP server discovers the user and starts
		// indexing (native delivery still needs the user's app password on the
		// MCP side to fetch content over WebDAV).
		await completeAuthorization(page)

		// The requesttoken is required for state-changing POSTs and for the
		// session-authenticated search endpoint.
		const requestToken = await page.evaluate(() => (window as unknown as { OC?: { requestToken?: string } }).OC?.requestToken ?? '')
		expect(requestToken).not.toBe('')

		// Step 2: enable the Notes sync preset through the admin UI so its native
		// listeners subscribe (this also verifies the reworked admin panel).
		await page.goto('/settings/admin/astrolabe')
		const notesCard = page.locator('.webhook-preset-card', { hasText: 'Notes' })
		await expect(notesCard).toBeVisible({ timeout: 30_000 })
		// If a previous run left it enabled, we're already good; otherwise enable.
		const enableButton = notesCard.getByRole('button', { name: 'Enable' })
		if (await enableButton.count() > 0) {
			await enableButton.click()
			await expect(notesCard.getByText('Enabled')).toBeVisible({ timeout: 15_000 })
		}

		// Step 3: create a uniquely identifiable note via WebDAV, under the
		// Notes folder so it matches the Notes preset's path filter.
		const marker = `astrolabe-native-sync-marker-${Date.now()}`
		const noteName = `native-sync-${Date.now()}.md`
		const putRes = await fetch(`${NC_BASE}/remote.php/dav/files/admin/Notes/${noteName}`, {
			method: 'PUT',
			headers: { Authorization: NC_AUTH },
			body: `Kubernetes cluster note. Unique phrase: ${marker}.`,
		})
		expect(putRes.ok, `WebDAV PUT failed: HTTP ${putRes.status}`).toBeTruthy()

		// Step 4: poll the session-authenticated search endpoint until the new
		// note is indexed and returned for its unique marker phrase.
		let lastCount = -1
		await expect.poll(
			async () => {
				const res = await page.request.get('/apps/astrolabe/api/search', {
					params: { query: marker, algorithm: 'hybrid', limit: '10', include_pca: 'false' },
					headers: { requesttoken: requestToken },
				})
				if (!res.ok()) {
					return false
				}
				const data = await res.json()
				const results: Array<{ title?: string, excerpt?: string }> = data.results ?? []
				lastCount = results.length
				return results.some((r) => (r.title ?? '').includes(marker) || (r.excerpt ?? '').includes(marker))
			},
			{ timeout: 240_000, intervals: [5_000], message: `note "${noteName}" never became searchable (last result count: ${lastCount})` },
		).toBe(true)
	})
})
