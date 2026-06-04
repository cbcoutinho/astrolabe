<template>
	<div class="astrolabe-admin">
		<NcSettingsSection v-if="loading" :name="t('astrolabe', 'Astrolabe')">
			<NcLoadingIcon :size="44" class="loading-icon" />
		</NcSettingsSection>

		<NcSettingsSection v-else-if="error" :name="t('astrolabe', 'Astrolabe')">
			<NcNoteCard type="error">
				<p><strong>{{ t('astrolabe', 'Cannot connect to MCP server') }}</strong></p>
				<p>{{ error }}</p>
				<p class="help-text">{{ t('astrolabe', 'Ensure MCP server is running and accessible. Check config.php for correct mcp_server_url.') }}</p>
				<NcButton variant="primary" @click="retryConnection">
					<template #icon>
						<Refresh :size="20" />
					</template>
					{{ t('astrolabe', 'Retry Connection') }}
				</NcButton>
			</NcNoteCard>
		</NcSettingsSection>

		<template v-else>
			<!-- Service status -->
			<NcSettingsSection :name="t('astrolabe', 'Service status')">
				<div class="status-card">
					<p><strong>{{ t('astrolabe', 'Version') }}:</strong> {{ serverStatus?.version || 'Unknown' }}</p>
					<p v-if="serverStatus?.uptime_seconds">
						<strong>{{ t('astrolabe', 'Uptime') }}:</strong> {{ formatUptime(serverStatus.uptime_seconds) }}
					</p>
					<p>
						<strong>{{ t('astrolabe', 'Auth Mode') }}:</strong> {{ serverStatus?.auth_mode || 'Unknown' }}
					</p>
					<p>
						<strong>{{ t('astrolabe', 'Semantic Search') }}:</strong>
						<span v-if="vectorSyncEnabled" class="status-badge status-enabled">
							{{ t('astrolabe', 'Enabled') }}
						</span>
						<span v-else class="status-badge status-disabled">
							{{ t('astrolabe', 'Disabled') }}
						</span>
					</p>
				</div>

				<NcNoteCard v-if="!vectorSyncEnabled" type="warning">
					<p><strong>{{ t('astrolabe', 'Semantic Search Not Configured') }}</strong></p>
					<p>{{ t('astrolabe', 'The MCP server does not have vector sync enabled. Astrolabe requires vector sync for semantic search, visualization, and webhooks.') }}</p>
					<p>
						<a href="https://github.com/cbcoutinho/nextcloud-mcp-server/blob/master/docs/configuration.md" target="_blank">
							{{ t('astrolabe', 'See the Configuration Guide for details.') }}
						</a>
					</p>
				</NcNoteCard>
			</NcSettingsSection>

			<!-- Indexing metrics -->
			<NcSettingsSection v-if="vectorSyncEnabled && vectorSyncStatus" :name="t('astrolabe', 'Indexing metrics')">
				<div class="metrics-grid">
					<div class="metric-card">
						<div class="metric-label">{{ t('astrolabe', 'Status') }}</div>
						<div class="metric-value" :class="`status-${vectorSyncStatus.status}`">
							{{ vectorSyncStatus.status }}
						</div>
					</div>
					<div class="metric-card">
						<div class="metric-label">{{ t('astrolabe', 'Indexed Documents') }}</div>
						<div class="metric-value">{{ formatNumber(vectorSyncStatus.indexed_documents) }}</div>
					</div>
					<div class="metric-card">
						<div class="metric-label">{{ t('astrolabe', 'Indexed Chunks') }}</div>
						<div class="metric-value">{{ formatNumber(vectorSyncStatus.indexed_chunks) }}</div>
					</div>
					<div class="metric-card">
						<div class="metric-label">{{ t('astrolabe', 'Pending Documents') }}</div>
						<div class="metric-value">{{ formatNumber(vectorSyncStatus.pending_documents) }}</div>
					</div>
					<div class="metric-card">
						<div class="metric-label">{{ t('astrolabe', 'Processing Rate') }}</div>
						<div class="metric-value">{{ formatNumber(vectorSyncStatus.documents_per_second, 1) }} docs/sec</div>
					</div>
				</div>
				<NcButton variant="secondary" @click="refreshStatus">
					<template #icon>
						<Refresh :size="20" />
					</template>
					{{ t('astrolabe', 'Refresh Status') }}
				</NcButton>
			</NcSettingsSection>

			<!-- Webhook management -->
			<NcSettingsSection
				v-if="vectorSyncEnabled"
				:name="t('astrolabe', 'Webhook management')"
				:description="t('astrolabe', 'Configure real-time synchronization for Nextcloud apps using webhooks. Webhooks provide instant updates to the MCP server when content changes.')">
				<div v-if="webhooksLoading" class="loading-indicator">
					<NcLoadingIcon :size="32" />
					<p>{{ t('astrolabe', 'Loading webhook presets...') }}</p>
				</div>

				<NcNoteCard v-else-if="webhooksProvisioningRequired" type="warning">
					<p><strong>{{ t('astrolabe', 'MCP server background access not provisioned') }}</strong></p>
					<p>
						{{ t('astrolabe', 'The MCP server needs an app password to call Nextcloud APIs on behalf of an admin. Enable background indexing for this admin account in Personal Settings, then reload this page.') }}
					</p>
					<div class="webhook-auth-actions">
						<NcButton variant="primary" @click="openPersonalSettings">
							{{ t('astrolabe', 'Go to Personal Settings') }}
						</NcButton>
					</div>
				</NcNoteCard>

				<NcNoteCard v-else-if="webhooksError" type="error">
					<p><strong>{{ t('astrolabe', 'Failed to load webhook presets') }}</strong></p>
					<p>{{ webhooksError }}</p>
				</NcNoteCard>

				<template v-else>
					<div v-if="webhookPresets.length === 0" class="empty-state">
						<NcNoteCard type="info">
							<p>{{ t('astrolabe', 'No webhook presets available. Install supported apps (Notes, Calendar, Tables, Forms) to enable webhooks.') }}</p>
						</NcNoteCard>
					</div>

					<div v-else class="webhook-presets-grid">
						<div v-for="preset in webhookPresets" :key="preset.id" class="webhook-preset-card">
							<div class="preset-header">
								<h4>{{ preset.name }}</h4>
								<span :class="`preset-status preset-status-${preset.enabled ? 'enabled' : 'disabled'}`">
									{{ preset.enabled ? t('astrolabe', 'Enabled') : t('astrolabe', 'Disabled') }}
								</span>
							</div>
							<p class="preset-description">{{ preset.description }}</p>
							<div class="preset-meta">
								<span class="preset-app">{{ t('astrolabe', 'App') }}: {{ preset.app }}</span>
								<span class="preset-events">{{ preset.events.length }} {{ t('astrolabe', 'events') }}</span>
							</div>
							<div class="preset-actions">
								<NcButton
									:variant="preset.enabled ? 'secondary' : 'primary'"
									:disabled="preset.toggling"
									@click="toggleWebhookPreset(preset)">
									{{ preset.toggling ? t('astrolabe', 'Please wait...') : (preset.enabled ? t('astrolabe', 'Disable') : t('astrolabe', 'Enable')) }}
								</NcButton>
							</div>
						</div>
					</div>

					<NcNoteCard type="info" class="webhook-info">
						<p><strong>{{ t('astrolabe', 'How Webhooks Work') }}</strong></p>
						<ul>
							<li>{{ t('astrolabe', 'Enable a preset to register webhooks for that app with the MCP server') }}</li>
							<li>{{ t('astrolabe', 'When content changes in Nextcloud, webhooks notify the MCP server instantly') }}</li>
							<li>{{ t('astrolabe', 'The MCP server updates its vector index in real-time for semantic search') }}</li>
							<li>{{ t('astrolabe', 'Disable a preset to stop receiving updates for that app') }}</li>
						</ul>
					</NcNoteCard>

					<NcNoteCard type="warning" class="webhook-requirements">
						<p><strong>{{ t('astrolabe', 'Requirements') }}</strong></p>
						<ul>
							<li>{{ t('astrolabe', 'The webhook_listeners app must be installed and enabled in Nextcloud') }}</li>
							<li>{{ t('astrolabe', 'The MCP server must be reachable from your Nextcloud instance') }}</li>
							<li>{{ t('astrolabe', 'The MCP server must have an app password for the calling admin (see Personal Settings)') }}</li>
						</ul>
					</NcNoteCard>
				</template>
			</NcSettingsSection>

			<!-- AI search provider settings -->
			<NcSettingsSection
				v-if="vectorSyncEnabled"
				:name="t('astrolabe', 'AI search provider settings')"
				:description="t('astrolabe', 'Configure the default search parameters for the AI Search provider in Nextcloud unified search.')">
				<div class="settings-form">
					<NcSelect
						:model-value="selectedAlgorithmOption"
						:options="algorithmOptions"
						:input-label="t('astrolabe', 'Search Algorithm')"
						class="form-field"
						@update:model-value="settings.algorithm = $event ? $event.id : 'hybrid'" />
					<p class="help-text">
						{{ t('astrolabe', 'Hybrid combines semantic understanding with keyword matching. Semantic finds conceptually similar content. BM25 matches exact keywords.') }}
					</p>

					<NcSelect
						:model-value="selectedFusionOption"
						:options="fusionOptions"
						:input-label="t('astrolabe', 'Fusion Method')"
						class="form-field"
						@update:model-value="settings.fusion = $event ? $event.id : 'rrf'" />
					<p class="help-text">
						{{ t('astrolabe', 'Only applies to hybrid search. RRF balances results well for most queries. DBSF may work better when keyword matches are over/under-weighted.') }}
					</p>

					<div class="form-field">
						<label>{{ t('astrolabe', 'Minimum Score Threshold') }}: {{ settings.scoreThreshold }}%</label>
						<input
							v-model="settings.scoreThreshold"
							type="range"
							min="0"
							max="100"
							step="5"
							class="score-slider" />
						<p class="help-text">
							{{ t('astrolabe', 'Filter out results below this relevance score. Set to 0 to show all results.') }}
						</p>
					</div>

					<NcTextField
						v-model="settings.limit"
						:label="t('astrolabe', 'Maximum Results')"
						type="number"
						:min="5"
						:max="100"
						:step="5"
						class="form-field" />
					<p class="help-text">
						{{ t('astrolabe', 'Maximum number of results to return per search query (5-100).') }}
					</p>

					<div class="form-actions">
						<NcButton variant="primary" :disabled="saving" @click="saveSettings">
							{{ saving ? t('astrolabe', 'Saving...') : t('astrolabe', 'Save Settings') }}
						</NcButton>
					</div>
				</div>
			</NcSettingsSection>

			<!-- User provisioning -->
			<NcSettingsSection :name="t('astrolabe', 'User provisioning')">
				<UserProvisioning :initial-allow-self-provision="allowUserSelfProvision" />
			</NcSettingsSection>

			<!-- Documentation -->
			<NcSettingsSection :name="t('astrolabe', 'Documentation')">
				<ul class="doc-links">
					<li>
						<a href="https://github.com/cbcoutinho/nextcloud-mcp-server/blob/master/docs/configuration.md" target="_blank">
							{{ t('astrolabe', 'Configuration Guide') }}
						</a>
					</li>
					<li>
						<a href="https://github.com/cbcoutinho/nextcloud-mcp-server" target="_blank">
							{{ t('astrolabe', 'GitHub Repository') }}
						</a>
					</li>
				</ul>
			</NcSettingsSection>
		</template>
	</div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'

