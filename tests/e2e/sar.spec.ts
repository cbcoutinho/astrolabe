/**
 * SPDX-FileCopyrightText: 2026 Astrolabe contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from './fixtures.ts'
import { completeAuthorization } from './helpers/authorize.ts'

/**
 * A SAR case, end to end: create it in a folder → collect a search result into
 * it → give a reason → export a redacted archive → ready for audit → close.
 * The case is stored in Nextcloud, so it survives a reload.
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
	test('collects, exports, and closes a case', async ({ authenticatedPage: page }) => {
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
			// Create the case in the folder; it becomes the active case.
			await sarNav.click()
			await page.getByRole('button', { name: 'New case' }).click()
			await page.getByRole('textbox', { name: 'Case name' }).fill('SAR-e2e')
			await page.locator('.sar-create textarea').fill('Jane Doe')
			await page.getByRole('button', { name: 'Choose folder' }).click()
			await page.locator('tr', { hasText: folder }).click()
			await page.getByRole('button', { name: `Choose ${folder}` }).click()
			await page.getByRole('button', { name: 'Create case' }).click()
			await expect(page.getByText('Collecting for SAR case SAR-e2e')).toBeVisible()

			// Search until the note is indexed, then add it to the case.
			const searchInput = page.getByRole('textbox', { name: 'Search query' })
			const result = page.locator('.mcp-result-item', { hasText: term })
			await expect.poll(async () => {
				await searchInput.fill(term)
				await searchInput.press('Control+Enter')
				await page.waitForTimeout(2_000)
				return result.count()
			}, { timeout: 240_000, intervals: [5_000] }).toBeGreaterThan(0)
			await result.first().locator('.mcp-add-to-sar').click()
			await expect(result.first().locator('.mcp-add-to-sar')).toContainText('In SAR')

			// Give the reason on the case page and export.
			await page.getByRole('button', { name: 'Open case' }).click()
			const reason = page.getByRole('textbox', { name: 'Reason for inclusion' })
			await reason.fill('Karen Smith\'s letter mentions the subject')
			await reason.blur()
			await expect(page.locator('.sar-item')).toHaveCount(1)
			await page.getByRole('button', { name: 'Create redacted archive' }).click()
			await expect(page.locator('.sar-case-header .sar-state')).toHaveText('Ready for audit', { timeout: 120_000 })
			await expect(page.locator('.sar-exports')).toContainText('v1 · ready for review')

			// The case and the archive are in Nextcloud.
			const stored = await (await nc('GET', `${caseDir}/sar-case.json`)).json()
			expect(stored.state).toBe('ready_for_audit')
			expect(stored.queries.map((q: { text: string }) => q.text)).toContain(term)
			const archive = await nc('GET', `${caseDir}/exports/SAR-e2e-v1.zip`)
			expect(archive.ok).toBe(true)
			// Zip entry names are stored uncompressed: the document's filename
			// carries the redacted title.
			const bytes = Buffer.from(await archive.arrayBuffer()).toString('latin1')
			expect(bytes).toContain('documents/001-Letter-re-[PERSON_1]')
			expect(bytes).not.toContain('Karen-Smith')

			// Close it; after a reload the case is still there, closed.
			page.once('dialog', (dialog) => dialog.accept())
			await page.getByRole('button', { name: 'Close case' }).click()
			await expect(page.locator('.sar-case-header .sar-state')).toHaveText('Closed')
			await page.reload()
			await page.locator('.app-navigation-entry__name', { hasText: 'Subject Access Request' }).click()
			await expect(page.locator('.sar-list-row', { hasText: 'SAR-e2e' })).toContainText('Closed')
		} finally {
			await nc('DELETE', `/index.php/apps/notes/api/v1/notes/${note.id}`)
			await nc('DELETE', `/remote.php/dav/files/admin/${folder}`)
		}
	})
})
