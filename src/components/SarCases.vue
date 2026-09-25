<template>
	<div class="sar-cases">
		<!-- Case list -->
		<template v-if="!current">
			<NcNoteCard type="info">
				<p>
					{{ t('astrolabe', 'A case collects the documents about one person, with a reason for each, and produces redacted archives of them for review. Cases are stored in Nextcloud, so anyone who can write the case folder (for example a team folder) can work on them.') }}
				</p>
			</NcNoteCard>

			<div class="sar-toolbar">
				<NcButton variant="primary" @click="showCreate = !showCreate">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('astrolabe', 'New case') }}
				</NcButton>
				<NcButton variant="tertiary" :aria-label="t('astrolabe', 'Refresh')" @click="loadCases">
					<template #icon>
						<Refresh :size="20" />
					</template>
				</NcButton>
			</div>

			<form v-if="showCreate" class="sar-create" @submit.prevent="createCase">
				<NcTextField v-model="draft.name" :label="t('astrolabe', 'Case name')" />
				<NcTextArea
					v-model="draft.subject"
					:label="t('astrolabe', 'Data subject: names, aliases, email addresses, phone numbers and NI numbers (one per line)')"
					resize="vertical" />
				<NcTextField v-model="draft.description" :label="t('astrolabe', 'Description (e.g. request reference)')" />
				<div class="sar-folder">
					<NcButton @click="pickFolder">
						<template #icon>
							<FolderSearch :size="20" />
						</template>
						{{ t('astrolabe', 'Choose folder') }}
					</NcButton>
					<span class="sar-muted">{{ draft.folder || t('astrolabe', 'No folder chosen') }}</span>
				</div>
				<NcButton type="submit" variant="primary" :disabled="busy">
					{{ t('astrolabe', 'Create case') }}
				</NcButton>
			</form>

			<NcNoteCard v-if="error" type="error">
				<p>{{ error }}</p>
			</NcNoteCard>

			<NcEmptyContent
				v-if="!loading && cases.length === 0"
				:name="t('astrolabe', 'No cases yet')" />
			<ul v-else class="sar-list">
				<li v-for="c in cases" :key="c.case_id">
					<button class="sar-list-row" type="button" @click="openCase(c.case_id)">
						<strong>{{ c.name }}</strong>
						<span class="sar-state" :class="'sar-state-' + c.state">{{ stateLabel(c.state) }}</span>
						<span class="sar-muted">{{ n('astrolabe', '%n document', '%n documents', c.items) }}</span>
						<span v-if="c.case_id === activeCaseId" class="sar-active">{{ t('astrolabe', 'Collecting from search') }}</span>
					</button>
				</li>
			</ul>
		</template>

		<!-- One case -->
		<template v-else>
			<div class="sar-toolbar">
				<NcButton variant="tertiary" @click="backToList">
					<template #icon>
						<ArrowLeft :size="20" />
					</template>
					{{ t('astrolabe', 'All cases') }}
				</NcButton>
			</div>

			<div class="sar-case-header">
				<h3>{{ current.case.name }}</h3>
				<span class="sar-state" :class="'sar-state-' + current.case.state">{{ stateLabel(current.case.state) }}</span>
			</div>
			<p class="sar-muted">
				{{ current.path }}
			</p>

			<NcNoteCard v-if="error" type="error">
				<p>{{ error }}</p>
			</NcNoteCard>

			<div class="sar-actions">
				<NcButton
					v-if="isOpen && current.case_id !== activeCaseId"
					@click="$emit('activate', { id: current.case_id, name: current.case.name })">
					<template #icon>
						<Magnify :size="20" />
					</template>
					{{ t('astrolabe', 'Collect from search') }}
				</NcButton>
				<span v-else-if="isOpen" class="sar-active">{{ t('astrolabe', 'Collecting from search') }}</span>
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
			<NcEmptyContent
				v-if="current.items_total === 0"
				:name="t('astrolabe', 'No documents yet')"
				:description="t('astrolabe', 'Choose \'Collect from search\', then use \'Add to SAR\' on search results.')" />
			<ul v-else class="sar-items">
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
						:modelValue="item.reason"
						:label="t('astrolabe', 'Reason for inclusion')"
						:error="!item.reason.trim()"
						:disabled="!isOpen"
						@update:modelValue="item.reason = $event"
						@blur="saveItem(item)" />
					<!-- Only files have pages; leave both empty for the whole document. -->
					<div v-if="item.doc_type === 'file'" class="sar-item-pages">
						<NcTextField
							:modelValue="item.page_start ?? ''"
							type="number"
							min="1"
							:label="t('astrolabe', 'From page')"
							:disabled="!isOpen"
							@update:modelValue="item.page_start = page($event)"
							@blur="saveItem(item)" />
						<NcTextField
							:modelValue="item.page_end ?? ''"
							type="number"
							min="1"
							:label="t('astrolabe', 'To page')"
							:disabled="!isOpen"
							@update:modelValue="item.page_end = page($event)"
							@blur="saveItem(item)" />
					</div>
				</li>
			</ul>

			<h4>{{ t('astrolabe', 'Searches ({count})', { count: current.case.queries.length }) }}</h4>
			<ul class="sar-queries">
				<li v-for="(q, i) in current.case.queries" :key="i">
					{{ q.text }}
					<span class="sar-muted">{{ q.hits === null ? '' : n('astrolabe', '%n hit', '%n hits', q.hits) }}</span>
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
import { FilePickerType, getFilePickerBuilder } from '@nextcloud/dialogs'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import Close from 'vue-material-design-icons/Close.vue'
import FolderSearch from 'vue-material-design-icons/FolderSearch.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'

