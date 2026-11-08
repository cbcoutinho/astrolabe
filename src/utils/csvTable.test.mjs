// Run: node --test src/utils/csvTable.test.mjs
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { parseCsv, parseCsvWindow } from './csvTable.js'

test('quoted fields keep delimiters, quotes and newlines', () => {
	const rows = parseCsv('a,"b,c","say ""hi""","x\ny"\r\n1,2,3,4\n').map((r) => r.cells)
	assert.deepEqual(rows, [['a', 'b,c', 'say "hi"', 'x\ny'], ['1', '2', '3', '4']])
})

test('sniffs semicolon and tab delimiters', () => {
	assert.deepEqual(parseCsv('a;b,c;d').map((r) => r.cells), [['a', 'b,c', 'd']])
	assert.deepEqual(parseCsv('a\tb\n1\t2').map((r) => r.cells), [['a', 'b'], ['1', '2']])
})

test('window from file start: header, chunk rows flagged', () => {
	const t = parseCsvWindow({ before: 'h1,h2\nr1,a\n', chunk: 'r2,b\n', after: 'r3,c\nr4,d' })
	assert.deepEqual(t.header, ['h1', 'h2'])
	assert.deepEqual(t.rows.map((r) => [r.cells[0], r.inChunk]), [['r1', false], ['r2', true], ['r3', false], ['r4', false]])
})

test('truncated edges drop partial lines and the header', () => {
	const t = parseCsvWindow({
		before: 'tial,x\nr1,a\n',
		chunk: 'r2,b',
		after: '\nr3,c\nr4,pa',
		hasMoreBefore: true,
		hasMoreAfter: true,
	})
	assert.equal(t.header, null)
	assert.deepEqual(t.rows.map((r) => [r.cells[0], r.inChunk]), [['r1', false], ['r2', true], ['r3', false]])
})

test('delimiter sniffing ignores quoted spans', () => {
	assert.deepEqual(parseCsv('"a;b;c",d\n1,2').map((r) => r.cells), [['a;b;c', 'd'], ['1', '2']])
})

test('window without a whole row yields an empty table', () => {
	const t = parseCsvWindow({ chunk: 'partial,row', hasMoreBefore: true, hasMoreAfter: true })
	assert.deepEqual(t, { header: null, rows: [] })
})
