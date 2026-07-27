/**
 * Per-endpoint pagination behaviour.
 *
 * There is deliberately no global default. FluentCart endpoints disagree about parameter names
 * and maxima, so a single shared 10/50 rule would quietly rewrite the semantics of whichever
 * endpoints did not match it — and the caller would never know which page they had really read.
 */
export interface PaginationProfile {
	/** Public tool this profile belongs to. */
	tool: string
	/** Upstream query parameter carrying the page number. */
	pageParam: string
	/** Upstream query parameter carrying the page size. */
	perPageParam: string
	/** Observed default when the parameter is omitted, or null when upstream does not say. */
	defaultPerPage: number | null
	minPerPage: number
	/** Verified upstream maximum. Requests above it are rejected, never silently clamped. */
	maxPerPage: number
	/** Where the default and maximum were observed. */
	evidence: string
}

export class PaginationError extends Error {
	readonly code = 'INVALID_PAGINATION'
	constructor(message: string) {
		super(message)
		this.name = 'PaginationError'
	}
}

const PROFILES: readonly PaginationProfile[] = [
	{
		tool: 'fluentcart_order_list',
		pageParam: 'page',
		perPageParam: 'per_page',
		defaultPerPage: 10,
		minPerPage: 1,
		maxPerPage: 100,
		evidence: 'tests/fixtures/rest read-contract capture, orders index',
	},
	{
		tool: 'fluentcart_product_list',
		pageParam: 'page',
		perPageParam: 'per_page',
		defaultPerPage: 10,
		minPerPage: 1,
		maxPerPage: 100,
		evidence: 'tests/fixtures/rest read-contract capture, products index',
	},
	{
		tool: 'fluentcart_customer_list',
		pageParam: 'page',
		perPageParam: 'per_page',
		defaultPerPage: 10,
		minPerPage: 1,
		maxPerPage: 100,
		evidence: 'tests/fixtures/rest read-contract capture, customers index',
	},
	{
		tool: 'fluentcart_subscription_list',
		pageParam: 'page',
		perPageParam: 'per_page',
		defaultPerPage: 10,
		minPerPage: 1,
		maxPerPage: 100,
		evidence: 'tests/fixtures/rest read-contract capture, subscriptions index',
	},
]

const BY_TOOL = new Map(PROFILES.map((profile) => [profile.tool, profile]))

export function paginationProfiles(): readonly PaginationProfile[] {
	return PROFILES
}

export function paginationProfile(tool: string): PaginationProfile | null {
	return BY_TOOL.get(tool) ?? null
}

export interface PaginationRequest {
	page: number
	perPage: number
	/** Upstream query parameters to send, already named for the selected endpoint. */
	params: Record<string, number>
}

/**
 * Validate and bind pagination for one tool.
 *
 * The size is bounded BEFORE the upstream request, which is the only place bounding is safe:
 * trimming a page after it arrives and then advancing the page number skips whatever was
 * trimmed, and nothing downstream can detect that rows went missing.
 */
export function resolvePagination(
	tool: string,
	input: { page?: unknown; per_page?: unknown },
): PaginationRequest {
	const profile = paginationProfile(tool)
	if (!profile) {
		throw new PaginationError(
			`No pagination profile for ${tool}. Add one with its verified default and maximum before exposing a list tool.`,
		)
	}

	const page = normaliseInteger(input.page, 'page', 1) ?? 1
	if (page < 1) throw new PaginationError(`page must be 1 or greater, received ${page}.`)

	const requested = normaliseInteger(input.per_page, 'per_page', profile.minPerPage)
	// Omitted means "use the endpoint's own default", so nothing is sent and upstream decides.
	if (requested === null) {
		return {
			page,
			perPage: profile.defaultPerPage ?? profile.minPerPage,
			params: { [profile.pageParam]: page },
		}
	}

	if (requested < profile.minPerPage) {
		throw new PaginationError(
			`per_page must be at least ${profile.minPerPage} for ${tool}, received ${requested}.`,
		)
	}
	if (requested > profile.maxPerPage) {
		throw new PaginationError(
			`per_page must be at most ${profile.maxPerPage} for ${tool}, received ${requested}.`,
		)
	}

	return {
		page,
		perPage: requested,
		params: { [profile.pageParam]: page, [profile.perPageParam]: requested },
	}
}

function normaliseInteger(value: unknown, field: string, _min: number): number | null {
	if (value === undefined || value === null || value === '') return null

	const parsed = typeof value === 'number' ? value : Number(value)
	if (!Number.isFinite(parsed)) {
		throw new PaginationError(`${field} must be a number, received ${JSON.stringify(value)}.`)
	}
	if (!Number.isInteger(parsed)) {
		throw new PaginationError(`${field} must be a whole number, received ${parsed}.`)
	}
	return parsed
}

/**
 * Continuation instructions phrased in the public tool's own parameter names.
 *
 * A caller should never have to know the upstream parameter is called `per_page` when the tool
 * input calls it something else.
 */
export function continuationHint(tool: string, nextPage: number | null): string | null {
	if (nextPage === null) return null
	return `Call ${tool} again with page=${nextPage} for the next page.`
}
