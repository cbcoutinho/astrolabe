// Run: node --test src/utils/documentUrl.test.mjs
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { documentUrl } from './documentUrl.js'

const toUrl = (path) => `/index.php${path}`

test('notes open by id, including the legacy note_id field', () => {
	assert.equal(documentUrl({ doc_type: 'note', id: 7 }, toUrl), '/index.php/apps/notes/#/note/7')
	assert.equal(documentUrl({ doc_type: 'note', note_id: 8 }, toUrl), '/index.php/apps/notes/#/note/8')
})

test('files open in the Files app, or its root without an id', () => {
	assert.equal(
		documentUrl({ doc_type: 'file', id: 42 }, toUrl),
		'/index.php/apps/files/files/42?dir=/&editing=false&openfile=true',
	)
	assert.equal(documentUrl({ doc_type: 'file' }, toUrl), '/index.php/apps/files/')
})

test('deck cards need both board and card id for a deep link', () => {
	assert.equal(
		documentUrl({ doc_type: 'deck_card', id: 3, metadata: { board_id: 9 } }, toUrl),
		'/index.php/apps/deck/board/9/card/3',
	)
	assert.equal(documentUrl({ doc_type: 'deck_card', id: 3 }, toUrl), '/index.php/apps/deck/')
})

test('news items prefer the external article URL, unwrapped', () => {
	assert.equal(
		documentUrl({ doc_type: 'news_item', metadata: { url: 'https://example.org/a' } }, toUrl),
		'https://example.org/a',
	)
	assert.equal(documentUrl({ doc_type: 'news_item' }, toUrl), '/index.php/apps/news/')
})

test('types without a stable deep link fall back to their app root', () => {
	assert.equal(documentUrl({ doc_type: 'calendar' }, toUrl), '/index.php/apps/calendar/')
	assert.equal(documentUrl({ doc_type: 'calendar_event' }, toUrl), '/index.php/apps/calendar/')
	assert.equal(documentUrl({ doc_type: 'mail_message', id: 1 }, toUrl), '/index.php/apps/mail/')
	assert.equal(documentUrl({ doc_type: 'contact', id: 1 }, toUrl), '/index.php/apps/contacts/')
})

test('unknown or missing doc types land on Astrolabe', () => {
	assert.equal(documentUrl({ doc_type: 'something_new' }, toUrl), '/index.php/apps/astrolabe/')
	assert.equal(documentUrl({}, toUrl), '/index.php/apps/astrolabe/')
})
