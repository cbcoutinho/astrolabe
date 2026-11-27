<template>
	<!-- Search Controls -->
	<div class="mcp-search-card">
		<NcTextArea
			:modelValue="query"
			:label="t('astrolabe', 'Search query')"
			:placeholder="t('astrolabe', 'Enter your search query… (Ctrl/⌘+Enter to search)')"
			class="mcp-search-input"
			resize="vertical"
			:rows="2"
			@update:modelValue="$emit('update:query', $event)"
			@keydown.ctrl.enter.prevent="$emit('search')"
			@keydown.meta.enter.prevent="$emit('search')" />

		<div class="mcp-search-row">
			<NcSelect
				:modelValue="selectedAlgorithmOption"
				:options="algorithmOptions"
				:placeholder="t('astrolabe', 'Algorithm')"
				:clearable="false"
				class="mcp-algorithm-select"
				@update:modelValue="$emit('update:algorithm', $event ? $event.id : (algorithmOptions[0] ? algorithmOptions[0].id : ''))" />

			<NcButton
				variant="primary"
				:disabled="!query.trim() || loading || !algorithm"
				@click="$emit('search')">
				<template #icon>
					<Magnify :size="20" />
				</template>
				{{ t('astrolabe', 'Search') }}
			</NcButton>
		</div>

		<!-- Advanced Options Toggle -->
		<NcButton
			variant="tertiary"
			class="mcp-advanced-toggle"
			@click="showAdvanced = !showAdvanced">
			<template #icon>
				<ChevronDown v-if="!showAdvanced" :size="20" />
				<ChevronUp v-else :size="20" />
			</template>
			{{ showAdvanced ? t('astrolabe', 'Hide advanced') : t('astrolabe', 'Advanced options') }}
		</NcButton>

		<!-- Advanced Options -->
		<div v-show="showAdvanced" class="mcp-advanced-options">
			<div class="mcp-advanced-grid">
				<div class="mcp-option-group">
					<label>{{ t('astrolabe', 'Document Types') }}</label>
					<div class="mcp-checkbox-grid">
						<NcCheckboxRadioSwitch
							v-for="docType in docTypeOptions"
							:key="docType.id"
							:modelValue="selectedDocTypes.includes(docType.id)"
							type="checkbox"
							@update:modelValue="toggleDocType(docType.id, $event)">
							{{ docType.label }}
						</NcCheckboxRadioSwitch>
					</div>
				</div>

				<div class="mcp-option-group">
					<label>{{ t('astrolabe', 'Result Limit') }}</label>
					<NcTextField
						:modelValue="limit"
						type="number"
						:min="1"
						:max="100"
						@update:modelValue="$emit('update:limit', $event)" />
				</div>

				<div class="mcp-option-group">
					<label for="mcp-granularity">{{ t('astrolabe', 'Results per document') }}</label>
					<select
						id="mcp-granularity"
						:value="granularity"
						class="mcp-select"
						@change="$emit('update:granularity', $event.target.value)">
						<option value="chunk">
							{{ t('astrolabe', 'Best passages — a long document may appear several times') }}
						</option>
						<!-- Dense-only search cannot group passages by document:
							 the server answers that combination with a 422. -->
						<option value="document" :disabled="algorithm === 'semantic'">
							{{ t('astrolabe', 'One row per document') }}
						</option>
					</select>
				</div>

				<div class="mcp-option-group">
					<label for="mcp-minimum-relevance">{{ t('astrolabe', 'Minimum relevance') }}: {{ relevanceApplies ? minRelevance + '%' : t('astrolabe', 'n/a') }}</label>
					<input
						id="mcp-minimum-relevance"
						:value="minRelevance"
						type="range"
						min="0"
						max="100"
						step="5"
						class="mcp-score-slider"
						:disabled="!relevanceApplies"
						aria-describedby="mcp-minimum-relevance-hint"
						@input="$emit('update:minRelevance', Number($event.target.value))">
					<p id="mcp-minimum-relevance-hint" class="mcp-option-hint">
						{{ relevanceApplies
							? t('astrolabe', 'Hides results below this relevance. The server applies it before cutting the page, so a filtered search still returns a full page.')
							: t('astrolabe', 'Exact phrase search finds every document containing the phrase, unranked, so relevance does not apply.') }}
					</p>
				</div>

				<div class="mcp-option-group">
					<label for="mcp-modified-after">{{ t('astrolabe', 'Modified after') }}</label>
					<input
						id="mcp-modified-after"
						:value="modifiedAfter"
						type="datetime-local"
						class="mcp-date-input"
						@input="$emit('update:modifiedAfter', $event.target.value)">
				</div>

				<div class="mcp-option-group">
					<label for="mcp-modified-before">{{ t('astrolabe', 'Modified before') }}</label>
					<input
						id="mcp-modified-before"
						:value="modifiedBefore"
						type="datetime-local"
						class="mcp-date-input"
						@input="$emit('update:modifiedBefore', $event.target.value)">
				</div>

				<div class="mcp-option-group">
					<label>{{ t('astrolabe', 'Folders (files)') }}</label>
					<NcButton
						variant="secondary"
						class="mcp-folder-pick-btn"
						:disabled="!pathFilterApplicable"
						@click="pickFolders">
						<template #icon>
							<FolderSearch :size="20" />
						</template>
						{{ pathPrefixes.length
							? t('astrolabe', 'Add folders…')
							: t('astrolabe', 'Choose folders…') }}
					</NcButton>
					<!-- Selected folders are surfaced as removable chips in the
						 shared active-filters bar below (same pattern as the date
						 range), so they're not duplicated inline here. -->
					<span v-if="!pathFilterApplicable" class="mcp-path-hint">
						{{ t('astrolabe', 'Select the Files type to filter by folder') }}
					</span>
				</div>
			</div>

			<NcNoteCard v-if="dateRangeError" type="warning" class="mcp-date-error">
				<div>{{ dateRangeError }}</div>
			</NcNoteCard>
		</div>

		<!-- Active filter chips (ADR-027) -->
		<div v-if="activeFilters.length > 0" class="mcp-active-filters">
			<span
				v-for="chip in activeFilters"
				:key="chip.key"
				class="mcp-filter-chip">
				{{ chip.label }}
				<button
					type="button"
					class="mcp-filter-chip-close"
					:aria-label="t('astrolabe', 'Remove filter')"
					@click="removeFilter(chip)">
					<Close :size="16" />
				</button>
			</span>
		</div>
	</div>
