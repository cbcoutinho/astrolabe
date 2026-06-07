<template>
	<div class="astrolabe-personal">
		<NcSettingsSection
			:name="t('astrolabe', 'Astrolabe')"
			:description="t('astrolabe', 'AI-powered semantic search across your Nextcloud content. Find documents by meaning, not just keywords.')">
			<div class="status-card">
				<p><strong>{{ t('astrolabe', 'Service URL') }}:</strong> <code>{{ serverUrl }}</code></p>
				<p><strong>{{ t('astrolabe', 'Version') }}:</strong> {{ serverStatus?.version || 'Unknown' }}</p>
			</div>
		</NcSettingsSection>

		<NcSettingsSection
			:name="t('astrolabe', 'Background indexing')"
			:description="t('astrolabe', 'Search itself uses your active Nextcloud session — no authorization step is needed. To have the MCP server index your files in the background, provide an app password it can use to read your files via WebDAV.')">
			<template v-if="!allowSelfProvision">
				<p v-if="hasAccess">
					<span class="status-badge status-enabled">{{ t('astrolabe', 'Background indexing enabled') }}</span>
				</p>
				<p v-if="hasAccess && provisionedAt" class="help-text">
					{{ t('astrolabe', 'Provisioned at') }}: {{ formatDate(provisionedAt) }}
				</p>
				<NcNoteCard type="info">
					<p v-if="hasAccess">
						{{ t('astrolabe', 'Your background indexing is managed by your administrator.') }}
					</p>
					<p v-else>
						{{ t('astrolabe', 'Background indexing is managed by your administrator. Contact them to have it enabled for your account.') }}
					</p>
				</NcNoteCard>
			</template>

			<template v-else-if="hasAccess">
				<p>
					<span class="status-badge status-enabled">{{ t('astrolabe', 'Background indexing enabled') }}</span>
				</p>
				<p v-if="provisionedAt" class="help-text">
					{{ t('astrolabe', 'Provisioned at') }}: {{ formatDate(provisionedAt) }}
				</p>
				<div id="mcp-revoke-background-form" class="actions">
					<NcButton
						id="mcp-revoke-background-button"
						variant="warning"
						:disabled="busy"
						@click="disable">
						<template #icon>
							<Delete :size="20" />
						</template>
						{{ busy ? t('astrolabe', 'Disabling…') : t('astrolabe', 'Disable background indexing') }}
					</NcButton>
				</div>
				<p class="help-text">
					{{ t('astrolabe', 'The MCP server will lose access to your Nextcloud files. Existing indexed content remains searchable until it next reconciles.') }}
				</p>
			</template>

			<template v-else>
				<div class="actions">
					<NcButton
						id="mcp-enable-background-button"
						variant="primary"
						:disabled="busy"
						@click="enable">
						<template #icon>
							<Check :size="20" />
						</template>
						{{ busy ? t('astrolabe', 'Enabling…') : t('astrolabe', 'Enable background indexing') }}
					</NcButton>
				</div>
				<p class="help-text">
					{{ t('astrolabe', 'One click generates a dedicated app password from your current session, sends it to the MCP server, and stores it encrypted in your user preferences. No copy-paste needed — you can disable it again at any time.') }}
				</p>
			</template>
		</NcSettingsSection>

		<NcSettingsSection :name="t('astrolabe', 'Search your content')">
			<template v-if="vectorSyncEnabled">
				<p class="help-text">
					{{ t('astrolabe', 'Use natural language to search across your Notes, Files, Calendar, and Deck cards. Ask questions like "meeting notes from last week" or "recipes with chicken".') }}
				</p>
				<div class="actions">
					<NcButton variant="primary" :href="appUrl">
						<template #icon>
							<Magnify :size="20" />
						</template>
						{{ t('astrolabe', 'Open Astrolabe') }}
					</NcButton>
				</div>
			</template>
			<NcNoteCard v-else type="warning">
				<p>{{ t('astrolabe', 'Semantic search is not enabled on this server. Contact your administrator to enable this feature.') }}</p>
			</NcNoteCard>
		</NcSettingsSection>
	</div>
</template>

<script setup>
import { ref } from 'vue'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'

import { NcSettingsSection, NcButton, NcNoteCard } from '@nextcloud/vue'

import Check from 'vue-material-design-icons/Check.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'

const config = loadState('astrolabe', 'personal-config', {})
const serverUrl = ref(config.serverUrl || '')
const serverStatus = ref(config.serverStatus || null)
const vectorSyncEnabled = ref(config.vectorSyncEnabled ?? false)
const hasAccess = ref(config.hasBackgroundAccess ?? false)
const provisionedAt = ref(config.backgroundSyncProvisionedAt ?? null)
const allowSelfProvision = ref(config.allowUserSelfProvision ?? true)
const busy = ref(false)

const appUrl = generateUrl('/apps/astrolabe/')
const base = '/apps/astrolabe/api/v1/background-sync'

function formatDate(timestamp) {
	return new Date(timestamp * 1000).toLocaleString()
}

// Re-read the current provisioning state from the server after a change,
// instead of reloading the whole page.
async function refreshStatus() {
	try {
		const res = await axios.get(generateUrl(`${base}/status`))
		if (res.data?.success) {
			hasAccess.value = res.data.has_background_access
			provisionedAt.value = res.data.provisioned_at
		}
	} catch (err) {
		console.error('Failed to refresh background-sync status:', err)
	}
}