import {
	NcSettingsSection,
	NcLoadingIcon,
	NcNoteCard,
	NcButton,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'

import Refresh from 'vue-material-design-icons/Refresh.vue'

import UserProvisioning from './UserProvisioning.vue'

// Reactive state
const loading = ref(true)
const error = ref(null)
const serverStatus = ref(null)
const vectorSyncStatus = ref(null)
const vectorSyncEnabled = ref(false)
const saving = ref(false)

// Webhook management state
const webhooksLoading = ref(false)
const webhooksError = ref(null)
// Set when the MCP server reports HTTP 428 (Precondition Required) — the
// admin has not provisioned background indexing yet, so the MCP server has
// no app password to call Nextcloud APIs with. Drives the CTA card below.
const webhooksProvisioningRequired = ref(false)
const webhookPresets = ref([])

// Load initial state from PHP
const initialData = loadState('astrolabe', 'admin-config', {})
const settings = ref(initialData.searchSettings || {
	algorithm: 'hybrid',
	fusion: 'rrf',
	scoreThreshold: 0,
	limit: 20,
})
const allowUserSelfProvision = ref(initialData.allowUserSelfProvision ?? true)

// Computed properties
const algorithmOptions = computed(() => [
	{ id: 'hybrid', label: t('astrolabe', 'Hybrid (Recommended)') },
	{ id: 'semantic', label: t('astrolabe', 'Semantic Only') },
	{ id: 'bm25', label: t('astrolabe', 'Keyword (BM25) Only') },
])

const fusionOptions = computed(() => [
	{ id: 'rrf', label: t('astrolabe', 'RRF - Reciprocal Rank Fusion (Recommended)') },
	{ id: 'dbsf', label: t('astrolabe', 'DBSF - Distribution-Based Score Fusion') },
])

// Computed properties for NcSelect (converts between stored ID and option object)
const selectedAlgorithmOption = computed(() =>
	algorithmOptions.value.find(opt => opt.id === settings.value.algorithm) || algorithmOptions.value[0],
)

const selectedFusionOption = computed(() =>
	fusionOptions.value.find(opt => opt.id === settings.value.fusion) || fusionOptions.value[0],
)

// Methods
async function loadServerStatus() {
	loading.value = true
	error.value = null

	try {
		// Fetch server status first (required)
		const statusResponse = await axios.get(generateUrl('/apps/astrolabe/api/admin/server-status'))

		if (statusResponse.data.success) {
			serverStatus.value = statusResponse.data.status
			vectorSyncEnabled.value = statusResponse.data.status?.vector_sync_enabled ?? false
		}

		// Fetch vector sync status only if enabled (non-fatal)
		if (vectorSyncEnabled.value) {
			try {
				const syncResponse = await axios.get(generateUrl('/apps/astrolabe/api/admin/vector-status'))
				if (syncResponse.data.success) {
					vectorSyncStatus.value = syncResponse.data.status
				}
			} catch (syncErr) {
				console.warn('Vector sync status unavailable:', syncErr.message)
			}
		}
	} catch (err) {
		console.error('Failed to load server status:', err)
		error.value = err.response?.data?.error || err.message || t('astrolabe', 'Network error')
	} finally {
		loading.value = false
	}
}

async function refreshStatus() {
	await loadServerStatus()
	showSuccess(t('astrolabe', 'Status refreshed'))
}

async function retryConnection() {
	// Clear error and retry loading server status
	error.value = null
	loading.value = true
	await loadServerStatus()
}

async function saveSettings() {
	saving.value = true

	try {
		const response = await axios.post(
			generateUrl('/apps/astrolabe/api/admin/search-settings'),
			settings.value,
			{ headers: { 'Content-Type': 'application/json' } },
		)

		if (response.data.success) {
			showSuccess(t('astrolabe', 'Settings saved successfully'))
		}
	} catch (err) {
		console.error('Failed to save settings:', err)
		showError(t('astrolabe', 'Failed to save settings'))
	} finally {
		saving.value = false
	}
}

async function loadWebhookPresets() {
	webhooksLoading.value = true
	webhooksError.value = null
	webhooksProvisioningRequired.value = false

	try {
		const response = await axios.get(generateUrl('/apps/astrolabe/api/admin/webhooks/presets'))

		if (response.data.success) {
			// Convert presets object to array with IDs
			const presetsObj = response.data.presets
			webhookPresets.value = Object.keys(presetsObj).map(id => ({
				id,
				...presetsObj[id],
				toggling: false,
			}))
		} else {
			webhooksError.value = response.data.error || t('astrolabe', 'Failed to load webhook presets')
		}
	} catch (err) {
		console.error('Failed to load webhook presets:', err)
		// 428 Precondition Required → admin hasn't completed Login Flow v2
		// provisioning. Show the dedicated CTA instead of a generic error.
		if (err.response?.status === 428 || err.response?.data?.provisioning_required) {
			webhooksProvisioningRequired.value = true
		} else {
			webhooksError.value = err.response?.data?.error || err.message || t('astrolabe', 'Network error')
		}
	} finally {
		webhooksLoading.value = false
	}
}

// Invariant: webhooksProvisioningRequired is reset on entry to
// loadWebhookPresets and only ever set true here on a 428 — we never clear
// it on success because the provisioning CTA card hides the toggle buttons
// (the v-else-if chain shows only one card at a time), so a successful
// toggle while the flag is true is not user-reachable.
async function toggleWebhookPreset(preset) {
	preset.toggling = true

	const endpoint = preset.enabled
		? `/apps/astrolabe/api/admin/webhooks/presets/${preset.id}/disable`
		: `/apps/astrolabe/api/admin/webhooks/presets/${preset.id}/enable`

	try {
		const response = await axios.post(generateUrl(endpoint))

		if (response.data.success) {
			// Toggle the enabled state
			preset.enabled = !preset.enabled
			showSuccess(response.data.message || (preset.enabled ? t('astrolabe', 'Webhook preset enabled') : t('astrolabe', 'Webhook preset disabled')))
		} else {
			showError(response.data.error || t('astrolabe', 'Failed to toggle webhook preset'))
		}
	} catch (err) {
		console.error('Failed to toggle webhook preset:', err)
		// 428 → admin hasn't provisioned. Surface the dedicated CTA at the
		// top of the page rather than a transient toast that the user might
		// dismiss without action.
		if (err.response?.status === 428 || err.response?.data?.provisioning_required) {
			webhooksProvisioningRequired.value = true
			showError(t('astrolabe', 'Authorize Astrolabe with the MCP server in Personal Settings to manage webhooks'))
		} else {
			showError(err.response?.data?.error || err.message || t('astrolabe', 'Network error'))
		}
	} finally {
		preset.toggling = false
	}
}

function openPersonalSettings() {
	window.location.href = generateUrl('/settings/user/astrolabe')
}

function formatUptime(seconds) {
	const hours = Math.floor(seconds / 3600)
	const minutes = Math.floor((seconds % 3600) / 60)
	return t('astrolabe', '{hours} hours, {minutes} minutes', { hours, minutes })
}

function formatNumber(value, decimals = 0) {
	if (value === undefined || value === null) return '0'
	return Number(value).toLocaleString(undefined, {
		minimumFractionDigits: decimals,
		maximumFractionDigits: decimals,
	})
}

// Lifecycle hooks
onMounted(async () => {
	await loadServerStatus()
	// Load webhook presets if vector sync is enabled
	if (vectorSyncEnabled.value) {
		await loadWebhookPresets()
	}
})
</script>

<style scoped lang="scss">
// Fix NcNoteCard icon sizing issues in Vue 3/@nextcloud/vue 9
.astrolabe-admin :deep(.notecard) {
	max-width: 100%;
	margin-bottom: 16px;

	.notecard__icon {
		flex-shrink: 0;
		width: 24px;
		height: 24px;

		svg {
			width: 24px;
			height: 24px;
		}
	}
}

.loading-icon {
	margin: 20px 0;
}

.help-text {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin-top: 8px;
}

.status-card {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 20px;
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

	&.status-disabled {
		background: var(--color-background-dark);
		color: var(--color-text-maxcontrast);
	}
}

.metrics-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
	gap: 16px;
	margin-bottom: 16px;
}

