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
					@click="activeSection = 'status'; $refs.indexStatus.load()">
					<template #icon>
						<ChartBox :size="20" />
					</template>
				</NcAppNavigationItem>

				<NcAppNavigationItem
					v-if="sarAvailable"
					:name="t('astrolabe', 'Subject Access Request')"
					:active="activeSection === 'sar'"
					@click="activeSection = 'sar'">
					<template #icon>
						<ShieldAccount :size="20" />
					</template>
					<template #counter>
						<NcCounterBubble v-if="activeSarCase && sarCaseKeys.length" :count="sarCaseKeys.length" />
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

				<NcNoteCard v-if="sarAvailable && activeSarCase" type="info" class="sar-collecting">
					<p>
						<template v-if="sarCollecting">
							{{ t('astrolabe', 'Searching for SAR case {name}: one result per document. Each search is logged in the case with its filters.', { name: activeSarCase.name }) }}
						</template>
						<template v-else>
							{{ t('astrolabe', 'SAR case {name} is not open, so searches are not added to it.', { name: activeSarCase.name }) }}
						</template>
						<NcButton v-if="!showSarSidebar" variant="tertiary" @click="showSarSidebar = true">
							{{ t('astrolabe', 'Show case') }}
						</NcButton>
						<NcButton variant="tertiary" @click="stopSarCase">
							{{ t('astrolabe', 'Leave case') }}
						</NcButton>
					</p>
				</NcNoteCard>

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
								<label for="mcp-granularity">{{ t('astrolabe', 'Results per document') }}</label>
								<select id="mcp-granularity" v-model="granularity" class="mcp-select">
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
									v-model.number="minRelevance"
									type="range"
									min="0"
									max="100"
									step="5"
									class="mcp-score-slider"
									:disabled="!relevanceApplies"
									aria-describedby="mcp-minimum-relevance-hint">
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
					<!-- A search that needs the embedding service can wait on it
						 for a while (e.g. a GPU starting up): say so, and let the
						 user stop waiting. -->
					<template v-if="searchSlow">
						<span class="mcp-slow-hint">
							{{ t('astrolabe', 'Still searching. The embedding service may be starting up.') }}
						</span>
						<NcButton variant="tertiary" @click="cancelSearch">
							{{ t('astrolabe', 'Cancel') }}
						</NcButton>
					</template>
				</div>

				<!-- Timed out: the embedding service did not answer. Keyword search
					 does not need it, so offer that instead of a dead end. -->
				<NcNoteCard v-if="searchTimedOut" type="warning" class="mcp-error">
					<div>{{ error }}</div>
					<div class="mcp-timeout-actions">
						<NcButton variant="secondary" @click="performSearch(retryLoadMore)">
							{{ t('astrolabe', 'Try again') }}
						</NcButton>
						<NcButton
							v-if="canSearchByKeyword"
							variant="primary"
							@click="searchByKeyword">
							{{ t('astrolabe', 'Search by keyword instead') }}
						</NcButton>
					</div>
				</NcNoteCard>

				<!-- Error State -->
				<NcNoteCard v-else-if="error" type="error" class="mcp-error">
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
							<span v-if="rerankedUsed" class="mcp-filter-info">
								· {{ t('astrolabe', 'reranked') }}
							</span>
						</span>
						<NcButton
							v-if="sarCollecting"
							variant="secondary"
							class="mcp-add-all-to-sar"
							:disabled="addingAllToSar || filteredResults.every((r) => inSar(r))"
							@click="addAllToSar">
							<template #icon>
								<NcLoadingIcon v-if="addingAllToSar" :size="18" />
								<PlaylistPlus v-else :size="18" />
							</template>
							{{ algorithmUsed === 'exact' && sarTotal > 0
								? n('astrolabe', 'Add all %n match to SAR', 'Add all %n matches to SAR', sarTotal)
								: t('astrolabe', 'Add all to SAR') }}
						</NcButton>
						<span class="mcp-algorithm-badge">{{ algorithmUsed }}</span>
					</div>

					<!-- 3D Visualization -->
					<SearchResultsPlot
						v-if="showVisualization && coordinates.length > 0"
						ref="resultsPlot"
						v-model:showQueryPoint="showQueryPoint"
						:results="filteredResultsAndCoords.results"
						:coordinates="filteredResultsAndCoords.coordinates"
						:queryCoords="queryCoords"
						:scoreRange="scoreRange"
						:uncalibratedRange="uncalibratedRange"
						@select="viewChunk" />

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
										v-if="!isImageHit(result)"
										variant="tertiary"
										:aria-label="t('astrolabe', 'Show Chunk')"
										@click="viewChunk(result)">
										<template #icon>
											<Eye :size="18" />
										</template>
										{{ t('astrolabe', 'Show Chunk') }}
									</NcButton>
									<NcButton
										v-if="result.doc_type === 'file'"
										variant="tertiary"
										@click="mcpApp = { fileId: parseInt(result.id, 10), chunkIndex: result.chunk_index ?? null }">
										{{ t('astrolabe', 'Open in App') }}
									</NcButton>
									<NcButton
										v-if="sarCollecting"
										variant="tertiary"
										class="mcp-add-to-sar"
										:disabled="inSar(result)"
										@click="addToSar(result)">
										<template #icon>
											<PlaylistCheck v-if="inSar(result)" :size="18" />
											<PlaylistPlus v-else :size="18" />
										</template>
										{{ inSar(result) ? t('astrolabe', 'In SAR') : t('astrolabe', 'Add to SAR') }}
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
							<!-- An image hit matched what the picture shows, not a passage of
								text: it has no chunk to view, so show the picture itself. -->
							<a
								v-if="isImageHit(result) && !brokenPreviews[result.id]"
								:href="getDocumentUrl(result)"
								class="mcp-result-preview"
								tabindex="-1"
								aria-hidden="true"
								@click.prevent="navigateToDocument(result)">
								<!-- Decorative: the title link beside it names the same file. -->
								<img
									:src="imagePreviewUrl(result)"
									alt=""
									loading="lazy"
									@error="brokenPreviews[result.id] = true">
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
							<div v-if="typeof result.relevance === 'number' && result.relevance_source !== 'exact_match'" class="mcp-relevance">
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
					<NcButton
						v-if="sarCollecting && sarHasMore"
						class="mcp-load-more"
						:disabled="loadingMore"
						@click="performSearch(true)">
						<template #icon>
							<NcLoadingIcon v-if="loadingMore" :size="20" />
						</template>
						{{ t('astrolabe', 'Load more') }}
					</NcButton>
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

			<!-- Subject Access Request Section -->
			<div v-if="sarAvailable" v-show="activeSection === 'sar'" class="mcp-section">
				<div class="mcp-section-header">
					<h2>{{ t('astrolabe', 'Subject Access Request') }}</h2>
				</div>
				<SarCases
					ref="sarCases"
					:activeCaseId="activeSarCase ? activeSarCase.id : null"
					@activate="activateSarCase" />
			</div>

			<!-- Index Status Section -->
			<IndexStatus v-show="activeSection === 'status'" ref="indexStatus" />
		</NcAppContent>

		<!-- The active SAR case, beside the search it is collected from. -->
		<NcAppSidebar
			v-if="sarAvailable && activeSarCase && showSarSidebar && activeSection === 'search'"
			class="sar-sidebar"
			:name="activeSarCase.name"
			:subname="t('astrolabe', 'Subject access request')"
			@close="showSarSidebar = false">
			<SarCasePanel
				ref="sarPanel"
				:caseId="activeSarCase.id"
				@changed="onSarCaseChanged" />
		</NcAppSidebar>

		<!-- PDF/Chunk Viewer Modal -->
		<DocumentViewer
			ref="documentViewer"
			:summaryModes="summaryModes"
			@error="error = $event" />

		<McpAppHost
			v-if="mcpApp"
			:fileId="mcpApp.fileId"
			:chunkIndex="mcpApp.chunkIndex"
			@close="mcpApp = null" />
	</NcContent>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { FilePickerType, getFilePickerBuilder, showError } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppSidebar from '@nextcloud/vue/components/NcAppSidebar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcCounterBubble from '@nextcloud/vue/components/NcCounterBubble'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ChartBox from 'vue-material-design-icons/ChartBox.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import Close from 'vue-material-design-icons/Close.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import FolderSearch from 'vue-material-design-icons/FolderSearch.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import PlaylistCheck from 'vue-material-design-icons/PlaylistCheck.vue'