</template>

<script>
import { FilePickerType, getFilePickerBuilder } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import Close from 'vue-material-design-icons/Close.vue'
import FolderSearch from 'vue-material-design-icons/FolderSearch.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import { activeFilterChips, mergeFolders, pathFilterApplicable, toggledSelection } from '../utils/searchFilters.js'

/**
 * The search card: query, algorithm, advanced options and the active-filter
 * chips.
 *
 * Presentational. Every value is owned by the parent and bound with
 * `v-model:<name>`, because the parent's search, its SAR case paging and its
 * watchers all read the same state. Emits `search` when the user submits.
 */
export default {
	name: 'SearchForm',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
		NcSelect,
		NcTextArea,
		NcTextField,
		ChevronDown,
		ChevronUp,
		Close,
		FolderSearch,
		Magnify,
	},

	props: {
		query: { type: String, required: true },
		algorithm: { type: String, required: true },
		selectedDocTypes: { type: Array, required: true },
		limit: { type: [Number, String], required: true },
		granularity: { type: String, required: true },
		minRelevance: { type: Number, required: true },
		modifiedAfter: { type: String, required: true },
		modifiedBefore: { type: String, required: true },
		pathPrefixes: { type: Array, required: true },
		dateRangeError: { type: String, default: null },

		/** Algorithms the server can serve, as `{id, label}`. */
		algorithmOptions: { type: Array, required: true },
		/** Doc types enabled for this user, as `{id, label}`. */
		docTypeOptions: { type: Array, required: true },
		/** False for exact phrase search, where relevance means nothing. */
		relevanceApplies: { type: Boolean, required: true },
		/** A search is running: submitting again is disabled. */
		loading: { type: Boolean, required: true },
	},

	emits: [
		'search',
		'update:query',
		'update:algorithm',
		'update:selectedDocTypes',
		'update:limit',
		'update:granularity',
		'update:minRelevance',
		'update:modifiedAfter',
		'update:modifiedBefore',
		'update:pathPrefixes',
		'update:dateRangeError',
	],

	data() {
		return {
			showAdvanced: false,
		}
	},

	computed: {
		selectedAlgorithmOption() {
			return this.algorithmOptions.find((opt) => opt.id === this.algorithm) || this.algorithmOptions[0]
		},

		pathFilterApplicable() {
			return pathFilterApplicable(this.selectedDocTypes)
		},

		activeFilters() {
			return activeFilterChips(this, this.t)
		},
	},

	methods: {
		toggleDocType(docTypeId, checked) {
			this.$emit('update:selectedDocTypes', toggledSelection(this.selectedDocTypes, docTypeId, checked))
		},

		// Remove a single active filter when its chip's close button is clicked.
		removeFilter(chip) {
			if (chip.kind === 'doc') {
				this.toggleDocType(chip.value, false)
			} else if (chip.kind === 'after') {
				this.$emit('update:modifiedAfter', '')
			} else if (chip.kind === 'before') {
				this.$emit('update:modifiedBefore', '')
			} else if (chip.kind === 'path') {
				this.$emit('update:pathPrefixes', this.pathPrefixes.filter((p) => p !== chip.value))
			}
			this.$emit('update:dateRangeError', null)
		},

		// Open the native Nextcloud folder picker and merge the chosen
		// directories into the path filter. Picking from the user's own Files
		// tree means every value is a valid, server-side path, so the filter
		// can't silently match nothing because of a typo.
		async pickFolders() {
			const picker = getFilePickerBuilder(this.t('astrolabe', 'Select folders to search'))
				.setMultiSelect(true)
				.setMimeTypeFilter(['httpd/unix-directory'])
				.setType(FilePickerType.Choose)
				.allowDirectories(true)
				.build()

			// pick() rejects on cancel (normal — map to null, leaving the
			// selection untouched). It resolves to the selected path(s): an array
			// under multi-select, a single string otherwise; mergeFolders
			// normalizes both into an array.
			const picked = await picker.pick().catch(() => null)
			this.$emit('update:pathPrefixes', mergeFolders(this.pathPrefixes, picked))
		},
	},
}
</script>

