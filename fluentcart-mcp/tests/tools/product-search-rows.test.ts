// Product search that costs what an answer costs.
//
// `fluentcart_product_search_by_name` is the first call in the commonest product question there
// is — "how is the t-shirt selling" — and it was returning 1,064 characters per row. Three fields
// described the same image (`thumbnail`, `featured_media`, `gallery_image`), prices came twice
// (`min_price` 800 beside `formatted_min_price` "8.00&euro;"), and `wp_terms` carried Laravel's
// internal `laravel_through_key`.
//
// Measured live on a 20-product store: `name=shirt` 2,446 -> 484 characters, and an unfiltered
// page 10,604 -> 2,112. Both 80% off, with nothing lost that identifies or prices a product.
import { describe, expect, it, vi } from 'vitest'
import type { FluentCartClient } from '../../src/api/client.js'
import { createAllTools } from '../../src/tools/index.js'
import { projectProductSearch, searchProductQuery } from '../../src/tools/product-search-rows.js'

/** The envelope and row exactly as the route returns them. */
const PAGE = {
	products: {
		current_page: 1,
		total: 1,
		data: [
			{
				ID: 28,
				post_title: "Basic Men's T-Shirt",
				thumbnail: 'https://store.invalid/men-t-shirt-1.jpeg',
				wp_terms: [{ term_taxonomy_id: '2', term_id: 2, laravel_through_key: '28', count: '10' }],
				detail: {
					id: 4,
					post_id: 28,
					fulfillment_type: 'physical',
					min_price: '800',
					max_price: '860',
					default_variation_id: '18',
					stock_availability: 'in-stock',
					variation_type: 'simple_variations',
					featured_media: { id: 29, url: 'https://store.invalid/men-t-shirt-1.jpeg' },
					gallery_image: { media: 'x'.repeat(300) },
					formatted_min_price: '8.00&euro;',
					created_at: '2026-02-28T20:36:07+00:00',
				},
			},
		],
	},
}

async function search(payload: unknown, input: Record<string, unknown> = {}) {
	const get = vi.fn().mockResolvedValue({ data: payload, status: 200 })
	const tool = createAllTools({ get } as unknown as FluentCartClient, {}).find(
		(candidate) => candidate.name === 'fluentcart_product_search_by_name',
	)
	if (!tool) throw new Error('fluentcart_product_search_by_name is not registered')
	const result = (await tool.handler(input as never, {} as never)) as {
		content: { text: string }[]
	}
	return result.content[0]?.text ?? ''
}

describe('a search result identifies and prices a product, and stops there', () => {
	it('keeps what picking a product needs', async () => {
		const row = JSON.parse(await search(PAGE)).products.data[0]

		expect(row).toEqual({
			ID: 28,
			post_title: "Basic Men's T-Shirt",
			min_price: '800',
			max_price: '860',
			stock_availability: 'in-stock',
			fulfillment_type: 'physical',
			variation_type: 'simple_variations',
			default_variation_id: '18',
		})
	})

	it('drops the three descriptions of one image and the duplicated price', async () => {
		const text = await search(PAGE)

		for (const noise of [
			'thumbnail',
			'featured_media',
			'gallery_image',
			'formatted_min_price',
			'laravel_through_key',
			'created_at',
		]) {
			expect(text, `${noise} is not part of an answer`).not.toContain(noise)
		}
	})

	it('keeps the paginator so a caller can page', async () => {
		const page = JSON.parse(await search(PAGE)).products
		expect(page.current_page).toBe(1)
		expect(page.total).toBe(1)
	})

	it('leaves a payload it does not recognise exactly as it found it', () => {
		// Guessing an envelope has already produced three transforms in this project that silently
		// did nothing. An unfamiliar shape must pass through, not come back empty.
		const stranger = { message: 'nothing here' }
		expect(projectProductSearch(stranger)).toBe(stranger)
		expect(projectProductSearch(null)).toBeNull()
		expect(projectProductSearch([1, 2])).toEqual([1, 2])
	})

	it('handles a row with no detail block', () => {
		const projected = projectProductSearch({ data: [{ ID: 7, post_title: 'Bare' }] }) as {
			data: Record<string, unknown>[]
		}
		expect(projected.data[0]).toEqual({ ID: 7, post_title: 'Bare' })
	})

	it('reads a top-level data array as well as the products wrapper', () => {
		const projected = projectProductSearch({
			data: [{ ID: 9, post_title: 'Flat', detail: { min_price: '100' } }],
		}) as { data: Record<string, unknown>[] }
		expect(projected.data[0]?.min_price).toBe('100')
	})
})

/**
 * The route drops `ShopResource::get`'s total and returns a bare `simplePaginate` envelope, so a
 * caller reading a full page cannot tell the catalogue from its first tenth. That is how "the most
 * expensive thing I sell" comes back as the most expensive thing on page one.
 */
describe('paging a route that will not say how much there is', () => {
	const envelope = (rows: number, perPage: number) => ({
		products: {
			current_page: 1,
			per_page: perPage,
			data: Array.from({ length: rows }, (_, index) => ({ ID: index, post_title: `P${index}` })),
		},
	})

	it('says there is more when the page came back full', () => {
		const projected = projectProductSearch(envelope(10, 10)) as { products: { has_more: boolean } }
		expect(projected.products.has_more).toBe(true)
	})

	it('says there is no more when the page came back short, empty included', () => {
		for (const rows of [0, 6, 9]) {
			const projected = projectProductSearch(envelope(rows, 10)) as {
				products: { has_more: boolean }
			}
			expect(projected.products.has_more, `${rows} of 10 is the last page`).toBe(false)
		}
	})
})

/**
 * `page`, `per_page` and a category filter were all unreachable. Proven live: `page=2` and
 * `per_page=50` each returned page one unchanged, while `current_page=2` returned the rest.
 */
describe('the query spelling this endpoint actually reads', () => {
	it('sends page as current_page and category_id as termId', () => {
		expect(searchProductQuery({ name: 'hoodie', page: 2, category_id: 2 })).toEqual({
			name: 'hoodie',
			current_page: 2,
			termId: 2,
		})
	})

	it('sends neither key when neither was asked for', () => {
		expect(searchProductQuery({ name: 'hoodie' })).toEqual({ name: 'hoodie' })
		expect(searchProductQuery({})).toEqual({})
	})

	it('passes a deliberate zero through rather than dropping it as falsy', () => {
		// `category_id: 0` is a caller mistake worth surfacing upstream, not one to silently discard.
		expect(searchProductQuery({ category_id: 0, page: 0 })).toEqual({ termId: 0, current_page: 0 })
	})
})
