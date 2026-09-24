<template>
	<div class="sar-export">
		<NcNoteCard type="info">
			<p>
				{{ t('astrolabe', 'Collect documents about one person from search results, then export a redacted archive. Other people\'s names, email addresses, phone numbers and NI numbers are replaced with placeholders; the person\'s own identifiers below are kept. Review the archive before sharing it.') }}
			</p>
		</NcNoteCard>

		<h3>{{ t('astrolabe', 'Data subject') }}</h3>
		<NcTextArea
			v-model="subjectText"
			:label="t('astrolabe', 'Names, aliases, email addresses, phone numbers and NI numbers (one per line)')"
			:placeholder="t('astrolabe', 'Jane Doe\nMs Doe\njane.doe@example.org')"
			resize="vertical"
			:disabled="running" />

		<h3>{{ t('astrolabe', 'Documents ({count})', { count: items.length }) }}</h3>
		<NcEmptyContent
			v-if="items.length === 0"
			:name="t('astrolabe', 'No documents selected')"
			:description="t('astrolabe', 'Use \'Add to SAR\' on search results.')" />
		<ul v-else class="sar-items">
			<li v-for="(item, index) in items" :key="item.doc_type + ':' + item.doc_id" class="sar-item">
				<div class="sar-item-header">
					<span class="mcp-result-type">{{ item.doc_type }}</span>
					<strong class="sar-item-title">{{ item.title || t('astrolabe', 'Untitled') }}</strong>
					<NcButton
						variant="tertiary"
						:aria-label="t('astrolabe', 'Remove')"
						:disabled="running"
						@click="$emit('remove', index)">
						<template #icon>
							<Close :size="18" />
						</template>
					</NcButton>
				</div>
				<NcTextField
					v-model="item.reason"
					:label="t('astrolabe', 'Reason for inclusion')"
					:error="submitted && !item.reason.trim()"
					:disabled="running" />
				<!-- Only files have pages; leave both empty for the whole document. -->
				<div v-if="item.doc_type === 'file'" class="sar-item-pages">
					<NcTextField
						v-model="item.page_start"
						type="number"
						min="1"
						:label="t('astrolabe', 'From page')"
						:disabled="running" />
					<NcTextField
						v-model="item.page_end"
						type="number"
						min="1"
						:label="t('astrolabe', 'To page')"
						:disabled="running" />
				</div>
			</li>
		</ul>

		<h3>{{ t('astrolabe', 'Archive') }}</h3>
		<NcTextField
			v-model="name"
			:label="t('astrolabe', 'Archive name')"
			:helperText="t('astrolabe', 'Letters, digits, spaces, dots, dashes and underscores')"
			:disabled="running" />
		<div class="sar-folder">
			<NcButton :disabled="running" @click="pickFolder">
				<template #icon>
					<FolderSearch :size="20" />
				</template>
				{{ t('astrolabe', 'Choose output folder') }}
			</NcButton>
			<span class="sar-folder-path">{{ outputFolder || t('astrolabe', 'No folder chosen') }}</span>
		</div>

		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<NcButton
			variant="primary"
			:disabled="running || busy"
			@click="submit">
			<template #icon>
				<NcLoadingIcon v-if="busy" :size="20" />
			</template>
			{{ t('astrolabe', 'Create redacted archive') }}
		</NcButton>

		<div v-if="status" class="sar-status">
			<NcNoteCard v-if="status.state === 'running'" type="info">
				<p>{{ t('astrolabe', 'Redacting: {processed} of {total} documents', { processed: status.processed, total: status.total }) }}</p>
			</NcNoteCard>
			<NcNoteCard v-else-if="status.state === 'done'" type="success">
				<p>{{ t('astrolabe', 'Ready for review: {path}', { path: status.archive_path }) }}</p>
				<p v-if="status.failed > 0">
					{{ t('astrolabe', '{failed} document(s) could not be exported; see the archive index.', { failed: status.failed }) }}
				</p>
				<a :href="folderUrl" class="sar-open-folder">{{ t('astrolabe', 'Open in Files') }}</a>
			</NcNoteCard>
			<NcNoteCard v-else type="error">
				<p>{{ t('astrolabe', 'Export failed: {message}', { message: status.message || '' }) }}</p>
			</NcNoteCard>
		</div>
	</div>
