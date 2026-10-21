<template>
	<NcSettingsSection
		:name="t('astrolabe', 'MCP server connection')"
		:description="t('astrolabe', 'Astrolabe authenticates to the MCP server on behalf of the current session user. With the oidc app (Nextcloud is the identity provider) it mints a short-lived JWT for the OIDC client below. With the user_oidc app (sign-in through an external identity provider such as Keycloak) it uses the user’s own token from that provider.')"
		docUrl="https://docs.astrolabecloud.com/getting-started/setup">
		<form class="connection-settings" @submit.prevent="save">
			<!-- type="text", never "url": a url input strips leading/trailing
				whitespace from .value (HTML value sanitization), which would hide
				exactly the stray space this form exists to flag. -->
			<NcTextField
				v-for="field in textFields"
				:key="field.key"
				v-model="form[field.key]"
				:label="field.label"
				:placeholder="field.placeholder"
				type="text"
				:inputmode="field.url ? 'url' : undefined"
				:error="errors[field.key] !== ''"
				:aria-invalid="errors[field.key] !== '' ? 'true' : undefined"
				:helperText="errors[field.key] || field.help"
				autocomplete="off"
				spellcheck="false"
				@update:modelValue="serverErrors[field.key] = ''" />

			<NcPasswordField
				v-model="form.webhookSecret"
				:label="t('astrolabe', 'Webhook shared secret')"
				:placeholder="secretConfigured ? t('astrolabe', 'Configured — leave blank to keep') : t('astrolabe', 'Not configured')"
				:error="errors.webhookSecret !== ''"
				:aria-invalid="errors.webhookSecret !== '' ? 'true' : undefined"
				:helperText="errors.webhookSecret || t('astrolabe', 'Must match the MCP server’s WEBHOOK_SECRET. Sent as a bearer token, so use an https:// server URL. The stored value is never displayed.')"
				autocomplete="new-password"
				@update:modelValue="serverErrors.webhookSecret = ''" />

			<NcButton
				type="submit"
				variant="primary"
				:disabled="saving || hasErrors || !dirty">
				{{ saving ? t('astrolabe', 'Saving…') : t('astrolabe', 'Save') }}
			</NcButton>
		</form>
	</NcSettingsSection>
</template>

<script setup>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcPasswordField, NcSettingsSection, NcTextField } from '@nextcloud/vue'
import { computed, reactive, ref } from 'vue'
import { connectionFieldError } from './connectionValidation.js'

const props = defineProps({
	// { serverUrl, publicUrl, clientId, webhookSecretConfigured } from the
	// admin-config initial state. The secret itself is never provided.
	config: {
		type: Object,
		required: true,
	},
})

const emit = defineEmits(['saved'])

// Server response keys → form keys, for mapping a 422's per-field errors.
const CONFIG_KEYS = {
	mcp_server_url: 'serverUrl',
	mcp_server_public_url: 'publicUrl',
	astrolabe_client_id: 'clientId',
	mcp_webhook_secret: 'webhookSecret',
}

const textFields = [
	{
		key: 'serverUrl',
		url: true,
		label: t('astrolabe', 'MCP Server URL (internal)'),
		placeholder: 'https://<your-slug>.tenants.astrolabecloud.com',
		help: t('astrolabe', 'Where Astrolabe reaches the MCP server — your tenant URI for Astrolabe Cloud. Use https:// when background indexing is enabled: user app passwords are sent to this URL.'),
	},
	{
		key: 'publicUrl',
		url: true,
		label: t('astrolabe', 'MCP Server URL (public)'),
		placeholder: 'https://mcp.example.com',
		help: t('astrolabe', 'The audience (aud) of minted access tokens. Leave empty to use the internal URL.'),
	},
	{
		key: 'clientId',
		url: false,
		label: t('astrolabe', 'OIDC client identifier'),
		placeholder: t('astrolabe', 'Enter OIDC client ID'),
		help: t('astrolabe', 'With the oidc app: the public client Astrolabe mints tokens for. With user_oidc: the client at your identity provider that the MCP server accepts tokens for.'),
	},
]

const saved = ref({
	serverUrl: props.config.serverUrl ?? '',
	publicUrl: props.config.publicUrl ?? '',
	clientId: props.config.clientId ?? '',
})
const secretConfigured = ref(props.config.webhookSecretConfigured ?? false)
const form = reactive({ ...saved.value, webhookSecret: '' })
const serverErrors = reactive({ serverUrl: '', publicUrl: '', clientId: '', webhookSecret: '' })
const saving = ref(false)

// A stored value that is already invalid (saved before this validation existed)
// shows its error as soon as the page loads, which is the point: it explains a
// connection failure the status section below can only report.
const errors = computed(() => ({
	serverUrl: connectionFieldError(form.serverUrl, { url: true }) || serverErrors.serverUrl,
	publicUrl: connectionFieldError(form.publicUrl, { url: true }) || serverErrors.publicUrl,
	clientId: connectionFieldError(form.clientId) || serverErrors.clientId,
	webhookSecret: connectionFieldError(form.webhookSecret) || serverErrors.webhookSecret,
}))
const hasErrors = computed(() => Object.values(errors.value).some((e) => e !== ''))
const dirty = computed(() => form.webhookSecret !== ''
	|| textFields.some((f) => form[f.key] !== saved.value[f.key]))

async function save() {
	if (hasErrors.value) {
		return
	}
	saving.value = true
	try {
		const { data } = await axios.post(
			generateUrl('/apps/astrolabe/api/admin/connection-settings'),
			{ ...form },
		)
		saved.value = {
			serverUrl: data.config.serverUrl,
			publicUrl: data.config.publicUrl,
			clientId: data.config.clientId,
		}
		secretConfigured.value = data.config.webhookSecretConfigured
		form.webhookSecret = ''
		showSuccess(t('astrolabe', 'Connection settings saved'))
		emit('saved', data.config)
	} catch (err) {
		const fieldErrors = err.response?.status === 422 ? err.response.data?.errors : null
		if (fieldErrors) {
			for (const [configKey, message] of Object.entries(fieldErrors)) {
				serverErrors[CONFIG_KEYS[configKey] ?? configKey] = message
			}
		} else {
			console.error('Failed to save connection settings:', err)
			showError(t('astrolabe', 'Failed to save connection settings'))
		}
	} finally {
		saving.value = false
	}
}
</script>

<style scoped lang="scss">
.connection-settings {
	display: flex;
	flex-direction: column;
	gap: 16px;
	max-width: 600px;

	button {
		align-self: flex-start;
	}
}
</style>
