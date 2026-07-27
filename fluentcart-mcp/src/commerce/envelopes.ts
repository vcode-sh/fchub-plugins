/**
 * The shape every paginated commerce response uses.
 *
 * `total` and `hasMore` are nullable on purpose: several FluentCart endpoints do not report a
 * total, and inventing one from the length of a single page is how agents end up confidently
 * telling a merchant they have ten orders when they have four hundred.
 */
export interface PageEnvelope<T> {
	data: T[]
	page: number
	perPage: number
	total: number | null
	hasMore: boolean | null
	nextPage: number | null
	warnings: string[]
}

export function emptyEnvelope<T>(page: number, perPage: number): PageEnvelope<T> {
	return { data: [], page, perPage, total: null, hasMore: false, nextPage: null, warnings: [] }
}

/**
 * Build an envelope from an upstream page.
 *
 * `total` is passed through only when upstream actually reported one. `hasMore` and `nextPage`
 * are derived from that total when available, and otherwise from whether the page came back
 * full — a heuristic that can never skip rows, only offer one extra empty page.
 */
export function buildEnvelope<T>(
	rows: T[],
	options: { page: number; perPage: number; total?: number | null; warnings?: string[] },
): PageEnvelope<T> {
	const { page, perPage } = options
	const total = options.total ?? null

	const hasMore = total === null ? rows.length >= perPage : page * perPage < total
	return {
		data: rows,
		page,
		perPage,
		total,
		hasMore,
		nextPage: hasMore ? page + 1 : null,
		warnings: options.warnings ?? [],
	}
}
