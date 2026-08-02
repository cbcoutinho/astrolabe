<template>
	<div class="astrolabe-admin">
		<NcSettingsSection v-if="loading" :name="t('astrolabe', 'Astrolabe')">
			<NcLoadingIcon :size="44" class="loading-icon" />
		</NcSettingsSection>

		<NcSettingsSection v-else-if="error" :name="t('astrolabe', 'Astrolabe')">
			<NcNoteCard type="error">
				<p><strong>{{ t('astrolabe', 'Cannot connect to MCP server') }}</strong></p>
				<p>{{ error }}</p>
				<p class="help-text">
					{{ t('astrolabe', 'Ensure MCP server is running and accessible. Check config.php for correct mcp_server_url.') }}
				</p>
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
						<div class="metric-label">
							{{ t('astrolabe', 'Status') }}
						</div>
						<div class="metric-value" :class="`status-${vectorSyncStatus.status}`">
							{{ vectorSyncStatus.status }}
						</div>
					</div>
					<div class="metric-card">
						<div class="metric-label">
							{{ t('astrolabe', 'Indexed Documents') }}
						</div>
						<div class="metric-value">
							{{ formatNumber(vectorSyncStatus.indexed_documents) }}
						</div>
					</div>
					<div class="metric-card">
						<div class="metric-label">
							{{ t('astrolabe', 'Indexed Chunks') }}
						</div>
						<div class="metric-value">
							{{ formatNumber(vectorSyncStatus.indexed_chunks) }}
						</div>
					</div>
					<div class="metric-card">
						<div class="metric-label">
							{{ t('astrolabe', 'Pending Documents') }}
						</div>
						<div class="metric-value">
							{{ formatNumber(vectorSyncStatus.pending_documents) }}
						</div>
					</div>
					<div class="metric-card">
						<div class="metric-label">
							{{ t('astrolabe', 'Processing Rate') }}
						</div>
						<div class="metric-value">
							{{ formatNumber(vectorSyncStatus.documents_per_second, 1) }} docs/sec
						</div>
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
				:name="t('astrolabe', 'Sync management')"
				:description="t('astrolabe', 'Configure real-time synchronization for Nextcloud apps. Astrolabe listens for changes in Nextcloud and delivers them directly to the MCP server, so its vector index stays up to date instantly.')">
				<NcNoteCard v-if="!nativeSyncEnabled" type="warning">
					<p><strong>{{ t('astrolabe', 'Native sync is turned off') }}</strong></p>
					<p>{{ t('astrolabe', 'Change events are not being delivered. The MCP server falls back to periodic polling until native sync is re-enabled.') }}</p>
				</NcNoteCard>

				<NcNoteCard v-else-if="!webhookSecretConfigured" type="warning">
					<p><strong>{{ t('astrolabe', 'Webhook secret not configured') }}</strong></p>
					<p>{{ t('astrolabe', 'Set the “Webhook shared secret” in the MCP Server Configuration above to match the MCP server’s WEBHOOK_SECRET. Until then, change events cannot be delivered and the MCP server relies on periodic polling.') }}</p>
				</NcNoteCard>

				<div v-if="webhooksLoading" class="loading-indicator">
					<NcLoadingIcon :size="32" />
					<p>{{ t('astrolabe', 'Loading sync presets…') }}</p>
				</div>

				<NcNoteCard v-else-if="webhooksError" type="error">
					<p><strong>{{ t('astrolabe', 'Failed to load sync presets') }}</strong></p>
					<p>{{ webhooksError }}</p>
				</NcNoteCard>

				<template v-else>
					<div v-if="webhookPresets.length === 0" class="empty-state">
						<NcNoteCard type="info">
							<p>{{ t('astrolabe', 'No sync presets available. Install supported apps (Notes, Calendar, Tables, Forms) to enable synchronization.') }}</p>
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
							<p class="preset-description">
								{{ preset.description }}
							</p>
							<div class="preset-meta">
								<span class="preset-app">{{ t('astrolabe', 'App') }}: {{ preset.app }}</span>
								<span class="preset-events">{{ preset.events.length }} {{ t('astrolabe', 'events') }}</span>
							</div>
							<div class="preset-actions">
								<NcButton
									:variant="preset.enabled ? 'secondary' : 'primary'"
									:disabled="preset.toggling"
									@click="toggleWebhookPreset(preset)">
									{{ preset.toggling ? t('astrolabe', 'Please wait…') : (preset.enabled ? t('astrolabe', 'Disable') : t('astrolabe', 'Enable')) }}
								</NcButton>
							</div>
						</div>
					</div>

					<NcNoteCard type="info" class="webhook-info">
						<p><strong>{{ t('astrolabe', 'How sync works') }}</strong></p>
						<ul>
							<li>{{ t('astrolabe', 'Enable a preset to start delivering that app’s change events to the MCP server') }}</li>
							<li>{{ t('astrolabe', 'When content changes in Nextcloud, Astrolabe delivers the change to the MCP server instantly') }}</li>
							<li>{{ t('astrolabe', 'The MCP server updates its vector index in real-time for semantic search') }}</li>
							<li>{{ t('astrolabe', 'Disable a preset to stop delivering updates for that app') }}</li>
						</ul>
					</NcNoteCard>

					<NcNoteCard type="warning" class="webhook-requirements">
						<p><strong>{{ t('astrolabe', 'Requirements') }}</strong></p>
						<ul>
							<li>{{ t('astrolabe', 'The MCP server must be reachable from your Nextcloud instance') }}</li>
							<li>{{ t('astrolabe', 'The webhook shared secret above must match the MCP server’s WEBHOOK_SECRET') }}</li>
							<li>{{ t('astrolabe', 'Each user must have background indexing provisioned so the MCP server can index their content (see Personal Settings)') }}</li>
						</ul>
					</NcNoteCard>
				</template>
			</NcSettingsSection>

			<!-- Searchable sources (admin consent) -->
			<NcSettingsSection
				v-if="vectorSyncEnabled"
				:name="t('astrolabe', 'Searchable sources')"
				:description="t('astrolabe', 'Choose which installed apps may be indexed and searched semantically. Only apps installed in Nextcloud are listed. Disabling a source deletes its already-indexed content and stops further indexing — re-enabling requires a full re-index.')">
				<div v-if="searchSources.length === 0" class="empty-state">
					<NcNoteCard type="info">
						<p>{{ t('astrolabe', 'No supported apps are installed.') }}</p>
					</NcNoteCard>
				</div>
				<div v-else class="settings-form">
					<div v-for="source in searchSources" :key="source.app" class="source-row">
						<NcCheckboxRadioSwitch
							:modelValue="source.enabled"
							type="switch"
							:disabled="savingSources"
							@update:modelValue="onToggleSource(source, $event)">
							{{ sourceLabel(source) }}
						</NcCheckboxRadioSwitch>
					</div>
					<NcNoteCard type="warning" class="sources-info">
						<p>{{ t('astrolabe', 'A source must be both installed and enabled to be searchable. Disabling a source removes its indexed data; this cannot be undone without re-indexing.') }}</p>
					</NcNoteCard>
				</div>
			</NcSettingsSection>

			<!-- AI search provider settings -->
			<NcSettingsSection
				v-if="vectorSyncEnabled"
				:name="t('astrolabe', 'AI search provider settings')"
				:description="t('astrolabe', 'Configure the default search parameters for the AI Search provider in Nextcloud unified search.')">
				<div class="settings-form">
					<NcCheckboxRadioSwitch
						:modelValue="settings.showVisualization"
						type="switch"
						class="form-field"
						@update:modelValue="settings.showVisualization = $event">
						{{ t('astrolabe', 'Show vector space visualization') }}
					</NcCheckboxRadioSwitch>
					<p class="help-text">
						{{ t('astrolabe', 'Display the interactive 3D vector plot of search results on the Astrolabe app page. When disabled, the plot is hidden and its computation is skipped.') }}
					</p>

					<NcSelect
						:modelValue="selectedAlgorithmOption"
						:options="algorithmOptions"
						:inputLabel="t('astrolabe', 'Search Algorithm')"
						:clearable="false"
						class="form-field"
						@update:modelValue="settings.algorithm = $event ? $event.id : (algorithmOptions[0] ? algorithmOptions[0].id : 'hybrid')" />
					<p class="help-text">
						{{ t('astrolabe', 'Hybrid combines semantic understanding with keyword matching. Semantic finds conceptually similar content. BM25 matches exact keywords.') }}
					</p>

					<NcSelect
						:modelValue="selectedFusionOption"
						:options="fusionOptions"
						:inputLabel="t('astrolabe', 'Fusion Method')"
						class="form-field"
						@update:modelValue="settings.fusion = $event ? $event.id : 'rrf'" />
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
							class="score-slider"
							:disabled="settings.algorithm !== 'semantic'">
						<p class="help-text">
							{{ t('astrolabe', 'Filter out results below this relevance score. Set to 0 to show all results.') }}
						</p>
						<!--
							Only dense-only search scores on a scale a percentage can address
							(cosine similarity, genuinely 0-1). Keyword and hybrid return a
							fused rank artifact whose whole range is a few percent, so a
							non-zero value there would return NOTHING for every query. The
							provider suppresses it for those algorithms, and this says so
							rather than leaving the control looking effective.
						-->
						<p v-if="settings.algorithm !== 'semantic'" class="help-text">
							{{ t('astrolabe', 'Not applicable to the selected search algorithm — this threshold applies to Semantic search only, and is ignored for Keyword and Hybrid. Use the relevance slider on the search page instead.') }}
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
							{{ saving ? t('astrolabe', 'Saving…') : t('astrolabe', 'Save Settings') }}
						</NcButton>
					</div>
				</div>
			</NcSettingsSection>

			<!-- User provisioning -->
			<NcSettingsSection :name="t('astrolabe', 'User provisioning')">
				<UserProvisioning :initialAllowSelfProvision="allowUserSelfProvision" />
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

		<NcDialog
			v-if="confirmDialogOpen"
			:open="confirmDialogOpen"
			:name="t('astrolabe', 'Disable source for semantic search?')"
			:message="confirmMessage"
			:buttons="confirmButtons"
			@update:open="onConfirmDialogToggle" />
	</div>
