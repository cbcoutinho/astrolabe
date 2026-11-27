// The slider/bar rules for a search page, kept out of App.vue so they can be
// unit-tested (node --test src/utils/relevance.test.mjs).

// `relevance_source` the server uses for a value that orders results but has no
// fitted curve: dense cosine, DBSF, or a reranker without a calibration curve.
// Its absolute level is the model's scale, not a probability, so an absolute
// cut on it is meaningless -- amazon.rerank-v1 puts good hits around 0.2.
export const UNCALIBRATED = 'uncalibrated'

// For uncalibrated rows the cut and bars follow this, not `relevance`; the
// server derives that from the same signal (a clamp), so the two agree in order.
//
// It is the cross-encoder score when the server reranked, else the retrieval
// score, matching the order the server returned rows in, so the filter always
// removes a suffix of the list rather than punching holes in it. Reranking is
// NOT required: searches ask for it only when the server advertises
// `rerank_available`, and it degrades to retrieval order on failure, so
// `rerank_score` may be absent from some or all rows -- this must stay a
// fallback, never a requirement.
export const rankScore = (r) => r.rerank_score ?? r.score ?? 0

const isUncalibrated = (r) => r.relevance_source === UNCALIBRATED

export function scoreRange(rows) {
	return rows.reduce(
		(acc, r) => {
			const s = rankScore(r)
			return { min: Math.min(acc.min, s), max: Math.max(acc.max, s) }
		},
		{ min: Infinity, max: -Infinity },
	)
}

// Interpolate across the observed range: 0% keeps everything, 100% keeps only
// rows tied with the best. Scale- AND offset-invariant, so it works for an RRF
// score (~0.033 max), a cosine, and a DBSF score that can go negative (a 0
// anchor would drop every negative row at 0%). Both ends are pinned because
// `min + 1 * (max - min)` can land a float ULP above `max` and drop the top row.
export function relativeCut({ min, max }, minRelevance) {
	// Empty page: the range is +/-Infinity and the cut would be NaN; nothing to keep anyway.
	if (minRelevance <= 0 || !Number.isFinite(max)) {
		return -Infinity
	}
	if (minRelevance >= 100) {
		return max
	}
	return min + (minRelevance / 100) * (max - min)
}

export function hasUncalibrated(results) {
	return results.some(isUncalibrated)
}

// THE cut (minRelevance in percent). Three regimes:
//  * any row uncalibrated -> relative-to-top over the uncalibrated rows; other
//    rows (an ordinal tail the reranker did not reach) are never hidden, as
//    with a row that has no `relevance` at all;
//  * otherwise, rows carry `relevance` -> absolute cut on it, since a
//    calibrated/ordinal value means the same thing on every query;
//  * an older server with no `relevance` -> relative over all rows.
export function relevancePredicate(results, minRelevance) {
	if (hasUncalibrated(results)) {
		const cut = relativeCut(scoreRange(results.filter(isUncalibrated)), minRelevance)
		return (r) => !isUncalibrated(r) || rankScore(r) >= cut
	}
	if (results.some((r) => typeof r.relevance === 'number')) {
		const cut = minRelevance / 100
		// Missing relevance is UNKNOWN, not zero: never dropped by the cut.
		return (r) => typeof r.relevance !== 'number' || r.relevance >= cut
	}
	const cut = relativeCut(scoreRange(results), minRelevance)
	return (r) => rankScore(r) >= cut
}

// [0,1] fill for a row's bar / plot marker. Uncalibrated rows are normalised
// across this page's uncalibrated range, so a low-scale reranker does not draw
// every bar near-empty; everything else shows its own clamped `relevance`.
// `range` is scoreRange(uncalibrated rows), computed once per page.
export function displayRelevance(r, range) {
	if (isUncalibrated(r)) {
		const { min, max } = range
		return max > min ? Math.min(1, Math.max(0, (rankScore(r) - min) / (max - min))) : 1
	}
	return Math.min(1, Math.max(0, r.relevance ?? 0))
}
