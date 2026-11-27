// Run: node --test src/utils/plot.test.mjs
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { clickedResultIndex, plotScore, plotTraces } from './plot.js'

const range = { min: 0.01, max: 0.03 }
const noUncal = { min: 0, max: 0 }

test('plotScore: a calibrated relevance plots at its own value', () => {
	const r = { relevance: 0.8, relevance_source: 'cross_encoder_calibrated', score: 0.01 }
	assert.equal(plotScore(r, range, noUncal), 0.8)
})

test('plotScore: without relevance, the rank score is scaled across the page', () => {
	assert.equal(plotScore({ score: 0.03 }, range, noUncal), 1)
	assert.equal(plotScore({ score: 0.01 }, range, noUncal), 0)
	assert.ok(Math.abs(plotScore({ score: 0.02 }, range, noUncal) - 0.5) < 1e-9)
	// rerank_score wins over score when present.
	assert.equal(plotScore({ rerank_score: 0.03, score: 0.01 }, range, noUncal), 1)
})

test('plotScore: a flat page plots every row at full size', () => {
	assert.equal(plotScore({ score: 0.5 }, { min: 0.5, max: 0.5 }, noUncal), 1)
})

test('plotScore: clamped to [0,1], never NaN', () => {
	assert.equal(plotScore({ relevance: 1.7 }, range, noUncal), 1)
	assert.equal(plotScore({ relevance: -0.2 }, range, noUncal), 0)
	assert.equal(plotScore({ score: Number.NaN }, range, noUncal), 0)
	assert.equal(plotScore({ score: 99 }, range, noUncal), 1)
})

test('plotTraces: documents first, query second, parallel to the input', () => {
	const results = [
		{ title: 'A', relevance: 0.9, relevance_source: 'cross_encoder_calibrated', rerank_score: 2.5, score: 0.03 },
		{ title: 'B', score: 0.01 },
	]
	const coordinates = [[1, 2, 3], [4, 5, 6]]
	const [docs, query] = plotTraces({
		results,
		coordinates,
		queryCoords: [7, 8, 9],
		scoreRange: range,
		uncalibratedRange: noUncal,
		showQueryPoint: false,
	})
	assert.equal(docs.name, 'Documents')
	assert.deepEqual(docs.x, [1, 4])
	assert.deepEqual(docs.z, [3, 6])
	assert.equal(docs.customdata[0].title, 'A')
	assert.equal(docs.customdata[0].scores, 'Relevance: 90%<br>Rerank score: 2.500<br>Retrieval score: 0.030')
	// No rerank line for a row the reranker did not score; "Relative" when
	// the number shown is not the row's own relevance.
	assert.equal(docs.customdata[1].scores, 'Relative score: 0%<br>Retrieval score: 0.010')
	assert.deepEqual(docs.marker.color, [0.9, 0])
	assert.equal(query.name, 'Query')
	assert.deepEqual([query.x[0], query.y[0], query.z[0]], [7, 8, 9])
	assert.equal(query.visible, false)
})

test('clickedResultIndex: document points only', () => {
	assert.equal(clickedResultIndex({ points: [{ curveNumber: 0, pointNumber: 3 }] }), 3)
	assert.equal(clickedResultIndex({ points: [{ curveNumber: 0, pointNumber: 0 }] }), 0)
	assert.equal(clickedResultIndex({ points: [{ curveNumber: 1, pointNumber: 0 }] }), null)
	assert.equal(clickedResultIndex({ points: [] }), null)
	assert.equal(clickedResultIndex({}), null)
})