defineProps({
	// The case "Add to SAR" on search results writes to, if any.
	activeCaseId: { type: Number, default: null },
})
const emit = defineEmits(['activate', 'changed'])

const API = generateUrl('/apps/astrolabe/api/v1/sar/cases')
const POLL_MS = 2000
// Permission.CREATE in @nextcloud/files: the user can add files to a folder.
const PERMISSION_CREATE = 4

const cases = ref([])
const current = ref(null)
const loading = ref(false)
const busy = ref(false)
const error = ref('')
const showCreate = ref(false)
const draft = ref({ name: '', subject: '', description: '', folder: '' })
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

// Every mutating call returns the whole case; show it and tell the parent.
function show(data) {
	current.value = data
	subjectText.value = data.case.subject.join('\n')
	descriptionText.value = data.case.description
	emit('changed', data)
	schedulePoll()
}

async function loadCases() {
	loading.value = true
	error.value = ''
	try {
		cases.value = (await axios.get(API)).data.cases
	} catch (err) {
		fail(err, t('astrolabe', 'Could not load cases.'))
	} finally {
		loading.value = false
	}
}

async function openCase(id) {
	error.value = ''
	try {
		show((await axios.get(`${API}/${id}`, { params: { limit: 1000 } })).data)
	} catch (err) {
		fail(err, t('astrolabe', 'Could not open the case.'))
	}
}

function backToList() {
	clearTimeout(pollTimer)
	current.value = null
	loadCases()
}

async function pickFolder() {
	const picker = getFilePickerBuilder(t('astrolabe', 'Choose where to create the case'))
		.setMultiSelect(false)
		.setMimeTypeFilter(['httpd/unix-directory'])
		.setType(FilePickerType.Choose)
		.allowDirectories(true)
		// Only folders the user can create in, including shared and team
		// folders with write access. The server checks again.
		.setCanPick((node) => (node.permissions & PERMISSION_CREATE) !== 0)
		.build()
	const picked = await picker.pick().catch(() => null)
	if (picked) {
		draft.value.folder = picked
	}
}

async function mutate(request, fallback) {
	busy.value = true
	error.value = ''
	try {
		show((await request()).data)
		return true
	} catch (err) {
		fail(err, fallback)
		return false
	} finally {
		busy.value = false
	}
}

async function createCase() {
	const subject = draft.value.subject.split('\n').map((s) => s.trim()).filter(Boolean)
	if (!draft.value.name.trim() || subject.length === 0 || !draft.value.folder) {
		error.value = t('astrolabe', 'Give the case a name, at least one identifier of the data subject, and a folder.')
		return
	}
	const created = await mutate(() => axios.post(API, {
		folder: draft.value.folder,
		name: draft.value.name.trim(),
		subject,
		description: draft.value.description.trim(),
	}), t('astrolabe', 'Could not create the case.'))
	if (created) {
		showCreate.value = false
		draft.value = { name: '', subject: '', description: '', folder: '' }
		emit('activate', { id: current.value.case_id, name: current.value.case.name })
	}
}

function patch(body, fallback) {
	return mutate(() => axios.patch(`${API}/${current.value.case_id}`, body), fallback)
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
		() => axios.post(`${API}/${current.value.case_id}/items`, body),
		t('astrolabe', 'Could not update the documents.'),
	)
}

function saveItem(item) {
	return items({
		add: [{
			doc_type: item.doc_type,
			doc_id: item.doc_id,
			reason: item.reason,
			page_start: item.page_start ?? null,
			page_end: item.page_end ?? null,
		}],
	})
}

function removeItem(item) {
	return items({ remove: [{ doc_type: item.doc_type, doc_id: item.doc_id }] })
}

function exportCase() {
	return mutate(
		() => axios.post(`${API}/${current.value.case_id}/exports`, {}),
		t('astrolabe', 'Could not start the export.'),
	)
}

function schedulePoll() {
	clearTimeout(pollTimer)
	if (current.value?.case.state === 'exporting') {
		pollTimer = setTimeout(async () => {
			try {
				show((await axios.get(`${API}/${current.value.case_id}`, { params: { limit: 1000 } })).data)
			} catch {
				// A blip while polling is not a failed export: try again.
				schedulePoll()
			}
		}, POLL_MS)
	}
}

// Opened from the parent (e.g. "Collect from search" → back to this case).
defineExpose({ openCase })

onMounted(loadCases)
onBeforeUnmount(() => clearTimeout(pollTimer))
</script>

<style scoped>
.sar-cases {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 860px;
}

.sar-toolbar,
.sar-actions,
.sar-folder,
.sar-case-header {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}

.sar-create,
.sar-items,
.sar-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.sar-list-row {
	display: flex;
	align-items: center;
	gap: 12px;
	width: 100%;
	text-align: start;
	padding: 10px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	cursor: pointer;
}

.sar-list-row:hover {
	background: var(--color-background-hover);
}

.sar-item {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
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
	max-width: 320px;
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

.sar-active {
	color: var(--color-primary-element);
	font-weight: bold;
}

.sar-muted {
	color: var(--color-text-maxcontrast);
}
</style>
