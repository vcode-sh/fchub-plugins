// The storefront catalogue has to fit in an answer.
//
// `fluentcart_public_products` could not answer at all on a 16-product store: the default page was
// 53,762 characters, past the emergency cap, and `per_page: 50` made it 77,308. Two causes, both
// measured:
//
//  - Rows arrive as raw WordPress post columns. `guid` and `post_name` are routing, `comment_status`
//    and `ping_status` are blog features FluentCart does not use, and the `_gmt` timestamps restate
//    the local ones beside them.
//  - `variants` was 77% of every row — 3,714 of 4,848 characters — because the listing embedded
//    each variant's entire record. Browsing a catalogue needs the option names and their prices;
//    the rest belongs to `fluentcart_product_get`.
//
// After: 15,911 characters for the default page, 23,599 for all 16 products in one call.
import { describe, expect, it, vi } from 'vitest'
import type { FluentCartClient } from '../../src/api/client.js'
import { createAllTools } from '../../src/tools/index.js'

/** The envelope the route really uses: three levels before the rows. */
function catalogue(rows: Record<string, unknown>[]) {
	return { products: { products: { current_page: 1, total: rows.length, data: rows } } }
}

function productRow(): Record<string, unknown> {
	return {
		ID: 28,
		post_title: "Basic Men's T-Shirt",
		post_status: 'publish',
		post_date: '2026-02-28 20:36:06',
		post_date_gmt: '2026-02-28 20:36:06',
		post_modified_gmt: '2026-02-28 20:36:06',
		post_name: 'basic-mens-t-shirt-28-02-2026',
		guid: 'https://store.invalid/?items=basic-mens-t-shirt',
		comment_status: 'open',
		ping_status: 'closed',
		view_url: 'https://store.invalid/item/basic-mens-t-shirt/',
		variants: [
			{
				id: 18,
				variation_title: 'Forest Green',
				item_price: 800,
				stock_status: 'in-stock',
				other_info: { payment_type: 'onetime', billing_summary: 'x'.repeat(400) },
				attr_map: { colour: 'forest-green' },
				created_at: '2026-02-28',
				updated_at: '2026-02-28',
			},
		],
	}
}

async function listCatalogue(rows: Record<string, unknown>[]) {
	const get = vi.fn().mockResolvedValue({ data: catalogue(rows), status: 200 })
	const client = { get } as unknown as FluentCartClient
	const tool = createAllTools(client, {}).find(
		(candidate) => candidate.name === 'fluentcart_public_products',
	)
	if (!tool) throw new Error('fluentcart_public_products is not registered')
	const result = (await tool.handler({} as never, {} as never)) as { content: { text: string }[] }
	return result.content[0]?.text ?? ''
}

describe('the public catalogue drops what is not about a product', () => {
	it('removes the WordPress post plumbing', async () => {
		const text = await listCatalogue([productRow()])

		for (const noise of [
			'post_date_gmt',
			'post_modified_gmt',
			'post_name',
			'guid',
			'comment_status',
			'ping_status',
		]) {
			expect(text, `${noise} is WordPress plumbing`).not.toContain(noise)
		}
	})

	it('keeps what a shopper and an agent both need', async () => {
		const text = await listCatalogue([productRow()])

		expect(text).toContain("Basic Men's T-Shirt")
		expect(text).toContain('publish')
		expect(text).toContain('view_url')
		// The local timestamp survives; only its GMT twin goes.
		expect(text).toContain('post_date')
	})

	it('reduces each variant to its option and price', async () => {
		const text = await listCatalogue([productRow()])
		const rows = JSON.parse(text).products.products.data as Record<string, unknown>[]
		const variants = rows[0]?.variants as Record<string, unknown>[] | undefined
		const variant = variants?.[0]

		// The colour is the point of a variant in a catalogue, so it stays.
		expect(variant).toEqual({
			id: 18,
			variation_title: 'Forest Green',
			item_price: 800,
			stock_status: 'in-stock',
		})
		expect(text).not.toContain('attr_map')
		expect(text).not.toContain('billing_summary')
	})

	it('keeps the paginator so a caller can page', async () => {
		const text = await listCatalogue([productRow(), productRow()])
		const inner = JSON.parse(text).products.products

		expect(inner.current_page).toBe(1)
		expect(inner.total).toBe(2)
	})

	it('leaves a payload it does not recognise alone', async () => {
		const get = vi.fn().mockResolvedValue({ data: { message: 'nothing here' }, status: 200 })
		const client = { get } as unknown as FluentCartClient
		const tool = createAllTools(client, {}).find(
			(candidate) => candidate.name === 'fluentcart_public_products',
		)
		if (!tool) throw new Error('fluentcart_public_products is not registered')
		const result = (await tool.handler({} as never, {} as never)) as {
			content: { text: string }[]
		}
		expect(JSON.parse(result.content[0]?.text ?? '{}')).toEqual({ message: 'nothing here' })
	})

	it('survives a variants value that is not an array', async () => {
		const row = { ...productRow(), variants: null }
		const text = await listCatalogue([row])
		expect(JSON.parse(text).products.products.data[0].variants).toBeNull()
	})
})
