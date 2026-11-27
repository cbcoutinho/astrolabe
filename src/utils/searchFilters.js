/**
 * SPDX-FileCopyrightText: 2025 Astrolabe contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Pure helpers behind the search form's structured filters (ADR-027), kept
 * free of DOM-bound imports so they can be tested under `node --test`.
 */

/**
 * Whether the folder (path) filter applies. file_path is only indexed for
 * files, so applying the filter while searching non-file types would silently
 * return nothing. It applies when Files is selected, or when no doc-type
 * filter is set (cross-app search includes files).
 *
 * @param {string[]} selectedDocTypes The selected doc-type ids.
 * @return {boolean} True when the path filter applies.
 */
export function pathFilterApplicable(selectedDocTypes) {
	return selectedDocTypes.length === 0 || selectedDocTypes.includes('file')
}

/**
 * Convert a native datetime-local value ("2026-01-01T00:00", local
 * wall-clock) to an RFC 3339 / ISO 8601 UTC string ("2026-01-01T…Z").
 *
 * @param {string} localValue A datetime-local value.
 * @return {string} The UTC timestamp, or '' for empty/invalid input so callers
 *   can omit the bound.
 */
export function toRfc3339(localValue) {
	if (!localValue) {
		return ''
	}
	const date = new Date(localValue)
	if (Number.isNaN(date.getTime())) {
		return ''
	}
	return date.toISOString()
}

/**
 * Short, locale-aware label for a filter chip from a datetime-local value.
 *
 * @param {string} localValue A datetime-local value.
 * @return {string} The label; the input unchanged when it is not a date.
 */
export function formatFilterDate(localValue) {
	const date = new Date(localValue)
	if (Number.isNaN(date.getTime())) {
		return localValue
	}
	return date.toLocaleString()
}

/**
 * A copy of `list` with `id` added (checked) or removed (unchecked). Order is
 * kept and an id is never added twice.
 *
 * @param {string[]} list The current selection.
 * @param {string} id The id to toggle.
 * @param {boolean} checked Whether it should be selected.
 * @return {string[]} The new selection.
 */
export function toggledSelection(list, id, checked) {
	if (checked) {
		return list.includes(id) ? [...list] : [...list, id]
	}
	return list.filter((item) => item !== id)
}

/**
 * Merge picked folders into the current set: trimmed, blanks dropped,
 * duplicates removed, first occurrence wins.
 *
 * @param {string[]} existing The folders already selected.
 * @param {string|string[]|null} picked What the folder picker returned.
 * @return {string[]} The merged folders.
 */
export function mergeFolders(existing, picked) {
	const paths = Array.isArray(picked) ? picked : [picked]
	const cleaned = paths
		.map((p) => (p || '').trim())
		.filter(Boolean)
	return [...new Set([...existing, ...cleaned])]
}

/**
 * The active structured filters as closable chips, so the user always sees
 * what is narrowing their results even with the advanced panel collapsed.
 *
 * @param {object} filters The form's filter state.
 * @param {string[]} filters.selectedDocTypes Selected doc-type ids.
 * @param {{id: string, label: string}[]} filters.docTypeOptions Offered doc types.
 * @param {string} filters.modifiedAfter datetime-local lower bound, or ''.
 * @param {string} filters.modifiedBefore datetime-local upper bound, or ''.
 * @param {string[]} filters.pathPrefixes Selected folders.
 * @param {(app: string, text: string, vars?: object) => string} t Translator.
 * @return {{key: string, label: string, kind: string, value?: string}[]} Chips.
 */
export function activeFilterChips({ selectedDocTypes, docTypeOptions, modifiedAfter, modifiedBefore, pathPrefixes }, t) {
	const chips = []
	for (const id of selectedDocTypes) {
		const opt = docTypeOptions.find((o) => o.id === id)
		chips.push({
			key: 'doc:' + id,
			label: opt ? opt.label : id,
			kind: 'doc',
			value: id,
		})
	}
	if (modifiedAfter) {
		chips.push({
			key: 'after',
			label: t('astrolabe', 'After {date}', { date: formatFilterDate(modifiedAfter) }),
			kind: 'after',
		})
	}
	if (modifiedBefore) {
		chips.push({
			key: 'before',
			label: t('astrolabe', 'Before {date}', { date: formatFilterDate(modifiedBefore) }),
			kind: 'before',
		})
	}
	// One chip per selected folder; only surface them when they will
	// actually apply (files in scope).
	if (pathFilterApplicable(selectedDocTypes)) {
		for (const folder of pathPrefixes) {
			chips.push({
				key: `path:${folder}`,
				label: t('astrolabe', 'Folder: {path}', { path: folder }),
				kind: 'path',
				value: folder,
			})
		}
	}
	return chips
}
