// Stock numbers that an agent would read the wrong way.
//
// Asked "what is low on stock", the server used to fail twice over. Nothing was discoverable —
// neither variant tool said "stock" or "inventory" anywhere, so the query returned coupon settings
// and a PDF template status. And once a caller did reach the variants, the payload contradicted
// itself: 27 of this store's 76 variants came back as `stock_status: in-stock` beside
// `total_stock: 0`.
//
// That contradiction was ours, not FluentCart's. Every one of the 27 has `manage_stock` off, so
// the counter is simply an untouched zero and `stock_status` is the whole truth. The projection
// returned the meaningless number and dropped the flag that explains it. Measured live: 49
// variants track stock, all at 100 available; 27 do not track and all read 0.
import { describe, expect, it, vi } from 'vitest'
import type { FluentCartClient } from '../../src/api/client.js'
import { createAllTools } from '../../src/tools/index.js'

/** A variant as `/variants` really returns it — `manage_stock` is a string, not a boolean. */
function variant(overrides: Record<string, unknown> = {}): Record<string, unknown> {
	return {
		id: 362,
		post_id: 28,
		variation_title: 'White',
		item_price: 800,
		sku: 'TS-WHT',
		stock_status: 'in-stock',
		manage_stock: '1',
		total_stock: 100,
		available: 100,
		committed: 0,
		on_hold: 0,
		backorders: 0,
		fulfillment_type: 'physical',
		payment_type: 'onetime',
		other_info: {},
		media: { url: 'x'.repeat(200) },
		...overrides,
	}
}

async function listAll(rows: Record<string, unknown>[], input: Record<string, unknown> = {}) {
	const get = vi.fn().mockResolvedValue({ data: rows, status: 200 })
	const client = { get } as unknown as FluentCartClient
	const tool = createAllTools(client, {}).find(
		(candidate) => candidate.name === 'fluentcart_variant_list_all',
	)
	if (!tool) throw new Error('fluentcart_variant_list_all is not registered')
	const result = (await tool.handler(input as never, {} as never)) as {
		content: { text: string }[]
	}
	return JSON.parse(result.content[0]?.text ?? '{}')
}

describe('a variant reports stock so it cannot be misread', () => {
	it('says a variant does not track stock instead of reporting zero units', async () => {
		const body = await listAll([variant({ manage_stock: '0', total_stock: 0, available: 0 })])
		const row = body.variants[0]

		expect(row.manage_stock).toBe(false)
		expect(row.stock_status).toBe('in-stock')
		// The zero is an untouched counter, not an empty shelf. Reporting it is the whole defect.
		expect(row).not.toHaveProperty('total_stock')
		expect(row).not.toHaveProperty('available')
	})

	it('normalises the flag to a boolean, because the string "0" is truthy', async () => {
		// `if (variant.manage_stock)` is the obvious line to write in code mode, and against the raw
		// value it reads every untracked variant as tracked.
		const body = await listAll([variant({ manage_stock: '0' }), variant({ manage_stock: '1' })])
		expect(body.variants.map((row: Record<string, unknown>) => row.manage_stock)).toEqual([
			false,
			true,
		])
	})

	it('reports available beside total, since available is what checkout decrements', async () => {
		const body = await listAll([variant({ total_stock: 100, available: 92, committed: 8 })])
		const row = body.variants[0]

		expect(row.total_stock).toBe(100)
		expect(row.available).toBe(92)
		expect(row.committed).toBe(8)
	})

	it('omits committed and on_hold when they are zero', async () => {
		const body = await listAll([variant()])
		expect(body.variants[0]).not.toHaveProperty('committed')
		expect(body.variants[0]).not.toHaveProperty('on_hold')
	})
})

describe('the stock filter answers the question without a catalogue scan', () => {
	const catalogue = [
		variant({ id: 1, variation_title: 'Plenty', available: 100 }),
		variant({ id: 2, variation_title: 'Nearly out', available: 3 }),
		variant({ id: 3, variation_title: 'Gone', available: 0 }),
		variant({ id: 4, variation_title: 'Digital', manage_stock: '0', available: 0 }),
	]

	it('finds what is running low', async () => {
		const body = await listAll(catalogue, { stock: 'low' })
		expect(body.variants.map((row: Record<string, unknown>) => row.id)).toEqual([2])
		expect(body.total).toBe(1)
		expect(body.total_in_store).toBe(4)
		expect(body.filter).toContain('fewer than 5')
	})

	it('lets the caller say what low means', async () => {
		const body = await listAll(catalogue, { stock: 'low', low_below: 200 })
		expect(body.variants.map((row: Record<string, unknown>) => row.id)).toEqual([1, 2])
	})

	it('finds what has sold out', async () => {
		const body = await listAll(catalogue, { stock: 'out' })
		expect(body.variants.map((row: Record<string, unknown>) => row.id)).toEqual([3])
	})

	it('never calls an untracked variant sold out', async () => {
		// A digital subscription that counts nothing has not run out of anything. Including it under
		// "what have I run out of" would be the same false reading in a different place.
		for (const filter of ['low', 'out']) {
			const body = await listAll(catalogue, { stock: filter })
			const ids = body.variants.map((row: Record<string, unknown>) => row.id)
			expect(ids, `${filter} must not match the untracked variant`).not.toContain(4)
		}
		expect((await listAll(catalogue, { stock: 'untracked' })).variants).toHaveLength(1)
		expect((await listAll(catalogue, { stock: 'tracked' })).variants).toHaveLength(3)
	})

	it('pages over the matches, not over the catalogue', async () => {
		const body = await listAll(catalogue, { stock: 'tracked', per_page: 2 })
		expect(body.total).toBe(3)
		expect(body.has_more).toBe(true)
		expect(body.total_in_store).toBe(4)
	})

	it('leaves the counts alone when no filter is asked for', async () => {
		const body = await listAll(catalogue)
		expect(body.total).toBe(4)
		expect(body).not.toHaveProperty('filter')
		expect(body).not.toHaveProperty('total_in_store')
	})
})
