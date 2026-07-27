/**
 * The reference-data contract: what each kind is, where it comes from and how it is read.
 *
 * Kept apart from the fetcher because this half is a reviewed statement about the store API and
 * the other half is behaviour. A route or permission expectation should be reviewable without
 * reading pagination and projection logic around it.
 */

export type ReferenceKind =
	| 'payment_methods'
	| 'tax_classes'
	| 'shipping_zones'
	| 'countries'
	| 'labels'
	| 'product_categories'

export interface ReferenceItem {
	id: string | number
	label: string
	code?: string
	status?: string
}

/**
 * What one reference kind is, in enough detail to audit without running it.
 *
 * `candidates` are the source field names a row may carry, in preference order. FluentCart is
 * not consistent across these endpoints, and guessing a single field name would silently
 * mislabel whole lists on the versions that disagree.
 */
export interface ReferenceDescriptor {
	kind: ReferenceKind
	method: 'GET'
	route: string
	/** Where rows live in the response body, tried in order before falling back to the root. */
	collectionPaths: readonly string[]
	permission: string
	candidates: {
		id: readonly string[]
		label: readonly string[]
		code: readonly string[]
		status: readonly string[]
	}
	/** Verified maximum page size. A larger request is rejected, never clamped. */
	maxPerPage: number
	defaultPerPage: number
	evidence: string
}

const COMMON_ID = ['id', 'ID', 'term_id', 'slug', 'key', 'code'] as const
const COMMON_LABEL = ['label', 'title', 'name', 'display_name', 'text'] as const

/**
 * Every kind names one exact route the current runtime serves.
 *
 * All six are GET reads over the authenticated admin namespace, so none of them can be reached
 * by the write-exposure policy and none needs a guarded path.
 */
export const REFERENCE_DESCRIPTORS: Readonly<Record<ReferenceKind, ReferenceDescriptor>> = {
	payment_methods: {
		kind: 'payment_methods',
		method: 'GET',
		route: '/settings/payment-methods/all',
		collectionPaths: ['payment_methods', 'methods', 'data'],
		permission: 'Authenticated store manager; FluentCart settings read capability.',
		candidates: {
			id: ['key', 'slug', 'id'],
			label: COMMON_LABEL,
			code: ['key', 'slug', 'gateway'],
			status: ['status', 'enabled', 'is_active'],
		},
		maxPerPage: 100,
		defaultPerPage: 50,
		evidence:
			'GET /settings/payment-methods/all in tests/fixtures/routes/fluentcart-1.5.5-core-pro-1.5.4.json',
	},
	tax_classes: {
		kind: 'tax_classes',
		method: 'GET',
		route: '/tax/classes',
		collectionPaths: ['tax_classes', 'classes', 'data'],
		permission: 'Authenticated store manager; FluentCart tax read capability.',
		candidates: {
			id: COMMON_ID,
			label: COMMON_LABEL,
			code: ['slug', 'code'],
			status: ['status', 'is_active'],
		},
		maxPerPage: 100,
		defaultPerPage: 50,
		evidence: 'GET /tax/classes in tests/fixtures/routes/fluentcart-1.5.5-core-pro-1.5.4.json',
	},
	shipping_zones: {
		kind: 'shipping_zones',
		method: 'GET',
		route: '/shipping/zones',
		collectionPaths: ['zones', 'shipping_zones', 'data'],
		permission: 'Authenticated store manager; FluentCart shipping read capability.',
		candidates: {
			id: COMMON_ID,
			label: COMMON_LABEL,
			code: ['slug', 'code', 'region'],
			status: ['status', 'is_active'],
		},
		maxPerPage: 100,
		defaultPerPage: 50,
		evidence: 'GET /shipping/zones in tests/fixtures/routes/fluentcart-1.5.5-core-pro-1.5.4.json',
	},
	countries: {
		kind: 'countries',
		method: 'GET',
		route: '/address-info/countries',
		collectionPaths: ['countries', 'data'],
		permission: 'Authenticated store manager; address reference read.',
		candidates: {
			id: ['code', 'iso2', 'key', 'id'],
			label: ['name', 'label', 'title'],
			code: ['code', 'iso2', 'iso3'],
			status: [],
		},
		maxPerPage: 100,
		defaultPerPage: 50,
		evidence:
			'GET /address-info/countries in tests/fixtures/routes/fluentcart-1.5.5-core-pro-1.5.4.json',
	},
	labels: {
		kind: 'labels',
		method: 'GET',
		route: '/labels',
		collectionPaths: ['labels', 'data'],
		permission: 'Authenticated store manager; FluentCart label read capability.',
		candidates: {
			id: COMMON_ID,
			label: COMMON_LABEL,
			code: ['slug', 'code', 'color'],
			status: ['status', 'is_active'],
		},
		maxPerPage: 100,
		defaultPerPage: 50,
		evidence: 'GET /labels in tests/fixtures/routes/fluentcart-1.5.5-core-pro-1.5.4.json',
	},
	product_categories: {
		kind: 'product_categories',
		method: 'GET',
		route: '/products/fetch-term',
		collectionPaths: ['terms', 'categories', 'data'],
		permission: 'Authenticated store manager; product taxonomy read capability.',
		candidates: {
			id: ['term_id', 'id', 'slug'],
			label: COMMON_LABEL,
			code: ['slug', 'taxonomy'],
			status: ['status'],
		},
		maxPerPage: 100,
		defaultPerPage: 50,
		evidence:
			'GET /products/fetch-term in tests/fixtures/routes/fluentcart-1.5.5-core-pro-1.5.4.json',
	},
}

export const REFERENCE_KINDS = Object.keys(REFERENCE_DESCRIPTORS) as ReferenceKind[]

export class UnknownReferenceKindError extends Error {
	readonly code = 'UNKNOWN_REFERENCE_KIND'
	constructor(received: unknown) {
		super(
			`Unknown reference kind ${JSON.stringify(received)}. Supported kinds: ${REFERENCE_KINDS.join(', ')}.`,
		)
		this.name = 'UnknownReferenceKindError'
	}
}

/** Resolve a kind locally. An unknown kind never reaches the network. */
export function referenceDescriptor(kind: unknown): ReferenceDescriptor {
	if (typeof kind !== 'string' || !(kind in REFERENCE_DESCRIPTORS)) {
		throw new UnknownReferenceKindError(kind)
	}
	return REFERENCE_DESCRIPTORS[kind as ReferenceKind]
}

/**
 * Reviewed writes and the reference scope each one invalidates.
 *
 * Scoped deliberately: creating a label says nothing about tax classes, and clearing everything
 * would misrepresent what the write actually touched.
 */
export const REFERENCE_INVALIDATIONS: Readonly<Record<string, readonly ReferenceKind[]>> = {
	fluentcart_label_create: ['labels'],
	fluentcart_label_update_selections: ['labels'],
	fluentcart_tax_class_create: ['tax_classes'],
	fluentcart_tax_class_delete: ['tax_classes'],
	fluentcart_shipping_zone_create: ['shipping_zones'],
	fluentcart_shipping_zone_update: ['shipping_zones'],
	fluentcart_shipping_zone_delete: ['shipping_zones'],
	fluentcart_settings_save_payment_method: ['payment_methods'],
	fluentcart_settings_reorder_payment_methods: ['payment_methods'],
	fluentcart_product_terms_add: ['product_categories'],
}

/** Cache operation name for one kind, so invalidation can target a single list. */
export function referenceOperation(kind: ReferenceKind): string {
	return `reference:${kind}`
}
