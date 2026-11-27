// Run: node --test src/utils/searchFilters.test.mjs
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { activeFilterChips, formatFilterDate, mergeFolders, pathFilterApplicable, toggledSelection, toRfc3339 } from './searchFilters.js'

// Stand-in translator: substitutes {vars} like @nextcloud/l10n does.
const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (_, k) => String(vars[k]))

const docTypeOptions = [
	{ id: 'note', label: 'Notes' },
	{ id: 'file', label: 'Files' },
]

const noFilters = {
	selectedDocTypes: [],
	docTypeOptions,
	modifiedAfter: '',
	modifiedBefore: '',
	pathPrefixes: [],
}

test('pathFilterApplicable: no type filter, or Files among the types', () => {
	assert.equal(pathFilterApplicable([]), true)
	assert.equal(pathFilterApplicable(['file']), true)
	assert.equal(pathFilterApplicable(['note', 'file']), true)
	assert.equal(pathFilterApplicable(['note']), false)
})

test('toRfc3339: local wall-clock to UTC, empty for blank or invalid', () => {
	assert.equal(toRfc3339(''), '')
	assert.equal(toRfc3339(null), '')
	assert.equal(toRfc3339('not a date'), '')
	const out = toRfc3339('2026-01-01T00:00')
	assert.match(out, /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/)
	assert.equal(new Date(out).getTime(), new Date('2026-01-01T00:00').getTime())
})

test('formatFilterDate: passes through what is not a date', () => {
	assert.equal(formatFilterDate('garbage'), 'garbage')
	assert.equal(formatFilterDate('2026-01-01T00:00'), new Date('2026-01-01T00:00').toLocaleString())
})

test('toggledSelection: adds once, removes all, never mutates', () => {
	const list = ['note']
	assert.deepEqual(toggledSelection(list, 'file', true), ['note', 'file'])
	assert.deepEqual(toggledSelection(list, 'note', true), ['note'])
	assert.deepEqual(toggledSelection(list, 'note', false), [])
	assert.deepEqual(toggledSelection(list, 'file', false), ['note'])
	assert.deepEqual(list, ['note'])
	assert.notEqual(toggledSelection(list, 'note', true), list)
})

test('mergeFolders: single or multiple picks, trimmed, deduped, cancel is a no-op', () => {
	assert.deepEqual(mergeFolders(['/a'], ['/b', ' /c ', '/a']), ['/a', '/b', '/c'])
	assert.deepEqual(mergeFolders([], '/x'), ['/x'])
	assert.deepEqual(mergeFolders(['/a'], null), ['/a'])
	assert.deepEqual(mergeFolders(['/a'], ['', '  ', null]), ['/a'])
})

test('activeFilterChips: none when nothing is set', () => {
	assert.deepEqual(activeFilterChips(noFilters, t), [])
})

test('activeFilterChips: one chip per type, using its label when offered', () => {
	const chips = activeFilterChips({ ...noFilters, selectedDocTypes: ['note', 'gone'] }, t)
	assert.deepEqual(chips, [
		{ key: 'doc:note', label: 'Notes', kind: 'doc', value: 'note' },
		{ key: 'doc:gone', label: 'gone', kind: 'doc', value: 'gone' },
	])
})

test('activeFilterChips: date bounds', () => {
	const chips = activeFilterChips({ ...noFilters, modifiedAfter: 'x', modifiedBefore: 'y' }, t)
	assert.deepEqual(chips, [
		{ key: 'after', label: 'After x', kind: 'after' },
		{ key: 'before', label: 'Before y', kind: 'before' },
	])
})

test('activeFilterChips: folders only while the path filter applies', () => {
	const withFolder = { ...noFilters, pathPrefixes: ['/Docs'] }
	assert.deepEqual(activeFilterChips(withFolder, t), [
		{ key: 'path:/Docs', label: 'Folder: /Docs', kind: 'path', value: '/Docs' },
	])
	const notesOnly = activeFilterChips({ ...withFolder, selectedDocTypes: ['note'] }, t)
	assert.deepEqual(notesOnly.map((c) => c.kind), ['doc'])
})
