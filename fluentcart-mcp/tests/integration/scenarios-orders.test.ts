// Scenarios: the questions a merchant asks about orders and money taken.
//
// Each one is scored on all three of discovery, answer and cost — see support/scenario.ts for why
// a green HTTP 200 proves none of them.
import { beforeAll, describe, expect, it } from 'vitest'
import type { ToolDefinition } from '../../src/tools/_factory.js'
import { createAllTools } from '../../src/tools/index.js'
import { getLiveClient } from './support/live-client.js'
import { formatOutcomes, runScenario, type Scenario } from './support/scenario.js'

let tools: ToolDefinition[]

beforeAll(() => {
	tools = createAllTools(getLiveClient(), {})
})

/** Asserts that reads as a sentence in the failure report. */
function must(condition: unknown, message: string): asserts condition {
	if (!condition) throw new Error(message)
}

const SCENARIOS: Scenario[] = [
	{
		id: 'orders/recent',
		question: 'Show me the most recent orders.',
		discovery: { query: 'recent orders', expect: 'fluentcart_order_list' },
		budget: 4_000,
		run: async (ctx) => {
			const { isError, body } = await ctx.call('fluentcart_order_list', { per_page: 5 })
			must(!isError, 'order_list failed')
			// The route wraps its page under `orders`; the paginator sits inside that, not at the top.
			const page = body.orders as Record<string, unknown>
			const rows = (page?.data ?? []) as unknown[]
			must(rows.length === 5, `expected 5 rows, got ${rows.length}`)
			const [[total]] = ctx.db('select count(*) from wp_fct_orders;')
			must(String(page.total) === total, `total ${page.total} disagrees with the database ${total}`)
			ctx.note(`store holds ${total} orders`)
		},
	},
	{
		id: 'orders/one-in-full',
		question: 'What is in order 59, and what did the customer pay?',
		discovery: { query: 'order details', expect: 'fluentcart_order_get' },
		budget: 6_500,
		run: async (ctx) => {
			const { isError, body, text } = await ctx.call('fluentcart_order_get', { order_id: 59 })
			must(!isError, 'order_get failed')
			const order = (body.order ?? body) as Record<string, unknown>
			const [[paid, currency]] = ctx.db(
				'select total_paid, currency from wp_fct_orders where id=59;',
			)
			must(
				String(order.total_paid ?? order.total_amount) === paid,
				`order_get says ${order.total_paid ?? order.total_amount}, database says ${paid}`,
			)
			ctx.note(`order 59: ${paid} ${currency}`)
			must(!text.includes('ip_address'), 'order_get is leaking the customer IP again')
		},
	},
	{
		id: 'orders/pending-not-counted',
		question: 'Why does my order count differ from my sales report?',
		discovery: { query: 'sales summary this year', expect: 'fluentcart_report_sales_summary' },
		budget: 6_000,
		run: async (ctx) => {
			// The gap that made 34 and 25 look like a contradiction. The tools must let a caller
			// SEE the gap rather than leaving them to discover it by subtraction.
			const list = await ctx.call('fluentcart_order_list', { per_page: 1 })
			const summary = await ctx.call('fluentcart_report_sales_summary', {
				from: '2020-01-01',
				to: '2027-12-31',
				currency: 'EUR',
			})
			must(!summary.isError, 'sales_summary failed')
			const [[all]] = ctx.db('select count(*) from wp_fct_orders;')
			const [[countable]] = ctx.db(
				"select count(*) from wp_fct_orders where payment_status in ('paid','refunded','partially_paid','partially_refunded');",
			)
			const listed = (list.body.orders as Record<string, unknown>)?.total
			must(String(listed) === all, `order_list total ${listed} != ${all}`)
			must(all !== countable, 'this store no longer has the gap; the scenario needs new data')
			const warnings = JSON.stringify(summary.body.warnings ?? [])
			must(
				/payment status/i.test(warnings),
				'sales_summary no longer states which payment statuses it counts',
			)
			ctx.note(`${all} orders exist, ${countable} are countable — the report must say so, and does`)
		},
	},
	{
		id: 'orders/refunded',
		question: 'Which orders were refunded, and how much did I give back?',
		discovery: { query: 'refunded orders total', expect: 'fluentcart_report_sales_summary' },
		budget: 6_000,
		run: async (ctx) => {
			const { isError, body } = await ctx.call('fluentcart_report_sales_summary', {
				from: '2020-01-01',
				to: '2027-12-31',
				currency: 'EUR',
			})
			must(!isError, 'sales_summary failed')
			const data = body.data as Record<string, number>
			must(
				typeof data.refunded_amount === 'number' && typeof data.refunded_orders === 'number',
				'a refund question needs both the amount and the count',
			)
			const [[refunded]] = ctx.db(
				"select count(*) from wp_fct_orders where payment_status in ('refunded','partially_refunded');",
			)
			must(
				data.refunded_orders <= Number(refunded),
				`report claims ${data.refunded_orders} refunded orders, store has ${refunded} in total`,
			)
			ctx.note(`refunded ${data.refunded_amount} across ${data.refunded_orders} orders (EUR)`)
		},
	},
	{
		id: 'orders/transactions',
		question: 'Show me the payment attempts on order 59.',
		discovery: { query: 'transactions for an order', expect: 'fluentcart_order_transactions' },
		budget: 6_000,
		run: async (ctx) => {
			const { isError, text } = await ctx.call('fluentcart_order_transactions', { order_id: 59 })
			must(!isError, 'order_transactions failed')
			must(!/secret|webhook_secret|api_token/i.test(text), 'a gateway secret is in the payload')
		},
	},
]

describe('order scenarios', () => {
	it('answers every one', async () => {
		const outcomes = []
		for (const scenario of SCENARIOS) outcomes.push(await runScenario(tools, scenario))
		const report = formatOutcomes(outcomes)
		process.stderr.write(`\n${report}\n`)

		const failed = outcomes.filter((outcome) => !outcome.passed)
		expect(
			failed.map((outcome) => `${outcome.id}: ${outcome.reason}`),
			report,
		).toEqual([])
	}, 120_000)
})
