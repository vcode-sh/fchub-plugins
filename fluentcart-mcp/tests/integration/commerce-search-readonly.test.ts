// Live search lane. Reachable only through scripts/run-live-tests.mjs.
//
// Search is the one contract that cannot be proven by reading: a filter the endpoint quietly
// ignores returns a full unfiltered page that looks exactly like a successful narrow search. So
// this lane creates its own searchable records and asserts BOTH that the intended row comes back
// and that the decoy does not. A positive match alone would pass against an endpoint that
// ignored the query entirely.
//
// Everything created here is a draft product removed through the ledger. Drafts stay off the
// storefront for their whole life, and the pricing route is never called — it leaves an orphan
// wp_posts revision FluentCart REST cannot remove.
import { afterAll, beforeAll, describe, expect, it } from 'vitest'
import type { FluentCartClient } from '../../src/api/client.js'
import {
	buildSearchParams,
	getSearchCapability,
	SEARCH_ENTITIES,
	SearchError,
	searchPath,
} from '../../src/commerce/search.js'
import { CleanupLedger } from './support/cleanup-ledger.js'
import { getLiveClient, removeProduct, verifyProductMissing } from './support/live-client.js'
import { getLiveRun } from './support/live-run.js'

const run = getLiveRun()
const ledger = new CleanupLedger()

let client: FluentCartClient
/** Two products whose titles share the run prefix but differ in one unique token. */
let target: { id: number; token: string }
let decoy: { id: number; token: string }

function inner(payload: unknown): Record<string, unknown> {
	const record = (payload ?? {}) as Record<string, unknown>
	const nested = record.product ?? record.data ?? record
	return (nested ?? {}) as Record<string, unknown>
}

async function createSearchableProduct(token: string): Promise<{ id: number; token: string }> {
	const res = await client.post('/products', {
		post_title: `${run.prefix} ${token}`,
		post_status: 'draft',
		post_excerpt: 'Run-owned search fixture',
		detail: { fulfillment_type: 'digital' },
	})

	const id = inner(res.data).ID
	if (typeof id !== 'number' || id <= 0) {
		throw new Error(`product create returned no usable id: ${JSON.stringify(res.data)}`)
	}
	ledger.track({ type: 'product', id, remove: removeProduct, verifyMissing: verifyProductMissing })
	return { id, token }
}

/** Run one search exactly as the tool would, and return the matched product ids. */
async function searchProductIds(options: {
	query?: string
	filters?: Record<string, unknown>
}): Promise<number[]> {
	const params = buildSearchParams('products', options)
	const res = await client.get(searchPath('products'), { ...params, per_page: 50 })

	const collect = (node: unknown, depth = 0): Record<string, unknown>[] => {
		if (!node || typeof node !== 'object' || depth > 4) return []
		const record = node as Record<string, unknown>
		if (Array.isArray(record.data)) {
			return record.data.filter((row): row is Record<string, unknown> => typeof row === 'object')
		}
		for (const value of Object.values(record)) {
			const found = collect(value, depth + 1)
			if (found.length > 0) return found
		}
		return []
	}

	return collect(res.data)
		.map((row) => row.ID ?? row.id)
		.filter((id): id is number => typeof id === 'number')
}

beforeAll(async () => {
	client = getLiveClient()
	target = await createSearchableProduct(`kestrel${Date.now().toString(36)}`)
	decoy = await createSearchableProduct(`marlin${Date.now().toString(36)}`)
})

afterAll(async () => {
	await ledger.cleanup()
})

describe('advertised capabilities match the store', () => {
	it('reaches every advertised search endpoint', async () => {
		for (const entity of SEARCH_ENTITIES) {
			const res = await client.get(searchPath(entity), { per_page: 1 })
			expect(res.status).toBe(200)
		}
	})

	it('advertises no advanced filtering, and never sends the parameter', async () => {
		for (const entity of SEARCH_ENTITIES) {
			expect(getSearchCapability(entity).advancedFilters).toBe(false)
		}
		expect(() =>
			buildSearchParams('orders', { filters: { advanced_filters: '[[{"property":"total"}]]' } }),
		).toThrow(SearchError)
	})
})

describe('free-text product search', () => {
	it('finds the run-owned product by its unique token', async () => {
		const ids = await searchProductIds({ query: target.token })
		expect(ids).toContain(target.id)
	})

	it('excludes the decoy, proving the query is applied rather than ignored', async () => {
		const ids = await searchProductIds({ query: target.token })
		expect(ids).not.toContain(decoy.id)
	})

	it('finds the decoy under its own token', async () => {
		const ids = await searchProductIds({ query: decoy.token })
		expect(ids).toContain(decoy.id)
		expect(ids).not.toContain(target.id)
	})

	it('returns neither for a token nothing carries', async () => {
		const ids = await searchProductIds({ query: `${run.prefix}-absent-token` })
		expect(ids).not.toContain(target.id)
		expect(ids).not.toContain(decoy.id)
	})

	it('matches both fixtures on the shared run prefix', async () => {
		const ids = await searchProductIds({ query: run.prefix })
		expect(ids).toEqual(expect.arrayContaining([target.id, decoy.id]))
	})
})

describe('status view filtering', () => {
	it('returns the drafts under the draft view and hides them under publish', async () => {
		const drafts = await searchProductIds({
			query: run.prefix,
			filters: { active_view: 'draft' },
		})
		expect(drafts).toEqual(expect.arrayContaining([target.id, decoy.id]))

		const published = await searchProductIds({
			query: run.prefix,
			filters: { active_view: 'publish' },
		})
		expect(published).not.toContain(target.id)
		expect(published).not.toContain(decoy.id)
	})

	it('keeps the digital fixtures out of the physical view', async () => {
		const physical = await searchProductIds({
			query: run.prefix,
			filters: { active_view: 'physical' },
		})
		expect(physical).not.toContain(target.id)
	})
})

describe('local rejection reaches no endpoint', () => {
	it('refuses an unknown filter before any request', async () => {
		expect(() => buildSearchParams('products', { filters: { post_author: 1 } })).toThrow(
			SearchError,
		)
	})

	it('refuses an unknown view value before any request', async () => {
		expect(() => buildSearchParams('products', { filters: { active_view: 'archived' } })).toThrow(
			SearchError,
		)
	})
})

describe('cleanup', () => {
	it('tracked both fixtures for removal', () => {
		expect(ledger.size).toBe(2)
	})
})