import PlaylistPlus from 'vue-material-design-icons/PlaylistPlus.vue'
import ShieldAccount from 'vue-material-design-icons/ShieldAccount.vue'
import DocumentViewer from './components/DocumentViewer.vue'
import IndexStatus from './components/IndexStatus.vue'
import McpAppHost from './components/McpAppHost.vue'
import SarCasePanel from './components/SarCasePanel.vue'
import SarCases from './components/SarCases.vue'
import SearchResultsPlot from './components/SearchResultsPlot.vue'
import { documentUrl } from './utils/documentUrl.js'
import { hasUncalibrated as anyUncalibrated, scoreRange as computeScoreRange, displayRelevance, relevancePredicate, UNCALIBRATED } from './utils/relevance.js'

// The active SAR case is a per-browser convenience; the case itself is on the
// server. Storage can be unavailable (private windows), so both are guarded.
// Per user: a shared browser profile must not carry one user's case to another.
const ACTIVE_SAR_CASE_KEY = `astrolabe.activeSarCase.${getCurrentUser()?.uid ?? ''}`
// Case items per request: the MCP server's maximum page.
const SAR_PAGE = 1000
// MAX_ITEMS_PER_CALL on the MCP server's /sar/cases/{id}/items.
const SAR_ITEMS_PER_CALL = 1000
// After this long a search is probably waiting on the embedding service.
const SLOW_SEARCH_MS = 8000