<style scoped lang="scss">
.mcp-search-card {
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large);
	padding: 20px;
	margin-bottom: 24px;
}

.mcp-search-row {
	display: flex;
	gap: 12px;
	align-items: flex-end;
	flex-wrap: wrap;
}

.mcp-search-input {
	width: 100%;
	margin-bottom: 12px;

	// `.mcp-search-input` lands on NcTextArea's wrapper div, so reach the real
	// <textarea> to bound how far the vertical drag handle can grow it.
	:deep(textarea) {
		max-height: 320px;
	}
}

.mcp-algorithm-select {
	min-width: 150px;
}

.mcp-advanced-toggle {
	margin-top: 12px;
}

.mcp-advanced-options {
	margin-top: 16px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

.mcp-advanced-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 20px;
}

.mcp-option-group {
	label {
		display: block;
		font-weight: 600;
		margin-bottom: 8px;
		color: var(--color-text-maxcontrast);
	}
}

// Explanatory line under a control. Two of the relevance controls are easy to
// confuse -- one filters the search, the other hides rows already returned --
// so each says which it is rather than relying on the label alone.
.mcp-option-hint {
	margin: 6px 0 0;
	font-size: 0.85em;
	line-height: 1.4;
	color: var(--color-text-maxcontrast);
}

.mcp-select {
	width: 100%;
	min-height: 34px;
	padding: 4px 8px;
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
}

.mcp-checkbox-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 8px;
}

.mcp-score-slider {
	width: 100%;
	margin-top: 8px;
	accent-color: var(--color-primary-element);
}

// ADR-027 modified-date range inputs + folder picker + active-filter chips
.mcp-date-input {
	width: 100%;
	padding: 6px 8px;
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);

	&:focus,
	&:hover {
		border-color: var(--color-primary-element);
	}

	&:disabled {
		opacity: 0.5;
		cursor: not-allowed;
	}
}

.mcp-path-hint {
	display: block;
	margin-top: 4px;
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
}

.mcp-date-error {
	margin-top: 12px;
}

.mcp-active-filters {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-top: 12px;
}

.mcp-filter-chip {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 2px 4px 2px 12px;
	border-radius: 16px;
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	font-size: 0.85em;
}

.mcp-filter-chip-close {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 24px;
	height: 24px;
	padding: 0;
	border: none;
	border-radius: 50%;
	background-color: transparent;
	color: inherit;
	cursor: pointer;

	&:hover,
	&:focus-visible {
		background-color: var(--color-primary-element);
		color: var(--color-primary-element-text);
	}
}

@media (max-width: 768px) {
	.mcp-search-row {
		flex-direction: column;
		align-items: stretch;
	}

	.mcp-algorithm-select {
		min-width: 100%;
	}

	.mcp-checkbox-grid {
		grid-template-columns: 1fr;
	}
}
</style>
