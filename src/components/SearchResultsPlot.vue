<template>
	<!-- 3D Visualization -->
	<div class="mcp-viz-container">
		<div class="mcp-viz-header">
			<h3>{{ t('astrolabe', 'Vector Space Visualization') }}</h3>
			<NcCheckboxRadioSwitch
				:modelValue="showQueryPoint"
				type="switch"
				@update:modelValue="toggleQueryPoint">
				{{ t('astrolabe', 'Show query point') }}
			</NcCheckboxRadioSwitch>
		</div>
		<div id="viz-plot-container" ref="container" class="mcp-viz-plot-container">
			<div id="viz-plot" ref="plot" />
		</div>
	</div>
</template>

<script>
import Plotly from 'plotly.js-dist-min'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import { clickedResultIndex, plotTraces } from '../utils/plot.js'

/**
 * The 3D PCA plot of a search's results.
 *
 * Rendering is driven by the parent through `render()`, called once a search's
 * coordinates are in and again (debounced) when the relevance filter moves: a
 * Plotly.newPlot tears down and rebuilds the WebGL scene, so it is not tied to
 * every prop change. Clicking a result point emits `select` with that result.
 */
export default {
	name: 'SearchResultsPlot',
	components: {
		NcCheckboxRadioSwitch,
	},

	props: {
		/** The results to plot, parallel to `coordinates` (already filtered). */
		results: {
			type: Array,
			required: true,
		},

		/** PCA coordinates, one [x,y,z] per entry of `results`. */
		coordinates: {
			type: Array,
			required: true,
		},

		/** The query's [x,y,z]; may be empty. */
		queryCoords: {
			type: Array,
			default: () => [],
		},

		/**
		 * Whether the query point is shown (`v-model:showQueryPoint`). Held by
		 * the parent so the choice survives the plot being re-created for
		 * each search.
		 */
		showQueryPoint: {
			type: Boolean,
			required: true,
		},

		/** Rank-score range over the whole page (utils/relevance.js). */
		scoreRange: {
			type: Object,
			required: true,
		},

		/** Range over the page's uncalibrated rows (utils/relevance.js). */
		uncalibratedRange: {
			type: Object,
			required: true,
		},
	},

	emits: ['select', 'update:showQueryPoint'],

	expose: ['render'],

	created() {
		// Non-reactive: the subset actually painted, which Plotly's pointIndex
		// refers to. Deep-observing it would buy nothing.
		this._renderedResults = []
	},

	beforeUnmount() {
		// Clean up Plotly event handlers to prevent memory leaks
		const plotDiv = this.$refs.plot
		if (plotDiv && plotDiv.on) {
			plotDiv.removeAllListeners('plotly_click')
		}
	},

	methods: {
		render() {
			const container = this.$refs.container
			if (!container) {
				return
			}

			const width = container.clientWidth
			const height = container.clientHeight || 400

			// Snapshot the subset that will actually be rendered.
			// handlePlotClick indexes into this, because Plotly's pointIndex
			// refers to the rendered trace data.
			this._renderedResults = this.results

			const traces = plotTraces({
				results: this.results,
				coordinates: this.coordinates,
				queryCoords: this.queryCoords,
				scoreRange: this.scoreRange,
				uncalibratedRange: this.uncalibratedRange,
				showQueryPoint: this.showQueryPoint,
			})

			const layout = {
				title: { text: `Vector Space (PCA 3D) - ${this.results.length} results` },
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

			const config = {
				responsive: true,
				displayModeBar: true,
			}

			const plotDiv = this.$refs.plot
			Plotly.newPlot(plotDiv, traces, layout, config)

			// Register click event handler for result points. Plotly.newPlot
			// reuses the same DOM node, so listeners stack across re-renders —
			// remove any prior handler before attaching a fresh one.
			plotDiv.removeAllListeners('plotly_click')
			plotDiv.on('plotly_click', this.handlePlotClick)
		},

		async toggleQueryPoint(visible) {
			this.$emit('update:showQueryPoint', visible)
			// Wait for the parent to pass the new value back down.
			await this.$nextTick()
			// Toggle query point visibility without recreating the plot
			if (this.queryCoords.length > 0) {
				const plotDiv = this.$refs.plot

				if (plotDiv && plotDiv.data && plotDiv.data.length >= 2) {
					// Trace index 1 is the query point
					Plotly.restyle(plotDiv, { visible }, [1])
				} else {
					// Plot doesn't exist yet, render it
					this.render()
				}
			}
		},

		handlePlotClick(eventData) {
			const pointIndex = clickedResultIndex(eventData)
			if (pointIndex === null) {
				return
			}

			// Index into the rendered (filtered) subset, not the parent's full
			// result list: the two differ when the relevance filter is set.
			const result = this._renderedResults[pointIndex]

			if (!result) {
				console.warn('Click handler: result not found for index', pointIndex)
				return
			}

			this.$emit('select', result)
		},
	},
}
</script>

<style scoped lang="scss">
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
</style>