function readActiveSarCase() {
	try {
		const value = JSON.parse(window.localStorage.getItem(ACTIVE_SAR_CASE_KEY))
		return value && Number.isInteger(value.id) ? value : null
	} catch {
		return null
	}
}

function writeActiveSarCase(value) {
	try {
		if (value) {
			window.localStorage.setItem(ACTIVE_SAR_CASE_KEY, JSON.stringify(value))
		} else {
			window.localStorage.removeItem(ACTIVE_SAR_CASE_KEY)
		}
	} catch {
		// Storage unavailable: the case simply is not remembered.
	}
}

export default {
	name: 'App',
	components: {
		NcContent,
		NcAppNavigation,
		NcAppNavigationItem,
		NcAppContent,
		NcAppSidebar,
		NcButton,
		NcTextField,
		NcTextArea,
		NcSelect,
		NcLoadingIcon,
		NcNoteCard,
		NcEmptyContent,
		NcCheckboxRadioSwitch,
		DocumentViewer,
		IndexStatus,
		SarCasePanel,
		SarCases,
		SearchResultsPlot,
		McpAppHost,
		NcCounterBubble,
		PlaylistCheck,
		PlaylistPlus,
		ShieldAccount,
		Magnify,
		ChartBox,
		Cog,
		ChevronDown,
		ChevronUp,
		OpenInNew,
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
			// Result shape: 'chunk' (best-matching passages, a long document can
			// occupy several rows) or 'document' (one row per document). The
			// server supported this all along; this page never asked for it.
			granularity: 'chunk',
			// The one relevance control, percent. Sent as `min_relevance`, which
			// the server applies before the trim to `limit` so the page
			// backfills with qualifying rows, and applied to the rows already
			// shown (isRelevant) so moving it takes effect at once. It used to
			// be two sliders, one per side; on a server that reports
			// `relevance` both cut on the same value, so one is enough.
			minRelevance: 0,
			// Image hits whose /core/preview failed: the row drops the
			// thumbnail rather than show a broken-image icon.
			brokenPreviews: {},
			// Whether the LAST response was actually reranked. The server asks
			// for it whenever it can, but reranking degrades to retrieval order
			// instead of failing, so this reports what happened.
			rerankedUsed: false,
			loading: false,
			error: null,
			// A search still running after SLOW_SEARCH_MS, and one that timed
			// out waiting on the embedding service (the server's 504).
			searchSlow: false,
			searchTimedOut: false,
			retryLoadMore: false,
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
			// MCP App host (POC): { fileId, chunkIndex } or null
			mcpApp: null,
			// Summary state. `summaryModes` comes from the backend and lists the
			// tiers a TaskProcessing provider can actually serve — empty means no
			// provider is installed, so the action is hidden rather than offered
			// and always failing.
			summaryModes: Array.isArray(appConfig.summaryModes) ? appConfig.summaryModes : [],

			// SAR cases (ADR-040). Hidden entirely unless the MCP server
			// advertises them. `activeSarCase` ({id, name}) is the case shown in
			// the sidebar; the case itself lives on the server, this only
			// remembers which one is active. While it is open, searches run
			// through it (logged with their filters, one row per document,
			// paged) and "Add to SAR" writes to it. `sarCaseKeys`
			// ("doc_type:doc_id") marks results already in it.
			sarAvailable: appConfig.sarAvailable === true,
			activeSarCase: readActiveSarCase(),
			sarCaseKeys: [],
			sarCaseState: null,
			showSarSidebar: true,
			sarHasMore: false,
			// Matches reported by the last case search (exact: every match).
			sarTotal: 0,
			loadingMore: false,
			addingAllToSar: false,
			sarNextOffset: 0,
			sarLastQuery: '',
			sarLastParams: {},
		}
	},

	computed: {
		// Searches run through the active case only while it is open.
		sarCollecting() {
			return this.sarAvailable && this.activeSarCase !== null && this.sarCaseState === 'open'
		},

		// Keyword search runs without the embedding service: the way out of a
		// search that timed out waiting on it.
		canSearchByKeyword() {
			return this.algorithm !== 'bm25' && this.algorithmOptions.some((opt) => opt.id === 'bm25')
		},

		algorithmOptions() {
			const all = [
				{ id: 'hybrid', label: this.t('astrolabe', 'Hybrid') },
				{ id: 'semantic', label: this.t('astrolabe', 'Semantic') },
				{ id: 'bm25', label: this.t('astrolabe', 'Keyword (BM25)') },
				// Every document containing the phrase, unranked (server
				// `algorithm: "exact"`). Offered only when advertised.
				{ id: 'exact', label: this.t('astrolabe', 'Exact phrase (every document)') },
			]
			// Only offer query types the MCP server advertises: an explicit `[]`
			// (vector sync off) hides all three; otherwise offer the advertised
			// set (all three when sync is on). `null` = the server didn't advertise
			// the set (older backend) ⇒ show all (the backend still rejects an
			// unsupported algorithm 422-side).
			const offered = this.supportedSearchTypes === null
				// An older server that does not advertise its set predates
				// "exact", so it is never offered on a guess.
				? all.filter((opt) => opt.id !== 'exact')
				: all.filter((opt) => this.supportedSearchTypes.includes(opt.id))
			// A case search lists one row per document, which the dense-only
			// algorithm cannot do; offer it only when nothing else is available.
			if (this.sarCollecting) {
				const grouped = offered.filter((opt) => opt.id !== 'semantic')
				if (grouped.length > 0) {
					return grouped
				}
			}
			return offered
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

		// Score RANGE (both ends) over this page -- see utils/relevance.js for
		// why the slider is relative-to-top wherever the value is not
		// comparable across queries (RRF ~0.033 max, DBSF can go negative).
		scoreRange() {
			return computeScoreRange(this.results)
		},

		// Range over the rows whose `relevance` is a model's raw scale with no
		// fitted curve (a non-bge reranker, dense cosine, DBSF). Bars and plot
		// markers for those rows are normalised across it, so a low-scale
		// reranker (amazon.rerank-v1 puts good hits near 0.2) does not draw
		// every bar near-empty.
		uncalibratedRange() {
			return computeScoreRange(this.results.filter((r) => r.relevance_source === UNCALIBRATED))
		},

		// Relevance means nothing for an exact phrase search: every result is
		// a match, none is "more" relevant. The slider is greyed out for it.
		relevanceApplies() {
			return this.algorithm !== 'exact'
		},

		// Any row whose relevance has no fitted curve. The slider then cuts
		// relative-to-top on those rows (an absolute cut on a raw model scale
		// drops good results), and min_relevance is not sent: the server would
		// apply it absolutely to the same raw value.
		hasUncalibrated() {
			return anyUncalibrated(this.results)
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
			if (!this.relevanceApplies) {
				return () => true
			}
			return relevancePredicate(this.results, this.minRelevance)
		},

		filteredResults() {
			return this.results.filter(this.isRelevant)
		},

		// Parallel arrays used by the plot (SearchResultsPlot). The coordinate guard is
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
		// Keep the chosen algorithm one that is offered: opening a case drops
		// "Semantic", and the value sent must follow what the picker shows.
		algorithmOptions(opts) {
			if (opts.length > 0 && !opts.some((opt) => opt.id === this.algorithm)) {
				this.algorithm = opts[0].id
			}
		},

		// Switching to dense-only search drops "One row per document", which
		// it cannot serve, so the next search does not fail with a 422.
		algorithm(value) {
			if (value === 'semantic' && this.granularity === 'document') {
				this.granularity = 'chunk'
			}
		},

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

		minRelevance() {
			// Debounce so rapid slider drags don't trigger many Plotly.newPlot
			// calls (each tears down and rebuilds the WebGL scene).
			if (this._minRelevanceTimer) {
				clearTimeout(this._minRelevanceTimer)
			}
			this._minRelevanceTimer = setTimeout(() => {
				this._minRelevanceTimer = null
				this.$refs.resultsPlot?.render()
			}, 150)
		},
	},

	created() {
		// Non-reactive instance state. Storing it in data() would make
		// Vue observe a timer ID, with no template benefit.
		this._minRelevanceTimer = null
	},

	mounted() {
		// Check for URL parameters to open chunk viewer
		this.handleUrlParameters()
		// A case remembered from an earlier visit: check it is still open.
		if (this.sarAvailable && this.activeSarCase) {
			this.loadSarCaseKeys()
		}
	},

	beforeUnmount() {
		clearTimeout(this.slowSearchTimer)
		this.searchAbort?.abort()
		if (this._minRelevanceTimer) {
			clearTimeout(this._minRelevanceTimer)
			this._minRelevanceTimer = null
		}
	},

	methods: {
		// ADR-042 image hit: matched by what the picture shows, so it has no
		// excerpt or chunk offsets.
		isImageHit(result) {
			return result.doc_type === 'file' && result.metadata?.match === 'image'
		},

		imagePreviewUrl(result) {
			return generateUrl('/core/preview') + '?' + new URLSearchParams({ fileId: String(result.id), x: '256', y: '256', a: '1' })
		},

		handleUrlParameters() {
			// Parse URL parameters
			const urlParams = new URLSearchParams(window.location.search)
			// POC debug route: ?app_file_id=<fileid>[&chunk_index=<n>] opens the MCP App
			if (urlParams.get('app_file_id')) {
				const ci = urlParams.get('chunk_index')
				this.mcpApp = { fileId: parseInt(urlParams.get('app_file_id'), 10), chunkIndex: ci === null ? null : parseInt(ci, 10) }
			}
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
		sarCaseUrl(suffix = '') {
			return generateUrl('/apps/astrolabe/api/v1/sar/cases/{id}', { id: this.activeSarCase.id }) + suffix
		},

		/**
		 * Whether a result's document is already in the active SAR case.
		 * Keyed by document, not chunk: several chunks of one document are
		 * one item.
		 *
		 * @param {object} result A search result
		 * @return {boolean} True when the document is in the case.
		 */
		inSar(result) {
			return this.sarCaseKeys.includes(`${result.doc_type}:${result.id}`)
		},

		/**
		 * Open a case beside the search page.
		 *
		 * @param {{id: number, name: string}} sarCase The case
		 */
		async activateSarCase(sarCase) {
			this.activeSarCase = sarCase
			writeActiveSarCase(sarCase)
			this.showSarSidebar = true
			this.results = []
			this.searched = false
			await this.loadSarCaseKeys()
			this.activeSection = 'search'
		},

		stopSarCase() {
			this.activeSarCase = null
			this.sarCaseKeys = []
			this.sarCaseState = null
			this.sarHasMore = false
			this.sarTotal = 0
			this.sarNextOffset = 0
			this.sarLastParams = {}
			writeActiveSarCase(null)
			this.$refs.sarCases?.loadCases()
		},

		// Which documents are already in the active case. A case that is gone
		// or no longer accessible stops being active.
		async loadSarCaseKeys() {
			// Every page: a case holds more items than one response carries.
			const keys = []
			try {
				let data
				do {
					({ data } = await axios.get(this.sarCaseUrl(), {
						params: { offset: keys.length, limit: SAR_PAGE },
					}))
					keys.push(...data.case.items.map((item) => `${item.doc_type}:${item.doc_id}`))
				} while (keys.length < data.items_total && data.case.items.length > 0)
				this.sarCaseState = data.case.state
				this.sarCaseKeys = keys
			} catch {
				this.stopSarCase()
			}
		},

		onSarCaseChanged(data) {
			if (!this.activeSarCase || data.case_id !== this.activeSarCase.id) {
				return
			}
			this.sarCaseState = data.case.state
			if (data.items_total > data.case.items.length) {
				// A change returns the first page only: fetch all the keys.
				this.loadSarCaseKeys()
				return
			}
			this.sarCaseKeys = data.case.items.map((item) => `${item.doc_type}:${item.doc_id}`)
		},

		// A case the user can no longer change (closed elsewhere, deleted,
		// access removed) answers 404/409: refresh what the sidebar shows.
		onSarCaseError(err, fallback) {
			showError(err.response?.data?.error || fallback)
			if (err.response?.status === 404) {
				this.stopSarCase()
			} else if (err.response?.status === 409) {
				this.$refs.sarPanel ? this.$refs.sarPanel.load() : this.loadSarCaseKeys()
			}
		},

		// Every result on the page that is not yet in the case, in one call.
		async addAllToSar() {
			if (this.addingAllToSar) {
				return
			}
			this.addingAllToSar = true
			try {
				await this.addAllPagesToSar()
			} finally {
				this.addingAllToSar = false
			}
		},

		async addAllPagesToSar() {
			// An exact search means "every document", not "this page": fetch
			// the remaining pages first. Stops if a page fails or adds nothing.
			// Further pages reuse the stored query and parameters, so editing
			// the form meanwhile changes nothing here.
			if (this.algorithmUsed === 'exact') {
				let offset = -1
				while (this.sarHasMore && this.sarNextOffset !== offset) {
					offset = this.sarNextOffset
					await this.performSearch(true)
				}
			}
			const found = this.sarLastQuery || null
			// One entry per document: with chunk rows a document can appear twice.
			const seen = new Set()
			const add = this.filteredResults
				.filter((result) => {
					const key = `${result.doc_type}:${result.id}`
					if (this.inSar(result) || seen.has(key)) {
						return false
					}
					seen.add(key)
					return true
				})
				.map((result) => ({
					doc_type: result.doc_type,
					doc_id: String(result.id),
					reason: '',
					title: result.title || '',
					found_by: found,
				}))
			if (add.length === 0) {
				return
			}
			// The server takes at most SAR_ITEMS_PER_CALL items per request.
			// Each call returns the whole case, so the last one shows it all.
			let data = null
			try {
				for (let i = 0; i < add.length; i += SAR_ITEMS_PER_CALL) {
					({ data } = await axios.post(this.sarCaseUrl('/items'), {
						add: add.slice(i, i + SAR_ITEMS_PER_CALL),
					}))
				}
				this.showSarCase(data)
			} catch (err) {
				if (data === null) {
					this.onSarCaseError(err, this.t('astrolabe', 'Could not add the documents to the case.'))
					return
				}
				// Earlier batches are in the case: show them, and say the rest
				// are not.
				this.showSarCase(data)
				this.onSarCaseError(err, this.t('astrolabe', 'Only some of the documents were added to the case. Add all again to add the rest.'))
			}
		},

		// The server returns the whole case after each change: show it.
		showSarCase(data) {
			if (this.$refs.sarPanel) {
				this.$refs.sarPanel.show(data)
			} else {
				this.onSarCaseChanged(data)
			}
		},

		/**
		 * Add a result's document to the active case, on the server, with the
		 * query that found it. The reason is filled in on the case page.
		 *
		 * @param {object} result A search result
		 */
		async addToSar(result) {
			if (!this.sarCollecting || this.inSar(result)) {
				return
			}
			try {
				const { data } = await axios.post(this.sarCaseUrl('/items'), {
					add: [{
						doc_type: result.doc_type,
						doc_id: String(result.id),
						reason: '',
						title: result.title || '',
						found_by: this.query.trim() || null,
					}],
				})
				this.showSarCase(data)
			} catch (err) {
				this.onSarCaseError(err, this.t('astrolabe', 'Could not add the document to the case.'))
			}
		},

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

		/**
		 * Run the search, or with `more` fetch the next page of a case search.
		 *
		 * @param {boolean} more Append the next page (strictly true: a click
		 *   handler passes its event here)
		 */
		async performSearch(more = false) {
			const loadMore = more === true && this.sarCollecting
			const queryText = loadMore ? this.sarLastQuery : this.query.trim()
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

			if (loadMore) {
				this.loadingMore = true
				if (this.searchTimedOut) {
					this.searchTimedOut = false
					this.error = null
				}
			} else {
				this.loading = true
				this.error = null
				this.searchTimedOut = false
				// A stale "reranked" label must not sit next to this search's
				// error or results.
				this.rerankedUsed = false
				this.searched = true
				this.coordinates = []
				this.queryCoords = []
				this.expandedExcerpts = {}
				this.sarNextOffset = 0
				this.sarLastQuery = queryText
			}

			// Cancellable, and flagged as slow once it has taken long enough that
			// it is probably waiting on the embedding service. A newer search
			// supersedes this one: it aborts it, and owns the shared state.
			this.searchAbort?.abort()
			const controller = new AbortController()
			this.searchAbort = controller
			clearTimeout(this.slowSearchTimer)
			// A superseded search may have been slow; this one has just started.
			this.searchSlow = false
			this.slowSearchTimer = setTimeout(() => {
				this.searchSlow = true
			}, SLOW_SEARCH_MS)

			try {
				const url = generateUrl('/apps/astrolabe/api/search')
				let params = {
					query: queryText,
					algorithm: this.algorithm,
					// The server caps a page at 100.
					limit: Math.min(parseInt(this.limit) || 20, 100),
					// Skip the PCA computation entirely when the admin has
					// disabled the visualization panel.
					include_pca: this.showVisualization,
				}

				// Result shape. 'document' asks for one row per document rather
				// than per passage -- the shape 'which files mention X' wants.
				params.granularity = this.granularity

				// Applied by the server BEFORE it trims to `limit`, so a filtered
				// request still returns a full page of qualifying rows. Only
				// sent when actually filtering, and never for an exact search,
				// which has no relevance to cut on.
				// Known limit: keyed on the PREVIOUS response's sources, so the first
				// search after switching to an uncalibrated reranker still sends it;
				// the client cut corrects from the next search on.
				if (this.relevanceApplies && this.minRelevance > 0 && !this.hasUncalibrated) {
					params.min_relevance = this.minRelevance / 100
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

				// With an open case, the same search runs through it: logged there
				// with these filters, one row per document, and paged.
				// "Load more" repeats the first page's search exactly, whatever the
				// filter fields show now.
				if (loadMore) {
					params = { ...this.sarLastParams, offset: this.sarNextOffset }
				} else if (this.sarCollecting) {
					params.sar_case = this.activeSarCase.id
					params.offset = 0
					this.sarLastParams = { ...params }
				}

				const response = await axios.get(url, { params, signal: controller.signal })

				if (response.data.success) {
					const page = response.data.results || []
					this.results = loadMore ? [...this.results, ...page] : page
					if (!loadMore) {
						// A preview may exist by now (generated since the last search).
						this.brokenPreviews = {}
					}
					this.algorithmUsed = response.data.algorithm_used || this.algorithm
					if (this.sarCollecting) {
						this.sarNextOffset += params.limit
						// An exact search reports the true number of matches, and
						// a page can come back short (a stale match dropped by
						// the server's access check), so page by the count. A
						// ranked search's total is only its candidate pool:
						// there a full page is what says more may follow.
						this.sarTotal = response.data.total_documents || 0
						this.sarHasMore = this.algorithmUsed === 'exact'
							? this.sarNextOffset < this.sarTotal
							: page.length === params.limit
						if (!loadMore) {
							// The case logged this search: show it in the sidebar.
							this.$refs.sarPanel?.load()
						}
					}
					// What the server actually did, not what was asked for --
					// reranking degrades to retrieval order rather than failing.
					this.rerankedUsed = response.data.reranked === true
					this.coordinates = response.data.coordinates_3d || []
					this.queryCoords = response.data.query_coords || []

					// Render visualization after DOM updates
					if (this.coordinates.length > 0) {
						this.$nextTick(() => {
							this.$refs.resultsPlot?.render()
						})
					}
				} else {
					this.error = response.data.error || this.t('astrolabe', 'Search failed')
					this.results = []
				}
			} catch (err) {
				if (axios.isCancel(err)) {
					// Cancelled by the user, or superseded by a newer search (which
					// then owns the error and results).
					if (this.searchAbort === controller) {
						this.error = this.t('astrolabe', 'Search cancelled.')
					}
					return
				}
				console.error('Search error:', err)
				if (err.response?.data?.code === 'search_timeout') {
					this.searchTimedOut = true
					// A timed-out "Load more" is retried as "Load more", keeping
					// the rows already shown.
					this.retryLoadMore = loadMore
					this.error = this.canSearchByKeyword
						? this.t('astrolabe', 'The search did not finish in time. The embedding service may be starting up or unavailable. Try again shortly, or search by keyword, which does not need it.')
						: this.t('astrolabe', 'The search did not finish in time. Try again shortly.')
				} else if (err.response && err.response.data && err.response.data.error) {
					// A 422 from the MCP server carries a readable `message`
					// next to its machine-readable `error` token: show that.
					this.error = err.response.data.message || err.response.data.error
				} else if (err.response && err.response.status === 503) {
					this.error = this.t('astrolabe', 'Search service unavailable. Please try again later.')
				} else {
					this.error = this.t('astrolabe', 'Network error. Please try again.')
				}
				if (!loadMore) {
					this.results = []
				}
				if (this.sarCollecting && [404, 409].includes(err.response?.status)) {
					this.onSarCaseError(err, this.error)
				}
			} finally {
				if (this.searchAbort === controller) {
					this.searchAbort = null
					clearTimeout(this.slowSearchTimer)
					this.searchSlow = false
					this.loading = false
					this.loadingMore = false
				}
			}
		},

		cancelSearch() {
			this.searchAbort?.abort()
		},

		searchByKeyword() {
			this.algorithm = 'bm25'
			this.performSearch()
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
			return this.t('astrolabe', 'Relevance {pct}% — ranks results against each other, but is not a probability on this server. A reranker configured on the server would make it a calibrated score.', { pct })
		},

		// Uncalibrated rows are normalised across this page's range (see
		// displayRelevance); others show their own value, clamped rather than
		// trusting the [0,1] contract: an out-of-range value
		// from a future server would otherwise render a negative-width or
		// >100% bar, which looks like a UI bug rather than a data problem.
		relevancePercent(result) {
			return Math.round(displayRelevance(result, this.uncalibratedRange) * 100)
		},

		getDocumentUrl(result) {
			return documentUrl(result, generateUrl)
		},

		navigateToDocument(result) {
			const url = this.getDocumentUrl(result)
			window.open(url, '_blank')
		},

		goToSettings() {
			window.location.href = generateUrl('/settings/user/astrolabe')
		},

		viewChunk(result) {
			this.$refs.documentViewer.open(result)
		},
	},
}
</script>

<style scoped lang="scss">
@use './styles/section.scss';

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
.mcp-slow-hint {
	flex-basis: 100%;
	text-align: center;
}

.mcp-timeout-actions {
	display: flex;
	gap: 8px;
	margin-top: 8px;
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

.mcp-result-preview {
	display: inline-block;
	margin-bottom: 6px;
}

.mcp-result-preview img {
	display: block;
	max-width: 256px;
	max-height: 256px;
	border-radius: var(--border-radius-large);
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

// Navigation footer
.app-navigation-entry__settings {
	height: auto !important;
	overflow: hidden !important;
	flex: 0 0 auto;
	padding: 3px;
	padding-top: 0 !important;
	margin: 0 3px;
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

<style lang="scss">
/* Fix for double margin/padding issue when nested in #content. Scoped to this
   app's own NcContent: the server's settings page is an NcContent too
   (#content-vue), and an unscoped rule stripped its header offset. */
#content-vue.app-astrolabe {
	margin-top: 0 !important;
	margin-inline-start: 0 !important;
}
</style>
