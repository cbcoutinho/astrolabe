// Reference widget: renders the MCP App document viewer inline wherever a
// …/apps/astrolabe/?app_file_id=N link is shown as a rich reference (POC).
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { registerWidget } from '@nextcloud/vue/functions/reference'
import { createApp, reactive } from 'vue'
import McpAppHost from './components/McpAppHost.vue'

const apps = new WeakMap()
const hosts = new Set()

// A widget is superseded when a later message links the same file: the newest
// viewer for a file stays live, older ones collapse (POC). Uses the message
// links, so it holds while lazily-rendered widgets are unmounted.
function isSuperseded(el, fileId) {
	return [...document.querySelectorAll('a[href*="app_file_id="]')].some((a) => {
		try {
			return new URL(a.href).searchParams.get('app_file_id') === String(fileId)
				&& Boolean(el.compareDocumentPosition(a) & Node.DOCUMENT_POSITION_FOLLOWING)
		} catch {
			return false
		}
	})
}

function refresh() {
	for (const h of hosts) {
		h.state.superseded = isSuperseded(h.el, h.fileId)
	}
}

registerWidget('astrolabe_mcp_app', (el, { richObject }) => {
	const state = reactive({ superseded: false })
	const app = createApp(McpAppHost, {
		state,
		fileId: richObject.file_id,
		chunkIndex: richObject.chunk_index ?? null,
		proposal: richObject.proposal ?? null,
		inline: true,
	})
	app.config.globalProperties.t = t
	app.config.globalProperties.n = n
	app.mount(el)
	apps.set(el, { app, host: { el, fileId: richObject.file_id, state } })
	hosts.add(apps.get(el).host)
	refresh()
}, (el) => {
	const entry = apps.get(el)
	entry?.app.unmount()
	hosts.delete(entry?.host)
	apps.delete(el)
}, { hasInteractiveView: true, fullWidth: true })
