import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { createApp } from 'vue'
import App from './App.vue'

const app = createApp(App)

// Add translation methods globally
app.config.globalProperties.t = t
app.config.globalProperties.n = n

app.mount('#astrolabe')
