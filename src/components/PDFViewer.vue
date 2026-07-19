<template>
	<div class="pdf-viewer">
		<div v-if="loading" class="loading-indicator">
			<NcLoadingIcon :size="64" />
			<p>{{ t('astrolabe', 'Loading PDF…') }}</p>
		</div>
		<div v-else-if="error" class="error-message">
			<AlertCircle :size="48" />
			<p>{{ error }}</p>
		</div>
		<div v-show="!loading && !error" class="pdf-image-container">
			<canvas ref="canvasEl" class="pdf-page-image" />
			<div
				v-for="(rect, i) in (pageNumber === bboxPage ? highlightBbox : [])"
				:key="i"
				class="pdf-highlight"
				:style="highlightStyle(rect)" />
		</div>
	</div>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { NcLoadingIcon } from '@nextcloud/vue'
/**
 * PDFViewer - client-side PDF rendering component.
 *
 * Pages are rasterized here, in the browser, from the copy of the file that
 * already lives in Nextcloud. The backend is deliberately not involved: it
 * used to render pages with PyMuPDF, which meant buffering the whole document
 * into the API pod and OOMKilled it on large scans.
 *
 * Bytes arrive through WebDavRangeTransport, so opening one page of a
 * multi-hundred-megabyte document transfers only the ranges PDF.js asks for.
 */
import { getDocument, GlobalWorkerOptions } from 'pdfjs-dist'
// `inline` is load-bearing, not a size trade-off. A non-inlined worker is
// emitted as a separate asset and referenced by a root-absolute URL
// (`new Worker("/assets/pdf.worker…")`), which 404s for a Nextcloud app served
// from /custom_apps/astrolabe/. Inlining yields a blob: worker with no URL to
// resolve — hence `worker-src blob:` in the app's CSP, see PageController.
import PdfWorker from 'pdfjs-dist/build/pdf.worker.min.mjs?worker&inline'
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import { fetchContentLength, resolveWebdavUrlByFileId, WebDavRangeTransport, webdavUrlForPath } from '../services/pdfRangeTransport.js'

const props = defineProps({
	filePath: {
		type: String,
		required: true,
	},
	// Nextcloud fileId, when known. Preferred over filePath for addressing the
	// file, since a share received by this user is mounted at a different path
	// than the owner indexed — see resolveUrl().
	docId: {
		type: Number,
		default: null,
	},
	pageNumber: {
		type: Number,
		default: 1,
	},
	scale: {
		type: Number,
		default: 2.0,
	},
	// Normalized [x0, y0, x1, y1] tuples (0..1, top-left origin) marking
	// the location of the matched chunk on the page. Drawn as a soft-yellow
	// overlay on top of the rendered page. Empty array = no highlight.
	highlightBbox: {
		type: Array,
		default: () => [],
		validator: (v) => v.every((r) => Array.isArray(r) && r.length === 4 && r.every((n) => typeof n === 'number')),
	},
	// Page the highlightBbox belongs to. The overlay only renders when
	// pageNumber === bboxPage so highlights don't bleed across navigation.
	bboxPage: {
		type: Number,
		default: null,
	},
})

const emit = defineEmits(['loaded', 'error', 'pageRendered'])

GlobalWorkerOptions.workerPort = new PdfWorker()

// Reactive state
const loading = ref(true)
const error = ref(null)
const totalPages = ref(0)
const canvasEl = ref(null)

// Non-reactive handles: wrapping a PDFDocumentProxy in a ref would make Vue
// walk its internals, and these are transport/render handles rather than view
// state.
let pdfDoc = null
let transport = null
let renderTask = null

/**
 * Release the current document and any in-flight transfers.
 */
function teardown() {
	renderTask?.cancel()
	renderTask = null
	pdfDoc?.destroy()
	pdfDoc = null
	transport?.abort()
	transport = null
}

/**
 * Resolve the file to a WebDAV URL in the current user's tree.
 *
 * fileId first: the indexed path belongs to the file's owner, and a share
 * received by this user is mounted at a different path, so path addressing
 * 404s on shared results. Path is the fallback for callers with no fileId.
 *
 * @return {Promise<string>} Absolute WebDAV URL
 */
