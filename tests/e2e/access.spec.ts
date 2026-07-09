/**
 * SPDX-FileCopyrightText: 2025 Astrolabe contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Integration test for astrolabe-side access checks on the content-fetch
 * endpoints.
 *
 * The check is a real-time, in-process Nextcloud ACL check (IRootFolder), so it
 * is independent of vector indexing. That lets this test exercise the exact
 * window the feature guards — access CHANGING between indexing and read — with
 * no dependency on the MCP server having indexed anything:
 *
 *   1. admin shares a file with bob    → bob's chunk-context request is allowed
 *      (not 403; it proceeds to the MCP backstop)
 *   2. admin revokes the share         → bob's chunk-context request is 403,
 *      returned locally before any MCP call
 *
 * (Never-had-access is already handled by the MCP query-time ACL prefilter and
 * is not what this feature fixes — hence the share-then-revoke shape.)
 *
 * Requires the second user "bob" from app-hooks/31-create-second-user.sh.
 */

import { test, expect } from './fixtures.ts'
import { login } from './helpers/login.ts'

const NC = 'http://localhost:8080'
const ADMIN_AUTH = 'Basic ' + Buffer.from('admin:admin').toString('base64')
const BOB = { user: 'bob', password: 'bobpassword123' }

/** Minimal OCS/WebDAV helpers over Node fetch with admin basic auth. */
async function adminFetch(path: string, init: RequestInit = {}): Promise<Response> {
	return fetch(`${NC}${path}`, {
		...init,
		headers: {
			Authorization: ADMIN_AUTH,
			'OCS-APIRequest': 'true',
			...(init.headers ?? {}),
		},
	})
}

test.describe('Astrolabe access checks', () => {
	test('a shared file is reachable, and denied once the share is revoked', async ({ page }) => {
		test.setTimeout(120_000)

		// 1. admin creates a file and shares it with bob (read-only).
		const fileName = `access-test-${Date.now()}.txt`
		const put = await adminFetch(`/remote.php/dav/files/admin/${fileName}`, {
			method: 'PUT',
			body: 'access check fixture',
		})
		expect(put.ok, `WebDAV PUT failed: ${put.status}`).toBeTruthy()

		const shareRes = await adminFetch('/ocs/v2.php/apps/files_sharing/api/v1/shares?format=json', {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: new URLSearchParams({ path: `/${fileName}`, shareType: '0', shareWith: BOB.user, permissions: '1' }).toString(),
		})
		expect(shareRes.ok, `OCS share create failed: ${shareRes.status}`).toBeTruthy()
		const shareJson = await shareRes.json()
		const shareData = shareJson?.ocs?.data ?? {}
		const shareId = String(shareData.id)
		// item_source / file_source is the numeric Nextcloud fileId.
		const fileId = String(shareData.file_source ?? shareData.item_source ?? '')
		expect(fileId).not.toBe('')

		// 2. Log in as bob and fetch the chunk context for the shared file.
		await login(page, BOB.user, BOB.password)
		const requestToken = await page.evaluate(() => (window as unknown as { OC?: { requestToken?: string } }).OC?.requestToken ?? '')
		expect(requestToken).not.toBe('')

		const chunkContext = (): Promise<import('@playwright/test').APIResponse> =>
			page.request.get('/apps/astrolabe/api/chunk-context', {
				params: { doc_type: 'file', doc_id: fileId, start: '0', end: '10' },
				headers: { requesttoken: requestToken },
			})

		// While shared, the local access check ALLOWS — the request proceeds to
		// the MCP backstop (which may 200 or error, but must NOT be 403).
		const allowed = await chunkContext()
		expect(allowed.status(), 'shared file must not be access-denied').not.toBe(403)

		// 3. admin revokes the share.
		const del = await adminFetch(`/ocs/v2.php/apps/files_sharing/api/v1/shares/${shareId}`, { method: 'DELETE' })
		expect(del.ok, `OCS share delete failed: ${del.status}`).toBeTruthy()

		// 4. bob can no longer access it — astrolabe denies locally with 403,
		// before the next sync would purge the stale index entry.
		await expect.poll(async () => (await chunkContext()).status(), {
			timeout: 30_000,
			intervals: [2_000],
			message: 'chunk-context should return 403 once the share is revoked',
		}).toBe(403)
	})
})