.metric-card {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 20px;
	text-align: center;
}

.metric-label {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	margin-bottom: 8px;
}

.metric-value {
	font-size: 24px;
	font-weight: 600;
	color: var(--color-main-text);

	&.status-idle {
		color: var(--color-success);
	}

	&.status-syncing {
		color: var(--color-warning);
	}

	&.status-error {
		color: var(--color-error);
	}
}

.settings-form {
	display: flex;
	flex-direction: column;
	gap: 4px;
	max-width: 480px;
}

.form-field {
	margin-bottom: 12px;

	label {
		display: block;
		font-weight: 600;
		margin-bottom: 8px;
		color: var(--color-main-text);
	}
}

.score-slider {
	width: 100%;
	accent-color: var(--color-primary-element);
}

.form-actions {
	display: flex;
	align-items: center;
	gap: 16px;
	margin-top: 12px;
}

.doc-links {
	list-style: none;
	padding: 0;
	margin: 0;

	li {
		margin-bottom: 8px;
	}

	a {
		color: var(--color-primary-element);
		text-decoration: none;

		&:hover {
			text-decoration: underline;
		}
	}
}

// Webhook management styles
.loading-indicator {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 12px;
	padding: 32px;
	color: var(--color-text-maxcontrast);
}

.webhook-presets-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
	gap: 16px;
	margin-bottom: 24px;
}

