/**
 * Admin settings page Vue app for Astrolabe.
 *
 * Mounts the AdminSettings Vue component for async loading
 * and improved UX.
 */

import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { createApp } from 'vue'
import AdminSettings from './components/admin/AdminSettings.vue'

const app = createApp(AdminSettings)

// Add translation methods globally
app.config.globalProperties.t = t
app.config.globalProperties.n = n

app.mount('#astrolabe-admin-settings')
