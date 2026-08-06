<template>
	<NcContent appName="astrolabe">
		<NcAppNavigation>
			<template #list>
				<NcAppNavigationItem
					:name="t('astrolabe', 'Semantic Search')"
					:active="activeSection === 'search'"
					@click="activeSection = 'search'">
					<template #icon>
						<Magnify :size="20" />
					</template>
				</NcAppNavigationItem>

				<NcAppNavigationItem
					:name="t('astrolabe', 'Index Status')"
					:active="activeSection === 'status'"
					@click="activeSection = 'status'; loadVectorStatus()">
					<template #icon>
						<ChartBox :size="20" />
					</template>
				</NcAppNavigationItem>
			</template>

			<template #footer>
				<ul class="app-navigation-entry__settings">
					<NcAppNavigationItem
						:name="t('astrolabe', 'Settings')"
						@click="goToSettings">
						<template #icon>
							<Cog :size="20" />
						</template>
					</NcAppNavigationItem>
				</ul>
			</template>
		</NcAppNavigation>

		<NcAppContent>
			<!-- Search Section -->
			<div v-show="activeSection === 'search'" class="mcp-section">
				<div class="mcp-section-header">
					<h2>{{ t('astrolabe', 'Semantic Search') }}</h2>
					<p class="mcp-description">
						{{ t('astrolabe', 'Search your indexed content using semantic similarity. Find documents by meaning, not just keywords.') }}
					</p>
				</div>

				<!-- Search Controls -->
				<div class="mcp-search-card">
					<NcTextArea
						v-model="query"
						:label="t('astrolabe', 'Search query')"
						:placeholder="t('astrolabe', 'Enter your search query… (Ctrl/⌘+Enter to search)')"
						class="mcp-search-input"
						resize="vertical"
						:rows="2"
						@keydown.ctrl.enter.prevent="performSearch"
						@keydown.meta.enter.prevent="performSearch" />

					<div class="mcp-search-row">
						<NcSelect
							:modelValue="selectedAlgorithmOption"
							:options="algorithmOptions"
							:placeholder="t('astrolabe', 'Algorithm')"
							:clearable="false"
							class="mcp-algorithm-select"
							@update:modelValue="algorithm = $event ? $event.id : (algorithmOptions[0] ? algorithmOptions[0].id : '')" />

						<NcButton
							variant="primary"
							:disabled="!query.trim() || loading || !algorithm"
							@click="performSearch">
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
									v-model="limit"
									type="number"
									:min="1"
									:max="100" />
							</div>

							<div class="mcp-option-group">
								<label for="mcp-minimum-score">{{ hasRelevance ? t('astrolabe', 'Minimum relevance') : t('astrolabe', 'Minimum relevance, relative to the best result') }}: {{ scoreThreshold }}%</label>
								<input
									id="mcp-minimum-score"
									v-model="scoreThreshold"
									type="range"
									min="0"
									max="100"
									step="5"
									class="mcp-score-slider">
							</div>

							<div class="mcp-option-group">
								<label for="mcp-modified-after">{{ t('astrolabe', 'Modified after') }}</label>
								<input
									id="mcp-modified-after"
									v-model="modifiedAfter"
									type="datetime-local"
									class="mcp-date-input">
							</div>

							<div class="mcp-option-group">
								<label for="mcp-modified-before">{{ t('astrolabe', 'Modified before') }}</label>
								<input
									id="mcp-modified-before"
									v-model="modifiedBefore"
									type="datetime-local"
									class="mcp-date-input">
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

				<!-- Loading State -->
				<div v-if="loading" class="mcp-loading">
					<NcLoadingIcon :size="32" />
					<span>{{ t('astrolabe', 'Searching…') }}</span>
				</div>

				<!-- Error State -->
				<NcNoteCard v-if="error" type="error" class="mcp-error">
					<div>{{ error }}</div>
				</NcNoteCard>

				<!-- Results -->
				<div v-if="results.length > 0 && !loading" class="mcp-results">
					<div class="mcp-results-header">
						<span>
							{{ filteredResults.length }} {{ t('astrolabe', 'results') }}
							<span v-if="filteredResults.length !== results.length" class="mcp-filter-info">
								({{ results.length - filteredResults.length }} {{ t('astrolabe', 'filtered by score') }})
							</span>
						</span>
						<span class="mcp-algorithm-badge">{{ algorithmUsed }}</span>
					</div>

					<!-- 3D Visualization -->
					<div v-if="showVisualization && coordinates.length > 0" class="mcp-viz-container">
						<div class="mcp-viz-header">
							<h3>{{ t('astrolabe', 'Vector Space Visualization') }}</h3>
							<NcCheckboxRadioSwitch
								:modelValue="showQueryPoint"
								type="switch"
								@update:modelValue="showQueryPoint = $event; updatePlot()">
								{{ t('astrolabe', 'Show query point') }}
							</NcCheckboxRadioSwitch>
						</div>
						<div id="viz-plot-container" class="mcp-viz-plot-container">
							<div id="viz-plot" ref="vizPlot" />
						</div>
					</div>

					<div class="mcp-results-list">
						<div
							v-for="(result, index) in filteredResults"
							:key="result.id || index"
							class="mcp-result-item"
							:class="'mcp-doc-type-' + (result.doc_type || 'unknown')">
							<div class="mcp-result-header">
								<span class="mcp-result-type">{{ result.doc_type || 'unknown' }}</span>
								<div class="mcp-result-actions">
									<NcButton
										variant="tertiary"
										:aria-label="t('astrolabe', 'Show Chunk')"
										@click="viewChunk(result)">
										<template #icon>
											<Eye :size="18" />
										</template>
										{{ t('astrolabe', 'Show Chunk') }}
									</NcButton>
								</div>
							</div>
							<a
								:href="getDocumentUrl(result)"
								class="mcp-result-title"
								@click.prevent="navigateToDocument(result)">
								{{ result.title || t('astrolabe', 'Untitled') }}
								<OpenInNew :size="14" class="mcp-external-icon" />
							</a>
							<!--
								Relevance. The bar is drawn for every source because
								all of them are monotone in whatever ordered the
								results; the NUMBER is shown only when the server
								says the value is a calibrated probability. Showing
								a percentage for `fusion_ordinal` would be the same
								mistake as the old score badge, with a better
								number behind it.
							-->
							<div v-if="typeof result.relevance === 'number'" class="mcp-relevance">
								<div
									class="mcp-relevance-bar"
									role="meter"
									aria-valuemin="0"
									aria-valuemax="100"
									:aria-valuenow="relevancePercent(result)"
									:title="relevanceTitle(result)"
									:aria-label="relevanceTitle(result)">
									<div
										class="mcp-relevance-fill"
										:style="{ width: relevancePercent(result) + '%' }" />
								</div>
								<span v-if="relevanceIsCalibrated(result)" class="mcp-relevance-value">
									{{ relevancePercent(result) }}%
								</span>
							</div>
							<div class="mcp-result-metadata">
								<span v-if="result.chunk_index !== undefined && result.total_chunks">
									{{ t('astrolabe', 'Chunk {chunk}/{total}', { chunk: result.chunk_index + 1, total: result.total_chunks }) }}
								</span>
								<span v-if="result.page_number && result.page_count" class="mcp-metadata-separator">
									· {{ t('astrolabe', 'Page {page}/{total}', { page: result.page_number, total: result.page_count }) }}
								</span>
							</div>
						</div>
					</div>
				</div>

				<!-- No Results -->
				<NcEmptyContent
					v-if="searched && results.length === 0 && !loading && !error"
					:name="t('astrolabe', 'No results found')"
					:description="t('astrolabe', 'Try a different query or search algorithm.')">
					<template #icon>
						<Magnify />
					</template>
				</NcEmptyContent>

				<!-- Initial State -->
				<NcEmptyContent
					v-if="!searched && !loading"
					:name="t('astrolabe', 'Semantic Search')"
					:description="t('astrolabe', 'Enter a query above to search your indexed content.')">
					<template #icon>
						<Magnify />
					</template>
				</NcEmptyContent>
			</div>

			<!-- Index Status Section -->
			<div v-show="activeSection === 'status'" class="mcp-section">
				<div class="mcp-section-header">
					<h2>{{ t('astrolabe', 'Index Status') }}</h2>
					<p class="mcp-description">
						{{ t('astrolabe', 'View the status of your vector index and sync progress.') }}
					</p>
				</div>

				<div v-if="statusLoading" class="mcp-loading">
					<NcLoadingIcon :size="32" />
					<span>{{ t('astrolabe', 'Loading status…') }}</span>
				</div>

				<NcNoteCard v-else-if="statusError" type="error">
					{{ statusError }}
				</NcNoteCard>

				<div v-else-if="vectorStatus" class="mcp-status-cards">
					<div class="mcp-status-card">
						<div class="mcp-status-label">
							{{ t('astrolabe', 'Sync Status') }}
						</div>
						<div class="mcp-status-value" :class="'status-' + vectorStatus.status">
							{{ vectorStatus.status }}
						</div>
					</div>

					<div class="mcp-status-card">
						<div class="mcp-status-label">
							{{ t('astrolabe', 'Indexed Documents') }}
						</div>
						<div class="mcp-status-value">
							{{ (vectorStatus.indexed_documents || 0).toLocaleString() }}
						</div>
					</div>

					<div class="mcp-status-card">
						<div class="mcp-status-label">
							{{ t('astrolabe', 'Indexed Chunks') }}
						</div>
						<div class="mcp-status-value">
							{{ (vectorStatus.indexed_chunks || 0).toLocaleString() }}
						</div>
					</div>

					<div class="mcp-status-card">
						<div class="mcp-status-label">
							{{ t('astrolabe', 'Pending Documents') }}
						</div>
						<div class="mcp-status-value">
							{{ (vectorStatus.pending_documents || 0).toLocaleString() }}
						</div>
					</div>

					<div v-if="vectorStatus.last_sync_time" class="mcp-status-card">
						<div class="mcp-status-label">
							{{ t('astrolabe', 'Last Sync') }}
						</div>
						<div class="mcp-status-value">
							{{ vectorStatus.last_sync_time }}
						</div>
					</div>
				</div>

				<NcButton variant="secondary" :disabled="statusLoading" @click="loadVectorStatus">
					<template #icon>
						<Refresh :size="20" />
					</template>
					{{ t('astrolabe', 'Refresh') }}
				</NcButton>
			</div>
		</NcAppContent>

		<!-- PDF/Chunk Viewer Modal -->
		<div v-if="showViewer" class="mcp-modal-overlay" @click.self="closeViewer">
			<div class="mcp-modal">
				<!-- Fixed Header -->
				<div class="mcp-modal-header">
					<h3>
						<a
							v-if="currentResult"
							:href="getDocumentUrl(currentResult)"
							class="mcp-modal-title-link"
							@click.prevent="navigateToDocument(currentResult)">
							{{ viewerTitle }}
							<OpenInNew :size="16" class="mcp-modal-title-icon" />
						</a>
						<span v-else>{{ viewerTitle }}</span>
					</h3>
					<div class="mcp-modal-actions">
						<!-- Hidden outright when no provider can serve a summary, so
							 the action is never offered where it would always fail. -->
						<NcButton
							v-if="canSummarize"
							variant="tertiary"
							:disabled="summaryLoading"
							:title="t('astrolabe', 'Summarize the passage shown here with AI')"
							@click="summarizeSection">
							<template #icon>
								<NcLoadingIcon v-if="summaryLoading" :size="20" />
								<CreationIcon v-else :size="20" />
							</template>
							{{ t('astrolabe', 'Summarize section') }}
						</NcButton>
						<NcButton variant="tertiary" @click="closeViewer">
							<template #icon>
								<Close :size="20" />
							</template>
						</NcButton>
					</div>
				</div>

				<!-- Scrollable Content -->
				<div class="mcp-modal-body">
					<!-- AI summary, above the document it describes -->
					<NcNoteCard v-if="summaryError" type="warning" class="mcp-summary">
						{{ summaryError }}
					</NcNoteCard>
					<NcNoteCard v-else-if="summaryText" type="info" class="mcp-summary">
						<MarkdownViewer :content="summaryText" />
						<p class="mcp-summary-provenance">
							<!-- Scope and tier, both stated. The backend can only
								 retrieve the passage around this chunk — the MCP
								 server's chunk-context endpoint is page-scoped — so
								 this is never a whole-document summary, and saying
								 otherwise would misrepresent what was read. -->
							{{ summaryMode === 'analyze-images'
								? t('astrolabe', 'Covers the pages shown here, read as images.')
								: t('astrolabe', 'Covers the passage shown here, from extracted text — layout and images were not read.') }}
						</p>
					</NcNoteCard>

					<!-- Loading State -->
					<div v-if="viewerLoading" class="mcp-viewer-loading">
						<NcLoadingIcon :size="32" />
						<span>{{ t('astrolabe', 'Loading content…') }}</span>
					</div>

					<!-- PDF Viewer (canvas only, controls in footer) -->
					<PDFViewer
						v-else-if="viewerType === 'pdf'"
						ref="pdfViewer"
						:filePath="currentPdfPath"
						:docId="currentPdfDocId"
						:pageNumber="viewerPage"
						:highlightBbox="currentBbox"
						:bboxPage="currentBboxPage"
						@prevPage="viewerPage--"
						@nextPage="viewerPage++"
						@loaded="handlePdfLoaded"
						@error="handlePdfError" />

					<!-- Markdown Viewer (for non-PDFs) -->
					<MarkdownViewer
						v-else
						:content="getMarkdownContent()" />
				</div>

				<!-- Fixed Footer (navigation controls) -->
				<div v-if="!viewerLoading && viewerType === 'pdf' && pdfTotalPages > 0" class="mcp-modal-footer">
					<NcButton
						:disabled="viewerPage <= 1"
						@click="viewerPage--">
						<template #icon>
							<ChevronLeft :size="20" />
						</template>
						{{ t('astrolabe', 'Previous') }}
					</NcButton>
					<span class="mcp-page-info">
						{{ t('astrolabe', 'Page {current} of {total}', { current: viewerPage, total: pdfTotalPages }) }}
					</span>
					<NcButton
						:disabled="viewerPage >= pdfTotalPages"
						@click="viewerPage++">
						<template #icon>
							<ChevronRight :size="20" />
						</template>
						{{ t('astrolabe', 'Next') }}
					</NcButton>
				</div>
			</div>
		</div>
	</NcContent>
