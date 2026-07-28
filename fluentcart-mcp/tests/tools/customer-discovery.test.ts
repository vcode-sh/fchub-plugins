// In dynamic mode search is the interface, so a customer tool that cannot be found does not exist.
//
// Every row below was measured against the live store first, in scenarios-customers.test.ts, and
// three of them were failures before the descriptions changed:
//
//  - "find customer by email" — the commonest support question there is — returned
//    customer_create, customer_update, customer_address_update and two email settings tools.
//    `fluentcart_customer_list` was nowhere in the top five. The word "email" lived only in the
//    schema field description, and the ranker scores names, titles and descriptions, never schemas.
//  - "average order value for a customer" returned three report tools and no customer tool at all,
//    because `aov` was never spelled out.
//  - "top customers by lifetime value" put `fluentcart_report_customer` first — a COUNT(*) with no
//    money in it anywhere — and `fluentcart_customer_recalculate_ltv`, a write, second. The tool
//    holding the figure ranked third.
//
// These are pinned as ranks rather than mere membership where the rank is the point: being visible
// at #5 behind four tools that cannot answer is a different thing from being the first suggestion.
import { describe, expect, it } from 'vitest'
import type { FluentCartClient } from '../../src/api/client.js'
import { searchTools } from '../../src/tools/dynamic-search.js'
import { createAllTools } from '../../src/tools/index.js'

const VISIBLE = 5

function ranking(query: string): string[] {
	// The registry is built for its definitions only; nothing here calls a handler.
	const tools = createAllTools({} as FluentCartClient, {})
	return searchTools(tools, query, { limit: VISIBLE }).map((row) => row.name)
}

/** Questions where the customer tool must be the first thing an agent is offered. */
const FIRST: [question: string, tool: string][] = [
	['find customer by email', 'fluentcart_customer_list'],
	['count of customers', 'fluentcart_customer_list'],
	['customers who never bought anything', 'fluentcart_customer_list'],
	['top customers by lifetime value', 'fluentcart_customer_list'],
	['average order value for a customer', 'fluentcart_customer_get'],
	['customer lifetime value', 'fluentcart_customer_get'],
	['customer addresses on file', 'fluentcart_customer_addresses'],
	['customer with zero orders', 'fluentcart_order_customer_orders'],
	['whole order history for a customer', 'fluentcart_customer_orders_simple'],
]

/** Questions where being on the page is enough, because more than one tool is a fair answer. */
const VISIBLE_AT_ALL: [question: string, tool: string][] = [
	['everything on file about a customer', 'fluentcart_customer_get'],
	['does this customer exist', 'fluentcart_customer_get'],
	['repeat customers', 'fluentcart_customer_list'],
	['refunded orders for one customer', 'fluentcart_order_customer_orders'],
	['new customers compared with last month', 'fluentcart_report_customer'],
	['attach a wordpress user to a customer', 'fluentcart_customer_attach_user'],
]

describe('a merchant asking about people finds the tool that answers', () => {
	for (const [question, tool] of FIRST) {
		it(`ranks ${tool} first for "${question}"`, () => {
			expect(ranking(question)[0], `got ${ranking(question).join(', ')}`).toBe(tool)
		})
	}

	for (const [question, tool] of VISIBLE_AT_ALL) {
		it(`surfaces ${tool} for "${question}"`, () => {
			const names = ranking(question)
			expect(names, `got ${names.join(', ')}`).toContain(tool)
		})
	}
})

describe('the search row stays a row, not a paragraph', () => {
	it('summarises customer_list by what it finds, not by how it sorts', () => {
		const [row] = searchTools(
			createAllTools({} as FluentCartClient, {}),
			'find customer by email',
			{ limit: 1 },
		)
		expect(row?.name).toBe('fluentcart_customer_list')
		// summarise() takes the first sentence, so the first sentence has to be the useful one.
		expect(row?.summary).toMatch(/email/i)
		expect(row?.summary.length).toBeLessThan(120)
	})
})

describe('the customer read tools do not send callers to parameters that do not exist', () => {
	const tools = createAllTools({} as FluentCartClient, {})

	function describedAs(name: string): string {
		const found = tools.find((tool) => tool.name === name)
		if (!found) throw new Error(`${name} is not registered`)
		return found.description
	}

	it('stops pointing customer_orders_simple at an order_list customer filter', () => {
		// Measured: /orders?customer_id=117 returned total 34 — the whole store — byte-identical to
		// /orders with no filter. The parameter is not rejected, it is discarded, so a caller
		// following the old sentence would have reported every order in the store as one
		// customer's history.
		const description = describedAs('fluentcart_customer_orders_simple')
		expect(description).not.toMatch(/order_list with customer_id/i)
		expect(description).toContain('fluentcart_order_customer_orders')

		const orderList = tools.find((tool) => tool.name === 'fluentcart_order_list')
		const shape = orderList?.schema.shape as Record<string, unknown> | undefined
		expect(Object.keys(shape ?? {})).not.toContain('customer_id')
	})

	it('warns that customer_addresses cannot tell an unknown customer from an empty one', () => {
		expect(describedAs('fluentcart_customer_addresses')).toContain('fluentcart_customer_get')
	})
})
