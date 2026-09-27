<template>
	<div class="sar-panel">
		<NcLoadingIcon v-if="!current && !error" :size="32" />
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template v-if="current">
			<div class="sar-case-header">
				<span class="sar-state" :class="'sar-state-' + current.case.state">{{ stateLabel(current.case.state) }}</span>
				<span class="sar-muted sar-path">{{ current.path }}</span>
			</div>

			<div class="sar-actions">
				<NcButton v-if="current.case.state === 'ready_for_audit'" :disabled="busy" @click="setState('open')">
					{{ t('astrolabe', 'Reopen') }}
				</NcButton>
				<NcButton
					v-if="isOpen || current.case.state === 'ready_for_audit'"
					variant="error"
					:disabled="busy"
					@click="closeCase">
					{{ t('astrolabe', 'Close case') }}
				</NcButton>
			</div>

			<h4>{{ t('astrolabe', 'Data subject') }}</h4>
			<NcTextArea
				v-model="subjectText"
				:label="t('astrolabe', 'Kept in exports; everyone else is redacted (one per line)')"
				resize="vertical"
				:disabled="!isOpen" />
			<NcTextField v-model="descriptionText" :label="t('astrolabe', 'Description')" :disabled="!isOpen" />
			<NcButton v-if="isOpen" :disabled="busy || !detailsChanged" @click="saveDetails">
				{{ t('astrolabe', 'Save') }}
			</NcButton>

			<h4>{{ t('astrolabe', 'Documents ({count})', { count: current.items_total }) }}</h4>
			<p v-if="current.items_total === 0" class="sar-muted">
				{{ t('astrolabe', 'Search, then use \'Add to SAR\' on the results.') }}
			</p>
			<p v-if="current.case.items.length < current.items_total" class="sar-muted">
				{{ t('astrolabe', 'Showing the first {shown} of {total} documents.', { shown: current.case.items.length, total: current.items_total }) }}
			</p>
			<ul v-if="current.items_total > 0" class="sar-items">
				<li v-for="item in current.case.items" :key="item.doc_type + ':' + item.doc_id" class="sar-item">
					<div class="sar-item-header">
						<span class="mcp-result-type">{{ item.doc_type }}</span>
						<strong class="sar-item-title">{{ item.title || t('astrolabe', 'Untitled') }}</strong>
						<NcButton
							v-if="isOpen"
							variant="tertiary"
							:aria-label="t('astrolabe', 'Remove')"
							@click="removeItem(item)">
							<template #icon>
								<Close :size="18" />
							</template>
						</NcButton>
					</div>
					<NcTextField
						:modelValue="field(item, 'reason')"
						:label="t('astrolabe', 'Reason for inclusion')"
						:error="!field(item, 'reason').trim()"
						:disabled="!isOpen"
						@update:modelValue="edit(item, 'reason', $event)"
						@blur="saveItem(item)" />
					<!-- Only files have pages; leave both empty for the whole document. -->
					<div v-if="item.doc_type === 'file'" class="sar-item-pages">
						<NcTextField
							:modelValue="field(item, 'page_start') ?? ''"
							type="number"
							min="1"
							:label="t('astrolabe', 'From page')"
							:disabled="!isOpen"
							@update:modelValue="edit(item, 'page_start', page($event))"
							@blur="saveItem(item)" />
						<NcTextField
							:modelValue="field(item, 'page_end') ?? ''"
							type="number"
							min="1"
							:label="t('astrolabe', 'To page')"
							:disabled="!isOpen"
							@update:modelValue="edit(item, 'page_end', page($event))"
							@blur="saveItem(item)" />
					</div>
				</li>
			</ul>

			<h4>{{ t('astrolabe', 'Searches ({count})', { count: current.case.queries.length }) }}</h4>
			<ul class="sar-queries">
				<li v-for="(q, i) in current.case.queries" :key="i">
					{{ q.text }}
					<span class="sar-muted">{{ q.hits === null ? '' : n('astrolabe', '%n hit', '%n hits', q.hits) }}</span>
					<div v-if="filterLabel(q.filters)" class="sar-muted sar-filters">
						{{ filterLabel(q.filters) }}
					</div>
				</li>
			</ul>

			<h4>{{ t('astrolabe', 'Exports') }}</h4>
			<NcNoteCard v-if="current.case.state === 'exporting' && current.latest_export" type="info">
				<p>{{ t('astrolabe', 'Redacting: {processed} of {total} documents', { processed: current.latest_export.processed, total: current.latest_export.total }) }}</p>
			</NcNoteCard>
			<ul class="sar-exports">
				<li v-for="e in current.case.exports" :key="e.version">
					v{{ e.version }} · {{ exportLabel(e) }}
					<a v-if="e.state === 'done'" :href="folderUrl(e.archive_path)">{{ t('astrolabe', 'Open in Files') }}</a>
					<span v-if="e.message" class="sar-muted">{{ e.message }}</span>
				</li>
			</ul>
			<NcButton v-if="isOpen"
				variant="primary"
				:disabled="busy"
				@click="exportCase">
				<template #icon>
					<NcLoadingIcon v-if="busy" :size="20" />
				</template>
				{{ t('astrolabe', 'Create redacted archive') }}
			</NcButton>
		</template>
	</div>