</template>

<script setup>
import axios from '@nextcloud/axios'
import { showError, showSuccess, showWarning } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
	NcSettingsSection,
	NcTextField,
} from '@nextcloud/vue'
import { computed, onMounted, ref } from 'vue'
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
const webhookPresets = ref([])

// Load initial state from PHP
const initialData = loadState('astrolabe', 'admin-config', {})
const settings = ref(initialData.searchSettings || {
	algorithm: 'hybrid',
	fusion: 'rrf',
	scoreThreshold: 0,
	limit: 20,
	showVisualization: true,
})
const allowUserSelfProvision = ref(initialData.allowUserSelfProvision ?? true)

// Native sync state. Astrolabe now delivers change events to the MCP server
// itself (no webhook_listeners app), authenticated with a shared secret — the
// UI warns when that secret is missing or the master switch is off.
const nativeSyncEnabled = ref(initialData.nativeSyncEnabled ?? true)
const webhookSecretConfigured = ref(initialData.webhookSecretConfigured ?? false)

// Searchable-sources (admin consent) state. Only installed sources are
// provided by the backend; each carries its current enabled state.
const searchSources = ref(initialData.searchSources || [])
const savingSources = ref(false)
const confirmDialogOpen = ref(false)
const pendingSource = ref(null)

const confirmMessage = computed(() => pendingSource.value
	? t('astrolabe', 'Disabling "{source}" deletes its already-indexed documents and stops further indexing. Re-enabling later requires a full re-index. Continue?', { source: sourceLabel(pendingSource.value) })
	: '')

