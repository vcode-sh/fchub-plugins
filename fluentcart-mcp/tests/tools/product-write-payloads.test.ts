// Payload-shape guards for the two product writes that were quietly destructive.
//
// The live lane in tests/integration/product-lifecycle.test.ts proves the behaviour end to end;
// these run in CI without a store, and fail fast on the exact keys that mattered.
import { describe, expect, it, vi } from 'vitest'
import type { FluentCartClient } from '../../src/api/client.js'
import { createAllTools } from '../../src/tools/index.js'
import { buildVariantFromExisting } from '../../src/tools/products-variant-payload.js'

function stubClient(getData: unknown = {}) {
	const get = vi.fn().mockResolvedValue({ data: getData, status: 200 })
	const post = vi.fn().mockResolvedValue({ data: {}, status: 200 })
	return { client: { get, post } as unknown as FluentCartClient, get, post }
}

function toolNamed(client: FluentCartClient, name: string) {
	const tool = createAllTools(client, {}).find((candidate) => candidate.name === name)
	if (!tool) throw new Error(`${name} is not registered`)
	return tool
}

describe('product_create asks for a product that can be sold', () => {
	it('sends a variation_type, because an omitted key gets no default', async () => {
		// ProductCreateRequest's `'simple'` fallback only runs for a key that is present
		// (Sanitizer.php:459), and ProductController::create makes the starter variant only when
		// variation_type === 'simple'. Omitting it produced products with zero variants.
		const { client, post } = stubClient()
		await toolNamed(client, 'fluentcart_product_create').handler(
			{ post_title: 'x' } as never,
			{} as never,
		)

		const body = post.mock.calls[0]?.[1] as { detail?: Record<string, unknown> }
		expect(body.detail?.variation_type).toBe('simple')
	})

	it('honours an explicit variation type', async () => {
		const { client, post } = stubClient()
		await toolNamed(client, 'fluentcart_product_create').handler(
			{ post_title: 'x', variation_type: 'advanced_variations' } as never,
			{} as never,
		)

		const body = post.mock.calls[0]?.[1] as { detail?: Record<string, unknown> }
		expect(body.detail?.variation_type).toBe('advanced_variations')
	})
})

describe('product_get asks for the variants it promises', () => {
	it('requests the variants relation by default', async () => {
		// Relations are opt-in upstream; without this the response carries no variants key at all.
		const { client, get } = stubClient({ product: {} })
		await toolNamed(client, 'fluentcart_product_get').handler(
			{ product_id: 1 } as never,
			{} as never,
		)

		expect(get.mock.calls[0]?.[1]).toMatchObject({ 'with[]': 'variants' })
	})
})

describe('buildVariantFromExisting is a merge, not a reset', () => {
	const existing = {
		id: 5,
		item_price: 1000,
		compare_price: 0,
		variation_title: 'v',
		serial_index: 3,
		manage_stock: '1',
		item_cost: 300,
		manage_cost: 'true',
		shipping_class: 'heavy',
		downloadable: 'false',
		total_stock: 5,
		available: 5,
		item_status: 'active',
		other_info: { payment_type: 'onetime' },
	}

	it('carries through every field the upstream rebuild would otherwise default', () => {
		// ProductVariationResource::update replaces the row from `Arr::get($variant, X, default)`,
		// so anything missing here is written back as a default rather than left alone.
		const payload = buildVariantFromExisting(existing, 1, 5)

		expect(payload.serial_index).toBe(3)
		expect(payload.manage_stock).toBe('1')
		expect(payload.manage_cost).toBe('true')
		expect(payload.shipping_class).toBe('heavy')
		expect(payload.downloadable).toBe('false')
		// Cost is stored in minor units and re-multiplied on write, so it goes back in whole units.
		expect(payload.item_cost).toBe(3)
	})

	it('unwraps the media meta row into the array the write expects', () => {
		// The read returns the meta row; the write wants its meta_value. Passing the wrapper back
		// does not merely fail to preserve the image, it overwrites the stored value with junk.
		const payload = buildVariantFromExisting(
			{
				...existing,
				media: { id: 55, object_id: '5', meta_value: [{ id: 7, url: 'u', title: 't' }] },
			},
			1,
			5,
		)

		expect(payload.media).toEqual([{ id: 7, url: 'u', title: 't' }])
	})

	it('accepts a media array that is already unwrapped', () => {
		const payload = buildVariantFromExisting(
			{ ...existing, media: [{ id: 7, url: 'u', title: 't' }] },
			1,
			5,
		)
		expect(payload.media).toEqual([{ id: 7, url: 'u', title: 't' }])
	})

	it('omits media entirely when there is no image', () => {
		// An empty media value is the signal upstream uses to DELETE the thumbnail, so the key must
		// be absent rather than present-and-empty.
		for (const media of [undefined, null, [], { id: 1, object_id: '5', meta_value: [] }]) {
			const payload = buildVariantFromExisting({ ...existing, media }, 1, 5)
			expect('media' in payload, `media must be absent for ${JSON.stringify(media)}`).toBe(false)
		}
	})
})

describe('variant_list reads the product relation that actually filters by product', () => {
	it('does not accept the successful but unfiltered variants route as an empty product', async () => {
		const get = vi.fn().mockImplementation(async (path: string) => {
			if (path === '/products/variants') {
				return { data: { variants: [] }, status: 200 }
			}
			return {
				data: {
					product: {
						variants: [{ id: 9, post_id: 1, variation_title: 'Only variant' }],
					},
				},
				status: 200,
			}
		})
		const client = { get } as unknown as FluentCartClient

		const result = (await toolNamed(client, 'fluentcart_variant_list').handler(
			{ product_id: 1 } as never,
			{} as never,
		)) as { content: { text: string }[] }
		const body = JSON.parse(result.content[0]?.text ?? '{}')

		// FluentCart 1.6 changed /products/variants from a type error into HTTP 200, but it still
		// ignores product_id and reads only its nested params shape. Treating that response as the
		// answer made every product look empty. Product detail is the route that really owns the
		// relation and works on both 1.5 and 1.6.
		expect(body.total).toBe(1)
		expect(body.variants).toEqual([
			expect.objectContaining({ id: 9, post_id: 1, variation_title: 'Only variant' }),
		])
		expect(get).toHaveBeenCalledTimes(1)
		expect(get.mock.calls[0]?.[0]).toBe('/products/1')
		expect(get.mock.calls[0]?.[1]).toMatchObject({ 'with[]': 'variants' })
	})
})
