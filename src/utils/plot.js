/**
 * SPDX-FileCopyrightText: 2025 Astrolabe contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Pure helpers behind the 3D vector-space plot (SearchResultsPlot.vue), kept
 * free of Plotly and the DOM so they can be tested under `node --test`.
 */

import { displayRelevance, rankScore } from './relevance.js'

/**
 * The [0,1] number a result is plotted at: the same one the list's relevance
 * bar shows, so a row the list calls relevant is not a speck here.
 *
 * `score` is the raw retrieval value -- ~0.03 at most under RRF -- and sizing
 * by it put every point at the minimum. `relevance` is the calibrated
 * cross-encoder probability when the search was reranked. Older servers that
 * do not send it fall back to the rank score (rerank_score ?? score) scaled
 * across this page's range.
 *
 * A row without a finite `relevance` is unknown, not 0% -- the same rule the
 * relevance filter applies -- so it falls back to the relative score. A
 * non-finite fallback plots at 0 rather than as a NaN-sized marker.
 *
 * @param {object} r A search result.
 * @param {{min: number, max: number}} range Rank-score range over the page.
 * @param {{min: number, max: number}} uncalibratedRange Range over uncalibrated rows.
 * @return {number} Plot score in [0,1].
 */
export function plotScore(r, range, uncalibratedRange) {
	const { min, max } = range
	const s = Number.isFinite(r.relevance)
		? displayRelevance(r, uncalibratedRange)
		: (max > min ? (rankScore(r) - min) / (max - min) : 1)
	return Number.isFinite(s) ? Math.min(1, Math.max(0, s)) : 0
}

/**
 * The two Plotly traces: trace 0 the document results, trace 1 the query
 * point. Click handling relies on that order.
 *
 * @param {object} input Plot input.
 * @param {object[]} input.results Rendered results, parallel to `coordinates`.
 * @param {number[][]} input.coordinates PCA coordinates, one [x,y,z] per result.
 * @param {number[]} input.queryCoords The query's [x,y,z].
 * @param {{min: number, max: number}} input.scoreRange Rank-score range over the page.
 * @param {{min: number, max: number}} input.uncalibratedRange Range over uncalibrated rows.
 * @param {boolean} input.showQueryPoint Whether the query trace is visible.
 * @return {object[]} [documentTrace, queryTrace]
 */
export function plotTraces({ results, coordinates, queryCoords, scoreRange, uncalibratedRange, showQueryPoint }) {
	const hasOwnRelevance = (r) => Number.isFinite(r.relevance)
	const scores = results.map((r) => plotScore(r, scoreRange, uncalibratedRange))

	// Trace 1: Document results (always visible)
	const documentTrace = {
		x: coordinates.map((c) => c[0]),
		y: coordinates.map((c) => c[1]),
		z: coordinates.map((c) => c[2]),
		mode: 'markers',
		type: 'scatter3d',
		name: 'Documents',
		visible: true,
		// The score lines are prebuilt per point: the rerank line exists
		// only for rows the reranker scored, which one shared
		// hovertemplate cannot express.
		customdata: results.map((r, i) => ({
			title: r.title,
			scores: [
				`${hasOwnRelevance(r) ? 'Relevance' : 'Relative score'}: ${Math.round(scores[i] * 100)}%`,
				...(Number.isFinite(r.rerank_score) ? [`Rerank score: ${r.rerank_score.toFixed(3)}`] : []),
				`Retrieval score: ${(r.score ?? 0).toFixed(3)}`,
			].join('<br>'),
			x: coordinates[i][0],
			y: coordinates[i][1],
			z: coordinates[i][2],
		})),

		hovertemplate:
			'<b>%{customdata.title}</b><br>'
			+ '%{customdata.scores}<br>'
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
			size: scores.map((s) => 4 + (Math.pow(s, 2) * 10)),
			opacity: scores.map((s) => 0.3 + (s * 0.7)),
			color: scores,
			colorscale: 'Viridis',
			showscale: true,
			colorbar: {
				title: { text: 'Relevance' },
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
		visible: showQueryPoint,
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

	return [documentTrace, queryTrace]
}

/**
 * The index, into the rendered results, of a clicked document point; null for
 * a click on the query point (trace 1) or on nothing.
 *
 * @param {object} eventData Plotly's plotly_click event.
 * @return {number|null} Index into the rendered results.
 */
export function clickedResultIndex(eventData) {
	if (!eventData?.points || eventData.points.length === 0) {
		return null
	}
	const point = eventData.points[0]
	// 0 = documents, 1 = query
	return point.curveNumber === 0 ? point.pointNumber : null
}
