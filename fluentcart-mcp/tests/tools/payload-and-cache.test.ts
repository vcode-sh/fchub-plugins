// Payload and cache fixes from the performance census.
//
// Three defects, all measured against the live store rather than reasoned about:
//
//  - `customer_orders_simple` returned 46,531 characters for one customer, over the emergency cap,
//    so it could not answer for anybody with real order history. 56% of the sibling paginated
//    route was `config`, 35% of the whole payload Redsys gateway internals. `GET /orders/{id}` had
//    been projected; the other two order-bearing routes were missed, because the fix had been
//    written per-route rather than once.
//  - `variant_list_all` advertised `page` and `per_page` that did nothing: `/variants` returns the
//    whole catalogue for `{}`, `{per_page:5}`, `{per_page:5,page:1}` and `{limit:5}` alike. Past
//    ~173 variants it crossed the cap and the error advised "retry with a smaller page size",
//    which could never work. This store has 76.
//  - Cached tools keyed on a constant string, so `shipping_zone_states` served the US states for a
//    request about France for up to an hour, with nothing to show the caller it had done so.
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { FluentCartClient } from '../../src/api/client.js'
import { clearCache } from '../../src/cache.js'
import { createAllTools } from '../../src/tools/index.js'
import { projectOrderEnvelope, stripOrderInternals } from '../../src/tools/orders-core.js'

function orderRow(id: number): Record<string, unknown> {
	return {
		id,
		status: 'completed',
		total_amount: 13000,
		ip_address: '148.251.46.45',
		uuid: 'order-uuid',
		config: { p24_session_id: 'sess', redsys_notification_snapshot: 'x'.repeat(200) },
		meta: [{ meta_key: 'redsys_reference' }],
		vendor_response: { blob: true },
	}
}

async function callWith(name: string, payload: unknown, input: Record<string, unknown> = {}) {
	const get = vi.fn().mockResolvedValue({ data: payload, status: 200 })
	const client = { get } as unknown as FluentCartClient
	const tool = createAllTools(client, {}).find((candidate) => candidate.name === name)
	if (!tool) throw new Error(`${name} is not registered`)
	const result = (await tool.handler(input as never, {} as never)) as {
		isError?: boolean
		content: { text: string }[]
	}
	return { text: result.content[0]?.text ?? '', get }
}

beforeEach(() => {
	clearCache()
})

describe('order rows are projected wherever they appear', () => {
	it('strips internals from a single row', () => {
		const clean = stripOrderInternals(orderRow(1)) as Record<string, unknown>
		expect(clean.config).toBeUndefined()
		expect(clean.ip_address).toBeUndefined()
		expect(clean.uuid).toBeUndefined()
		expect(clean.meta).toBeUndefined()
		expect(clean.vendor_response).toBeUndefined()
		expect(clean.status).toBe('completed')
	})

	it('finds rows however the route wrapped them', () => {
		// The three order-bearing routes each chose a different envelope; guarding on one spelling
		// is exactly how two of them kept their gateway payload.
		for (const envelope of [
			{ orders: { current_page: 1, data: [orderRow(1)] } },
			{ data: { current_page: 1, data: [orderRow(2)] } },
			{ orders: [orderRow(3)] },
			[orderRow(4)],
		]) {
			expect(JSON.stringify(projectOrderEnvelope(envelope))).not.toContain('ip_address')
			expect(JSON.stringify(projectOrderEnvelope(envelope))).not.toContain('p24_session_id')
		}
	})

	it('keeps the paginator around the rows', () => {
		const projected = projectOrderEnvelope({
			orders: { current_page: 3, total: 91, data: [orderRow(1)] },
		}) as { orders: Record<string, unknown> }

		expect(projected.orders.current_page).toBe(3)
		expect(projected.orders.total).toBe(91)
	})

	it('leaves a payload with no order rows untouched', () => {
		const input = { message: 'nothing here' }
		expect(projectOrderEnvelope(input)).toBe(input)
	})

	it('projects the unpaginated customer orders route', async () => {
		const { text } = await callWith(
			'fluentcart_customer_orders_simple',
			{ orders: [orderRow(1), orderRow(2)] },
			{ customer_id: 1 },
		)
		expect(text).not.toContain('ip_address')
		expect(text).not.toContain('redsys_notification_snapshot')
		expect(text).toContain('completed')
	})
})