</template>

<script>
import axios from '@nextcloud/axios'
import { FilePickerType, getFilePickerBuilder } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import Plotly from 'plotly.js-dist-min'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ChartBox from 'vue-material-design-icons/ChartBox.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import Close from 'vue-material-design-icons/Close.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import CreationIcon from 'vue-material-design-icons/Creation.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import FolderSearch from 'vue-material-design-icons/FolderSearch.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import MarkdownViewer from './components/MarkdownViewer.vue'
// Imported statically, despite PDF.js being several MB. A dynamic import emits
// a separate chunk whose URL Vite resolves against `base` (`/`), so it is
// requested from /js/… instead of /custom_apps/astrolabe/js/… and 404s — the
// same root-relative-URL problem that forces the worker to be inlined. Making
// this lazy again needs a base-aware chunk URL first.
import PDFViewer from './components/PDFViewer.vue'

// How many PDF pages to render for a multimodal summary. Kept under the
// backend's own cap; vision tokens dominate the cost of these calls, so sending
// more pages costs real money for diminishing returns.
const SUMMARY_PAGE_WINDOW = 3

// Generation runs on the admin's provider through a background job, so the
// budget is generous — a queued task behind a busy provider is normal.
const SUMMARY_POLL_MS = 1500
const SUMMARY_TIMEOUT_MS = 3 * 60 * 1000

