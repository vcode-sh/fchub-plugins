// Money that an agent would report wrongly.
//
// Found by driving the server through the seven questions a merchant actually asks. Three tools
// would each have made a competent agent state a false figure, and none of them errored:
//
//  - `report_top_sold_variants` added EUR and PLN into one number. Unscoped it reported "Casual
//    Classic Hoodie / Cadet Blue, quantity 12, amount 96" — that is 6 units at EUR 48 plus 6 units
//    at PLN 48. Its own sibling, `report_sales_summary`, refuses to run without a currency because
//    such a total "would be meaningless"; this tool was doing exactly that, and it is the top
//    search hit for the commonest analytics question there is.
//  - `report_product` said "Revenue in cents" and returned decimals: `gross_sale: 4962.7`, which
//    divided by 100 becomes EUR 49.63 instead of EUR 4,962.70. It is also store-wide, not
//    per-product, despite the name.
//  - `subscription_list` returned `recurring_amount: "99900"` for a EUR 999/yr plan and said
//    nothing about units, so the plan reads as EUR 99,900.
import { describe, expect, it, vi } from 'vitest'
import type { FluentCartClient } from '../../src/api/client.js'
import { createAllTools } from '../../src/tools/index.js'

function toolNamed(client: FluentCartClient, name: string) {
	const tool = createAllTools(client, {}).find((candidate) => candidate.name === name)
	if (!tool) throw new Error(`${name} is not registered`)
	return tool
}

const VARIANT_ROWS = {
	topSoldVariants: [
		{
			product_name: 'B Product',
			variation_name: 'Zeta',
			quantity: 5,
			total_amount: 50,
			media_url: 'x',
		},
		{ product_name: 'A Product', variation_name: 'Alpha', quantity: 5, total_amount: 50 },
		{ product_name: 'C Product', variation_name: 'Gamma', quantity: 12, total_amount: 96 },
	],
}

describe('the variant sales report pins a currency', () => {
	it('refuses to run without one', () => {
		const tool = toolNamed(
			{ get: async () => ({ data: {} }) } as never,
			'fluentcart_report_top_sold_variants',
		)
		expect(tool.schema.safeParse({ startDate: '2026-01-01' }).success).toBe(false)
		expect(tool.schema.safeParse({ currency: 'EUR' }).success).toBe(true)
	})

	it('rejects something that is not an ISO code', () => {
		const tool = toolNamed(
			{ get: async () => ({ data: {} }) } as never,
			'fluentcart_report_top_sold_variants',
		)
		expect(tool.schema.safeParse({ currency: 'euros' }).success).toBe(false)
	})

	it('sends the currency to the store and echoes it back', async () => {
		const get = vi.fn().mockResolvedValue({ data: VARIANT_ROWS, status: 200 })
		const tool = toolNamed(
			{ get } as unknown as FluentCartClient,
			'fluentcart_report_top_sold_variants',
		)
		const result = (await tool.handler({ currency: 'EUR' } as never, {} as never)) as {
			content: { text: string }[]
		}

		expect(get.mock.calls[0]?.[1]).toMatchObject({ 'params[currency]': 'EUR' })
		expect(JSON.parse(result.content[0]?.text ?? '{}').currency).toBe('EUR')
	})

	it('orders ties deterministically, so the same question gives the same answer', async () => {
		// The store breaks quantity ties arbitrarily: two identical calls minutes apart returned
		// different tails, one containing a variant the other omitted, both stated as complete.
		const get = vi.fn().mockResolvedValue({ data: VARIANT_ROWS, status: 200 })
		const tool = toolNamed(
			{ get } as unknown as FluentCartClient,
			'fluentcart_report_top_sold_variants',
		)

		const run = async () => {
			const result = (await tool.handler({ currency: 'EUR' } as never, {} as never)) as {
				content: { text: string }[]
			}
			return JSON.parse(result.content[0]?.text ?? '{}').variants as Record<string, unknown>[]
		}

		const first = await run()
		const second = await run()
		expect(first).toEqual(second)
		// Highest quantity first, then a stable name order among equals.
		expect(first.map((row) => row.variation_name)).toEqual(['Gamma', 'Alpha', 'Zeta'])
	})

	it('drops the image URL, which no sales question needs', async () => {
		const get = vi.fn().mockResolvedValue({ data: VARIANT_ROWS, status: 200 })
		const tool = toolNamed(
			{ get } as unknown as FluentCartClient,
			'fluentcart_report_top_sold_variants',
		)
		const result = (await tool.handler({ currency: 'EUR' } as never, {} as never)) as {
			content: { text: string }[]
		}
		expect(result.content[0]?.text).not.toContain('media_url')
	})

	it('states the store-side row limit rather than implying completeness', async () => {
		// `per_page` did nothing — 3 and 50 both returned 10 rows, and there is no second page.
		const get = vi.fn().mockResolvedValue({ data: VARIANT_ROWS, status: 200 })
		const tool = toolNamed(
			{ get } as unknown as FluentCartClient,
			'fluentcart_report_top_sold_variants',
		)
		const result = (await tool.handler({ currency: 'EUR' } as never, {} as never)) as {
			content: { text: string }[]
		}
		expect(JSON.parse(result.content[0]?.text ?? '{}').limit).toMatch(/no paging/)
		expect(tool.schema.safeParse({ currency: 'EUR', per_page: 50 }).success).toBe(true)
	})
})

describe('money units are stated where getting them wrong costs 100x', () => {
	const tools = createAllTools({ get: async () => ({ data: {} }) } as never, {})
	const describeOf = (name: string) => tools.find((tool) => tool.name === name)?.description ?? ''

	it('does not claim the store sales report is in cents', () => {
		const text = describeOf('fluentcart_report_product')
		expect(text).not.toMatch(/in cents/i)
		expect(text).toMatch(/decimal/i)
	})

	it('says the store sales report is store-wide, not per-product', () => {
		expect(describeOf('fluentcart_report_product')).toMatch(/store-wide/i)
	})

	it('says subscription amounts are minor units', () => {
		const text = describeOf('fluentcart_subscription_list')
		expect(text).toMatch(/minor unit/i)
		expect(text, 'the example is what makes it unmissable').toMatch(/99900/)
	})
})
