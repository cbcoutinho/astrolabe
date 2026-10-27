/**
 * SPDX-FileCopyrightText: 2025 Astrolabe contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect } from '@playwright/test'

/**
 * Wait until the MCP server has indexed the seeded data. After authorization
 * the server discovers the user and begins indexing. Polls the server's public
 * status API with Node fetch, not page.request, so no browser cookies (and no
 * CSRF token) are involved.
 */
export async function waitForIndexing(): Promise<void> {
	let pollCount = 0
	await expect.poll(
		async () => {
			pollCount++
			try {
				const res = await fetch('http://localhost:8000/api/v1/vector-sync/status')
				if (!res.ok) {
					console.log(`vector-sync poll #${pollCount}: HTTP ${res.status}`)
					return false
				}
				const data = await res.json()
				// Log full response on first poll to capture API shape
				if (pollCount === 1) {
					console.log(`vector-sync poll #1 full response: ${JSON.stringify(data)}`)
				}
				// Field names changed in MCP server v0.68: indexed_count → indexed_documents,
				// pending_count → pending_documents. Fallbacks can be removed once v0.68+ is the floor.
				const indexed = data.indexed_documents ?? data.indexed_count ?? 0
				const pending = data.pending_documents ?? data.pending_count ?? -1
				console.log(`vector-sync poll #${pollCount}: indexed=${indexed} pending=${pending}`)
				return indexed > 0 && pending === 0
			} catch (e) {
				console.log(`vector-sync poll #${pollCount} error: ${e instanceof Error ? e.message : String(e)}`)
				return false
			}
		},
		{ timeout: 240_000, intervals: [5_000] },
	).toBe(true)
}