async function resolveUrl() {
	if (props.docId !== null && props.docId !== undefined) {
		const byId = await resolveWebdavUrlByFileId(props.docId)
		if (byId) {
			return byId
		}
	}
	return webdavUrlForPath(props.filePath)
}

/**
 * Open the document for the current file.
 */
async function loadDocument() {
	teardown()

	const url = await resolveUrl()
	const length = await fetchContentLength(url)

	transport = new WebDavRangeTransport(length, url)
	pdfDoc = await getDocument({ range: transport }).promise
	totalPages.value = pdfDoc.numPages

	emit('loaded', { totalPages: pdfDoc.numPages })
}

/**
 * Rasterize the current page onto the canvas.
 */
async function renderPage() {
	// Clamp: a stale page number left over from a previous document would
	// reject inside PDF.js with a much less actionable error.
	const pageNum = Math.min(Math.max(props.pageNumber, 1), pdfDoc.numPages)
	const page = await pdfDoc.getPage(pageNum)
	const viewport = page.getViewport({ scale: props.scale })

	const canvas = canvasEl.value
	if (!canvas) {
		return
	}
	canvas.width = viewport.width
	canvas.height = viewport.height

	renderTask = page.render({
		canvas,
		canvasContext: canvas.getContext('2d'),
		viewport,
	})
	await renderTask.promise
	renderTask = null

	emit('pageRendered', { pageNumber: pageNum })
}

/**
 * Load (if needed) and render, mapping failures to a user-facing message.
 *
 * @param {boolean} reload Re-open the document rather than reusing it
 */
async function show(reload) {
	loading.value = true
	error.value = null

	try {
		if (reload || !pdfDoc) {
			await loadDocument()
		}
		await renderPage()
		loading.value = false
	} catch (err) {
		// A cancelled render is the expected outcome of navigating away
		// mid-render, not a failure worth surfacing.
		if (err?.name === 'RenderingCancelledException' || err?.name === 'AbortError') {
			return
		}
		console.error('PDF load error:', err)

		if (err?.name === 'PasswordException') {
			error.value = t('astrolabe', 'This PDF is password protected')
		} else if (err?.name === 'InvalidPDFException') {
			error.value = t('astrolabe', 'This file is not a valid PDF')
		} else if (err?.message?.includes('404')) {
			error.value = t('astrolabe', 'PDF file not found')
		} else if (err?.message?.includes('401') || err?.message?.includes('403')) {
			error.value = t('astrolabe', 'Authorization required to view PDF')
		} else {
			error.value = t('astrolabe', 'Unable to load PDF page')
		}

		emit('error', err)
		loading.value = false
	}
}

function highlightStyle(rect) {
	const [x0, y0, x1, y1] = rect.map((v) => Math.max(0, Math.min(1, v)))
	return {
		left: `${x0 * 100}%`,
		top: `${y0 * 100}%`,
		width: `${(x1 - x0) * 100}%`,
		height: `${(y1 - y0) * 100}%`,
	}
}

// A new file needs a fresh document; a new page only needs a re-render.
watch(() => props.filePath, () => show(true))
watch(() => [props.pageNumber, props.scale], () => show(false))

onMounted(() => show(true))
onBeforeUnmount(teardown)
</script>

<style scoped lang="scss">
.pdf-viewer {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 16px;
	padding: 16px;
}

.loading-indicator {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 16px;
	padding: 48px;

	p {
		color: var(--color-text-maxcontrast);
		font-size: 14px;
	}
}

.error-message {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 16px;
	padding: 48px;
	color: var(--color-error-text);

	p {
		font-size: 14px;
		text-align: center;
	}
}

.pdf-image-container {
	position: relative;
	display: inline-block;
	border: 1px solid var(--color-border);
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
	background: var(--color-main-background);
	max-width: 100%;
	overflow: auto;
}

.pdf-page-image {
	display: block;
	max-width: 100%;
	height: auto;
}

.pdf-highlight {
	position: absolute;
	background: rgba(255, 235, 59, 0.4);
	pointer-events: none;
}

@media (max-width: 768px) {
	.pdf-viewer {
		padding: 8px;
	}
}
</style>
