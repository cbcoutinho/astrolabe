<template>
	<div class="sar-cases">
		<NcNoteCard type="info">
			<p>
				{{ t('astrolabe', 'A case collects the documents about one person, with a reason for each, and produces redacted archives of them for review. Open a case to search for it: the case appears beside the search results. Cases are stored in Nextcloud, so anyone who can write the case folder (for example a team folder) can work on them.') }}
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
				:label="t('astrolabe', 'Data subject: names, aliases, email addresses, phone numbers, NI numbers and addresses (one per line)')"
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
				<button class="sar-list-row" type="button" @click="$emit('activate', { id: c.case_id, name: c.name })">
					<strong>{{ c.name }}</strong>
					<span class="sar-state" :class="'sar-state-' + c.state">{{ stateLabel(c.state) }}</span>
					<span class="sar-muted">{{ n('astrolabe', '%n document', '%n documents', c.items) }}</span>
					<span v-if="c.case_id === activeCaseId" class="sar-active">{{ t('astrolabe', 'Open beside search') }}</span>
				</button>
			</li>
		</ul>
	</div>
</template>

<script setup>
import axios from '@nextcloud/axios'
import { FilePickerType, getFilePickerBuilder } from '@nextcloud/dialogs'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import FolderSearch from 'vue-material-design-icons/FolderSearch.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'

defineProps({
	// The case open beside the search page, if any.
	activeCaseId: { type: Number, default: null },
})
const emit = defineEmits(['activate'])

const API = generateUrl('/apps/astrolabe/api/v1/sar/cases')
// Permission.CREATE in @nextcloud/files: the user can add files to a folder.
const PERMISSION_CREATE = 4

const cases = ref([])
const loading = ref(false)
const busy = ref(false)
const error = ref('')
const showCreate = ref(false)
const draft = ref({ name: '', subject: '', description: '', folder: '' })

function stateLabel(state) {
	return {
		open: t('astrolabe', 'Open'),
		exporting: t('astrolabe', 'Exporting'),
		ready_for_audit: t('astrolabe', 'Ready for audit'),
		closed: t('astrolabe', 'Closed'),
	}[state] ?? state
}

async function loadCases() {
	loading.value = true
	error.value = ''
	try {
		cases.value = (await axios.get(API)).data.cases
	} catch (err) {
		error.value = err.response?.data?.error || t('astrolabe', 'Could not load cases.')
	} finally {
		loading.value = false
	}
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

async function createCase() {
	const subject = draft.value.subject.split('\n').map((s) => s.trim()).filter(Boolean)
	if (!draft.value.name.trim() || subject.length === 0 || !draft.value.folder) {
		error.value = t('astrolabe', 'Give the case a name, at least one identifier of the data subject, and a folder.')
		return
	}
	busy.value = true
	error.value = ''
	try {
		const { data } = await axios.post(API, {
			folder: draft.value.folder,
			name: draft.value.name.trim(),
			subject,
			description: draft.value.description.trim(),
		})
		showCreate.value = false
		draft.value = { name: '', subject: '', description: '', folder: '' }
		emit('activate', { id: data.case_id, name: data.case.name })
	} catch (err) {
		error.value = err.response?.data?.error || t('astrolabe', 'Could not create the case.')
	} finally {
		busy.value = false
	}
}

defineExpose({ loadCases })

onMounted(loadCases)
</script>

<style scoped>
.sar-cases {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 860px;
}

.sar-toolbar,
.sar-folder {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}

.sar-create,
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
