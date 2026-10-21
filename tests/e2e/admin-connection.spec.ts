/**
 * SPDX-FileCopyrightText: 2026 Astrolabe contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * The admin "MCP server connection" form: a value pasted with a stray space is
 * flagged inline and can't be saved, the backend refuses it too, and a valid
 * save goes through.
 *
 * Every test leaves the environment's configuration exactly as it found it
 * (the valid save re-enters the compose environment's own webhook secret), so
 * it can run alongside specs that depend on a working MCP connection.
 */

import { test, expect } from './fixtures.ts'

// Matches mcp_webhook_secret set by app-hooks/25-configure-mcp-server.sh and
// WEBHOOK_SECRET on the mcp service in docker-compose.yml.
const WEBHOOK_SECRET = 'astrolabe-e2e-webhook-secret'
const EDGE_ERROR = 'Remove the space or invisible character at the start or end.'

test.describe('Astrolabe admin connection settings', () => {
	test.beforeEach(async ({ authenticatedPage: page }) => {
		await page.goto('/settings/admin/astrolabe')
		await expect(page.getByLabel('MCP Server URL (internal)')).not.toHaveValue('', { timeout: 30_000 })
	})

	test('flags a pasted trailing space inline and refuses to save it', async ({ authenticatedPage: page }) => {
		const serverUrl = page.getByLabel('MCP Server URL (internal)')
		const save = page.getByRole('button', { name: 'Save', exact: true }).first()
		const original = await serverUrl.inputValue()

		await serverUrl.fill(`${original} `)
		await expect(page.getByText(EDGE_ERROR)).toBeVisible()
		await expect(serverUrl).toHaveAttribute('aria-invalid', 'true')
		await expect(save).toBeDisabled()

		await serverUrl.fill(` ${original}`)
		await expect(page.getByText(EDGE_ERROR)).toBeVisible()

		await serverUrl.fill(original)
		await expect(page.getByText(EDGE_ERROR)).toBeHidden()

		// Nothing was saved: the stored value is unchanged.
		await page.reload()
		await expect(page.getByLabel('MCP Server URL (internal)')).toHaveValue(original, { timeout: 30_000 })
	})

	test('backend rejects whitespace and stores nothing', async ({ authenticatedPage: page }) => {
		const original = await page.getByLabel('MCP Server URL (internal)').inputValue()
		const clientId = await page.getByLabel('OIDC client identifier').inputValue()
		const requestToken = await page.evaluate(() => (window as unknown as { OC?: { requestToken?: string } }).OC?.requestToken ?? '')

		const res = await page.request.post('/apps/astrolabe/api/admin/connection-settings', {
			headers: { requesttoken: requestToken },
			data: { serverUrl: `${original} `, publicUrl: '', clientId: ` ${clientId}`, webhookSecret: '' },
		})

		expect(res.status()).toBe(422)
		const body = await res.json()
		expect(Object.keys(body.errors).sort()).toEqual(['astrolabe_client_id', 'mcp_server_url'])

		await page.reload()
		await expect(page.getByLabel('MCP Server URL (internal)')).toHaveValue(original, { timeout: 30_000 })
		await expect(page.getByLabel('OIDC client identifier')).toHaveValue(clientId)
	})

	test('saves valid settings', async ({ authenticatedPage: page }) => {
		const original = await page.getByLabel('MCP Server URL (internal)').inputValue()

		await page.getByLabel('Webhook shared secret', { exact: true }).fill(WEBHOOK_SECRET)
		await page.getByRole('button', { name: 'Save', exact: true }).first().click()

		// exact: the toast is also mirrored, prefixed "Success: ", into an
		// aria-live list for screen readers.
		await expect(page.getByText('Connection settings saved', { exact: true })).toBeVisible()
		await page.reload()
		await expect(page.getByLabel('MCP Server URL (internal)')).toHaveValue(original, { timeout: 30_000 })
		await expect(page.getByLabel('Webhook shared secret', { exact: true })).toHaveAttribute('placeholder', 'Configured — leave blank to keep')
	})
})