// Mint a dedicated app password from the current session (no copy-paste) and
// hand it to the MCP server. core/getapppassword exchanges the active *login*
// session for a fresh app password; it requires the OCS-APIRequest header +
// CSRF token.
async function mintSessionAppPassword() {
	const ocsBase = OC.linkToOCS('core', 2)
	const resp = await fetch(ocsBase + 'getapppassword', {
		method: 'GET',
		headers: {
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
			requesttoken: OC.requestToken,
		},
	})
	if (!resp.ok) {
		const err = new Error('getapppassword returned ' + resp.status)
		err.status = resp.status
		throw err
	}
	const data = await resp.json()
	const appPassword = data?.ocs?.data?.apppassword
	if (!appPassword) {
		throw new Error('getapppassword response missing apppassword')
	}
	// getapppassword names the new token after the request User-Agent (the
	// browser). Give it a recognisable name in Security settings instead.
	// Best-effort: the credential works regardless of its display name.
	await renameNewestAppToken('Astrolabe Background Sync').catch(() => {})
	return appPassword
}

// Rename the most recently created app token (the one core/getapppassword just
// minted) via the Security-settings authtokens API. Best-effort.
async function renameNewestAppToken(name) {
	const url = OC.generateUrl('/settings/personal/authtokens')
	const listResp = await fetch(url, {
		headers: { requesttoken: OC.requestToken, Accept: 'application/json' },
	})
	if (!listResp.ok) {
		return
	}
	const tokens = await listResp.json()
	if (!Array.isArray(tokens) || tokens.length === 0) {
		return
	}
	const newest = tokens.reduce((a, b) => (b.id > a.id ? b : a), tokens[0])
	await fetch(url + '/' + newest.id, {
		method: 'PUT',
		headers: { requesttoken: OC.requestToken, 'Content-Type': 'application/json' },
		body: JSON.stringify({ name }),
	})
}

// A 403 from core/getapppassword means the current session holds no usable
// login password to mint from — typically a session restored from a
// "remember me" cookie. Only a fresh interactive login repopulates the
// session password, so offer a one-click logout to re-authenticate.
function promptReLogin() {
	const message = t('astrolabe', 'Your Nextcloud session needs to be refreshed before background indexing can be enabled. This usually happens when you are signed in via a "remember me" cookie. Log out and sign back in, then enable it again?')
	// eslint-disable-next-line no-alert
	if (confirm(message)) {
		window.location.href = OC.generateUrl('/logout') + '?requesttoken=' + encodeURIComponent(OC.requestToken)
	}
}

async function enable() {
	busy.value = true
	try {
		const appPassword = await mintSessionAppPassword()
		const body = new FormData()
		body.append('appPassword', appPassword)
		const response = await fetch(generateUrl(`${base}/credentials`), {
			method: 'POST',
			headers: { requesttoken: OC.requestToken },
			body,
		})
		const result = await response.json()
		if (response.ok && result.success) {
			showSuccess(t('astrolabe', 'Background indexing enabled.'))
			await refreshStatus()
		} else {
			showError(result.error || t('astrolabe', 'Failed to enable background indexing.'))
		}
	} catch (error) {
		console.error('Background indexing provisioning error:', error)
		if (error && error.status === 403) {
			promptReLogin()
		} else {
			showError(t('astrolabe', 'Could not enable background indexing. Please try again.'))
		}
	} finally {
		busy.value = false
	}
}

async function disable() {
	// eslint-disable-next-line no-alert
	if (!confirm(t('astrolabe', 'Disable background indexing? The MCP server will lose access to your Nextcloud files.'))) {
		return
	}
	busy.value = true
	try {
		const response = await axios.post(generateUrl(`${base}/credentials/revoke`))
		if (response.data?.success) {
			showSuccess(t('astrolabe', 'Background indexing disabled.'))
			await refreshStatus()
		} else {
			showError(response.data?.error || t('astrolabe', 'Failed to disable background indexing.'))
		}
	} catch (error) {
		console.error('Revoke error:', error)
		// A 403 means an admin has since disabled self-provisioning (the Disable
		// button is normally hidden in that state, but guard the API path too).
		// Surface the server's message instead of the generic connection error.
		if (error?.response?.status === 403) {
			showError(error.response.data?.error || t('astrolabe', 'Background indexing is managed by your administrator.'))
		} else {
			showError(t('astrolabe', 'Unable to connect to server. Your access may already be revoked, or the server may be down.'))
		}
	} finally {
		busy.value = false
	}
}
</script>

<style scoped lang="scss">
.astrolabe-personal :deep(.notecard) {
	max-width: 100%;
}

.status-card {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px 20px;
	max-width: 480px;

	p {
		margin: 8px 0;

		&:first-child {
			margin-top: 0;
		}

		&:last-child {
			margin-bottom: 0;
		}
	}

	code {
		background: var(--color-background-dark);
		padding: 2px 6px;
		border-radius: var(--border-radius);
	}
}

.status-badge {
	display: inline-block;
	padding: 4px 10px;
	border-radius: var(--border-radius-pill, 100px);
	font-size: 13px;
	font-weight: 600;

	&.status-enabled {
		background: var(--color-success);
		color: var(--color-primary-element-text, #fff);
	}
}

.actions {
	margin: 12px 0 4px;
}

.help-text {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin-top: 8px;
	max-width: 600px;
}
</style>