.webhook-preset-card {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px;
	transition: border-color 0.2s ease;

	&:hover {
		border-color: var(--color-primary-element);
	}

	.preset-header {
		display: flex;
		justify-content: space-between;
		align-items: center;
		margin-bottom: 12px;

		h4 {
			margin: 0;
			font-size: 16px;
			font-weight: 600;
		}
	}

	.preset-status {
		display: inline-block;
		padding: 4px 10px;
		border-radius: var(--border-radius-pill, 100px);
		font-size: 12px;
		font-weight: 600;

		&.preset-status-enabled {
			background: var(--color-success);
			color: var(--color-primary-element-text, #fff);
		}

		&.preset-status-disabled {
			background: var(--color-background-dark);
			color: var(--color-text-maxcontrast);
		}
	}

	.preset-description {
		color: var(--color-text-maxcontrast);
		font-size: 14px;
		margin: 0 0 12px 0;
		line-height: 1.5;
	}

	.preset-meta {
		display: flex;
		gap: 16px;
		font-size: 13px;
		color: var(--color-text-maxcontrast);
		margin-bottom: 12px;

		.preset-app {
			font-weight: 500;
		}
	}

	.preset-actions {
		display: flex;
		justify-content: flex-end;
	}
}

.webhook-info,
.webhook-requirements {
	margin-top: 16px;

	ul {
		margin: 8px 0 0 0;
		padding-left: 20px;

		li {
			margin: 4px 0;
		}
	}
}
</style>