const confirmButtons = computed(() => [
	{
		label: t('astrolabe', 'Cancel'),
		callback: cancelDisable,
	},
	{
		label: t('astrolabe', 'Disable and delete indexed data'),
		variant: 'error',
		callback: confirmDisable,
	},
])

// Computed properties
const algorithmOptions = computed(() => {
	const all = [
		{ id: 'hybrid', label: t('astrolabe', 'Hybrid (Recommended)') },
		{ id: 'semantic', label: t('astrolabe', 'Semantic Only') },
		{ id: 'bm25', label: t('astrolabe', 'Keyword (BM25) Only') },
	]
	// Only offer query types the MCP server advertises. An explicit `[]` (vector
	// sync off) hides all three; otherwise offer the advertised set (all three
	// when sync is on). Not an array (status not loaded yet or older backend) ⇒
	// show all; the backend rejects an unsupported save (422).
	const supported = serverStatus.value?.supported_search_types
	if (!Array.isArray(supported)) {
		return all
	}
	return all.filter((opt) => supported.includes(opt.id))
})

const fusionOptions = computed(() => [
	{ id: 'rrf', label: t('astrolabe', 'RRF - Reciprocal Rank Fusion (Recommended)') },
	{ id: 'dbsf', label: t('astrolabe', 'DBSF - Distribution-Based Score Fusion') },
])

