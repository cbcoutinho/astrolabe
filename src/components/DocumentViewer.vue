<template>
	<!-- PDF/Chunk Viewer Modal -->
	<div v-if="showViewer" class="mcp-modal-overlay" @click.self="close">
		<div class="mcp-modal">
			<!-- Fixed Header -->
			<div class="mcp-modal-header">
				<h3>
					<a
						v-if="currentResult"
						:href="documentUrl(currentResult)"
						class="mcp-modal-title-link"
						@click.prevent="openDocument(currentResult)">
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
					<NcButton
						variant="tertiary"
						:aria-label="t('astrolabe', 'Close')"
						:title="t('astrolabe', 'Close')"
						@click="close">
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

				<!-- CSV files as a table, chunk rows highlighted -->
				<CsvViewer
					v-else-if="viewerType === 'csv'"
					v-bind="viewerContext" />

				<!-- Markdown Viewer (for non-PDFs) -->
				<MarkdownViewer
					v-else
					:content="markdownContent" />
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
</template>

<script>
import axios from '@nextcloud/axios'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import Close from 'vue-material-design-icons/Close.vue'
import CreationIcon from 'vue-material-design-icons/Creation.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import CsvViewer from './CsvViewer.vue'
import MarkdownViewer from './MarkdownViewer.vue'
// Imported statically, despite PDF.js being several MB. A dynamic import emits
// a separate chunk whose URL Vite resolves against `base` (`/`), so it is
// requested from /js/… instead of /custom_apps/astrolabe/js/… and 404s — the
// same root-relative-URL problem that forces the worker to be inlined. Making
// this lazy again needs a base-aware chunk URL first.
import PDFViewer from './PDFViewer.vue'
import { chunkContextParams, chunkMarkdown, imageFileIdsForSummary, isCsvResult, summaryPageWindow } from '../utils/chunkViewer.js'
import { documentUrl } from '../utils/documentUrl.js'

// How many PDF pages to render for a multimodal summary. Kept under the
// backend's own cap; vision tokens dominate the cost of these calls, so sending
// more pages costs real money for diminishing returns.
const SUMMARY_PAGE_WINDOW = 3

// Generation runs on the admin's provider through a background job, so the
// budget is generous — a queued task behind a busy provider is normal.
const SUMMARY_POLL_MS = 1500
const SUMMARY_TIMEOUT_MS = 3 * 60 * 1000

/**
 * The document viewer modal: the passage around a search hit (PDF page with
 * its bounding box, CSV table, or markdown), plus an optional AI summary.
 *
 * The parent opens it with `open(result)`. A load that fails because the user
 * lost access, or the document left the index, closes the modal and emits
 * `error` with a message for the page to show.
 */
export default {
	name: 'DocumentViewer',
	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		PDFViewer,
		MarkdownViewer,
		CsvViewer,
		ChevronLeft,
		ChevronRight,
		Close,
		CreationIcon,
		OpenInNew,
	},

	props: {
		/**
		 * Summary tiers a TaskProcessing provider can serve, from the
		 * backend. Empty means no provider is installed, so the action is
		 * hidden rather than offered and always failing.
		 */
		summaryModes: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['error'],

	expose: ['open'],

	data() {
		return {
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

		markdownContent() {
			return chunkMarkdown(this.viewerContext)
		},
	},

	methods: {
		documentUrl(result) {
			return documentUrl(result, generateUrl)
		},

		openDocument(result) {
			window.open(this.documentUrl(result), '_blank')
		},

		async open(result) {
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
				const response = await axios.get(url, { params: chunkContextParams(result) })

				if (response.data.success) {
					// Determine viewer type and setup
					if (result.doc_type === 'file' && response.data.page_number) {
						this.viewerType = 'pdf'
						this.currentPdfPath = result.metadata?.path || ''
						// result.id is the Nextcloud fileId for file doc types.
						this.currentPdfDocId = Number.isInteger(result.id) ? result.id : (parseInt(result.id, 10) || null)
						this.viewerPage = response.data.page_number
						this.currentBbox = response.data.chunk_bbox || []
						this.currentBboxPage = response.data.page_number
					} else {
						this.viewerType = isCsvResult(result) ? 'csv' : 'text'
						this.viewerContext = {
							chunk: response.data.chunk_text || '',
							before: response.data.before_context || '',
							after: response.data.after_context || '',
							// Absent flag = unknown: assume truncated, so no
							// partial line is shown and no bogus header guessed.
							hasMoreBefore: response.data.has_more_before !== false,
							hasMoreAfter: response.data.has_more_after !== false,
						}
					}
				} else {
					console.error('Failed to load chunk:', response.data.error)
					this.close()
				}
			} catch (err) {
				// 403 = access revoked since indexing (the staleness the local
				// check guards). Surface a friendly message instead of a stack trace.
				if (err.response && err.response.status === 403) {
					this.$emit('error', this.t('astrolabe', 'You no longer have access to this document.'))
				} else if (err.response && err.response.status === 404) {
					// Deleted or unindexed since the search, or a stale deep link.
					this.$emit('error', this.t('astrolabe', 'This document is no longer in the search index.'))
				} else {
					console.error('Error loading chunk:', err)
				}
				this.close()
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

		close() {
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
				for (const fileId of imageFileIdsForSummary(result)) {
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
		 * Rasterize a window of PDF pages around the one being viewed.
		 *
		 * @return {Promise<Blob[]>} PNG blobs, or an empty array when this is not a PDF.
		 */
		async capturePagesForSummary() {
			const viewer = this.$refs.pdfViewer
			if (this.viewerType !== 'pdf' || !viewer?.capturePages) {
				return []
			}

			const pages = summaryPageWindow(this.viewerPage, this.pdfTotalPages, SUMMARY_PAGE_WINDOW)

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
	},
}
</script>

<style scoped lang="scss">
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

@media (max-width: 768px) {
	.mcp-modal {
		width: 100%;
		height: 100%;
		border-radius: 0;
	}
}
</style>
