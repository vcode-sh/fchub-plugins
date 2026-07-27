/**
 * Search semantics that FluentCart's REST layer is proven to accept.
 *
 * Every filter below was read out of the filter class that serves the route, and every enum
 * value out of that class's own tab map. Nothing is inferred from a field existing on a table:
 * a column is not a filter, and a filter the endpoint ignores is worse than no filter at all —
 * the agent gets a full unfiltered page back and reports it as the answer.
 *
 * `advanced_filters` is deliberately not exposed. It is a real REST parameter — BaseFilter's
 * parseableKeyMap lists it and parseSearchGroups JSON-decodes it — but no operator has a
 * checked fixture proving how it encodes or what it returns. Advertising it would mean turning
 * an arbitrary expression into a guessed query string, so every entity reports
 * `advancedFilters: false` until operator evidence exists.
 */

export type SearchEntity = 'orders' | 'products' | 'customers' | 'subscriptions'

export const SEARCH_ENTITIES: readonly SearchEntity[] = [
	'orders',
	'products',
	'customers',
	'subscriptions',
]

export interface SearchCapability {
	entity: SearchEntity
	flatFilters: readonly string[]
	advancedFilters: boolean
	evidencePath: string
}

export class SearchError extends Error {
	readonly code = 'INVALID_SEARCH'
	constructor(message: string) {
		super(message)
		this.name = 'SearchError'
	}
}

interface FilterSpec {
	/** Query parameter as the endpoint reads it. */
	param: string
	/** Fixed set of accepted values, when the filter class enumerates one. */
	values?: readonly string[]
	/** True when the endpoint applies `whereIn`, so the value encodes as `param[]=`. */
	repeatable?: boolean
}

interface EntitySpec {
	path: string
	evidencePath: string
	filters: Record<string, FilterSpec>
}

/** Accepted by every filter class through BaseFilter::parseableKeyMap. */
const SHARED: Record<string, FilterSpec> = {
	sort_by: { param: 'sort_by' },
	sort_type: { param: 'sort_type', values: ['ASC', 'DESC'] },
}

const ORDER_VIEWS = [
	'on-hold',
	'paid',
	'completed',
	'processing',
	'renewal',
	'subscription',
	'onetime',
	'refunded',
	'partially_refunded',
	'upgraded_to',
	'upgraded_from',
	'b2b_purchase',
	'reverse_charge',
] as const

const PRODUCT_VIEWS = [
	'publish',
	'draft',
	'physical',
	'digital',
	'subscribable',
	'not_subscribable',
	'bundle',
	'non_bundle',
] as const

const SUBSCRIPTION_VIEWS = [
	'pending',
	'intended',
	'trialing',
	'active',
	'canceled',
	'paused',
	'expired',
	'failing',
	'expiring',
] as const

const ENTITIES: Record<SearchEntity, EntitySpec> = {
	orders: {
		path: '/orders',
		evidencePath: 'app/Services/Filter/OrderFilter.php',
		filters: {
			...SHARED,
			active_view: { param: 'active_view', values: ORDER_VIEWS },
			// OrderFilter::statusFilterKeys applies each with whereIn, so these are arrays.
			payment_statuses: { param: 'payment_statuses', repeatable: true },
			order_statuses: { param: 'order_statuses', repeatable: true },
			shipping_statuses: { param: 'shipping_statuses', repeatable: true },
		},
	},
	products: {
		path: '/products',
		evidencePath: 'app/Services/Filter/ProductFilter.php',
		filters: { ...SHARED, active_view: { param: 'active_view', values: PRODUCT_VIEWS } },
	},
	customers: {
		path: '/customers',
		// CustomerFilter::tabsMap is empty, so no active_view is advertised for customers.
		evidencePath: 'app/Services/Filter/CustomerFilter.php',
		filters: { ...SHARED },
	},
	subscriptions: {
		path: '/subscriptions',
		evidencePath: 'app/Modules/Subscriptions/Services/Filter/SubscriptionFilter.php',
		filters: { ...SHARED, active_view: { param: 'active_view', values: SUBSCRIPTION_VIEWS } },
	},
}

export function isSearchEntity(value: string): value is SearchEntity {
	return (SEARCH_ENTITIES as readonly string[]).includes(value)
}

export function assertSearchEntity(value: string): SearchEntity {
	if (!isSearchEntity(value)) {
		throw new SearchError(
			`Unknown search entity "${value}". Supported: ${SEARCH_ENTITIES.join(', ')}.`,
		)
	}
	return value
}

export function searchPath(entity: SearchEntity): string {
	return ENTITIES[entity].path
}

/**
 * What a caller may ask of one entity.
 *
 * `search` is listed first because it is the free-text filter every entity supports; the rest
 * are the structured ones that entity's filter class actually reads.
 */
export function getSearchCapability(entity: SearchEntity): SearchCapability {
	const spec = ENTITIES[entity]
	return {
		entity,
		flatFilters: ['search', ...Object.keys(spec.filters)],
		advancedFilters: false,
		evidencePath: spec.evidencePath,
	}
}

export function getAllSearchCapabilities(): SearchCapability[] {
	return SEARCH_ENTITIES.map(getSearchCapability)
}

/** The accepted values for a filter, when it has a fixed set. */
export function allowedValues(entity: SearchEntity, filter: string): readonly string[] | null {
	return ENTITIES[entity].filters[filter]?.values ?? null
}

/**
 * Turn a validated filter set into query parameters.
 *
 * Every failure here is local. An unknown filter name or an unknown enum value never reaches the
 * store: FluentCart ignores parameters it does not recognise, so a typo would come back as a
 * complete unfiltered page that looks exactly like a successful narrow search.
 */
export function buildSearchParams(
	entity: SearchEntity,
	options: { query?: string; filters?: Record<string, unknown> } = {},
): Record<string, unknown> {
	const spec = ENTITIES[entity]
	const params: Record<string, unknown> = {}

	if (options.query !== undefined) {
		if (typeof options.query !== 'string') {
			throw new SearchError('`query` must be a string.')
		}
		if (options.query.trim() !== '') params.search = options.query.trim()
	}

	for (const [name, value] of Object.entries(options.filters ?? {})) {
		if (value === undefined || value === null) continue

		const filter = spec.filters[name]
		if (!filter) {
			throw new SearchError(
				`Unknown filter "${name}" for ${entity}. Supported: ${Object.keys(spec.filters).join(', ')}.`,
			)
		}

		const values = Array.isArray(value) ? value : [value]
		if (!filter.repeatable && values.length > 1) {
			throw new SearchError(`Filter "${name}" for ${entity} accepts a single value.`)
		}

		const encoded = values.map((entry) => {
			const text = String(entry)
			if (filter.values && !filter.values.includes(text)) {
				throw new SearchError(
					`Unknown value "${text}" for ${entity} filter "${name}". Allowed: ${filter.values.join(', ')}.`,
				)
			}
			return text
		})

		params[filter.param] = filter.repeatable ? encoded : encoded[0]
	}

	return params
}