</template>

<script setup>
import axios from '@nextcloud/axios'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import Close from 'vue-material-design-icons/Close.vue'

const props = defineProps({
	caseId: { type: Number, required: true },
})
const emit = defineEmits(['changed'])

const API = generateUrl('/apps/astrolabe/api/v1/sar/cases')
const POLL_MS = 2000
// Items listed: the MCP server's maximum page.
const PAGE = 1000

const current = ref(null)
const busy = ref(false)
const error = ref('')
const subjectText = ref('')
const descriptionText = ref('')
let pollTimer = null

const isOpen = computed(() => current.value?.case.state === 'open')
const subjectLines = computed(() => subjectText.value.split('\n').map((s) => s.trim()).filter(Boolean))
const detailsChanged = computed(() => current.value !== null && (
	subjectLines.value.join('\n') !== current.value.case.subject.join('\n')
	|| descriptionText.value !== current.value.case.description))

function stateLabel(state) {
	return {
		open: t('astrolabe', 'Open'),
		exporting: t('astrolabe', 'Exporting'),
		ready_for_audit: t('astrolabe', 'Ready for audit'),
		closed: t('astrolabe', 'Closed'),
	}[state] ?? state
}

function exportLabel(e) {
	if (e.state === 'done') {
		return e.failed
			? t('astrolabe', 'ready for review, {failed} not exported (see its index)', { failed: e.failed })
			: t('astrolabe', 'ready for review')
	}
	return e.state === 'running' ? t('astrolabe', 'running') : t('astrolabe', 'failed')
}

// The filters a logged search ran with, as the archive lists them.
function filterLabel(filters) {
	if (!filters) {
		return ''
	}
	const parts = []
	if (filters.path_prefixes?.length) {
		parts.push(t('astrolabe', 'Folders: {folders}', { folders: filters.path_prefixes.join(', ') }))
	}
	if (filters.doc_types?.length) {
		parts.push(t('astrolabe', 'Types: {types}', { types: filters.doc_types.join(', ') }))
	}
	if (filters.modified_after || filters.modified_before) {
		parts.push(t('astrolabe', 'Modified {after} to {before}', {
			after: filters.modified_after || '…',
			before: filters.modified_before || '…',
		}))
	}
	return parts.join(' · ')
}

function folderUrl(path) {
	return generateUrl('/apps/files/?dir={dir}', { dir: path.slice(0, path.lastIndexOf('/')) || '/' })
}

function page(value) {
	const number = parseInt(value, 10)
	return Number.isInteger(number) && number > 0 ? number : null
}

function fail(err, fallback) {
	error.value = err.response?.data?.error || fallback
}

// Unsaved edits to a document, by key: what the user typed while a save,
// an add or a search reloaded the case. They overlay the server copy until
// saved, so a reload never discards typing in progress.
const drafts = reactive({})

function itemKey(item) {
	return item.doc_type + ':' + item.doc_id
}

function field(item, name) {
	const draft = drafts[itemKey(item)]
	return draft && name in draft ? draft[name] : item[name]
}

function edit(item, name, value) {
	(drafts[itemKey(item)] ??= {})[name] = value
}

// Every mutating call returns the whole case; show it and tell the parent.
// Unsaved subject and description edits are kept, like document drafts.
function show(data) {
	const detailsDirty = detailsChanged.value
	current.value = data
	if (!detailsDirty) {
		subjectText.value = data.case.subject.join('\n')
		descriptionText.value = data.case.description
	}
	emit('changed', data)
	schedulePoll()
}

