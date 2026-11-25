<template>
	<div :class="{ 'mcp-app-overlay': !inline }" @click.self="close" @keydown.esc="close">
		<div v-if="collapsed" class="mcp-app-superseded">
			<span>{{ t('astrolabe', 'Superseded by a later proposal') }}</span>
			<NcButton variant="tertiary" @click="showSuperseded = true">
				{{ t('astrolabe', 'Show') }}
			</NcButton>
		</div>
		<div v-show="!collapsed"
			ref="shell"
			class="mcp-app-shell"
			:class="{ fullscreen: displayMode === 'fullscreen', inline }">
			<div class="mcp-app-bar">
				<strong>{{ title }}</strong>
				<NcButton v-if="!inline || displayMode === 'fullscreen'" variant="tertiary" @click="close">
					{{ t('astrolabe', 'Close') }}
				</NcButton>
			</div>
			<div class="mcp-app-body">
				<iframe
					ref="frame"
					:src="viewUrl"
					:title="title"
					sandbox="allow-scripts"
					:style="{ height: frameHeight + 'px' }" />
				<aside v-if="answers.length || error" class="mcp-app-side">
					<div class="mcp-app-side-bar">
						<strong>{{ t('astrolabe', 'Assistant') }}</strong>
						<NcButton variant="tertiary" :aria-label="t('astrolabe', 'Hide the answers panel')" @click="closeSide">
							{{ t('astrolabe', 'Hide') }}
						</NcButton>
					</div>
					<NcNoteCard v-if="error" type="error">
						{{ error }}
					</NcNoteCard>
					<div v-for="(a, i) in answers" :key="i" class="mcp-app-answer">
						<p class="q" :title="a.question">
							{{ label(a.question) }}
						</p>
						<NcLoadingIcon v-if="a.pending" />
						<p v-else-if="a.failed" class="a failed">
							{{ a.answer }}
						</p>
						<NcRichText
							v-else
							class="a"
							:text="a.answer"
							useMarkdown />
					</div>
				</aside>
			</div>
		</div>
	</div>
</template>

<script>
// MCP Apps host (ext io.modelcontextprotocol/ui), exploratory POC. The protocol
// (initialize handshake, version negotiation, notifications) is ext-apps'
// AppBridge; this component supplies only the Nextcloud parts. The view runs
// opaque-origin (sandbox without allow-same-origin) from our own route, and
// every tool call is proxied through McpAppController with the user's token,
// so the bridge gets no MCP client of its own.
import { AppBridge, PostMessageTransport } from '@modelcontextprotocol/ext-apps/app-bridge'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcRichText from '@nextcloud/vue/components/NcRichText'

const VIEW_URI = 'ui://nextcloud-mcp/document-viewer.html'

