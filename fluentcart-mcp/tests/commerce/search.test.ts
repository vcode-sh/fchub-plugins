import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import {
	allowedValues,
	assertSearchEntity,
	buildSearchParams,
	getAllSearchCapabilities,
	getSearchCapability,
	SEARCH_ENTITIES,
	SearchError,
	searchPath,
} from '../../src/commerce/search.js'

const packageRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..')
const fixture = JSON.parse(
	readFileSync(
		resolve(packageRoot, 'tests/fixtures/routes/fluentcart-1.5.5-core-pro-1.5.4.json'),
		'utf8',
	),
)
const OPERATIONS = new Set(
	fixture.operations.map((o: { method: string; path: string }) => `${o.method} ${o.path}`),
)

describe('search route evidence', () => {
	it('searches only endpoints the captured registry serves', () => {
		for (const entity of SEARCH_ENTITIES) {
			expect(OPERATIONS.has(`GET ${searchPath(entity)}`)).toBe(true)
		}
	})

	it('covers exactly the four planned entities', () => {
		expect([...SEARCH_ENTITIES]).toEqual(['orders', 'products', 'customers', 'subscriptions'])
	})

	it('rejects an unknown entity locally', () => {
		expect(() => assertSearchEntity('invoices')).toThrow(SearchError)
		expect(() => assertSearchEntity('invoices')).toThrow(/orders, products, customers/)
	})
})

describe('advertised capabilities', () => {
	it('advertises no advanced filtering anywhere', () => {
		// `advanced_filters` is a real BaseFilter parameter, but no operator has a checked
		// fixture. Advertising it would mean encoding an arbitrary expression on a guess.
		for (const capability of getAllSearchCapabilities()) {
			expect(capability.advancedFilters).toBe(false)
		}
	})

	it('cites where the filters for each entity were verified', () => {
		for (const capability of getAllSearchCapabilities()) {
			expect(capability.evidencePath).toMatch(/Filter\.php$/)
		}
	})

	it('offers free-text search on every entity', () => {
		for (const entity of SEARCH_ENTITIES) {
			expect(getSearchCapability(entity).flatFilters).toContain('search')
		}
	})

	it('offers status views only where the filter class defines a tab map', () => {
		expect(getSearchCapability('orders').flatFilters).toContain('active_view')
		expect(getSearchCapability('products').flatFilters).toContain('active_view')
		expect(getSearchCapability('subscriptions').flatFilters).toContain('active_view')
		// CustomerFilter::tabsMap() returns an empty array, so customers get no view filter.
		expect(getSearchCapability('customers').flatFilters).not.toContain('active_view')
	})

	it('offers order status arrays only for orders', () => {
		expect(getSearchCapability('orders').flatFilters).toContain('payment_statuses')
		expect(getSearchCapability('products').flatFilters).not.toContain('payment_statuses')
	})

	it('publishes the enum values the filter classes accept', () => {
		expect(allowedValues('subscriptions', 'active_view')).toEqual([
			'pending',
			'intended',
			'trialing',
			'active',
			'canceled',
			'paused',
			'expired',
			'failing',
			'expiring',
		])
		expect(allowedValues('products', 'active_view')).toContain('digital')
		expect(allowedValues('customers', 'active_view')).toBeNull()
	})
})

describe('encoded requests', () => {
	it('encodes free text as the search parameter', () => {
		expect(buildSearchParams('orders', { query: 'ada@example.test' })).toEqual({
			search: 'ada@example.test',
		})
	})

	it('trims and drops an empty query rather than sending a blank filter', () => {
		expect(buildSearchParams('orders', { query: '  ada  ' })).toEqual({ search: 'ada' })
		expect(buildSearchParams('orders', { query: '   ' })).toEqual({})
	})

	it('encodes a status view', () => {
		expect(buildSearchParams('orders', { filters: { active_view: 'refunded' } })).toEqual({
			active_view: 'refunded',
		})
	})

	it('encodes repeatable status filters as arrays', () => {
		// OrderFilter applies these with whereIn, so an array is the correct encoding.
		expect(
			buildSearchParams('orders', { filters: { payment_statuses: ['paid', 'refunded'] } }),
		).toEqual({ payment_statuses: ['paid', 'refunded'] })
	})

	it('normalises a single repeatable value to an array', () => {
		expect(buildSearchParams('orders', { filters: { order_statuses: 'completed' } })).toEqual({
			order_statuses: ['completed'],
		})
	})

	it('combines query and filters', () => {
		expect(
			buildSearchParams('subscriptions', { query: 'ada', filters: { active_view: 'active' } }),
		).toEqual({ search: 'ada', active_view: 'active' })
	})

	it('ignores explicitly null filters instead of sending empty parameters', () => {
		expect(buildSearchParams('orders', { filters: { active_view: null } })).toEqual({})
	})
})

describe('local rejection', () => {
	// FluentCart ignores query parameters it does not recognise, so a typo would come back as a
	// complete unfiltered page that reads exactly like a successful narrow search. Every one of
	// these must fail before a request is made.
	it('rejects an unknown filter name and names the supported ones', () => {
		expect(() => buildSearchParams('orders', { filters: { customer_email: 'a@b.test' } })).toThrow(
			SearchError,
		)
		expect(() => buildSearchParams('orders', { filters: { customer_email: 'a@b.test' } })).toThrow(
			/active_view/,
		)
	})

	it('rejects a filter that belongs to a different entity', () => {
		expect(() => buildSearchParams('customers', { filters: { active_view: 'publish' } })).toThrow(
			SearchError,
		)
		expect(() =>
			buildSearchParams('products', { filters: { payment_statuses: ['paid'] } }),
		).toThrow(SearchError)
	})

	it('rejects an unknown enum value and lists what is allowed', () => {
		expect(() => buildSearchParams('products', { filters: { active_view: 'archived' } })).toThrow(
			/Allowed: publish, draft/,
		)
		expect(() =>
			buildSearchParams('subscriptions', { filters: { active_view: 'cancelled' } }),
		).toThrow(SearchError)
	})

	it('rejects several values for a single-valued filter', () => {
		expect(() =>
			buildSearchParams('orders', { filters: { active_view: ['paid', 'refunded'] } }),
		).toThrow(/single value/)
	})

	it('rejects a non-string query', () => {
		expect(() => buildSearchParams('orders', { query: 42 as unknown as string })).toThrow(
			SearchError,
		)
	})

	it('never emits an advanced_filters parameter', () => {
		const params = buildSearchParams('orders', { query: 'ada' })
		expect(Object.keys(params)).not.toContain('advanced_filters')
		expect(() =>
			buildSearchParams('orders', { filters: { advanced_filters: '[[{"property":"x"}]]' } }),
		).toThrow(SearchError)
	})
})