export default {
	name: 'App',
	components: {
		NcContent,
		NcAppNavigation,
		NcAppNavigationItem,
		NcAppContent,
		NcButton,
		NcTextField,
		NcTextArea,
		NcSelect,
		NcLoadingIcon,
		NcNoteCard,
		NcEmptyContent,
		NcCheckboxRadioSwitch,
		PDFViewer,
		MarkdownViewer,
		Magnify,
		ChartBox,
		Cog,
		ChevronDown,
		ChevronUp,
		ChevronLeft,
		ChevronRight,
		Refresh,
		OpenInNew,
		CreationIcon,
		Eye,
		Close,
		FolderSearch,
	},

	data() {
		// Read the page's initial state once (loadState reads the DOM each call).
		const appConfig = loadState('astrolabe', 'app-config', {})
		// Query algorithms the MCP server advertises. Distinguish three
		// states: `null` = the field was absent (older backend / unknown) ⇒ treat
		// permissively as "all"; a populated array ⇒ gate to it; an explicit `[]`
		// (vector sync off) ⇒ nothing is available. Collapsing `[]` into "all"
		// would offer Hybrid on a server that supports nothing, so every search
		// would hit the 422 backstop.
		const supportedSearchTypes = Array.isArray(appConfig.supportedSearchTypes)
			? appConfig.supportedSearchTypes
			: null
		// Default to hybrid when the server offers it (or when unknown), else the
		// first advertised type, else '' when nothing is available — so the
		// initially-selected algorithm is always one the server can serve.
		const defaultAlgorithm = (supportedSearchTypes === null || supportedSearchTypes.includes('hybrid'))
			? 'hybrid'
			: (supportedSearchTypes[0] ?? '')
		return {
			activeSection: 'search',
			// Search state
			query: '',
			algorithm: defaultAlgorithm,
			// Query types the server can serve; gates algorithmOptions below.
			supportedSearchTypes,
			showAdvanced: false,
			selectedDocTypes: [],
			// ADR-027 modified-date range filter. Bound to native
			// <input type="datetime-local"> (local wall-clock); serialized to
			// RFC 3339 (UTC) before sending. Empty string ⇒ open bound.
			modifiedAfter: '',
			modifiedBefore: '',
			dateRangeError: null,
			// ADR-027 Phase 2 path filter. Applies to file results only; sent as
			// a newline-separated path_prefixes list to the backend (OR-ed
			// MatchText on file_path). Newline is used because, unlike a comma,
			// it can't appear in a POSIX path. Folders are picked from the user's
			// Nextcloud files via the native folder picker, so the values are
			// always valid server paths.
			pathPrefixes: [],
			limit: 20,
			scoreThreshold: 0,
			loading: false,
			error: null,
			results: [],
			algorithmUsed: '',
			searched: false,
			expandedExcerpts: {},
			// Visualization state
			// Admin-controlled gate (initial state from PageController). When
			// false the Plotly panel is hidden and search skips PCA. Defaults
			// to true so the panel shows if the flag is ever absent.
			showVisualization: appConfig.showVisualization ?? true,
			// Per-user effective doc types (admin ∩ user) for the type filter;
			// empty/absent = show all (backend still intersects server-side).
			enabledDocTypes: appConfig.enabledDocTypes ?? [],
			coordinates: [],
			queryCoords: [],
			showQueryPoint: true,
			// Vector status state
			vectorStatus: null,
			statusLoading: false,
			statusError: null,
			// Viewer state
			showViewer: false,
			viewerLoading: false,
			viewerTitle: '',
			viewerType: 'text',
			viewerPage: 1,
			pdfTotalPages: 0,
			currentPdfPath: '',
			currentPdfDocId: null,
			currentBbox: [],
			currentBboxPage: null,
			currentResult: null, // Store the current result for document linking
			viewerContext: {
				chunk: '',
				before: '',
				after: '',
			},

			// Summary state. `summaryModes` comes from the backend and lists the
			// tiers a TaskProcessing provider can actually serve — empty means no
			// provider is installed, so the action is hidden rather than offered
			// and always failing.
			summaryModes: Array.isArray(appConfig.summaryModes) ? appConfig.summaryModes : [],
			summaryLoading: false,
			summaryText: '',
			summaryMode: '',
			summaryError: '',
			// Bumped whenever the viewer changes document. A summary can take
			// minutes, and the user can switch results while one is in flight, so
			// the resolving request checks this before writing its result — without
			// it, document A's summary can land on document B.
			summaryGeneration: 0,
		}
	},

	computed: {
		/**
		 * Whether a summary can be produced at all. Gated on the backend's
		 * advertised tiers rather than assumed: Astrolabe supplies retrieval, and
		 * generation comes from whatever TaskProcessing provider the admin
		 * installed — which on many instances is none.
		 *
		 * @return {boolean} True when at least one tier is available.
		 */
		canSummarize() {
			return this.summaryModes.length > 0 && !this.viewerLoading
		},

		algorithmOptions() {
			const all = [
				{ id: 'hybrid', label: this.t('astrolabe', 'Hybrid') },
				{ id: 'semantic', label: this.t('astrolabe', 'Semantic') },
				{ id: 'bm25', label: this.t('astrolabe', 'Keyword (BM25)') },
			]
			// Only offer query types the MCP server advertises: an explicit `[]`
			// (vector sync off) hides all three; otherwise offer the advertised
			// set (all three when sync is on). `null` = the server didn't advertise
			// the set (older backend) ⇒ show all (the backend still rejects an
			// unsupported algorithm 422-side).
			if (this.supportedSearchTypes === null) {
				return all
			}
			return all.filter((opt) => this.supportedSearchTypes.includes(opt.id))
		},

		docTypeOptions() {
			const all = [
				{ id: 'note', label: this.t('astrolabe', 'Notes') },
				{ id: 'file', label: this.t('astrolabe', 'Files') },
				{ id: 'deck_card', label: this.t('astrolabe', 'Deck Cards') },
				{ id: 'calendar', label: this.t('astrolabe', 'Calendar') },
				{ id: 'contact', label: this.t('astrolabe', 'Contacts') },
				{ id: 'news_item', label: this.t('astrolabe', 'News') },
				{ id: 'mail_message', label: this.t('astrolabe', 'Mail') },
			]
			// Only offer doc types enabled for this user (admin ∩ user). When the
			// server didn't provide the set (older backend), show all — the search
			// backend still intersects with the effective set server-side.
			if (!this.enabledDocTypes || this.enabledDocTypes.length === 0) {
				return all
			}
			return all.filter((opt) => this.enabledDocTypes.includes(opt.id))
		},

		selectedAlgorithmOption() {
			return this.algorithmOptions.find((opt) => opt.id === this.algorithm) || this.algorithmOptions[0]
		},

		// The path filter only makes sense for file results: file_path is only
		// indexed for files, so applying it while searching non-file types would
		// silently return nothing. Applicable when Files is selected or when no
		// doc-type filter is set (cross-app search includes files).
		pathFilterApplicable() {
			return this.selectedDocTypes.length === 0 || this.selectedDocTypes.includes('file')
		},

		// ADR-027: active structured filters rendered as closable chips, so the
		// user always sees what is narrowing their results even with the
		// advanced panel collapsed.
		activeFilters() {
			const chips = []
			for (const id of this.selectedDocTypes) {
				const opt = this.docTypeOptions.find((o) => o.id === id)
				chips.push({
					key: 'doc:' + id,
					label: opt ? opt.label : id,
					kind: 'doc',
					value: id,
				})
			}
			if (this.modifiedAfter) {
				chips.push({
					key: 'after',
					label: this.t('astrolabe', 'After {date}', { date: this.formatFilterDate(this.modifiedAfter) }),
					kind: 'after',
				})
			}
			if (this.modifiedBefore) {
				chips.push({
					key: 'before',
					label: this.t('astrolabe', 'Before {date}', { date: this.formatFilterDate(this.modifiedBefore) }),
					kind: 'before',
				})
			}
			// One chip per selected folder; only surface them when they will
			// actually apply (files in scope).
			if (this.pathFilterApplicable) {
				for (const folder of this.pathPrefixes) {
					chips.push({
						key: `path:${folder}`,
						label: this.t('astrolabe', 'Folder: {path}', { path: folder }),
						kind: 'path',
						value: folder,
					})
				}
			}
			return chips
		},

		// Rank key for the relevance filter: the cross-encoder score when the
		// server reranked, else the retrieval score. Matches the order the
		// server returned rows in, so the filter always removes a suffix of the
		// list rather than punching holes in it.
		//
		// Reranking is NOT required for any of this. This app never requests it
		// (no `rerank` field is sent), and the server defaults it off, so today
		// `rerank_score` is absent from every row and this falls through to
		// `score` on every result. The branch is here so the filter keeps
		// ranking on the better key if a rerank toggle is added later -- it must
		// stay a fallback, never a requirement.
		rankScore() {
			return (r) => r.rerank_score ?? r.score ?? 0
		},

		// Score RANGE over this page of results -- both ends, not just the
		// ceiling. Anchoring the cut at 0 would be wrong wherever a score can go
		// negative, and one of the offered fusions does: DBSF is
		// admin-selectable and normalises against mean +/- 3 standard
		// deviations, so points in the lower tail come back below zero. With a 0
		// anchor the LOOSEST setting (0%) computes a cut of exactly 0 and
		// silently drops every negative-scored row -- the precise opposite of
		// "0% shows everything" -- and raising the slider against a negative top
		// score moves the cut toward zero rather than away from it, inverting
		// the ordering this control exists to respect.
		scoreRange() {
			return this.results.reduce(
				(acc, r) => {
					const s = this.rankScore(r)
					return { min: Math.min(acc.min, s), max: Math.max(acc.max, s) }
				},
				{ min: Infinity, max: -Infinity },
			)
		},

		// The slider is RELATIVE, not absolute. An absolute cut cannot work:
		// the three things a row's `score` might be are on incomparable scales
		// -- cosine similarity in [0,1] for semantic search, an RRF fused score
		// bounded by 2/VECTOR_SEARCH_RRF_K (~0.033 at the server's default
		// k=60) for bm25/hybrid, or an unbounded DBSF score. A [0,100]%
		// control against the RRF range is inert above ~3%, and a near-perfect
		// hit legitimately scores 0.033.
		//
		// Relative-to-top is scale-invariant, so this control keeps working
		// across all three, and survives the server retuning k or switching
		// fusion without going inert again.
		// Interpolate across the observed range: 0% keeps everything, 100% keeps
		// only rows tied with the best. Both scale- AND offset-invariant, so it
		// behaves identically for a cosine similarity, an RRF fused score and a
		// DBSF score that may be negative.
		//
		// -Infinity when there are no results, which admits nothing and is
		// filtering an empty list anyway.
		scoreCut() {
			const { min, max } = this.scoreRange
			return min + (this.scoreThreshold / 100) * (max - min)
		},

		// Does this server report `relevance` (ADR-034)? Detected from the
		// response rather than negotiated: the field is self-describing, so a
		// server that has it says so by sending it. No capability call, no
		// version check, and no deploy-order dependency in either direction --
		// an older server simply keeps the relative-to-top behaviour below.
		hasRelevance() {
			return this.results.some((r) => typeof r.relevance === 'number')
		},

		// Only the calibrated source is a probability. `fusion_ordinal` orders
		// results honestly but is NOT one, and rendering it as a percentage
		// would recreate the exact bug this whole change exists to remove --
		// just with a better-behaved number behind it.
		relevanceIsCalibrated() {
			return (r) => r.relevance_source === 'cross_encoder_calibrated'
		},

		// The cut the slider applies, and the one thing it must agree with is
		// what the row displays. Absolute when the server reports `relevance`,
		// because that value means the same thing on every query and every
		// deployment -- which is precisely what the old relative cut could not
		// offer, and why it had to be relative.
		// THE cut, in one place. The list and the plot both consume this, so
		// their agreement is structural rather than two branches that happen to
		// match -- which is exactly the drift the comment on
		// filteredResultsAndCoords warns about.
		isRelevant() {
			if (this.hasRelevance) {
				const cut = this.scoreThreshold / 100
				// A row with no `relevance` is UNKNOWN, not zero, so it is never
				// dropped by the cut. hasRelevance is true when *some* row has
				// the field, and treating a missing one as 0 would silently
				// hide it the moment the slider left 0 -- the same "never hide
				// what you could not score" rule the server applies to rows the
				// reranker did not reach.
				return (r) => typeof r.relevance !== 'number' || r.relevance >= cut
			}
			const cut = this.scoreCut
			return (r) => this.rankScore(r) >= cut
		},

		filteredResults() {
			return this.results.filter(this.isRelevant)
		},

		// Parallel arrays used by renderPlot. The coordinate guard is
		// defensive against API drift so the plot never sees holes; the
		// list view (filteredResults) intentionally stays independent so
		// it still renders when PCA coordinates are unavailable.
		filteredResultsAndCoords() {
			// Same predicate as filteredResults by construction, so the plot and
			// the list cannot disagree about which results exist.
			const keep = this.isRelevant
			return this.results.reduce((acc, r, i) => {
				if (keep(r) && this.coordinates[i] !== undefined) {
					acc.results.push(r)
					acc.coordinates.push(this.coordinates[i])
				}
				return acc
			}, { results: [], coordinates: [] })
		},
	},

	watch: {
		docTypeOptions(opts) {
			// Drop any selected doc type that is no longer offered (e.g. the
			// user/admin disabled its source), so an invisible selection can't
			// silently restrict results. The backend also intersects, so this is
			// UX hygiene, not a security gate.
			const valid = new Set(opts.map((o) => o.id))
			const trimmed = this.selectedDocTypes.filter((id) => valid.has(id))
			if (trimmed.length !== this.selectedDocTypes.length) {
				this.selectedDocTypes = trimmed
			}
		},

		scoreThreshold() {
			// Debounce so rapid slider drags don't trigger many Plotly.newPlot
			// calls (each tears down and rebuilds the WebGL scene).
			if (this._scoreThresholdTimer) {
				clearTimeout(this._scoreThresholdTimer)
			}
			this._scoreThresholdTimer = setTimeout(() => {
				this._scoreThresholdTimer = null
				if (this.coordinates.length > 0) {
					this.renderPlot()
				}
			}, 150)
		},
	},

	created() {
		// Non-reactive instance state. Storing these in data() would make
		// Vue deep-observe a timer ID and a results-snapshot array on every
		// mutation, with no template benefit.
		this._scoreThresholdTimer = null
		this._renderedResults = []
	},

	mounted() {
		// Check for URL parameters to open chunk viewer
		this.handleUrlParameters()
	},

	beforeUnmount() {
		if (this._scoreThresholdTimer) {
			clearTimeout(this._scoreThresholdTimer)
			this._scoreThresholdTimer = null
		}
		// Clean up Plotly event handlers to prevent memory leaks
		const plotDiv = document.getElementById('viz-plot')
		if (plotDiv && plotDiv.on) {
			plotDiv.removeAllListeners('plotly_click')
		}
	},

	methods: {
		handleUrlParameters() {
			// Parse URL parameters
			const urlParams = new URLSearchParams(window.location.search)
			const docType = urlParams.get('doc_type')
			const docId = urlParams.get('doc_id')
			const chunkStart = urlParams.get('chunk_start')
			const chunkEnd = urlParams.get('chunk_end')

			// If we have chunk parameters, open the viewer
			if (docType && docId && chunkStart !== null && chunkEnd !== null) {
				// Construct a minimal result object
				const result = {
					doc_type: docType,
					id: parseInt(docId, 10),
					chunk_start_offset: parseInt(chunkStart, 10),
					chunk_end_offset: parseInt(chunkEnd, 10),
					title: urlParams.get('title') || this.t('astrolabe', 'Chunk Viewer'),
					metadata: {},
				}

				// Add optional metadata
				const path = urlParams.get('path')
				if (path) {
					result.metadata.path = path
				}
				const pageNumber = urlParams.get('page_number')
				if (pageNumber) {
					result.page_number = parseInt(pageNumber, 10)
				}
				const chunkIndex = urlParams.get('chunk_index')
				if (chunkIndex !== null) {
					result.chunk_index = parseInt(chunkIndex, 10)
				}
				const totalChunks = urlParams.get('total_chunks')
				if (totalChunks !== null) {
					result.total_chunks = parseInt(totalChunks, 10)
				}
				// Access-check identifiers, so a stale deep-link gets the same
				// local re-check as a live search result for every doc type
				// (missing ones just fall through to the MCP backstop).
				const boardId = urlParams.get('board_id')
				if (boardId) {
					result.metadata.board_id = boardId
				}
				const mailboxId = urlParams.get('mailbox_id')
				if (mailboxId) {
					result.metadata.mailbox_id = mailboxId
				}
				const calendarUri = urlParams.get('calendar_uri')
				if (calendarUri) {
					result.metadata.calendar_uri = calendarUri
				}

				// Open the chunk viewer
				this.$nextTick(() => {
					this.viewChunk(result)
				})

				// Clear URL parameters to avoid reopening on navigation
				const newUrl = window.location.pathname
				window.history.replaceState({}, '', newUrl)
			}
		},

		toggleDocType(docTypeId, checked) {
			if (checked && !this.selectedDocTypes.includes(docTypeId)) {
				this.selectedDocTypes.push(docTypeId)
			} else if (!checked) {
				const index = this.selectedDocTypes.indexOf(docTypeId)
				if (index > -1) {
					this.selectedDocTypes.splice(index, 1)
				}
			}
		},

		// Convert a native datetime-local value ("2026-01-01T00:00", local
		// wall-clock) to an RFC 3339 / ISO 8601 UTC string ("2026-01-01T…Z").
		// Returns '' for empty/invalid input so callers can omit the bound.
		toRfc3339(localValue) {
			if (!localValue) {
				return ''
			}
			const date = new Date(localValue)
			if (Number.isNaN(date.getTime())) {
				return ''
			}
			return date.toISOString()
		},

		// Short, locale-aware label for a filter chip from a datetime-local value.
		formatFilterDate(localValue) {
			const date = new Date(localValue)
			if (Number.isNaN(date.getTime())) {
				return localValue
			}
			return date.toLocaleString()
		},

		// Remove a single active filter when its chip's close button is clicked.
		removeFilter(chip) {
			if (chip.kind === 'doc') {
				this.toggleDocType(chip.value, false)
			} else if (chip.kind === 'after') {
				this.modifiedAfter = ''
			} else if (chip.kind === 'before') {
				this.modifiedBefore = ''
			} else if (chip.kind === 'path') {
				this.removeFolder(chip.value)
			}
			this.dateRangeError = null
		},

		// Remove a single selected folder from the path filter.
		removeFolder(folder) {
			this.pathPrefixes = this.pathPrefixes.filter((p) => p !== folder)
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
			// under multi-select, a single string otherwise; the guard below
			// normalizes both into an array.
			const picked = await picker.pick().catch(() => null)
			const paths = Array.isArray(picked) ? picked : [picked]
			const cleaned = paths
				.map((p) => (p || '').trim())
				.filter(Boolean)
			this.pathPrefixes = [...new Set([...this.pathPrefixes, ...cleaned])]
		},

		async performSearch() {
			const queryText = this.query.trim()
			if (!queryText) {
				return
			}

			// ADR-027: validate the modified-date range client-side so an
			// inverted range is caught in the browser, not after a round-trip.
			// The server-side guard remains the authoritative backstop.
			const modifiedAfter = this.toRfc3339(this.modifiedAfter)
			const modifiedBefore = this.toRfc3339(this.modifiedBefore)
			if (modifiedAfter && modifiedBefore
				&& new Date(modifiedAfter) > new Date(modifiedBefore)) {
				this.dateRangeError = this.t('astrolabe', '"Modified after" must be on or before "Modified before".')
				return
			}
			this.dateRangeError = null

			this.loading = true
			this.error = null
			this.searched = true
			this.coordinates = []
			this.queryCoords = []
			this.expandedExcerpts = {}

			try {
				const url = generateUrl('/apps/astrolabe/api/search')
				const params = {
					query: queryText,
					algorithm: this.algorithm,
					limit: parseInt(this.limit) || 20,
					// Skip the PCA computation entirely when the admin has
					// disabled the visualization panel.
					include_pca: this.showVisualization,
				}

				if (this.selectedDocTypes.length > 0) {
					params.doc_types = this.selectedDocTypes.join(',')
				}

				// RFC 3339 date bounds (UTC); omit open bounds entirely.
				if (modifiedAfter) {
					params.modified_after = modifiedAfter
				}
				if (modifiedBefore) {
					params.modified_before = modifiedBefore
				}

				// Path filter (files only) — only send when files are in scope so
				// it can't zero out a non-file search. Folders are joined with a
				// newline (which can't appear in a POSIX path, unlike a comma) and
				// the backend ORs them together.
				if (this.pathFilterApplicable && this.pathPrefixes.length) {
					params.path_prefixes = this.pathPrefixes.join('\n')
				}

				const response = await axios.get(url, { params })

				if (response.data.success) {
					this.results = response.data.results || []
					this.algorithmUsed = response.data.algorithm_used || this.algorithm
					this.coordinates = response.data.coordinates_3d || []
					this.queryCoords = response.data.query_coords || []

					// Render visualization after DOM updates
					if (this.coordinates.length > 0) {
						this.$nextTick(() => {
							this.renderPlot()
						})
					}
				} else {
					this.error = response.data.error || this.t('astrolabe', 'Search failed')
					this.results = []
				}
			} catch (err) {
				console.error('Search error:', err)
				if (err.response && err.response.data && err.response.data.error) {
					this.error = err.response.data.error
				} else if (err.response && err.response.status === 503) {
					this.error = this.t('astrolabe', 'Search service unavailable. Please try again later.')
				} else {
					this.error = this.t('astrolabe', 'Network error. Please try again.')
				}
				this.results = []
			} finally {
				this.loading = false
			}
		},

		async loadVectorStatus() {
			this.statusLoading = true
			this.statusError = null

			try {
				const url = generateUrl('/apps/astrolabe/api/vector-status')
				const response = await axios.get(url)

				if (response.data.success) {
					this.vectorStatus = response.data.status
				} else {
					this.statusError = response.data.error || this.t('astrolabe', 'Failed to load status')
				}
			} catch (err) {
				console.error('Status error:', err)
				if (err.response && err.response.data && err.response.data.error) {
					this.statusError = err.response.data.error
				} else {
					this.statusError = this.t('astrolabe', 'Network error. Please try again.')
				}
			} finally {
				this.statusLoading = false
			}
		},

		toggleExcerpt(index) {
			this.expandedExcerpts[index] = !this.expandedExcerpts[index]
		},

		truncateExcerpt(text, maxLength = 150) {
			if (!text || text.length <= maxLength) {
				return text
			}
			return text.substring(0, maxLength).trim() + '...'
		},

		// Tooltip for the relevance bar. Says plainly what the number is and,
		// for the sources that are not probabilities, what it is not -- the bar
		// alone would otherwise read as a confidence to anyone who has seen one
		// before.
		relevanceTitle(result) {
			// this.t, not a bare t(): App.vue never imports it — main.js wires
			// it onto globalProperties. A bare call would resolve only via
			// Nextcloud's legacy l10n global, and since this runs from the
			// template for every rendered row, its absence would throw during
			// render rather than just return an untranslated string.
			const pct = this.relevancePercent(result)
			if (this.relevanceIsCalibrated(result)) {
				return this.t('astrolabe', 'Relevance {pct}% — an estimated probability this result is relevant to your query. Relative to what was found: search always returns its best matches, even when nothing in your files answers the question.', { pct })
			}
			return this.t('astrolabe', 'Relevance {pct}% — ranks results against each other, but is not a probability on this server. Enable reranking for a calibrated score.', { pct })
		},

		// Clamped rather than trusting the [0,1] contract: an out-of-range value
		// from a future server would otherwise render a negative-width or
		// >100% bar, which looks like a UI bug rather than a data problem.
		relevancePercent(result) {
			return Math.round(Math.min(1, Math.max(0, result.relevance ?? 0)) * 100)
		},

		getDocumentUrl(result) {
			const docType = result.doc_type || 'unknown'
			const id = result.id || result.note_id
			const metadata = result.metadata || {}

			switch (docType) {
				case 'note':
					return generateUrl(`/apps/notes/#/note/${id}`)
				case 'file':
					if (id) {
						return generateUrl(`/apps/files/files/${id}?dir=/&editing=false&openfile=true`)
					}
					return generateUrl('/apps/files/')
				case 'deck_card':
					if (metadata.board_id && id) {
						return generateUrl(`/apps/deck/board/${metadata.board_id}/card/${id}`)
					}
					return generateUrl('/apps/deck/')
				case 'calendar':
				case 'calendar_event':
					return generateUrl('/apps/calendar/')
				case 'news_item':
				// Use external article URL if available, otherwise fall back to News app
					if (metadata.url) {
						return metadata.url
					}
					return generateUrl('/apps/news/')
				case 'mail_message':
				// The Mail app's per-message route is a client-side hash built
				// from account/mailbox/thread ids that aren't carried in the
				// indexed metadata, so there's no stable deep link to a single
				// message — fall back to the Mail app root.
					return generateUrl('/apps/mail/')
				case 'contact':
					return generateUrl('/apps/contacts/')
				default:
					return generateUrl('/apps/astrolabe/')
			}
		},

		navigateToDocument(result) {
			const url = this.getDocumentUrl(result)
			window.open(url, '_blank')
		},

		goToSettings() {
			window.location.href = generateUrl('/settings/user/astrolabe')
		},

		renderPlot() {
			const container = document.getElementById('viz-plot-container')
			if (!container) {
				return
			}

			const width = container.clientWidth
			const height = container.clientHeight || 400

			const queryCoords = this.queryCoords

			// Single source of truth for the threshold + coord guard.
			const { results, coordinates } = this.filteredResultsAndCoords

			// Snapshot the filtered subset that will actually be rendered.
			// handlePlotClick indexes into this — not this.results — because
			// Plotly's pointIndex refers to the rendered trace data.
			this._renderedResults = results

			const scores = results.map((r) => r.score)

			// Trace 1: Document results (always visible)
			const documentTrace = {
				x: coordinates.map((c) => c[0]),
				y: coordinates.map((c) => c[1]),
				z: coordinates.map((c) => c[2]),
				mode: 'markers',
				type: 'scatter3d',
				name: 'Documents',
				visible: true,
				customdata: results.map((r, i) => ({
					title: r.title,
					raw_score: r.original_score || r.score,
					relative_score: r.score,
					x: coordinates[i][0],
					y: coordinates[i][1],
					z: coordinates[i][2],
				})),

				hovertemplate:
					'<b>%{customdata.title}</b><br>'
					+ 'Raw Score: %{customdata.raw_score:.3f} (%{customdata.relative_score:.0%} relative)<br>'
					+ '(x=%{customdata.x}, y=%{customdata.y}, z=%{customdata.z})'
					+ '<extra></extra>',

				hoverlabel: {
					bgcolor: '#0082c9',
					bordercolor: '#0082c9',
					font: {
						size: 15,
						color: 'white',
					},
				},

				marker: {
					size: results.map((r) => 4 + (Math.pow(r.score, 2) * 10)),
					opacity: results.map((r) => 0.3 + (r.score * 0.7)),
					color: scores,
					colorscale: 'Viridis',
					showscale: true,
					colorbar: {
						title: { text: 'Relative Score' },
						x: 1.02,
						xanchor: 'left',
						thickness: 20,
						len: 0.8,
					},

					cmin: 0,
					cmax: 1,
				},
			}

			// Trace 2: Query point (visibility controlled by toggle)
			const queryTrace = {
				x: [queryCoords[0]],
				y: [queryCoords[1]],
				z: [queryCoords[2]],
				mode: 'markers',
				type: 'scatter3d',
				name: 'Query',
				visible: this.showQueryPoint,
				hovertemplate:
					'<b>Search Query</b><br>'
					+ `(x=${queryCoords[0]}, y=${queryCoords[1]}, z=${queryCoords[2]})`
					+ '<extra></extra>',

				marker: {
					size: 10,
					color: '#ef5350', // Subdued red (Material Design Red 400)
					line: {
						color: '#c62828', // Darker red border (Material Design Red 800)
						width: 1,
					},
				},
			}

			const layout = {
				title: { text: `Vector Space (PCA 3D) - ${results.length} results` },
				width,
				height,
				scene: {
					xaxis: { title: { text: 'PC1' } },
					yaxis: { title: { text: 'PC2' } },
					zaxis: { title: { text: 'PC3' } },
					camera: {
						eye: { x: 1.5, y: 1.5, z: 1.5 },
					},

					domain: {
						x: [0, 1],
						y: [0, 1],
					},
				},

				hovermode: 'closest',
				autosize: true,
				showlegend: false,
				margin: { l: 0, r: 100, t: 40, b: 0 },
			}

			const traces = [documentTrace, queryTrace]

			const config = {
				responsive: true,
				displayModeBar: true,
			}

			Plotly.newPlot('viz-plot', traces, layout, config)

			// Register click event handler for result points. Plotly.newPlot
			// reuses the same DOM node, so listeners stack across re-renders —
			// remove any prior handler before attaching a fresh one.
			const plotDiv = document.getElementById('viz-plot')
			if (plotDiv) {
				plotDiv.removeAllListeners('plotly_click')
				plotDiv.on('plotly_click', this.handlePlotClick)
			}
		},

		updatePlot() {
			// Toggle query point visibility without recreating the plot
			if (this.coordinates.length > 0 && this.queryCoords.length > 0 && this.results.length > 0) {
				const plotDiv = document.getElementById('viz-plot')

				if (plotDiv && plotDiv.data && plotDiv.data.length >= 2) {
					// Trace index 1 is the query point
					Plotly.restyle('viz-plot', { visible: this.showQueryPoint }, [1])
				} else {
					// Plot doesn't exist yet, render it
					this.renderPlot()
				}
			}
		},

		async viewChunk(result) {
			// Guard against concurrent loading
			if (this.viewerLoading) {
				return
			}

			this.showViewer = true
			this.viewerLoading = true
			this.viewerTitle = result.title || 'Chunk Viewer'
			this.currentResult = result // Store result for document linking
			// Switching documents must not leave the previous one's summary on
			// screen, nor let its in-flight request write over this one.
			this.resetSummary()

			try {
				// Fetch chunk context
				const url = generateUrl('/apps/astrolabe/api/chunk-context')
				const params = {
					doc_type: result.doc_type,
					doc_id: result.id,
					start: result.chunk_start_offset,
					end: result.chunk_end_offset,
				}
				// Pass chunk_index/total_chunks when known so the MCP server can
				// look up the chunk by the always-indexed chunk_index field
				// (faster and more robust than offset-based filtering).
				if (result.chunk_index !== undefined && result.chunk_index !== null) {
					params.chunk_index = result.chunk_index
				}
				if (result.total_chunks !== undefined && result.total_chunks !== null) {
					params.total_chunks = result.total_chunks
				}
				// Identifiers the astrolabe-side access check needs for non-file
				// doc types (files/notes are keyed by doc_id). Absent values just
				// fall through to the MCP server's verify-on-read backstop.
				const meta = result.metadata || {}
				if (meta.board_id !== undefined && meta.board_id !== null) {
					params.board_id = meta.board_id
				}
				if (meta.mailbox_id !== undefined && meta.mailbox_id !== null) {
					params.mailbox_id = meta.mailbox_id
				}
				if (meta.calendar_uri) {
					params.calendar_uri = meta.calendar_uri
				}
				if (meta.path) {
					params.path = meta.path
				}

				const response = await axios.get(url, { params })

				if (response.data.success) {
					// Determine viewer type and setup
					if (this.isPdfRenderable(result) && response.data.page_number) {
						this.viewerType = 'pdf'
						this.currentPdfPath = result.metadata?.path || ''
						// result.id is the Nextcloud fileId for file doc types.
						this.currentPdfDocId = Number.isInteger(result.id) ? result.id : (parseInt(result.id, 10) || null)
						this.viewerPage = response.data.page_number
						this.currentBbox = response.data.chunk_bbox || []
						this.currentBboxPage = response.data.page_number
					} else {
						this.viewerType = 'text'
						this.viewerContext = {
							chunk: response.data.chunk_text,
							before: response.data.before_context,
							after: response.data.after_context,
						}
					}
				} else {
					console.error('Failed to load chunk:', response.data.error)
					this.closeViewer()
				}
			} catch (err) {
				// 403 = access revoked since indexing (the staleness the local
				// check guards). Surface a friendly message instead of a stack trace.
				if (err.response && err.response.status === 403) {
					this.error = this.t('astrolabe', 'You no longer have access to this document.')
				} else {
					console.error('Error loading chunk:', err)
				}
				this.closeViewer()
			} finally {
				this.viewerLoading = false
			}
		},

		handlePdfError(error) {
			console.error('PDF viewer error:', error)
			this.viewerType = 'text'
		},

		handlePdfLoaded(event) {
			this.pdfTotalPages = event.totalPages || 0
		},

		getMarkdownContent() {
			// Combine before/chunk/after context into single markdown string
			let content = ''

			if (this.viewerContext.before) {
				content += this.viewerContext.before + '\n\n'
			}

			if (this.viewerContext.chunk) {
				// Highlight the main chunk with a separator
				content += '---\n\n'
				content += this.viewerContext.chunk
				content += '\n\n---'
			}

			if (this.viewerContext.after) {
				content += '\n\n' + this.viewerContext.after
			}

			return content
		},

		closeViewer() {
			this.showViewer = false
			this.pdfTotalPages = 0
			this.currentResult = null
			this.currentBbox = []
			this.currentBboxPage = null
			this.resetSummary()
		},

		resetSummary() {
			// Bumping the generation orphans any in-flight request, so its result
			// is discarded rather than written against whatever is now on screen.
			this.summaryGeneration++
			this.summaryLoading = false
			this.summaryText = ''
			this.summaryMode = ''
			this.summaryError = ''
		},

		/**
		 * Summarize the passage currently shown in the viewer.
		 *
		 * Deliberately scoped to the passage, not the document. The MCP server's
		 * chunk-context endpoint is page-scoped — asking for its widest window on a
		 * 216k-character PDF returns about 4k characters and reports that nothing
		 * further exists, because "more" means more of this page — and it rejects
		 * offsets that don't match an indexed chunk, so the document cannot be
		 * walked a window at a time either. Whole-document summarization needs the
		 * model to fetch the file through MCP tools instead; see the agent work.
		 *
		 * PDF pages are rasterized here rather than server-side: rendering PDFs on
		 * the MCP server is what previously OOMKilled its API pod, so the browser —
		 * which already has the document open and decoded — does it instead. An
		 * image document needs no rendering at all and is passed by file id.
		 *
		 * Falls through to the text tier whenever there are no pages to send, so a
		 * capture failure degrades the summary rather than failing the request.
		 */
		async summarizeSection() {
			const result = this.currentResult
			if (!result || this.summaryLoading) {
				return
			}

			this.resetSummary()
			// Claim this generation after the reset bumped it: every write below
			// is conditional on the viewer still showing the document this request
			// was made for.
			const generation = this.summaryGeneration
			this.summaryLoading = true

			try {
				const form = new FormData()
				form.append('doc_type', result.doc_type ?? '')
				form.append('doc_id', String(result.id ?? ''))
				form.append('start', String(result.chunk_start_offset ?? 0))
				form.append('end', String(result.chunk_end_offset ?? 0))
				if (result.chunk_index !== undefined && result.chunk_index !== null) {
					form.append('chunk_index', String(result.chunk_index))
				}
				if (result.total_chunks !== undefined && result.total_chunks !== null) {
					form.append('total_chunks', String(result.total_chunks))
				}
				// Access-check identifiers, so the backend can re-verify this user
				// still reaches the document before anything is generated.
				for (const key of ['board_id', 'mailbox_id', 'calendar_uri', 'path']) {
					const value = result.metadata?.[key]
					if (value !== undefined && value !== null && value !== '') {
						form.append(key, String(value))
					}
				}

				for (const blob of await this.capturePagesForSummary()) {
					form.append('pages[]', blob, 'page.png')
				}
				for (const fileId of this.imageFileIdsForSummary(result)) {
					form.append('image_file_ids[]', String(fileId))
				}

				const { data } = await axios.post(
					generateUrl('/apps/astrolabe/api/v1/summary'),
					form,
				)
				const text = await this.awaitSummaryTask(data.task_id)
				if (generation !== this.summaryGeneration) {
					return
				}
				this.summaryMode = data.mode ?? ''
				this.summaryText = text
			} catch (error) {
				if (generation !== this.summaryGeneration) {
					return
				}
				console.error('Summary failed:', error)
				this.summaryError = error.response?.data?.error
					?? this.t('astrolabe', 'Could not summarize this document.')
			} finally {
				// Only the request that still owns the viewer may clear the
				// spinner: a superseded one would stop the current request's.
				if (generation === this.summaryGeneration) {
					this.summaryLoading = false
				}
			}
		},

		/**
		 * Whether this result's own bytes are a PDF the viewer can render.
		 *
		 * A page number alone is no longer sufficient. The MCP server now indexes
		 * Word documents by rendering them to PDF, so a .doc/.docx chunk carries a
		 * page number and a bbox — but those describe the *rendition*, and
		 * currentPdfPath points at the original file, which pdf.js cannot open.
		 * Choosing the PDF viewer on page number alone therefore turns every
		 * office-document hit into a failed load. Such results fall back to the
		 * text viewer until the server can serve the rendition itself.
		 *
		 * Spreadsheets and .msg never carry a page number and are unaffected.
		 *
		 * @param {object} result The search result currently open.
		 * @return {boolean} True when the stored file is itself a PDF.
		 */
		isPdfRenderable(result) {
			if (result.doc_type !== 'file') {
				return false
			}
			const mime = result.metadata?.mime_type
			if (typeof mime === 'string' && mime) {
				return mime.split(';')[0].trim().toLowerCase() === 'application/pdf'
			}
			// Older index entries predate mime_type in the payload; fall back to
			// the path so they keep rendering instead of silently degrading.
			const path = result.metadata?.path
			return typeof path === 'string' && /\.pdf$/i.test(path.trim())
		},

		/**
		 * An image document is already the picture a vision model needs, so it is
		 * passed by file id with nothing staged.
		 *
		 * @param {object} result The search result currently open.
		 * @return {number[]} File ids to send, if any.
		 */
		imageFileIdsForSummary(result) {
			const isImage = result.doc_type === 'file'
				&& typeof result.metadata?.mime_type === 'string'
				&& result.metadata.mime_type.startsWith('image/')
			const fileId = Number(result.id)
			return isImage && Number.isInteger(fileId) && fileId > 0 ? [fileId] : []
		},

		/**
		 * Rasterize a window of PDF pages around the one being viewed.
		 *
		 * A window rather than the whole document, because vision tokens dominate
		 * the cost of these calls and the backend caps the count anyway. Centring
		 * on the current page keeps the summary about the part the user is actually
		 * looking at.
		 *
		 * @return {Promise<Blob[]>} PNG blobs, or an empty array when this is not a PDF.
		 */
		async capturePagesForSummary() {
			const viewer = this.$refs.pdfViewer
			if (this.viewerType !== 'pdf' || !viewer?.capturePages) {
				return []
			}

			const total = this.pdfTotalPages || 1
			const first = Math.max(1, Math.min(this.viewerPage, total) - 1)
			const pages = []
			for (let page = first; page <= total && pages.length < SUMMARY_PAGE_WINDOW; page++) {
				pages.push(page)
			}

			try {
				return await viewer.capturePages(pages)
			} catch (error) {
				// Degrade to the text tier rather than failing the whole request:
				// a summary without layout beats no summary.
				console.error('Page capture failed, falling back to text:', error)
				return []
			}
		},

		/**
		 * Poll core's TaskProcessing endpoint until the task settles.
		 *
		 * Core owns task state, so this reads it directly instead of proxying model
		 * output back through an endpoint of ours.
		 *
		 * @param {number} taskId The scheduled task.
		 * @return {Promise<string>} The generated summary.
		 */
		async awaitSummaryTask(taskId) {
			const deadline = Date.now() + SUMMARY_TIMEOUT_MS

			while (Date.now() < deadline) {
				await new Promise((resolve) => setTimeout(resolve, SUMMARY_POLL_MS))

				const { data } = await axios.get(generateOcsUrl('taskprocessing/task/{taskId}', { taskId }))
				const task = data?.ocs?.data?.task
				if (!task) {
					continue
				}
				if (task.status === 'STATUS_SUCCESSFUL') {
					return task.output?.output ?? ''
				}
				if (task.status === 'STATUS_FAILED' || task.status === 'STATUS_CANCELLED') {
					throw new Error(task.userFacingErrorMessage || task.errorMessage || 'task failed')
				}
			}

			throw new Error('summary timed out')
		},

		handlePlotClick(eventData) {
			// Only handle clicks on trace 0 (document results)
			// Trace 1 is the query point - ignore clicks on it
			if (!eventData.points || eventData.points.length === 0) {
				return
			}

			const point = eventData.points[0]
			const traceIndex = point.curveNumber // 0 = documents, 1 = query
			const pointIndex = point.pointNumber // Index in trace data

			// Ignore clicks on query point (trace 1)
			if (traceIndex !== 0) {
				return
			}

			// Index into the rendered (filtered) subset, not this.results.
			// pointIndex refers to the trace data Plotly painted, which may
			// be a subset of this.results when scoreThreshold is non-zero.
			const result = this._renderedResults[pointIndex]

			if (!result) {
				console.warn('Click handler: result not found for index', pointIndex)
				return
			}

			// Call existing viewChunk method
			this.viewChunk(result)
		},
	},
}
</script>