export default {
	name: 'McpAppHost',
	components: { NcButton, NcLoadingIcon, NcNoteCard, NcRichText },
	props: {
		fileId: { type: Number, required: true },
		chunkIndex: { type: Number, default: null },
		// Embedded as a reference widget (e.g. Assistant chat) instead of a modal.
		inline: { type: Boolean, default: false },
		// Shared with reference.js: { superseded } once a later message opens this file.
		state: { type: Object, default: null },
		// Opaque id of model-proposed redactions; the server swaps it for redact_text.
		proposal: { type: String, default: null },
	},

	emits: ['close'],
	data() {
		return {
			viewUrl: generateUrl('/apps/astrolabe/mcp-app/view?uri={uri}', { uri: VIEW_URI }),
			title: t('astrolabe', 'Document'),
			displayMode: 'inline',
			frameHeight: 600,
			answers: [],
			error: '',
			openResult: null,
			viewReady: false,
			showSuperseded: false,
		}
	},

	computed: {
		// Hidden, not unmounted: the iframe keeps its state if shown again.
		collapsed() {
			return Boolean(this.state?.superseded) && !this.showSuperseded
		},
	},

	watch: {
		// Teleporting the shell would move the iframe and reload the view
		// (losing staged regions). Fullscreen instead lifts it into the popover
		// top layer, which does not move it, and hides NC's header/navigation
		// for browsers without popover support.
		displayMode(mode) {
			document.body.classList.toggle('mcp-app-fullscreen', mode === 'fullscreen')
			this.topLayer(mode === 'fullscreen')
			this.bridge?.sendHostContextChange({ displayMode: mode })
		},
	},

	mounted() {
		if (!Number.isInteger(this.fileId)) {
			this.error = t('astrolabe', 'This result has no file id to open.')
			return
		}
		// Not in data(): a Vue proxy would break the bridge's private fields.
		this.bridge = this.createBridge()
		const win = this.$refs.frame.contentWindow
		this.bridge.connect(new PostMessageTransport(win, win)).catch((e) => {
			this.error = e.message
		})
		// The host calls the tool itself, then pushes the result once the view is initialized.
		this.callTool('open_document', this.toolArgs()).then((r) => {
			this.openResult = r
			this.title = r.structuredContent?.title || this.title
			this.pushResult()
		}).catch((e) => { this.error = e.message })
	},

	beforeUnmount() {
		document.body.classList.remove('mcp-app-fullscreen')
		this.topLayer(false)
		const bridge = this.bridge
		bridge?.teardownResource({ reason: 'closed' })
			.catch(() => {})
			.finally(() => bridge.close())
	},

	methods: {
		createBridge() {
			const bridge = new AppBridge(null, { name: 'Astrolabe', version: '0' }, {
				openLinks: {},
				serverTools: {},
				updateModelContext: { text: {} },
			}, {
				hostContext: {
					theme: document.body.matches('[data-theme-dark], [data-themes*="dark"]') ? 'dark' : 'light',
					displayMode: this.displayMode,
					availableDisplayModes: ['inline', 'fullscreen'],
					containerDimensions: { maxHeight: Math.round(window.innerHeight * 0.85) },
					locale: document.documentElement.lang || 'en',
					platform: 'web',
				},
			})
			bridge.oninitialized = () => {
				this.viewReady = true
				this.pushResult()
			}
			bridge.oncalltool = async ({ name, arguments: args }) => {
				let title
				try {
					return await this.callTool(name, args || {})
				} catch (err) {
					// The server says which calls write (409 + confirm). The view is
					// third-party HTML, so the user's say-so is asked here, in the host,
					// where the view cannot click for them. (A session could POST
					// confirmed itself; the guard is against the view, which has no
					// access to this API.)
					if (err.response?.status !== 409 || !err.response.data?.confirm) {
						throw new Error(err.response?.data?.error || err.message, { cause: err })
					}
					title = err.response.data.title || name
				}
				// ponytail: native confirm(); swap for a Nextcloud dialog if it jars.
				if (!window.confirm(t('astrolabe', 'The document viewer wants to: {title}. This changes your files. Continue?', { title }))) {
					return { content: [{ type: 'text', text: 'The user cancelled the change.' }], isError: true }
				}
				try {
					return await this.callTool(name, args || {}, true)
				} catch (err) {
					throw new Error(err.response?.data?.error || err.message, { cause: err })
				}
			}
			bridge.onmessage = async (params) => {
				// Fire and forget: ask() reports its own failure in the side panel.
				void this.ask(params.content?.map((c) => c.text).filter(Boolean).join('\n') || '')
				return {}
			}
			bridge.onupdatemodelcontext = async (params) => {
				// Kept server-side so the user's next chat turn sees the viewer's state.
				const text = params.content?.map((c) => c.text).filter(Boolean).join('\n')
				if (text) {
					axios.post(generateUrl('/apps/astrolabe/api/v1/mcp-app/model-context'), { fileId: this.fileId, text }).catch(() => {})
				}
				return {}
			}
			bridge.onopenlink = async ({ url }) => {
				if (/^https?:\/\//.test(url || '')) {
					window.open(url, '_blank', 'noopener')
				}
				return {}
			}
			bridge.onrequestdisplaymode = async ({ mode }) => {
				this.displayMode = mode === 'fullscreen' ? 'fullscreen' : 'inline'
				return { mode: this.displayMode }
			}
			bridge.onsizechange = ({ height }) => {
				if (this.displayMode === 'inline' && height) {
					this.frameHeight = Math.min(height, Math.round(window.innerHeight * 0.85))
				}
			}
			return bridge
		},

		// First line, capped: a Summarize "question" is the whole passage.
		label(question) {
			const line = (question || '').trim().split('\n', 1)[0]
			return line.length > 80 ? line.slice(0, 79) + '…' : line
		},

		toolArgs() {
			const args = { file_id: this.fileId }
			if (this.chunkIndex !== null) {
				args.chunk_index = this.chunkIndex
			}
			if (this.proposal) {
				args.proposal = this.proposal
			}
			return args
		},

		pushResult() {
			if (!this.viewReady || !this.openResult) {
				return
			}
			// JSON copies: Vue's reactive proxies are not structured-cloneable.
			this.bridge.sendToolInput({ arguments: JSON.parse(JSON.stringify(this.toolArgs())) })
			this.bridge.sendToolResult(JSON.parse(JSON.stringify(this.openResult)))
		},

		async callTool(name, args, confirmed = false) {
			const { data } = await axios.post(generateUrl('/apps/astrolabe/api/v1/mcp-app/tools/call'), { name, arguments: args, uri: VIEW_URI, confirmed })
			return data
		},

		/**
		 * Show the shell in (or take it out of) the top layer. In the Assistant
		 * modal, a transformed ancestor makes `position: fixed` relative to the
		 * modal, so a fullscreen shell overflowed the screen and its tray was
		 * unreachable. The top layer ignores every ancestor.
		 *
		 * @param {boolean} on whether the shell should cover the viewport
		 */
		topLayer(on) {
			const el = this.$refs.shell
			if (!el?.showPopover) {
				return
			}
			if (on && !el.matches(':popover-open')) {
				el.setAttribute('popover', 'manual')
				el.showPopover()
			} else if (!on && el.hasAttribute('popover')) {
				if (el.matches(':popover-open')) {
					el.hidePopover()
				}
				el.removeAttribute('popover')
			}
		},

		close() {
			if (this.inline) {
				// Inline: closing only leaves fullscreen; the widget stays in the chat.
				this.displayMode = 'inline'
				return
			}
			this.$emit('close')
		},

		// Closing drops the answers (a pending one is discarded when it lands:
		// ask() mutates an entry no longer in the list) and the error.
		closeSide() {
			this.answers = []
			this.error = ''
		},

		// ui/message: answered by the agent in the side panel, not the chat.
		async ask(question) {
			this.answers.unshift({ question, answer: '', pending: true, failed: false })
			// Mutate the reactive proxy, not the raw object, or nothing re-renders.
			const entry = this.answers[0]
			try {
				const { data } = await axios.post(generateUrl('/apps/astrolabe/api/v1/mcp-app/message'), {
					// The viewer state reaches the model server-side, fenced as data.
					text: question,
				})
				entry.answer = data.output
			} catch (err) {
				entry.failed = true
				entry.answer = t('astrolabe', 'The assistant could not answer: {error}', { error: err.response?.data?.error || err.message })
			}
			entry.pending = false
		},
	},
}
</script>