describe('variant_list_all pages honestly', () => {
	const catalogue = Array.from({ length: 120 }, (_, index) => ({
		id: index + 1,
		post_id: 1,
		variation_title: `Variant ${index + 1}`,
		item_price: 1000,
	}))

	it('returns a page rather than the whole catalogue', async () => {
		const { text } = await callWith('fluentcart_variant_list_all', catalogue, { per_page: 5 })
		const body = JSON.parse(text)

		expect(body.variants).toHaveLength(5)
		expect(body.total).toBe(120)
		expect(body.has_more).toBe(true)
	})

	it('advances with the page number', async () => {
		const first = JSON.parse(
			(await callWith('fluentcart_variant_list_all', catalogue, { per_page: 5 })).text,
		)
		const second = JSON.parse(
			(await callWith('fluentcart_variant_list_all', catalogue, { per_page: 5, page: 2 })).text,
		)
		expect(first.variants[0].id).not.toBe(second.variants[0].id)
	})

	it('says the paging is ours, because the store did none of it', async () => {
		// A caller that believed the store paged would draw the wrong conclusion about cost.
		const { text, get } = await callWith('fluentcart_variant_list_all', catalogue, { per_page: 5 })
		expect(JSON.parse(text).paging).toMatch(/fluentcart-mcp/)
		// The dead parameters must not travel: the endpoint ignores them anyway.
		expect(get.mock.calls[0]?.[1]).toBeUndefined()
	})

	it('reports the last page without claiming more', async () => {
		const { text } = await callWith('fluentcart_variant_list_all', catalogue.slice(0, 3), {})
		const body = JSON.parse(text)
		expect(body.total).toBe(3)
		expect(body.has_more).toBe(false)
	})
})

describe('cache entries belong to their request', () => {
	it('does not serve one country to a request for another', async () => {
		const get = vi
			.fn()
			.mockResolvedValueOnce({ data: { country_code: 'US', states: ['CA'] }, status: 200 })
			.mockResolvedValueOnce({ data: { country_code: 'FR', states: ['IDF'] }, status: 200 })
		const client = { get } as unknown as FluentCartClient
		const tool = createAllTools(client, {}).find(
			(candidate) => candidate.name === 'fluentcart_shipping_zone_states',
		)
		if (!tool) throw new Error('fluentcart_shipping_zone_states is not registered')

		const us = (await tool.handler({ country: 'US' } as never, {} as never)) as {
			content: { text: string }[]
		}
		const fr = (await tool.handler({ country: 'FR' } as never, {} as never)) as {
			content: { text: string }[]
		}

		expect(us.content[0]?.text).toContain('US')
		expect(fr.content[0]?.text, 'France was served the cached US answer').toContain('FR')
		expect(get).toHaveBeenCalledTimes(2)
	})

	it('still caches when the same request repeats', async () => {
		const get = vi.fn().mockResolvedValue({ data: { country_code: 'US' }, status: 200 })
		const client = { get } as unknown as FluentCartClient
		const tool = createAllTools(client, {}).find(
			(candidate) => candidate.name === 'fluentcart_shipping_zone_states',
		)
		if (!tool) throw new Error('fluentcart_shipping_zone_states is not registered')

		await tool.handler({ country: 'US' } as never, {} as never)
		await tool.handler({ country: 'US' } as never, {} as never)

		expect(get, 'the cache must still do its job').toHaveBeenCalledTimes(1)
	})

	it('never serves one client context from another client cache', async () => {
		const firstGet = vi.fn().mockResolvedValue({ data: { shop: { store_name: 'Alpha' } } })
		const secondGet = vi.fn().mockResolvedValue({ data: { shop: { store_name: 'Beta' } } })
		const first = createAllTools({ get: firstGet } as unknown as FluentCartClient).find(
			(candidate) => candidate.name === 'fluentcart_app_init',
		)
		const second = createAllTools({ get: secondGet } as unknown as FluentCartClient).find(
			(candidate) => candidate.name === 'fluentcart_app_init',
		)
		if (!(first && second)) throw new Error('fluentcart_app_init is not registered')

		const alpha = await first.handler({})
		const beta = await second.handler({})

		expect(alpha.content[0]?.text).toContain('Alpha')
		expect(beta.content[0]?.text).toContain('Beta')
		expect(firstGet).toHaveBeenCalledTimes(1)
		expect(secondGet).toHaveBeenCalledTimes(1)
	})
})