// Computed properties for NcSelect (converts between stored ID and option object)
const selectedAlgorithmOption = computed(() => algorithmOptions.value.find((opt) => opt.id === settings.value.algorithm) || algorithmOptions.value[0])

const selectedFusionOption = computed(() => fusionOptions.value.find((opt) => opt.id === settings.value.fusion) || fusionOptions.value[0])

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

			// Keep the selected algorithm in step with what the server can serve:
			// if the stored algorithm is no longer advertised (e.g. vector sync was
			// turned off), fall back to a supported type so Save can't submit — and
			// 422 on — an unsupported value.
			const supported = statusResponse.data.status?.supported_search_types
			if (Array.isArray(supported) && supported.length > 0 && !supported.includes(settings.value.algorithm)) {
				settings.value.algorithm = supported.includes('hybrid') ? 'hybrid' : supported[0]
			}
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

// Static label map so the l10n extraction toolchain can pick up each string
// (a dynamic t(source.label) wouldn't be statically analyzable). Keep keys in
// sync with SearchSources::CATALOG; falls back to the server-provided label.
const SOURCE_LABELS = {
	notes: t('astrolabe', 'Notes'),
	files: t('astrolabe', 'Files'),
	deck: t('astrolabe', 'Deck'),
	news: t('astrolabe', 'News'),
	mail: t('astrolabe', 'Mail'),
	calendar: t('astrolabe', 'Calendar'),
	contacts: t('astrolabe', 'Contacts'),
}

function sourceLabel(source) {
	return SOURCE_LABELS[source.app] ?? source.label
}

// Toggling a source. Enabling is non-destructive and applies immediately;
// disabling deletes indexed data, so it routes through a confirmation dialog.
function onToggleSource(source, value) {
	if (value) {
		// Snapshot BEFORE the optimistic mutation so a failed save can revert.
		const snapshot = searchSources.value.map((s) => ({ ...s }))
		source.enabled = true
		persistSources(snapshot)
	} else {
		pendingSource.value = source
		confirmDialogOpen.value = true
	}
}

function cancelDisable() {
	// The switch is bound to source.enabled, which we never changed, so it
	// snaps back to "on" on its own. Just clear the pending state.
	confirmDialogOpen.value = false
	pendingSource.value = null
}

// Dialog dismissed (Escape / outside-click / button) — keep confirmDialogOpen
// in sync and clear the pending source so a stale label can't linger.
function onConfirmDialogToggle(open) {
	confirmDialogOpen.value = open
	if (!open) {
		pendingSource.value = null
	}
}