<style>
body.mcp-app-fullscreen #header,
body.mcp-app-fullscreen .app-navigation,
body.mcp-app-fullscreen #app-navigation-vue {
	visibility: hidden !important;
}
</style>

<style scoped>
.mcp-app-overlay { position: fixed; inset: 0; background: rgba(0, 0, 0, .5); z-index: 10000; display: flex; align-items: center; justify-content: center; }

.mcp-app-shell { background: var(--color-main-background); border-radius: var(--border-radius-large); width: min(1200px, 95vw); max-height: 95vh; display: flex; flex-direction: column; overflow: hidden; }

.mcp-app-shell.inline { width: 100%; max-height: none; border: 1px solid var(--color-border); }

.mcp-app-shell.inline.fullscreen { position: fixed; inset: 0; z-index: 10000; }

.mcp-app-shell.fullscreen { width: 100vw; height: 100vh; max-height: 100vh; border-radius: 0; }

/* Undo the UA popover box (centred, bordered, fit-content). */
.mcp-app-shell.fullscreen:popover-open { inset: 0; margin: 0; padding: 0; border: 0; max-width: none; color: inherit; }

.mcp-app-superseded { display: flex; justify-content: space-between; align-items: center; padding: 4px 12px; border: 1px dashed var(--color-border); border-radius: var(--border-radius-large); color: var(--color-text-maxcontrast); }

.mcp-app-bar { display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; border-bottom: 1px solid var(--color-border); }

.mcp-app-body { display: flex; min-height: 0; flex: 1; }

.mcp-app-body iframe { flex: 1; border: 0; min-width: 0; }

.fullscreen .mcp-app-body iframe { height: auto !important; }

.mcp-app-side { width: 340px; overflow: auto; padding: 12px; border-inline-start: 1px solid var(--color-border); }

.mcp-app-side-bar { display: flex; justify-content: space-between; align-items: center; margin: -4px 0 8px; }

.mcp-app-answer { margin-bottom: 16px; }

.mcp-app-answer .q { font-weight: bold; }

.mcp-app-answer .a.failed { white-space: pre-wrap; color: var(--color-error-text); }
</style>
