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
		<!-- The rendered page is the container, not just the canvas: it is the
			 canvas plus its highlight overlays. Labelling it here also keeps the
			 img role off the canvas, which is an interactive element and so
			 cannot carry a non-interactive role. Without this the viewer exposes
			 nothing to assistive tech, where the <img> it replaced at least had
			 alt text. -->
		<div
			v-show="!loading && !error"
			class="pdf-image-container"
			role="img"
			:aria-label="t('astrolabe', 'Page {page} of the PDF', { page: pageNumber })">
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
 * Bytes arrive through WebDavRangeTransport, so only the ranges PDF.js asks
 * for cross the wire rather than the whole document. For a non-linearized scan
 * that is still a lot of them — see the note in pdfRangeTransport.js.
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
import { fetchInitialData, RANGE_CHUNK_SIZE, resolveDocument, WebDavRangeTransport } from '../services/pdfRangeTransport.js'

const props = defineProps({
	filePath: {
		type: String,
		required: true,
	},
	// Nextcloud fileId, when known. The fallback when the indexed path misses,
	// since a share received by this user is mounted at a different path than
	// the owner indexed — see resolveDocument().
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

// Bumped by every show(). An async load/render that finds the counter has moved
// on has been superseded, and discards its result rather than writing to the
// shared handles above. App.vue sets currentPdfPath, currentPdfDocId and
// viewerPage together in one synchronous block, so without this a single result
// click could otherwise start two overlapping loads whose completion order
// decides what is displayed.
let generation = 0

// Cancels the lookup/bootstrap fetches of an in-flight load. The transport owns
// cancellation for its own range requests; this covers the ones that happen
// before the transport exists.
let loadController = null

/**
 * Cancel an in-flight render and wait for it to actually settle.
 *
 * `cancel()` only requests cancellation; the canvas stays claimed until the
 * task's promise rejects, so the await is what makes it safe to start the next
 * render immediately afterwards.
 */
async function cancelRender() {
	const task = renderTask
	if (!task) {
		return
	}
	renderTask = null
	task.cancel()
	try {
		await task.promise
	} catch {
		// Expected: cancellation rejects with RenderingCancelledException.
	}
}

/**
 * Release the current document and any in-flight transfers.
 */
function teardown() {
	loadController?.abort()
	loadController = null
	renderTask?.cancel()
	renderTask = null
	pdfDoc?.destroy()
	pdfDoc = null
	transport?.abort()
	transport = null
}

/**
 * Open the document for the current file.
 *
 * Everything is built into locals and only published to the shared handles once
 * this call is confirmed to still be the current one; a superseded load tears
 * down what it built instead, so its range fetches stop rather than streaming
 * into a document nobody will display.
 *
 * @param {number} gen Generation this call belongs to
 * @return {Promise<boolean>} False if superseded before completing
 */
async function loadDocument(gen) {
	teardown()

	const controller = new AbortController()
	loadController = controller

	const { url, length } = await resolveDocument(props.filePath, props.docId, controller.signal)
	if (gen !== generation) {
		return false
	}
	const initialData = await fetchInitialData(url, length, controller.signal)
	if (gen !== generation) {
		return false
	}

	const pendingTransport = new WebDavRangeTransport(length, url, initialData, {
		// Ranges are fetched outside the load/render promise chain, so a failed
		// one would otherwise leave the viewer spinning on bytes that will
		// never arrive.
		onError: (err) => {
			console.error('PDF range transport failed:', err)
			error.value = t('astrolabe', 'Network error loading PDF')
			loading.value = false
		},
	})
	const pendingDoc = await getDocument({
		range: pendingTransport,
		// Currently inert, and kept deliberately. pdf.js only forwards
		// rangeChunkSize to its NetworkStream branch, not to the custom-transport
		// branch this uses, so the chunked-stream manager falls back to its own
		// 65536 default — measured identical at 64 KB through 1 MB. Passing it
		// keeps the intent explicit and starts working if pdf.js ever wires it
		// up; do not expect changing RANGE_CHUNK_SIZE to alter request sizes
		// today.
		rangeChunkSize: RANGE_CHUNK_SIZE,
		// Runtime assets, resolved against this module's own URL so they work
		// wherever the app is installed (/apps/ or /custom_apps/). wasmUrl is
		// the load-bearing one: scanned pages are JPEG 2000 / JBIG2, and
		// without the OpenJPEG WASM module the decoder fails and the page
		// renders as a blank canvas with only a console warning.
		wasmUrl: new URL('../pdfjs/wasm/', import.meta.url).href,
		cMapUrl: new URL('../pdfjs/cmaps/', import.meta.url).href,
		cMapPacked: true,
		standardFontDataUrl: new URL('../pdfjs/standard_fonts/', import.meta.url).href,
		iccUrl: new URL('../pdfjs/iccs/', import.meta.url).href,
		// Both default to false, and both defaults are wrong here.
		//
		// disableAutoFetch: PDF.js otherwise keeps pulling ranges in the
		// background until it holds the whole document, "even if it isn't
		// needed to display the current page" — hundreds of requests and the
		// full file for a one-page view, which is exactly the transfer this
		// change exists to avoid.
		//
		// disableStream: this transport serves ranges, not a progressive
		// stream, so without it PDF.js waits on data that never arrives.
		disableAutoFetch: true,
		disableStream: true,
	}).promise

	if (gen !== generation) {
		// Superseded while the document was opening. Tear down what this call
		// built rather than the shared handles, which now belong to the newer
		// load, so the abandoned transport stops fetching ranges.
		pendingTransport.abort()
		pendingDoc.destroy()
		return false
	}

	transport = pendingTransport
	pdfDoc = pendingDoc
	totalPages.value = pdfDoc.numPages

	emit('loaded', { totalPages: pdfDoc.numPages })
	return true
}

/**
 * Rasterize the current page onto the canvas.
 */
async function renderPage() {
	// PDF.js refuses to run two renders against the same canvas ("Cannot use
	// the same canvas during multiple render() operations"). Page navigation
	// is only bounds-gated, not render-gated, so double-clicking Next during a
	// slow render — very plausible on the large scans this targets — would
	// otherwise surface that as a spurious "Unable to load PDF page".
	await cancelRender()

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
	const gen = ++generation
	loading.value = true
	error.value = null

	try {
		if (reload || !pdfDoc) {
			if (!(await loadDocument(gen))) {
				return
			}
		}
		if (gen !== generation) {
			return
		}
		await renderPage()
		if (gen !== generation) {
			// A newer show() is already driving the viewer; leaving `loading`
			// to it avoids flashing this stale render's completion.
			return
		}
		loading.value = false
	} catch (err) {
		// A cancelled render is the expected outcome of navigating away
		// mid-render, not a failure worth surfacing. Nor is a superseded call:
		// the newer one owns the error state now.
		if (
			err?.name === 'RenderingCancelledException'
			|| err?.name === 'AbortError'
			|| gen !== generation
		) {
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

// One watcher, not two. App.vue assigns filePath, docId and pageNumber together
// in a single synchronous block, so separate watchers land in the same reactive
// flush and each start an independent show() — two concurrent loads racing over
// the same document handles. Deciding reload-vs-rerender once per flush means
// that click produces exactly one show().
watch(
	() => [props.filePath, props.docId, props.pageNumber, props.scale],
	([filePath, docId], [prevFilePath, prevDocId]) => {
		// A different file needs a fresh document; a new page or scale only
		// needs a re-render of the one already open.
		show(filePath !== prevFilePath || docId !== prevDocId)
	},
)

/**
 * Render pages to PNG blobs for a multimodal summary.
 *
 * Deliberately renders to its own detached canvas rather than reusing the
 * displayed one: PDF.js refuses two concurrent renders against the same canvas,
 * and reusing it would also make the viewer visibly flicker through the captured
 * pages. This is also why nothing here touches `renderTask` — capture must not
 * interfere with, or be cancelled by, ordinary page navigation.
 *
 * Rendered at a fixed scale rather than the viewer's: what the user happens to
 * have zoomed to is unrelated to what a vision model needs to read the page.
 *
 * @param {number[]} pageNumbers Pages to capture, 1-based.
 * @param {number} scale Render scale.
 * @return {Promise<Blob[]>} PNG blobs, in the order requested.
 */
async function capturePages(pageNumbers, scale = 2.0) {
	if (!pdfDoc) {
		return []
	}

	const blobs = []
	for (const requested of pageNumbers) {
		const pageNum = Math.min(Math.max(requested, 1), pdfDoc.numPages)
		const page = await pdfDoc.getPage(pageNum)
		const viewport = page.getViewport({ scale })

		const canvas = document.createElement('canvas')
		canvas.width = viewport.width
		canvas.height = viewport.height
		await page.render({
			canvas,
			canvasContext: canvas.getContext('2d'),
			viewport,
		}).promise

		const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'))
		if (blob) {
			blobs.push(blob)
		}
	}
	return blobs
}

defineExpose({ capturePages })

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
