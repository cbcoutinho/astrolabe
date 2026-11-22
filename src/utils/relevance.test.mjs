// Run: node --test src/utils/relevance.test.mjs
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { displayRelevance, relevancePredicate, scoreRange } from './relevance.js'

// amazon.rerank-v1 shape: low raw scale, no fitted curve on the server.
const uncal = [0.21, 0.12, 0.05, 0.1].map((s) => ({
	rerank_score: s,
	relevance: s,
	relevance_source: 'uncalibrated',
}))
const kept = (rows, pct) => rows.filter(relevancePredicate(rows, pct)).map((r) => r.rerank_score)

test('uncalibrated: 0% keeps all, 100% keeps only the top', () => {
	assert.deepEqual(kept(uncal, 0), [0.21, 0.12, 0.05, 0.1])
	assert.deepEqual(kept(uncal, 100), [0.21])
})

test('uncalibrated: cut is relative, not absolute', () => {
	// An absolute 30% cut would drop every row; relative 50% keeps >= 0.13.
	assert.deepEqual(kept(uncal, 30), [0.21, 0.12, 0.1])
	assert.deepEqual(kept(uncal, 50), [0.21])
})

test('uncalibrated: 100% keeps the top even when float interpolation overshoots', () => {
	const rows = [0.1, 0.3].map((s) => ({ rerank_score: s, relevance: s, relevance_source: 'uncalibrated' }))
	// 0.1 + 1 * (0.3 - 0.1) === 0.30000000000000004 > 0.3
	assert.deepEqual(kept(rows, 100), [0.3])
})

test('uncalibrated: an ordinal tail the reranker missed is never hidden', () => {
	const tail = { score: 0.01, relevance: 0.02, relevance_source: 'fusion_ordinal' }
	const rows = [...uncal, tail]
	assert.ok(rows.filter(relevancePredicate(rows, 100)).includes(tail))
})

test('calibrated: absolute cut on relevance, unchanged', () => {
	const rows = [0.9, 0.4, 0.2].map((p) => ({ rerank_score: p, relevance: p, relevance_source: 'cross_encoder_calibrated' }))
	assert.deepEqual(kept(rows, 30), [0.9, 0.4])
	assert.deepEqual(kept(rows, 100), [])
})

test('no relevance field (older server): relative over rank score', () => {
	const rows = [0.03, 0.02, 0.01].map((s) => ({ score: s }))
	assert.deepEqual(rows.filter(relevancePredicate(rows, 50)).map((r) => r.score), [0.03, 0.02])
})

test('displayRelevance normalises uncalibrated rows across the page', () => {
	const range = scoreRange(uncal)
	assert.equal(displayRelevance(uncal[0], range), 1)
	assert.equal(displayRelevance(uncal[2], range), 0)
	assert.equal(displayRelevance({ relevance: 0.42, relevance_source: 'cross_encoder_calibrated' }, range), 0.42)
	const one = scoreRange([uncal[0]])
	assert.equal(displayRelevance(uncal[0], one), 1)
})

test('empty page admits nothing and does not throw', () => {
	assert.deepEqual([].filter(relevancePredicate([], 50)), [])
})

test('uncalibrated DBSF with negative scores: 0% keeps all, cut is offset-invariant', () => {
	const rows = [0.4, -0.2, -0.8].map((s) => ({ score: s, relevance: Math.max(0, s), relevance_source: 'uncalibrated' }))
	const keep = (pct) => rows.filter(relevancePredicate(rows, pct)).map((r) => r.score)
	assert.deepEqual(keep(0), [0.4, -0.2, -0.8])
	assert.deepEqual(keep(40), [0.4, -0.2])
	assert.deepEqual(keep(100), [0.4])
})

test('mixed calibrated + uncalibrated page: only uncalibrated rows are cut', () => {
	const cal = { rerank_score: 0.01, relevance: 0.05, relevance_source: 'cross_encoder_calibrated' }
	const rows = [...uncal, cal]
	const keptRows = rows.filter(relevancePredicate(rows, 100))
	assert.ok(keptRows.includes(cal))
	assert.deepEqual(keptRows.filter((r) => r !== cal).map((r) => r.rerank_score), [0.21])
})
