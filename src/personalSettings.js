/**
 * Personal settings entrypoint for Astrolabe.
 *
 * Mounts the Vue personal-settings page (background-indexing opt-in/out).
 * Provisioning mints a dedicated app password from the current Nextcloud
 * session via the core `getapppassword` OCS endpoint and hands it to the
 * MCP server — the user never copies or pastes a credential.
 */

import { createApp } from 'vue'
import PersonalSettings from './components/personal/PersonalSettings.vue'

const el = document.getElementById('astrolabe-personal-settings')
if (el) {
	createApp(PersonalSettings).mount(el)
}
