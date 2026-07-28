// Two shipping tools that answered "nothing is available" when the truth was "you did not say where".
//
// Both failed silently, with HTTP 200 and an empty list, which is the expensive kind: an agent
// reports the empty list as a finding rather than retrying.
//
//  - `shipping_zone_states` sent `country`. The route reads `country_code`. Nothing rejected the
//    wrong name, so every country returned `{country_code: "", states: [], address_locale: []}`.
//    A merchant building state-level shipping for the US was told the US has no states. Measured
//    live against the raw route: `country_code=US` returns 54 subdivisions, `country=US` returns 0.
//  - `order_shipping_methods` declared `z.object({})` while OrderController::getShippingMethods
//    reads country_code, state and order_items. With no destination it takes the
//    `if (empty($countryCode))` branch at line 990 and returns an empty `shipping_methods` with
//    every method under `other_shipping_methods` — the list the controller defines as the ones
//    that do NOT reach the destination. Live, this store's one FREE method sat in "other" forever.
import { describe, expect, it, vi } from 'vitest'
import type { FluentCartClient } from '../../src/api/client.js'
import { createAllTools } from '../../src/tools/index.js'

function toolNamed(get: ReturnType<typeof vi.fn>, name: string) {
	const tool = createAllTools({ get } as unknown as FluentCartClient, {}).find(
		(candidate) => candidate.name === name,
	)
	if (!tool) throw new Error(`${name} is not registered`)
	return tool
}

function stubGet() {
	return vi.fn().mockResolvedValue({ data: { data: { states: [] } }, status: 200 })
}

describe('zone states ask the store the question it answers', () => {
	it('sends country_code, not country', async () => {
		const get = stubGet()
		const tool = toolNamed(get, 'fluentcart_shipping_zone_states')
		await tool.handler({ country_code: 'US' } as never, {} as never)

		const query = get.mock.calls[0]?.[1] as Record<string, unknown>
		expect(query).toMatchObject({ country_code: 'US' })
		expect(query, 'the wrong name is what made every country look empty').not.toHaveProperty(
			'country',
		)
	})

	it('accepts the old country spelling and forwards it correctly', async () => {
		const get = stubGet()
		const tool = toolNamed(get, 'fluentcart_shipping_zone_states')
		await tool.handler({ country: 'de' } as never, {} as never)

		expect(get.mock.calls[0]?.[1]).toMatchObject({ country_code: 'DE' })
	})

	it('normalises case and padding, so " fr " is not a third country', async () => {
		const get = stubGet()
		const tool = toolNamed(get, 'fluentcart_shipping_zone_states')
		await tool.handler({ country_code: ' fr ' } as never, {} as never)

		expect(get.mock.calls[0]?.[1]).toMatchObject({ country_code: 'FR' })
	})

	it('caches per country rather than across them', async () => {
		// This tool caches for a long TTL, which is right — subdivisions do not change hourly — but
		// only if the key carries the country. A shared key would serve one country's states for
		// every other, which is the same wrong answer the parameter bug gave, from a different
		// direction. Distinct codes here so earlier cases in this file cannot warm the entry.
		const get = stubGet()
		const tool = toolNamed(get, 'fluentcart_shipping_zone_states')

		await tool.handler({ country_code: 'AQ' } as never, {} as never)
		await tool.handler({ country_code: 'BV' } as never, {} as never)
		await tool.handler({ country_code: 'AQ' } as never, {} as never)

		const asked = get.mock.calls.map((call) => (call[1] as { country_code: string }).country_code)
		expect(asked, 'the repeat must be served from cache, the new country must not').toEqual([
			'AQ',
			'BV',
		])
	})

	it('requires a country at all', () => {
		const tool = toolNamed(stubGet(), 'fluentcart_shipping_zone_states')
		expect(tool.schema.safeParse({}).success).toBe(false)
		expect(tool.schema.safeParse({ country_code: 'US' }).success).toBe(true)
	})
})

describe('shipping methods can be asked about a destination', () => {
	it('accepts the parameters the controller actually reads', () => {
		const tool = toolNamed(stubGet(), 'fluentcart_order_shipping_methods')

		expect(tool.schema.safeParse({ country_code: 'DE' }).success).toBe(true)
		expect(tool.schema.safeParse({ country_code: 'US', state: 'CA' }).success).toBe(true)
		expect(tool.schema.safeParse({ order_items: [{ id: 1 }] }).success).toBe(true)
	})

	it('forwards the destination rather than dropping it', async () => {
		const get = stubGet()
		const tool = toolNamed(get, 'fluentcart_order_shipping_methods')
		await tool.handler({ country_code: 'DE', state: 'BE' } as never, {} as never)

		expect(get.mock.calls[0]?.[1]).toMatchObject({ country_code: 'DE', state: 'BE' })
	})

	it('says what the second list means, since its name suggests the opposite', () => {
		// "other_shipping_methods" sounds like extras. It is the methods that cannot reach the
		// destination, and reading it as availability inverts the answer.
		const tool = toolNamed(stubGet(), 'fluentcart_order_shipping_methods')

		expect(tool.description).toContain('other_shipping_methods')
		expect(tool.description).toMatch(/do NOT reach/i)
		expect(tool.description).toMatch(/no shipping available/i)
	})
})