<style scoped lang="scss">
.mcp-section {
	/* Standard Nextcloud app padding - matches Deck/core spacing */
	padding: 44px 24px 24px var(--default-clickable-area);
	/* Remove max-width to allow content to fill available space like Notes app */
	min-height: calc(100vh - 150px); /* Ensure content extends to bottom of viewport */
}

.mcp-section-header {
	margin-bottom: 24px;

	h2 {
		margin: 0 0 8px 0;
		font-size: 22px;
		font-weight: 600;
	}

	.mcp-description {
		color: var(--color-text-maxcontrast);
		margin: 0;
	}
}

// Search card
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

.mcp-checkbox-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 8px;
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

// Loading and error states
.mcp-loading {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 12px;
	padding: 48px;
	color: var(--color-text-maxcontrast);
}

.mcp-error {
	margin: 16px 0;
}

.mcp-error-detail {
	margin-top: 6px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	overflow-wrap: break-word;
	font-family: monospace;
}

// Visualization
.mcp-viz-container {
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large);
	padding: 16px;
	margin-bottom: 24px;
}

.mcp-viz-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 12px;

	h3 {
		margin: 0;
		font-size: 16px;
		font-weight: 600;
	}
}

.mcp-viz-plot-container {
	width: 100%;
	height: 400px;
	background: var(--color-main-background);
	border-radius: var(--border-radius);
}

