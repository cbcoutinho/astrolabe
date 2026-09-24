/**
 * SPDX-FileCopyrightText: 2026 Astrolabe contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from './fixtures.ts'
import { completeAuthorization } from './helpers/authorize.ts'

/**
 * SAR export, end to end: search → add to the SAR basket → subject, reason and
 * output folder → submit → the MCP server writes a redacted archive to the
 * folder and the view reports it ready for review.
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

test.describe('Subject Access Request export', () => {
	test('exports a redacted archive to the chosen folder', async ({ authenticatedPage: page }) => {
		test.setTimeout(360_000)
		const term = `zorblat${Date.now()}`
		const folder = `SAR-e2e-${Date.now()}`

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
			// Search until the new note is indexed and returned.
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
			await sarNav.click()

			await page.locator('.sar-export textarea').fill('Jane Doe')
			await page.getByRole('textbox', { name: 'Reason for inclusion' })
				.fill('Karen Smith\'s letter mentions the subject')
			await page.getByRole('textbox', { name: 'Archive name' }).fill('SAR-e2e')

			await page.getByRole('button', { name: 'Choose output folder' }).click()
			await page.locator('tr', { hasText: folder }).click()
			await page.getByRole('button', { name: `Choose ${folder}` }).click()
			await expect(page.locator('.sar-folder-path')).toHaveText(`/${folder}`)

			await page.getByRole('button', { name: 'Create redacted archive' }).click()
			await expect(page.getByText(`Ready for review: /${folder}/SAR-e2e.zip`))
				.toBeVisible({ timeout: 120_000 })

			const status = await nc('GET', `/remote.php/dav/files/admin/${folder}/SAR-e2e.status.json`)
			expect(status.ok).toBe(true)
			expect(await status.json()).toMatchObject({ state: 'done', total: 1, failed: 0 })
			const archive = await nc('GET', `/remote.php/dav/files/admin/${folder}/SAR-e2e.zip`)
			expect(archive.ok).toBe(true)
			// The archive's entry names are stored uncompressed in the zip:
			// the document's filename carries the redacted title.
			const bytes = Buffer.from(await archive.arrayBuffer()).toString('latin1')
			expect(bytes).toContain('documents/001-Letter-re-[PERSON_1]')
			expect(bytes).not.toContain('Karen-Smith')
		} finally {
			await nc('DELETE', `/index.php/apps/notes/api/v1/notes/${note.id}`)
			await nc('DELETE', `/remote.php/dav/files/admin/${folder}`)
		}
	})
})
