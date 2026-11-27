<template>
	<div class="mcp-section">
		<div class="mcp-section-header">
			<h2>{{ t('astrolabe', 'Index Status') }}</h2>
			<p class="mcp-description">
				{{ t('astrolabe', 'View the status of your vector index and sync progress.') }}
			</p>
		</div>

		<div v-if="statusLoading" class="mcp-loading">
			<NcLoadingIcon :size="32" />
			<span>{{ t('astrolabe', 'Loading status…') }}</span>
		</div>

		<NcNoteCard v-else-if="statusError" type="error">
			{{ statusError }}
		</NcNoteCard>

		<div v-else-if="vectorStatus" class="mcp-status-cards">
			<div class="mcp-status-card">
				<div class="mcp-status-label">
					{{ t('astrolabe', 'Sync Status') }}
				</div>
				<div class="mcp-status-value" :class="'status-' + vectorStatus.status">
					{{ vectorStatus.status }}
				</div>
			</div>

			<div class="mcp-status-card">
				<div class="mcp-status-label">
					{{ t('astrolabe', 'Indexed Documents') }}
				</div>
				<div class="mcp-status-value">
					{{ (vectorStatus.indexed_documents || 0).toLocaleString() }}
				</div>
			</div>

			<div class="mcp-status-card">
				<div class="mcp-status-label">
					{{ t('astrolabe', 'Indexed Chunks') }}
				</div>
				<div class="mcp-status-value">
					{{ (vectorStatus.indexed_chunks || 0).toLocaleString() }}
				</div>
			</div>

			<div class="mcp-status-card">
				<div class="mcp-status-label">
					{{ t('astrolabe', 'Pending Documents') }}
				</div>
				<div class="mcp-status-value">
					{{ (vectorStatus.pending_documents || 0).toLocaleString() }}
				</div>
			</div>

			<div v-if="vectorStatus.last_sync_time" class="mcp-status-card">
				<div class="mcp-status-label">
					{{ t('astrolabe', 'Last Sync') }}
				</div>
				<div class="mcp-status-value">
					{{ vectorStatus.last_sync_time }}
				</div>
			</div>
		</div>

		<NcButton variant="secondary" :disabled="statusLoading" @click="load">
			<template #icon>
				<Refresh :size="20" />
			</template>
			{{ t('astrolabe', 'Refresh') }}
		</NcButton>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import Refresh from 'vue-material-design-icons/Refresh.vue'

/**
 * The "Index Status" section: the user's vector-sync state.
 *
 * Loaded on demand rather than on mount: the parent calls `load()` each time
 * the section is opened, so the numbers are fresh whenever they are shown.
 */
export default {
	name: 'IndexStatus',
	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		Refresh,
	},

	expose: ['load'],

	data() {
		return {
			vectorStatus: null,
			statusLoading: false,
			statusError: null,
		}
	},

	methods: {
		async load() {
			this.statusLoading = true
			this.statusError = null

			try {
				const url = generateUrl('/apps/astrolabe/api/vector-status')
				const response = await axios.get(url)

				if (response.data.success) {
					this.vectorStatus = response.data.status
				} else {
					this.statusError = response.data.error || this.t('astrolabe', 'Failed to load status')
				}
			} catch (err) {
				console.error('Status error:', err)
				if (err.response && err.response.data && err.response.data.error) {
					this.statusError = err.response.data.error
				} else {
					this.statusError = this.t('astrolabe', 'Network error. Please try again.')
				}
			} finally {
				this.statusLoading = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
@use '../styles/section.scss';

.mcp-status-cards {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
	gap: 16px;
	margin-bottom: 24px;
}

.mcp-status-card {
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large);
	padding: 20px;
	text-align: center;
}

.mcp-status-label {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	margin-bottom: 8px;
}

.mcp-status-value {
	font-size: 24px;
	font-weight: 600;
	color: var(--color-main-text);

	&.status-idle { color: var(--color-success-text); }
	&.status-syncing { color: var(--color-warning-text); }
	&.status-error { color: var(--color-error-text); }
}
</style>
