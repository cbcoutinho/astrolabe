// Run: node --test src/utils/chunkViewer.test.mjs
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { chunkContextParams, chunkMarkdown, imageFileIdsForSummary, isCsvResult, summaryPageWindow } from './chunkViewer.js'

test('chunkContextParams: offsets always, optional fields only when present', () => {
	assert.deepEqual(
		chunkContextParams({ doc_type: 'note', id: 5, chunk_start_offset: 10, chunk_end_offset: 20 }),
		{ doc_type: 'note', doc_id: 5, start: 10, end: 20 },
	)
})

test('chunkContextParams: chunk_index 0 and board_id 0 are sent, null is not', () => {
	const params = chunkContextParams({
		doc_type: 'deck_card',
		id: 1,
		chunk_start_offset: 0,
		chunk_end_offset: 5,
		chunk_index: 0,
		total_chunks: null,
		metadata: { board_id: 0, mailbox_id: null, calendar_uri: '', path: '' },
	})
	assert.equal(params.chunk_index, 0)
	assert.equal('total_chunks' in params, false)
	assert.equal(params.board_id, 0)
	assert.equal('mailbox_id' in params, false)
	assert.equal('calendar_uri' in params, false)
	assert.equal('path' in params, false)
})

test('chunkContextParams: access-check identifiers pass through', () => {
	const params = chunkContextParams({
		doc_type: 'file',
		id: 2,
		metadata: { mailbox_id: 4, calendar_uri: 'personal', path: '/a.pdf' },
	})
	assert.equal(params.mailbox_id, 4)
	assert.equal(params.calendar_uri, 'personal')
	assert.equal(params.path, '/a.pdf')
})

test('isCsvResult: by mime type or .csv extension, files only', () => {
	assert.equal(isCsvResult({ doc_type: 'file', metadata: { mime_type: 'text/csv' } }), true)
	assert.equal(isCsvResult({ doc_type: 'file', metadata: { path: '/x/Data.CSV' } }), true)
	assert.equal(isCsvResult({ doc_type: 'file', metadata: { path: '/x/data.csv.pdf' } }), false)
	assert.equal(isCsvResult({ doc_type: 'note', metadata: { mime_type: 'text/csv' } }), false)
	assert.equal(isCsvResult({ doc_type: 'file' }), false)
})

test('chunkMarkdown: chunk set off by rules between its context', () => {
	assert.equal(
		chunkMarkdown({ before: 'B', chunk: 'C', after: 'A' }),
		'B\n\n---\n\nC\n\n---\n\nA',
	)
	assert.equal(chunkMarkdown({ chunk: 'C' }), '---\n\nC\n\n---')
	assert.equal(chunkMarkdown({ before: '', chunk: '', after: '' }), '')
})

test('imageFileIdsForSummary: positive integer ids of image files only', () => {
	assert.deepEqual(imageFileIdsForSummary({ doc_type: 'file', id: '12', metadata: { mime_type: 'image/png' } }), [12])
	assert.deepEqual(imageFileIdsForSummary({ doc_type: 'file', id: 12, metadata: { mime_type: 'application/pdf' } }), [])
	assert.deepEqual(imageFileIdsForSummary({ doc_type: 'file', id: 0, metadata: { mime_type: 'image/png' } }), [])
	assert.deepEqual(imageFileIdsForSummary({ doc_type: 'file', id: 'x', metadata: { mime_type: 'image/png' } }), [])
	assert.deepEqual(imageFileIdsForSummary({ doc_type: 'note', id: 3, metadata: { mime_type: 'image/png' } }), [])
	assert.deepEqual(imageFileIdsForSummary({ doc_type: 'file', id: 3 }), [])
})

test('summaryPageWindow: starts one page before the current page', () => {
	assert.deepEqual(summaryPageWindow(5, 10, 3), [4, 5, 6])
	assert.deepEqual(summaryPageWindow(1, 10, 3), [1, 2, 3])
})

test('summaryPageWindow: clamps to the document', () => {
	assert.deepEqual(summaryPageWindow(10, 10, 3), [9, 10])
	assert.deepEqual(summaryPageWindow(99, 4, 3), [3, 4])
	assert.deepEqual(summaryPageWindow(1, 1, 3), [1])
	// Total unknown (0) is treated as a single page.
	assert.deepEqual(summaryPageWindow(3, 0, 3), [1])
})

test('summaryPageWindow: never exceeds the size, always within 1..total', () => {
	for (let total = 1; total <= 12; total++) {
		for (let current = 1; current <= total + 2; current++) {
			const pages = summaryPageWindow(current, total, 3)
			assert.ok(pages.length >= 1 && pages.length <= 3)
			assert.ok(pages.every((p) => p >= 1 && p <= total))
			assert.ok(pages.includes(Math.min(current, total)))
		}
	}
})