#viz-plot {
	width: 100%;
	height: 100%;

	// Pointer cursor for clickable result points (trace 0)
	:deep(.scatterlayer .trace:first-child .point) {
		cursor: pointer !important;
	}

	// Default cursor for query point (trace 1)
	:deep(.scatterlayer .trace:nth-child(2) .point) {
		cursor: default !important;
	}
}

// Results
.mcp-results {
	margin-top: 24px;
}

.mcp-results-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
	padding-bottom: 12px;
	border-bottom: 1px solid var(--color-border);
	color: var(--color-text-maxcontrast);
}

.mcp-algorithm-badge {
	padding: 4px 10px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 600;
	text-transform: uppercase;
	background: var(--color-background-dark);
}

.mcp-filter-info {
	font-size: 12px;
	color: var(--color-text-lighter);
	font-weight: normal;
}

.mcp-results-list {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.mcp-result-item {
	padding: 16px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large);
	border-inline-start: 4px solid var(--color-primary-element);
	transition: transform 0.15s, box-shadow 0.15s;

	&:hover {
		transform: translateX(4px);
		box-shadow: 0 2px 12px rgba(0, 0, 0, 0.1);
	}
}

.mcp-result-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 8px;
}

.mcp-result-type {
	padding: 2px 8px;
	border-radius: 10px;
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
}