async function load() {
	error.value = ''
	try {
		show((await axios.get(`${API}/${props.caseId}`, { params: { limit: PAGE } })).data)
	} catch (err) {
		fail(err, t('astrolabe', 'Could not open the case.'))
	}
}

async function mutate(request, fallback) {
	busy.value = true
	error.value = ''
	try {
		const { data } = await request()
		show(data)
		// A change returns the first page of items only; list up to a full page.
		if (data.case.items.length < Math.min(data.items_total, PAGE)) {
			await load()
		}
		return true
	} catch (err) {
		fail(err, fallback)
		return false
	} finally {
		busy.value = false
	}
}

function patch(body, fallback) {
	return mutate(() => axios.patch(`${API}/${props.caseId}`, body), fallback)
}

function saveDetails() {
	return patch(
		{ subject: subjectLines.value, description: descriptionText.value },
		t('astrolabe', 'Could not save the case.'),
	)
}

function setState(state) {
	return patch({ state }, t('astrolabe', 'Could not change the case.'))
}

function closeCase() {
	if (window.confirm(t('astrolabe', 'Close this case? It becomes read-only and cannot be reopened. Its archives are kept.'))) {
		setState('closed')
	}
}

function items(body) {
	return mutate(
		() => axios.post(`${API}/${props.caseId}/items`, body),
		t('astrolabe', 'Could not update the documents.'),
	)
}

async function saveItem(item) {
	const key = itemKey(item)
	const sent = { ...drafts[key] }
	if (Object.keys(sent).length === 0) {
		return true
	}
	const saved = await items({
		add: [{
			doc_type: item.doc_type,
			doc_id: item.doc_id,
			reason: field(item, 'reason'),
			page_start: field(item, 'page_start') ?? null,
			page_end: field(item, 'page_end') ?? null,
		}],
	})
	// Drop only what was sent: anything typed since stays a draft.
	const draft = drafts[key]
	if (saved && draft) {
		for (const [name, value] of Object.entries(sent)) {
			if (draft[name] === value) {
				delete draft[name]
			}
		}
		if (Object.keys(draft).length === 0) {
			delete drafts[key]
		}
	}
	return saved
}

function removeItem(item) {
	return items({ remove: [{ doc_type: item.doc_type, doc_id: item.doc_id }] })
}

function exportCase() {
	return mutate(
		() => axios.post(`${API}/${props.caseId}/exports`, {}),
		t('astrolabe', 'Could not start the export.'),
	)
}

function schedulePoll() {
	clearTimeout(pollTimer)
	if (current.value?.case.state === 'exporting') {
		pollTimer = setTimeout(async () => {
			try {
				show((await axios.get(`${API}/${props.caseId}`, { params: { limit: PAGE } })).data)
			} catch {
				// A blip while polling is not a failed export: try again.
				schedulePoll()
			}
		}, POLL_MS)
	}
}

// The search page adds documents and runs searches itself; it hands the
// updated case back here, or asks for a reload after a logged search.
defineExpose({ show, load })

watch(() => props.caseId, () => {
	current.value = null
	load()
}, { immediate: true })
onBeforeUnmount(() => clearTimeout(pollTimer))
</script>

<style scoped>
.sar-panel {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 0 12px 12px;
}

.sar-actions,
.sar-case-header {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}

.sar-path {
	overflow-wrap: anywhere;
}

.sar-items {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.sar-item {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 8px;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.sar-item-header {
	display: flex;
	align-items: center;
	gap: 8px;
}

.sar-item-title {
	flex: 1;
	overflow: hidden;
	text-overflow: ellipsis;
}

.sar-item-pages {
	display: flex;
	gap: 8px;
}

.sar-queries li {
	margin-bottom: 6px;
}

.sar-filters {
	font-size: 0.9em;
}

.sar-state {
	border-radius: var(--border-radius-pill);
	padding: 2px 10px;
	background: var(--color-background-dark);
	font-size: 0.9em;
}

.sar-state-open { background: var(--color-primary-element-light); }

.sar-state-exporting { background: var(--color-warning); color: var(--color-warning-text); }

.sar-state-ready_for_audit { background: var(--color-success); color: var(--color-success-text); }

.sar-muted {
	color: var(--color-text-maxcontrast);
}
</style>