function confirmDisable() {
	// Capture the source before clearing state so we never depend on the
	// dialog's close/callback event ordering.
	const src = pendingSource.value
	// Snapshot BEFORE the optimistic mutation so a failed save can revert.
	const snapshot = searchSources.value.map((s) => ({ ...s }))
	confirmDialogOpen.value = false
	pendingSource.value = null
	if (src) {
		src.enabled = false
		persistSources(snapshot)
	}
}

// Persist the full enabled/disabled state. The backend receives the disabled
// source ids and purges any newly-disabled source's indexed data. `snapshot` is
// the pre-toggle state, restored if the save fails.
async function persistSources(snapshot) {
	savingSources.value = true
	const disabledSources = searchSources.value
		.filter((s) => !s.enabled)
		.map((s) => s.app)

	try {
		const response = await axios.post(
			generateUrl('/apps/astrolabe/api/admin/search-sources'),
			{ disabledSources },
			{ headers: { 'Content-Type': 'application/json' } },
		)

		if (response.data.success) {
			// Re-sync from the authoritative server state.
			searchSources.value = response.data.searchSources || searchSources.value
			// The backend persists before purging, so the setting is always saved
			// on success — confirm that first, then surface any purge warning so
			// the admin doesn't think the save itself failed.
			showSuccess(t('astrolabe', 'Searchable sources updated'))
			const purge = response.data.purge
			if (purge?.warning) {
				showWarning(purge.warning)
			}
		} else {
			searchSources.value = snapshot
			showError(response.data.error || t('astrolabe', 'Failed to update searchable sources'))
		}
	} catch (err) {
		searchSources.value = snapshot
		console.error('Failed to update searchable sources:', err)
		showError(err.response?.data?.error || err.message || t('astrolabe', 'Network error'))
	} finally {
		savingSources.value = false
	}
}

async function loadWebhookPresets() {
	webhooksLoading.value = true
	webhooksError.value = null

	try {
		const response = await axios.get(generateUrl('/apps/astrolabe/api/admin/webhooks/presets'))

		if (response.data.success) {
			// Convert presets object to array with IDs
			const presetsObj = response.data.presets
			webhookPresets.value = Object.keys(presetsObj).map((id) => ({
				id,
				...presetsObj[id],
				toggling: false,
			}))
		} else {
			webhooksError.value = response.data.error || t('astrolabe', 'Failed to load webhook presets')
		}
	} catch (err) {
		console.error('Failed to load webhook presets:', err)
		webhooksError.value = err.response?.data?.error || err.message || t('astrolabe', 'Network error')
	} finally {
		webhooksLoading.value = false
	}
}

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
			showSuccess(response.data.message || (preset.enabled ? t('astrolabe', 'Sync preset enabled') : t('astrolabe', 'Sync preset disabled')))
		} else {
			showError(response.data.error || t('astrolabe', 'Failed to toggle sync preset'))
		}
	} catch (err) {
		console.error('Failed to toggle sync preset:', err)
		showError(err.response?.data?.error || err.message || t('astrolabe', 'Network error'))
	} finally {
		preset.toggling = false
	}
}

function formatUptime(seconds) {
	const hours = Math.floor(seconds / 3600)
	const minutes = Math.floor((seconds % 3600) / 60)
	return t('astrolabe', '{hours} hours, {minutes} minutes', { hours, minutes })
}

function formatNumber(value, decimals = 0) {
	if (value === undefined || value === null) {
		return '0'
	}
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
		color: var(--color-success-text);
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
		color: var(--color-success-text);
	}

	&.status-syncing {
		color: var(--color-warning-text);
	}

	&.status-error {
		color: var(--color-error-text);
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
			color: var(--color-success-text);
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
		padding-inline-start: 20px;

		li {
			margin: 4px 0;
		}
	}
}
</style>
