/**
 * SPDX-FileCopyrightText: 2026 Astrolabe contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from './fixtures.ts'
import { completeAuthorization } from './helpers/authorize.ts'

/**
 * A SAR case, end to end: create it in a folder → it opens in a sidebar beside
 * the search page → search (logged in the case with its filters) → add a
 * result → give a reason in the sidebar → export a redacted archive → ready
 * for audit → close. The case is stored in Nextcloud, so it survives a reload.
 *
 * Needs an MCP server that advertises `sar_export_available` (it needs the
 * embedding gateway's /v1/ner, stubbed in nextcloud-mcp-server's compose
 * stack). The UI is hidden otherwise, and the test skips saying so rather
 * than failing on a server that cannot serve the feature.
 */

const NC = new URL(process.env.BASE_URL ?? 'http://localhost:8080/index.php/').origin
const AUTH = { Authorization: 'Basic ' + Buffer.from('admin:admin').toString('base64') }

async function nc(method: string, path: string, body?: unknown): Promise<Response> {
	return fetch(NC + path, {
		method,
		headers: { ...AUTH, 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
		body: body === undefined ? undefined : JSON.stringify(body),
	})
}

test.describe('Subject Access Request cases', () => {
	test('collects, exports, and closes a case from the search page', async ({ authenticatedPage: page }) => {
		test.setTimeout(360_000)
		const term = `zorblat${Date.now()}`
		const folder = `SAR-e2e-${Date.now()}`
		const caseDir = `/remote.php/dav/files/admin/${folder}/SAR-e2e`

		await completeAuthorization(page)
		await page.goto('/apps/astrolabe')
		const sarNav = page.locator('.app-navigation-entry__name', { hasText: 'Subject Access Request' })
		const available = await sarNav.isVisible({ timeout: 15_000 }).catch(() => false)
		test.skip(!available, 'MCP server does not advertise sar_export_available')

		const created = await nc('POST', '/index.php/apps/notes/api/v1/notes', {
			title: `Letter re Karen Smith ${term}`,
			content: `Jane Doe met Karen Smith about ${term}. Tom Brown took notes.`,
			category: '',
		})
		expect(created.ok).toBe(true)
		const note = await created.json()
		expect((await nc('MKCOL', `/remote.php/dav/files/admin/${folder}`)).status).toBe(201)

		try {
			// Create the case in the folder: it opens beside the search page.
			await sarNav.click()
			await page.getByRole('button', { name: 'New case' }).click()
			await page.getByRole('textbox', { name: 'Case name' }).fill('SAR-e2e')
			await page.locator('.sar-create textarea').fill('Jane Doe')
			await page.getByRole('button', { name: 'Choose folder' }).click()
			await page.locator('tr', { hasText: folder }).click()
			await page.getByRole('button', { name: `Choose ${folder}` }).click()
			await page.getByRole('button', { name: 'Create case' }).click()
			await expect(page.getByText('Searching for SAR case SAR-e2e')).toBeVisible()
			const sidebar = page.locator('.sar-sidebar')
			await expect(sidebar).toContainText('SAR-e2e')
			await expect(sidebar.locator('.sar-state')).toHaveText('Open')

			// Search until the note is indexed, then add it to the case.
			const searchInput = page.getByRole('textbox', { name: 'Search query' })
			const result = page.locator('.mcp-result-item', { hasText: term })
			await expect.poll(async () => {
				await searchInput.fill(term)
				// Wait for the search itself, not a fixed delay: a case search
				// also logs the query (3-4s locally), and the results list is
				// hidden until it finishes.
				const responded = page.waitForResponse((r) => r.url().includes('/api/search'))
				await searchInput.press('Control+Enter')
				await responded
				await page.waitForTimeout(500)
				return result.count()
			}, { timeout: 240_000, intervals: [5_000] }).toBeGreaterThan(0)
			// One row per document while searching for a case.
			await expect(result).toHaveCount(1)
			await result.locator('.mcp-add-to-sar').click()
			await expect(result.locator('.mcp-add-to-sar')).toContainText('In SAR')

			// The search is logged in the case, shown in the sidebar.
			await expect(sidebar.locator('.sar-queries')).toContainText(term)

			// Give the reason in the sidebar and export.
			const reason = sidebar.getByRole('textbox', { name: 'Reason for inclusion' })
			await reason.fill('Karen Smith\'s letter mentions the subject')
			await reason.blur()
			await expect(sidebar.locator('.sar-item')).toHaveCount(1)
			await sidebar.getByRole('button', { name: 'Create redacted archive' }).click()
			await expect(sidebar.locator('.sar-state')).toHaveText('Ready for audit', { timeout: 120_000 })
			await expect(sidebar.locator('.sar-exports')).toContainText('v1 · ready for review')
			await expect(page.getByText('SAR case SAR-e2e is not open')).toBeVisible()

			// The case and the archive are in Nextcloud.
			const stored = await (await nc('GET', `${caseDir}/sar-case.json`)).json()
			expect(stored.state).toBe('ready_for_audit')
			const logged = stored.queries.find((q: { text: string }) => q.text === term)
			expect(logged?.filters?.granularity).toBe('document')
			const archive = await nc('GET', `${caseDir}/exports/SAR-e2e-v1.zip`)
			expect(archive.ok).toBe(true)
			// Zip entry names are stored uncompressed: the document's filename
			// carries the redacted title.
			const bytes = Buffer.from(await archive.arrayBuffer()).toString('latin1')
			expect(bytes).toContain('documents/001-Letter-re-[PERSON_1]')
			expect(bytes).not.toContain('Karen-Smith')

			// Close it; after a reload the case is still there, closed.
			page.once('dialog', (dialog) => dialog.accept())
			await sidebar.getByRole('button', { name: 'Close case' }).click()
			await expect(sidebar.locator('.sar-state')).toHaveText('Closed')
			await page.reload()
			await page.locator('.app-navigation-entry__name', { hasText: 'Subject Access Request' }).click()
			await expect(page.locator('.sar-list-row', { hasText: 'SAR-e2e' })).toContainText('Closed')
		} finally {
			await nc('DELETE', `/index.php/apps/notes/api/v1/notes/${note.id}`)
			await nc('DELETE', `/remote.php/dav/files/admin/${folder}`)
		}
	})
})
