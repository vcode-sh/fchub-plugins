// Scenarios: the analytics, tax and shipping questions a merchant actually asks.
//
// The flagship one is `analytics/tshirt-colours` — "are t-shirt sales going well this year, and
// which colours sell better". It is here because it is the question this whole effort was aimed
// at, and because it exercises the two things that were most broken: a revenue figure that can be
// trusted, and a per-variant breakdown that does not mix currencies.
import { beforeAll, describe, expect, it } from 'vitest'
import type { ToolDefinition } from '../../src/tools/_factory.js'
import { createAllTools } from '../../src/tools/index.js'
import { getLiveClient } from './support/live-client.js'
import { formatOutcomes, runScenario, type Scenario } from './support/scenario.js'

let tools: ToolDefinition[]

beforeAll(() => {
	tools = createAllTools(getLiveClient(), {})
})

function must(condition: unknown, message: string): asserts condition {
	if (!condition) throw new Error(message)
}

const WIDE = { from: '2020-01-01', to: '2027-12-31' }

const SCENARIOS: Scenario[] = [
	{
		id: 'analytics/tshirt-colours',
		question: 'Are t-shirt sales going well this year, and which colours sell better?',
		discovery: { query: 'which colours sell best', expect: 'fluentcart_report_top_sold_variants' },
		budget: 5_000,
		run: async (ctx) => {
			const summary = await ctx.call('fluentcart_report_sales_summary', {
				from: '2026-01-01',
				to: '2026-12-31',
				currency: 'EUR',
			})
			must(!summary.isError, 'sales_summary failed')
			const gross = (summary.body.data as Record<string, number>).gross_sales
			const [[dbGross]] = ctx.db(
				"select sum(total_paid) from wp_fct_orders where currency='EUR' and payment_status in ('paid','refunded','partially_paid','partially_refunded') and created_at >= '2026-01-01' and created_at < '2027-01-01';",
			)
			must(
				Math.round(gross * 100) === Number(dbGross),
				`gross_sales ${gross} does not match the database ${Number(dbGross) / 100}`,
			)

			const variants = await ctx.call('fluentcart_report_top_sold_variants', {
				currency: 'EUR',
				startDate: '2026-01-01',
				endDate: '2026-12-31',
			})
			must(!variants.isError, 'top_sold_variants failed')
			const rows = variants.body.variants as { variation_name: string; quantity: number }[]
			must(rows.length > 0, 'no variants came back for a year the store sold in')
			// Ranked, so "which sells better" is answerable from the order alone.
			for (let index = 1; index < rows.length; index += 1) {
				must(
					Number(rows[index - 1]?.quantity) >= Number(rows[index]?.quantity),
					'variants are not ranked by quantity, so the ranking cannot be read off',
				)
			}
			must(
				variants.body.currency === 'EUR',
				'the variant report must state the currency it scoped to',
			)
			ctx.note(
				`EUR ${gross} gross; best variant ${rows[0]?.variation_name} at ${rows[0]?.quantity} units`,
			)
		},
	},
	{
		id: 'analytics/currency-is-pinned',
		question: 'My store takes EUR and PLN — are the totals mixing them?',
		budget: 4_000,
		run: async (ctx) => {
			const eur = await ctx.call('fluentcart_report_sales_summary', { ...WIDE, currency: 'EUR' })
			const pln = await ctx.call('fluentcart_report_sales_summary', { ...WIDE, currency: 'PLN' })
			const eurGross = (eur.body.data as Record<string, number>).gross_sales
			const plnGross = (pln.body.data as Record<string, number>).gross_sales

			const [[dbEur]] = ctx.db(
				"select sum(total_paid) from wp_fct_orders where currency='EUR' and payment_status in ('paid','refunded','partially_paid','partially_refunded');",
			)
			const [[dbPln]] = ctx.db(
				"select sum(total_paid) from wp_fct_orders where currency='PLN' and payment_status in ('paid','refunded','partially_paid','partially_refunded');",
			)
			must(
				Math.round(eurGross * 100) === Number(dbEur),
				`EUR ${eurGross} != ${Number(dbEur) / 100}`,
			)
			must(
				Math.round(plnGross * 100) === Number(dbPln),
				`PLN ${plnGross} != ${Number(dbPln) / 100}`,
			)
			must(eurGross !== plnGross, 'both currencies returned the same total, which cannot be right')
			ctx.note(`EUR ${eurGross} and PLN ${plnGross} kept apart`)
		},
	},
	{
		id: 'analytics/currency-required',
		question: 'What are my total sales? (asked without naming a currency)',
		budget: 2_000,
		run: async (ctx) => {
			// Refusing is the correct answer. A store taking two currencies has no single total, and
			// answering with one would be a number that means nothing.
			const tool = tools.find((entry) => entry.name === 'fluentcart_report_sales_summary')
			must(tool, 'sales_summary is not registered')
			const parsed = tool.schema.safeParse({ from: '2026-01-01', to: '2026-12-31' })
			must(!parsed.success, 'the summary accepted a request with no currency')
			ctx.note('refuses rather than adding EUR to PLN')
		},
	},
	{
		id: 'analytics/month-by-month',
		question: 'Show me sales month by month this year.',
		discovery: { query: 'sales by month', expect: 'fluentcart_report_sales_trend' },
		budget: 6_000,
		run: async (ctx) => {
			const { isError, body } = await ctx.call('fluentcart_report_sales_trend', {
				from: '2026-01-01',
				to: '2026-12-31',
				currency: 'EUR',
				granularity: 'monthly',
			})
			must(!isError, 'sales_trend failed')
			const rows = body.data as Record<string, unknown>[]
			must(rows.length === 12, `a full year monthly should give 12 buckets, got ${rows.length}`)
			must(
				body.granularity !== undefined,
				'the trend must state which granularity the store actually applied',
			)
			ctx.note(`12 buckets, granularity ${JSON.stringify(body.granularity)}`)
		},
	},
	{
		id: 'analytics/empty-period',
		question: 'How did we do in 1990?',
		budget: 4_000,
		run: async (ctx) => {
			// A period with nothing in it must answer zero, not fail and not omit the buckets.
			const { isError, body } = await ctx.call('fluentcart_report_sales_summary', {
				from: '1990-01-01',
				to: '1990-12-31',
				currency: 'EUR',
			})
			must(!isError, 'an empty period should be an answer, not an error')
			const data = body.data as Record<string, number>
			must(data.order_count === 0, `expected 0 orders in 1990, got ${data.order_count}`)
			must(data.gross_sales === 0, `expected 0 gross in 1990, got ${data.gross_sales}`)
			ctx.note('an empty period answers zero rather than erroring')
		},
	},
	{
		id: 'analytics/best-sellers',
		question: 'What are my best selling products?',
		discovery: { query: 'best selling products', expect: 'fluentcart_report_top_products' },
		budget: 5_000,
		run: async (ctx) => {
			const { isError, body } = await ctx.call('fluentcart_report_top_products', {
				...WIDE,
				currency: 'EUR',
			})
			must(!isError, 'top_products failed')
			const rows = body.data as Record<string, unknown>[]
			must(Array.isArray(rows) && rows.length > 0, 'no best sellers on a store with 34 orders')
			ctx.note(`${rows.length} products ranked`)
		},
	},
	{
		id: 'tax/what-do-i-charge',
		question: 'What tax am I charging, and where?',
		discovery: { query: 'tax rates', expect: 'fluentcart_tax_rate_list' },
		budget: 3_000,
		run: async (ctx) => {
			const overview = await ctx.call('fluentcart_tax_rate_list')
			must(!overview.isError, 'tax_rate_list failed')
			must(Number(overview.body.total_countries) > 0, 'the overview reports no countries at all')
			const detail = await ctx.call('fluentcart_tax_rate_list', { country: 'DE' })
			must(!detail.isError, 'per-country tax lookup failed')
			const [[dbRates]] = ctx.db("select count(*) from wp_fct_tax_rates where country='DE';")
			const rates = detail.body.rates as unknown[]
			must(
				String(rates.length) === dbRates,
				`DE shows ${rates.length} rates, database has ${dbRates}`,
			)
			ctx.note(`${overview.body.total_countries} countries seeded; DE has ${dbRates} rates`)
		},
	},
	{
		id: 'tax/reference-is-labelled',
		question: 'What is the standard VAT rate in Portugal?',
		budget: 3_000,
		run: async (ctx) => {
			// A legitimate use of the reference table — but the answer must say it is not this store.
			const { isError, body } = await ctx.call('fluentcart_tax_config_rates', { country: 'PT' })
			must(!isError, 'tax_config_rates failed')
			must(
				String(body.note).includes('REFERENCE DATA, NOT THIS STORE'),
				'the reference table answered without saying it is not the store',
			)
			ctx.note('reference lookup works and labels itself')
		},
	},
	{
		id: 'shipping/where-can-i-ship',
		question: 'Can I ship to Germany, and what would it cost?',
		discovery: { query: 'shipping methods', expect: 'fluentcart_order_shipping_methods' },
		budget: 3_000,
		run: async (ctx) => {
			const { isError, body } = await ctx.call('fluentcart_order_shipping_methods', {
				country_code: 'DE',
			})
			must(!isError, 'order_shipping_methods failed')
			const applicable = body.shipping_methods as unknown[]
			const other = body.other_shipping_methods as unknown[]
			const [[enabled]] = ctx.db(
				"select count(*) from wp_fct_shipping_methods where is_enabled='1';",
			)
			must(
				applicable.length + other.length === Number(enabled),
				`the two lists hold ${applicable.length + other.length} methods, the store has ${enabled} enabled`,
			)
			must(applicable.length > 0, 'no method reaches Germany, on a zone covering the whole world')
			ctx.note(`${applicable.length} of ${enabled} enabled methods reach DE`)
		},
	},
	{
		id: 'shipping/state-level',
		question: 'I want to charge different shipping in California — what states can I pick?',
		budget: 3_000,
		run: async (ctx) => {
			const { isError, body } = await ctx.call('fluentcart_shipping_zone_states', {
				country_code: 'US',
			})
			must(!isError, 'shipping_zone_states failed')
			const states = (body.data as Record<string, unknown>).states as unknown[]
			must(states.length > 40, `the US came back with ${states.length} states`)
			ctx.note(`${states.length} US subdivisions offered`)
		},
	},
	{
		id: 'analytics/nonexistent-order',
		question: 'Show me order 999999.',
		budget: 2_000,
		run: async (ctx) => {
			// Must fail cleanly and say so, not return an empty shell that reads as a real order.
			const { isError, text } = await ctx.call('fluentcart_order_get', { order_id: 999_999 })
			must(isError || /not found|no order|404/i.test(text), 'a missing order did not say so')
			must(!/stack trace|\.php|Fatal error/i.test(text), 'the failure leaked server internals')
			ctx.note('a missing order fails cleanly, with no PHP in the message')
		},
	},
]

describe('analytics, tax and shipping scenarios', () => {
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
	}, 180_000)
})