// Document type colors
.mcp-doc-type-note {
	border-inline-start-color: #1565c0;
	.mcp-result-type { background: #e3f2fd; color: #1565c0; }
}

.mcp-doc-type-file {
	border-inline-start-color: #2e7d32;
	.mcp-result-type { background: #e8f5e9; color: #2e7d32; }
}

.mcp-doc-type-deck_card {
	border-inline-start-color: #ef6c00;
	.mcp-result-type { background: #fff3e0; color: #ef6c00; }
}

.mcp-doc-type-calendar {
	border-inline-start-color: #c2185b;
	.mcp-result-type { background: #fce4ec; color: #c2185b; }
}

.mcp-doc-type-contact {
	border-inline-start-color: #7b1fa2;
	.mcp-result-type { background: #f3e5f5; color: #7b1fa2; }
}

.mcp-doc-type-news_item {
	border-inline-start-color: #00838f;
	.mcp-result-type { background: #e0f7fa; color: #00838f; }
}

.mcp-doc-type-mail_message {
	border-inline-start-color: #5e35b1;
	.mcp-result-type { background: #ede7f6; color: #5e35b1; }
}

.mcp-result-title {
	font-weight: 600;
	font-size: 15px;
	color: var(--color-main-text);
	margin-bottom: 6px;
	line-height: 1.4;
	text-decoration: none;
	display: block;

	&:hover {
		color: var(--color-primary-element);
	}
}

a.mcp-result-title {
	cursor: pointer;
}

.mcp-relevance {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-top: 6px;
}

.mcp-relevance-bar {
	flex: 0 0 96px;
	height: 4px;
	border-radius: 2px;
	background: var(--color-background-darker);
	overflow: hidden;
}

.mcp-relevance-fill {
	height: 100%;
	border-radius: 2px;
	background: var(--color-primary-element);
}

.mcp-relevance-value {
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
}

.mcp-result-metadata {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-bottom: 6px;
	line-height: 1.4;
}

.mcp-result-actions {
	display: flex;
	align-items: center;
	gap: 8px;
}

.mcp-external-icon {
	opacity: 0.5;
	margin-inline-start: 4px;
	vertical-align: middle;
}

.mcp-result-title:hover .mcp-external-icon {
	opacity: 1;
}

.mcp-score-slider {
	width: 100%;
	margin-top: 8px;
	accent-color: var(--color-primary-element);
}

// Status section
.mcp-status-cards {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
	gap: 16px;
	margin-bottom: 24px;
}

.mcp-status-card {
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large);
	padding: 20px;
	text-align: center;
}

.mcp-status-label {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	margin-bottom: 8px;
}

.mcp-status-value {
	font-size: 24px;
	font-weight: 600;
	color: var(--color-main-text);

	&.status-idle { color: var(--color-success-text); }
	&.status-syncing { color: var(--color-warning-text); }
	&.status-error { color: var(--color-error-text); }
}

// Navigation footer
.app-navigation-entry__settings {
	height: auto !important;
	overflow: hidden !important;
	flex: 0 0 auto;
	padding: 3px;
	padding-top: 0 !important;
	margin: 0 3px;
}

// Modal
.mcp-modal-overlay {
	position: fixed;
	top: 0;
	inset-inline: 0;
	bottom: 0;
	background: rgba(0, 0, 0, 0.5);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 10000;
}

.mcp-modal {
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	width: 90%;
	max-width: 900px;
	height: 80vh;
	display: flex;
	flex-direction: column;
	box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
}

.mcp-modal-header {
	padding: 16px 20px;
	border-bottom: 1px solid var(--color-border);
	display: flex;
	justify-content: space-between;
	align-items: center;

	h3 {
		margin: 0;
		font-size: 18px;
		font-weight: 600;
		flex: 1;
		min-width: 0; // Allow text truncation if needed
	}
}

// Summarize + Close sit together in the header; without an explicit gap they
// render flush against each other, matching .mcp-modal-footer's spacing.
.mcp-modal-actions {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-shrink: 0;
}

.mcp-modal-title-link {
	color: var(--color-main-text);
	text-decoration: none;
	display: inline-flex;
	align-items: center;
	gap: 6px;
	transition: color 0.15s;

	&:hover {
		color: var(--color-primary-element);

		.mcp-modal-title-icon {
			opacity: 1;
		}
	}
}

.mcp-modal-title-icon {
	opacity: 0.5;
	transition: opacity 0.15s;
	flex-shrink: 0;
}

.mcp-modal-body {
	flex: 1;
	overflow: auto;
	padding: 20px;
	position: relative;
}

.mcp-modal-footer {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 16px;
	padding: 16px 20px;
	border-top: 1px solid var(--color-border);
	background: var(--color-main-background);
	flex-shrink: 0;

	.mcp-page-info {
		font-size: 14px;
		color: var(--color-text-maxcontrast);
		min-width: 150px;
		text-align: center;
	}
}

.mcp-viewer-loading {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	height: 100%;
	color: var(--color-text-lighter);
	gap: 16px;
}

.mcp-text-viewer {
	font-family: monospace;
	line-height: 1.6;
	white-space: pre-wrap;
}

.mcp-context-text {
	color: var(--color-text-lighter);
}

.mcp-highlighted-chunk {
	background: #fff9c4;
	color: #000;
	padding: 4px;
	border-radius: 2px;
	font-weight: bold;
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

	.mcp-modal {
		width: 100%;
		height: 100%;
		border-radius: 0;
	}
}
</style>

<style lang="scss">
/* Fix for double margin/padding issue when nested in #content */
#content-vue {
	margin-top: 0 !important;
	margin-inline-start: 0 !important;
}
</style>