</template>

<script setup>
import axios from '@nextcloud/axios'
import { FilePickerType, getFilePickerBuilder } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { computed, onBeforeUnmount, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import Close from 'vue-material-design-icons/Close.vue'
import FolderSearch from 'vue-material-design-icons/FolderSearch.vue'

const props = defineProps({
	// {doc_type, doc_id, title, reason, page_start, page_end}; reason and pages
	// are edited in place.
	items: { type: Array, required: true },
	// The searches that found the items, recorded in the archive.
	queries: { type: Array, required: true },
})
defineEmits(['remove'])

const POLL_MS = 2000
// Permission.CREATE in @nextcloud/files: the user can add files to a folder.
const PERMISSION_CREATE = 4
const API = generateUrl('/apps/astrolabe/api/v1/sar/exports')

const subjectText = ref('')
const name = ref('SAR-' + new Date().toISOString().slice(0, 10))
const outputFolder = ref('')
const error = ref('')
const busy = ref(false)
const submitted = ref(false)
const status = ref(null)
let pollTimer = null

const running = computed(() => status.value?.state === 'running')
const folderUrl = computed(() => generateUrl('/apps/files/?dir={dir}', { dir: outputFolder.value }))

async function pickFolder() {
	const picker = getFilePickerBuilder(t('astrolabe', 'Choose where to save the archive'))
		.setMultiSelect(false)
		.setMimeTypeFilter(['httpd/unix-directory'])
		.setType(FilePickerType.Choose)
		.allowDirectories(true)
		// Only folders the user can create files in, which includes shared
		// and team folders with write access. The server checks again.
		.setCanPick((node) => (node.permissions & PERMISSION_CREATE) !== 0)
		.build()
	const picked = await picker.pick().catch(() => null)
	if (picked) {
		outputFolder.value = picked
	}
}

function page(value) {
	const n = parseInt(value, 10)
	return Number.isInteger(n) && n > 0 ? n : null
}

function validate(subject) {
	if (subject.length === 0) {
		return t('astrolabe', 'Enter at least one identifier of the data subject.')
	}
	if (props.items.length === 0) {
		return t('astrolabe', 'Add at least one document from the search results.')
	}
	if (props.items.some((item) => !item.reason.trim())) {
		return t('astrolabe', 'Give a reason for every document.')
	}
	if (!outputFolder.value) {
		return t('astrolabe', 'Choose an output folder.')
	}
	return ''
}

async function submit() {
	submitted.value = true
	const subject = subjectText.value.split('\n').map((s) => s.trim()).filter(Boolean)
	error.value = validate(subject)
	if (error.value) {
		return
	}
	busy.value = true
	try {
		const { data } = await axios.post(API, {
			output_folder: outputFolder.value,
			name: name.value.trim(),
			subject,
			items: props.items.map((item) => ({
				doc_type: item.doc_type,
				doc_id: String(item.doc_id),
				reason: item.reason.trim(),
				page_start: page(item.page_start),
				page_end: page(item.page_end),
			})),
			queries: props.queries,
		})
		status.value = data
		schedulePoll()
	} catch (err) {
		error.value = err.response?.data?.error || t('astrolabe', 'Could not start the export.')
	} finally {
		busy.value = false
	}
}

function schedulePoll() {
	clearTimeout(pollTimer)
	if (status.value?.state === 'running') {
		pollTimer = setTimeout(poll, POLL_MS)
	}
}

async function poll() {
	try {
		const { data } = await axios.get(API, {
			params: { output_folder: outputFolder.value, name: name.value.trim() },
		})
		status.value = data
	} catch (err) {
		// A blip while polling is not a failed export: keep the last status and
		// try again.
		error.value = err.response?.data?.error || t('astrolabe', 'Could not read the export status.')
	}
	schedulePoll()
}

onBeforeUnmount(() => clearTimeout(pollTimer))
</script>

<style scoped>
.sar-export {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 800px;
}

.sar-items {
	display: flex;
	flex-direction: column;
	gap: 12px;
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

.sar-folder {
	display: flex;
	align-items: center;
	gap: 12px;
}

.sar-folder-path {
	color: var(--color-text-maxcontrast);
}

.sar-open-folder {
	text-decoration: underline;
}
</style>
