<template>
	<div
		class="mcp-result-item"
		:class="'mcp-doc-type-' + (result.doc_type || 'unknown')">
		<div class="mcp-result-header">
			<span class="mcp-result-type">{{ result.doc_type || 'unknown' }}</span>
			<div class="mcp-result-actions">
				<NcButton
					v-if="!isImageHit"
					variant="tertiary"
					:aria-label="t('astrolabe', 'Show Chunk')"
					@click="$emit('view', result)">
					<template #icon>
						<Eye :size="18" />
					</template>
					{{ t('astrolabe', 'Show Chunk') }}
				</NcButton>
				<NcButton
					v-if="result.doc_type === 'file'"
					variant="tertiary"
					@click="$emit('openApp', { fileId: parseInt(result.id, 10), chunkIndex: result.chunk_index ?? null })">
					{{ t('astrolabe', 'Open in App') }}
				</NcButton>
				<NcButton
					v-if="sarCollecting"
					variant="tertiary"
					class="mcp-add-to-sar"
					:disabled="inSar"
					@click="$emit('addToSar', result)">
					<template #icon>
						<PlaylistCheck v-if="inSar" :size="18" />
						<PlaylistPlus v-else :size="18" />
					</template>
					{{ inSar ? t('astrolabe', 'In SAR') : t('astrolabe', 'Add to SAR') }}
				</NcButton>
			</div>
		</div>
		<a
			:href="documentUrl"
			class="mcp-result-title"
			@click.prevent="openDocument">
			{{ result.title || t('astrolabe', 'Untitled') }}
			<OpenInNew :size="14" class="mcp-external-icon" />
		</a>
		<!-- An image hit matched what the picture shows, not a passage of
			text: it has no chunk to view, so show the picture itself. -->
		<a
			v-if="isImageHit && !previewBroken"
			:href="documentUrl"
			class="mcp-result-preview"
			tabindex="-1"
			aria-hidden="true"
			@click.prevent="openDocument">
			<!-- Decorative: the title link beside it names the same file. -->
			<img
				:src="imagePreviewUrl"
				alt=""
				loading="lazy"
				@error="$emit('previewError', result)">
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
				:aria-valuenow="relevancePercent"
				:title="relevanceTitle"
				:aria-label="relevanceTitle">
				<div
					class="mcp-relevance-fill"
					:style="{ width: relevancePercent + '%' }" />
			</div>
			<span v-if="relevanceCalibrated" class="mcp-relevance-value">
				{{ relevancePercent }}%
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
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import Eye from 'vue-material-design-icons/Eye.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import PlaylistCheck from 'vue-material-design-icons/PlaylistCheck.vue'
import PlaylistPlus from 'vue-material-design-icons/PlaylistPlus.vue'
import { documentUrl } from '../utils/documentUrl.js'
import { isCalibrated } from '../utils/relevance.js'

/**
 * One row of the search results.
 *
 * The relevance percent comes from the parent, because uncalibrated rows are
 * normalised across the whole page (see utils/relevance.js displayRelevance),
 * which one row cannot see.
 */
export default {
	name: 'SearchResultItem',
	components: {
		NcButton,
		Eye,
		OpenInNew,
		PlaylistCheck,
		PlaylistPlus,
	},

	props: {
		result: {
			type: Object,
			required: true,
		},

		/** The relevance bar's value, 0–100. */
		relevancePercent: {
			type: Number,
			required: true,
		},

		/** An open SAR case is collecting: show "Add to SAR". */
		sarCollecting: {
			type: Boolean,
			default: false,
		},

		/** The result is already in the open SAR case. */
		inSar: {
			type: Boolean,
			default: false,
		},

		/** The image preview failed to load before: show no thumbnail. */
		previewBroken: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['view', 'openApp', 'addToSar', 'previewError'],

	computed: {
		isImageHit() {
			return this.result.doc_type === 'file' && this.result.metadata?.match === 'image'
		},

		imagePreviewUrl() {
			return generateUrl('/core/preview') + '?' + new URLSearchParams({ fileId: String(this.result.id), x: '256', y: '256', a: '1' })
		},

		documentUrl() {
			return documentUrl(this.result, generateUrl)
		},

		relevanceCalibrated() {
			return isCalibrated(this.result)
		},

		// Tooltip for the relevance bar. Says plainly what the number is and,
		// for the sources that are not probabilities, what it is not -- the bar
		// alone would otherwise read as a confidence to anyone who has seen one
		// before.
		relevanceTitle() {
			// this.t, not a bare t(): this file never imports it — main.js
			// wires it onto globalProperties. A bare call would resolve only via
			// Nextcloud's legacy l10n global, and since this runs for every
			// rendered row, its absence would throw during render rather than
			// just return an untranslated string.
			const pct = this.relevancePercent
			if (this.relevanceCalibrated) {
				return this.t('astrolabe', 'Relevance {pct}% — an estimated probability this result is relevant to your query. Relative to what was found: search always returns its best matches, even when nothing in your files answers the question.', { pct })
			}
			return this.t('astrolabe', 'Relevance {pct}% — ranks results against each other, but is not a probability on this server. A reranker configured on the server would make it a calibrated score.', { pct })
		},
	},

	methods: {
		openDocument() {
			window.open(this.documentUrl, '_blank')
		},
	},
}
</script>

<style scoped lang="scss">
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
</style>
