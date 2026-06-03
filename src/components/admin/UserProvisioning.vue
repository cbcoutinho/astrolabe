<template>
	<div class="user-provisioning">
		<p class="section-description">
			{{ t('astrolabe', 'Manage the Nextcloud app passwords the MCP server uses to index each user\'s files in the background. You can provision access on a user\'s behalf, or turn off self-service so provisioning is admin-only.') }}
		</p>

		<div class="self-provision-toggle">
			<NcCheckboxRadioSwitch
				:model-value="allowSelfProvision"
				:disabled="togglingSelfProvision"
				type="switch"
				@update:model-value="onToggleSelfProvision">
				{{ t('astrolabe', 'Allow users to self-provision background indexing') }}
			</NcCheckboxRadioSwitch>
			<p class="help-text">
				{{ t('astrolabe', 'When disabled, the per-user "Enable background indexing" button is hidden and only admins can provision. Existing user-provisioned passwords keep working until deprovisioned here.') }}
			</p>
		</div>

		<div v-if="loading" class="loading-indicator">
			<NcLoadingIcon :size="32" />
			<p>{{ t('astrolabe', 'Loading users...') }}</p>
		</div>

		<NcNoteCard v-else-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template v-else>
			<NcNoteCard v-if="capped" type="warning">
				<p>{{ t('astrolabe', 'Showing the first {count} users only; not all users are listed.', { count: users.length }) }}</p>
			</NcNoteCard>

			<table class="provisioning-table">
				<thead>
					<tr>
						<th>{{ t('astrolabe', 'User') }}</th>
						<th>{{ t('astrolabe', 'Status') }}</th>
						<th>{{ t('astrolabe', 'Provisioned at') }}</th>
						<th class="actions-col">{{ t('astrolabe', 'Actions') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="user in users" :key="user.uid">
						<td>
							<span class="user-name">{{ user.display_name || user.uid }}</span>
							<span v-if="user.display_name && user.display_name !== user.uid" class="user-uid">{{ user.uid }}</span>
						</td>
						<td>
							<span
								class="status-badge"
								:class="user.has_background_access ? 'status-enabled' : 'status-disabled'">
								{{ user.has_background_access ? t('astrolabe', 'Provisioned') : t('astrolabe', 'Not provisioned') }}
							</span>
						</td>
						<td>{{ user.provisioned_at ? formatDate(user.provisioned_at) : '—' }}</td>
						<td class="actions-col">
							<NcButton
								v-if="user.has_background_access"
								variant="secondary"
								:disabled="user.busy"
								@click="deprovision(user)">
								{{ user.busy ? t('astrolabe', 'Please wait...') : t('astrolabe', 'Deprovision') }}
							</NcButton>
							<NcButton
								v-else
								variant="primary"
								:disabled="user.busy"
								@click="provision(user)">
								{{ user.busy ? t('astrolabe', 'Please wait...') : t('astrolabe', 'Provision') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>

			<NcButton variant="tertiary" class="refresh-button" @click="loadUsers">
				<template #icon>
					<Refresh :size="20" />
				</template>
				{{ t('astrolabe', 'Refresh') }}
			</NcButton>
		</template>
	</div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { showError, showSuccess, showWarning } from '@nextcloud/dialogs'

import {
	NcLoadingIcon,
	NcNoteCard,
	NcButton,
	NcCheckboxRadioSwitch,
} from '@nextcloud/vue'

import Refresh from 'vue-material-design-icons/Refresh.vue'

const props = defineProps({
	initialAllowSelfProvision: {
		type: Boolean,
		default: true,
	},
})

const loading = ref(true)
const error = ref(null)
const users = ref([])
const capped = ref(false)
const allowSelfProvision = ref(props.initialAllowSelfProvision)
const togglingSelfProvision = ref(false)

const baseUrl = '/apps/astrolabe/api/v1/background-sync/admin'

async function loadUsers() {
	loading.value = true
	error.value = null

	try {
		const response = await axios.get(generateUrl(`${baseUrl}/users`))
		if (response.data.success) {
			users.value = (response.data.users || []).map(u => ({ ...u, busy: false }))
			capped.value = response.data.capped ?? false
			allowSelfProvision.value = response.data.self_provision_allowed ?? allowSelfProvision.value
		} else {
			error.value = response.data.error || t('astrolabe', 'Failed to load users')
		}
	} catch (err) {
		console.error('Failed to load provisioning list:', err)
		error.value = err.response?.data?.error || err.message || t('astrolabe', 'Network error')
	} finally {
		loading.value = false
	}
}

async function provision(user) {
	user.busy = true
	try {
		const response = await axios.post(generateUrl(`${baseUrl}/users/${encodeURIComponent(user.uid)}`))
		if (response.data.success) {
			if (response.data.mcp_sync === false) {
				// Local credential stored but the MCP server didn't accept it
				// (e.g. unreachable, or an OIDC user whose login name differs
				// from the UID). Surface it without treating it as a failure.
				showWarning(response.data.message || t('astrolabe', 'Provisioned locally, but the MCP server did not confirm sync.'))
			} else {
				showSuccess(t('astrolabe', 'Provisioned {user}', { user: user.display_name || user.uid }))
			}
			await loadUsers()
		} else {
			showError(response.data.error || t('astrolabe', 'Failed to provision user'))
		}
	} catch (err) {
		console.error('Failed to provision user:', err)
		showError(err.response?.data?.error || err.message || t('astrolabe', 'Network error'))
	} finally {
		user.busy = false
	}
}

async function deprovision(user) {
	// Confirm this destructive action — it revokes the user's app password and
	// the MCP server loses access to index their files.
	const name = user.display_name || user.uid
	if (!confirm(t('astrolabe', 'Deprovision background indexing for {user}? Their app password will be revoked.', { user: name }))) {
		return
	}
	user.busy = true
	try {
		const response = await axios.delete(generateUrl(`${baseUrl}/users/${encodeURIComponent(user.uid)}`))
		if (response.data.success) {
			showSuccess(t('astrolabe', 'Deprovisioned {user}', { user: user.display_name || user.uid }))
			await loadUsers()
		} else {
			showError(response.data.error || t('astrolabe', 'Failed to deprovision user'))
		}
	} catch (err) {
		console.error('Failed to deprovision user:', err)
		showError(err.response?.data?.error || err.message || t('astrolabe', 'Network error'))
	} finally {
		user.busy = false
	}
}

async function onToggleSelfProvision(value) {
	togglingSelfProvision.value = true
	try {
		const response = await axios.post(
			generateUrl(`${baseUrl}/self-provision`),
			{ enabled: value },
			{ headers: { 'Content-Type': 'application/json' } },
		)
		if (response.data.success) {
			allowSelfProvision.value = response.data.self_provision_allowed
			showSuccess(value
				? t('astrolabe', 'User self-provisioning enabled')
				: t('astrolabe', 'User self-provisioning disabled'))
		} else {
			showError(response.data.error || t('astrolabe', 'Failed to update setting'))
		}
	} catch (err) {
		console.error('Failed to update self-provision setting:', err)
		showError(err.response?.data?.error || err.message || t('astrolabe', 'Network error'))
	} finally {
		togglingSelfProvision.value = false
	}
}

function formatDate(timestamp) {
	return new Date(timestamp * 1000).toLocaleString()
}

onMounted(loadUsers)
</script>

<style scoped lang="scss">
.user-provisioning {
	.section-description {
		color: var(--color-text-maxcontrast);
		margin-bottom: 16px;
	}

	.help-text {
		color: var(--color-text-maxcontrast);
		font-size: 13px;
		margin-top: 8px;
	}

	.self-provision-toggle {
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
		padding: 16px 20px;
		margin-bottom: 24px;
	}

	.loading-indicator {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 12px;
		padding: 32px;
		color: var(--color-text-maxcontrast);
	}
}

.provisioning-table {
	width: 100%;
	border-collapse: collapse;

	th,
	td {
		text-align: left;
		padding: 10px 12px;
		border-bottom: 1px solid var(--color-border);
		vertical-align: middle;
	}

	th {
		font-weight: 600;
		color: var(--color-text-maxcontrast);
		font-size: 13px;
	}

	.actions-col {
		text-align: right;
		white-space: nowrap;
	}

	.user-name {
		display: block;
		font-weight: 500;
	}

	.user-uid {
		display: block;
		font-size: 12px;
		color: var(--color-text-maxcontrast);
	}
}

.status-badge {
	display: inline-block;
	padding: 4px 10px;
	border-radius: 12px;
	font-size: 13px;
	font-weight: 600;

	&.status-enabled {
		background: var(--color-success);
		color: white;
	}

	&.status-disabled {
		background: var(--color-background-dark);
		color: var(--color-text-maxcontrast);
	}
}

.refresh-button {
	margin-top: 16px;
}
</style>
