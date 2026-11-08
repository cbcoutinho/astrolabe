/**
 * Turn a chunk-context window of a CSV file into table rows.
 *
 * The window is before + chunk + after, cut at arbitrary character offsets,
 * so a truncated edge (has_more_before / has_more_after) starts or ends
 * mid-line and that partial line is dropped. The header is only known when
 * the window reaches the start of the file.
 *
 * @param {object} w chunk-context window
 * @param {string} [w.before] text before the chunk
 * @param {string} [w.chunk] the matched chunk
 * @param {string} [w.after] text after the chunk
 * @param {boolean} [w.hasMoreBefore] window does not start at the file start
 * @param {boolean} [w.hasMoreAfter] window does not end at the file end
 * @return {{header: string[]|null, rows: {cells: string[], inChunk: boolean}[]}}
 */
export function parseCsvWindow({ before = '', chunk = '', after = '', hasMoreBefore = false, hasMoreAfter = false }) {
	const text = before + chunk + after
	const chunkStart = before.length
	const chunkEnd = chunkStart + chunk.length

	// ponytail: a quoted field spanning the cut line can mis-align the first
	// or last row; fetch the file's header/row boundaries if that matters.
	let from = 0
	let to = text.length
	if (hasMoreBefore) {
		const nl = text.indexOf('\n')
		from = nl === -1 ? to : nl + 1
	}
	if (hasMoreAfter) {
		const nl = text.lastIndexOf('\n')
		to = nl < from ? from : nl
	}

	const records = parseCsv(text, from, to)
	let header = null
	if (!hasMoreBefore && records.length > 0) {
		header = records.shift().cells
	}
	return {
		header,
		rows: records.map((r) => ({
			cells: r.cells,
			inChunk: r.start < chunkEnd && r.end > chunkStart,
		})),
	}
}

/**
 * RFC 4180 parse of text[from, to), keeping each record's offsets.
 * Delimiter is sniffed from the first line (`,`, `;` or tab).
 *
 * @param {string} text the whole window
 * @param {number} from start offset
 * @param {number} to end offset
 * @return {{cells: string[], start: number, end: number}[]}
 */
export function parseCsv(text, from = 0, to = text.length) {
	const firstLineEnd = text.indexOf('\n', from)
	const firstLine = text.slice(from, firstLineEnd === -1 || firstLineEnd > to ? to : firstLineEnd)
	const delim = [',', ';', '\t']
		.map((d) => [d, firstLine.split(d).length])
		.reduce((a, b) => (b[1] > a[1] ? b : a))[0]

	const records = []
	let cells = []
	let field = ''
	let quoted = false
	let recStart = from
	const endRecord = (end) => {
		cells.push(field.endsWith('\r') ? field.slice(0, -1) : field)
		if (cells.length > 1 || cells[0] !== '') {
			records.push({ cells, start: recStart, end })
		}
		cells = []
		field = ''
	}

	for (let i = from; i < to; i++) {
		const c = text[i]
		if (quoted) {
			if (c === '"' && text[i + 1] === '"' && i + 1 < to) {
				field += '"'
				i++
			} else if (c === '"') {
				quoted = false
			} else {
				field += c
			}
		} else if (c === '"' && field === '') {
			quoted = true
		} else if (c === delim) {
			cells.push(field)
			field = ''
		} else if (c === '\n') {
			endRecord(i)
			recStart = i + 1
		} else {
			field += c
		}
	}
	if (field !== '' || cells.length > 0) {
		endRecord(to)
	}
	return records
}
