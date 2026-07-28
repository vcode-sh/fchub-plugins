// Scenarios: the questions a merchant asks about recurring revenue. Scored on discovery, answer and
// cost together — see support/scenario.ts. Ground truth is the database, never a second endpoint.
// The discount half lives in scenarios-coupons.test.ts. Money fields are minor units, so a EUR
// 999/yr plan arrives as 99900; pause, resume and reactivate are absent because 1.5.5 stubs them.
import { beforeAll, describe, expect, it } from 'vitest'
import type { ToolDefinition } from '../../src/tools/_factory.js'
import { createAllTools } from '../../src/tools/index.js'
import { getLiveClient } from './support/live-client.js'
import { formatOutcomes, runScenario, type Scenario } from './support/scenario.js'

let tools: ToolDefinition[]

beforeAll(() => {
	tools = createAllTools(getLiveClient(), {})
})

// Wide enough to cover the store's whole history — for the two reports that discard it anyway.
const WIDE = { startDate: '2020-01-01', endDate: '2027-12-31' }
type Row = Record<string, unknown>
type Page = { data: Row[]; total: number }

// Asserts that read as a sentence in the failure report.
function must(condition: unknown, message: string): asserts condition {
	if (!condition) throw new Error(message)
}

const SCENARIOS: Scenario[] = [
	{
		id: 'subs/active-and-worth',
		question: 'How many active subscriptions do I have, and what are they worth?',
		discovery: { query: 'active subscriptions', expect: 'fluentcart_subscription_list' },
		budget: 1_200,
		run: async (ctx) => {
			const call = { active_view: 'active', per_page: 50 }
			const { isError, body } = await ctx.call('fluentcart_subscription_list', call)
			must(!isError, 'subscription_list failed')
			const page = body.data as Page
			const sql =
				"select count(*), max(recurring_amount), max(billing_interval) from wp_fct_subscriptions where status='active';"
			const [[live, amount, interval]] = ctx.db(sql)
			must(String(page.total) === live, `list says ${page.total} active, database ${live}`)
			const row = page.data[0] as Row
			must(String(row.recurring_amount) === amount, `${row.recurring_amount} is not ${amount}`)
			must(row.currency === 'EUR', 'the currency did not survive the projection')
			ctx.note(`${live} active at ${amount} minor units ${interval} = ${Number(amount) / 100} EUR`)
		},
	},
	{
		id: 'subs/mrr',
		question: 'What is my MRR?',
		discovery: { query: 'MRR', expect: 'fluentcart_report_subscription_retention' },
		budget: 2_000,
		run: async (ctx) => {
			const window = { startDate: '2026-04-01', endDate: '2026-07-31' }
			const { isError, body } = await ctx.call('fluentcart_report_subscription_retention', window)
			must(!isError, 'subscription_retention failed')
			const rows = body.retention_data as Record<string, string>[]
			const yearly =
				"select sum(recurring_amount) from wp_fct_subscriptions where status='active' and billing_interval='yearly';"
			const expected = Number(ctx.db(yearly)[0]?.[0]) / 100 / 12
			const mrr = Number(rows[rows.length - 1]?.mrr)
			must(Math.abs(mrr - expected) < 0.01, `MRR ${mrr} against ${expected.toFixed(2)} stored`)
			ctx.note(`MRR ${mrr} — a yearly plan of ${expected * 12 * 100} minor units, over twelve`)
		},
	},
	{
		id: 'subs/renews-next-week',
		question: 'Whose subscription renews next week?',
		discovery: {
			query: 'subscriptions renewing next week',
			expect: 'fluentcart_subscription_list',
		},
		budget: 2_500,
		run: async (ctx) => {
			const { isError, body } = await ctx.call('fluentcart_subscription_list', { per_page: 50 })
			must(!isError, 'subscription_list failed')
			const rows = (body.data as Page).data
			const [[scheduled, soon]] = ctx.db(
				'select sum(next_billing_date is not null), sum(next_billing_date between utc_timestamp() and date_add(utc_timestamp(), interval 7 day)) from wp_fct_subscriptions;',
			)
			const week = Date.now() + 7 * 86_400_000
			const dated = rows.filter((row) => row.next_billing_date !== null)
			must(dated.length === Number(scheduled), `${dated.length} dates against ${scheduled} stored`)
			const due = dated.filter((row) => Date.parse(`${row.next_billing_date}Z`) < week).length
			must(due === Number(soon), `${due} renewals read as due, the database says ${soon}`)
			ctx.note(`${scheduled} of ${rows.length} carry a renewal date; ${soon} inside 7 days`)
		},
	},
	{
		id: 'subs/upcoming-renewals',
		question: 'What renewals are coming up, and what are they worth?',
		discovery: { query: 'upcoming renewals', expect: 'fluentcart_report_future_renewals' },
		budget: 800,
		run: async (ctx) => {
			const { isError, body } = await ctx.call('fluentcart_report_future_renewals', WIDE)
			const [from, to] = body.period as [string, string]
			must(!isError, 'future_renewals failed')
			must(!to.startsWith('2027'), 'the window is no longer hardcoded; correct the description')
			const [[due]] = ctx.db(
				`select count(*) from wp_fct_subscriptions where next_billing_date between '${from}' and '${to}';`,
			)
			must(String(body.totalRenewals) === due, `projects ${body.totalRenewals}, stored ${due}`)
			ctx.note(`caller range discarded; answered for ${from}..${to} and found ${due}`)
		},
	},
	{
		id: 'subs/failed-to-bill',
		question: 'Which subscriptions failed to bill?',
		discovery: { query: 'subscriptions failed to bill', expect: 'fluentcart_subscription_list' },
		budget: 2_000,
		run: async (ctx) => {
			// past_due is absent from SubscriptionFilter::tabsMap, so active_view no longer offers it —
			// see tests/tools/subscription-filter-views.test.ts. search reaches it. pending is the
			// control: an empty failing list proves nothing unless the same argument returns rows.
			const probes = { failing: 'active_view', pending: 'active_view', past_due: 'search' }
			let pending = 0
			for (const [status, key] of Object.entries(probes)) {
				const call = await ctx.call('fluentcart_subscription_list', { [key]: status })
				must(!call.isError, `subscription_list ${key}=${status} failed`)
				const [[n]] = ctx.db(`select count(*) from wp_fct_subscriptions where status='${status}';`)
				const total = (call.body.data as Page).total
				must(String(total) === n, `${key}=${status}: tool says ${total}, database says ${n}`)
				if (status === 'pending') pending = total
			}
			must(pending > 0, 'the control status is empty too, so the filter proves nothing')
			ctx.note(`nothing failing or past_due; the same filter still returns ${pending} pending`)
		},
	},
	{
		id: 'subs/charged-after-cancelling',
		question: 'This customer says they cancelled but were charged. What is the history?',
		discovery: { query: 'subscription billing history', expect: 'fluentcart_subscription_get' },
		budget: 2_000,
		run: async (ctx) => {
			const [[id]] = ctx.db("select id from wp_fct_subscriptions where status='active' limit 1;")
			const call = { subscription_id: Number(id) }
			const { isError, body, text } = await ctx.call('fluentcart_subscription_get', call)
			must(!isError, 'subscription_get failed')
			const sub = body.subscription as Row
			const [[canceled, billed, charged]] = ctx.db(
				`select ifnull(s.canceled_at,'-'), s.bill_count, (select count(*) from wp_fct_orders o where o.id=s.parent_order_id and o.payment_status='paid') from wp_fct_subscriptions s where s.id=${id};`,
			)
			must((sub.canceled_at ?? '-') === canceled, `canceled_at ${sub.canceled_at} != ${canceled}`)
			must(String(sub.bill_count) === billed, `billed ${sub.bill_count} times, not ${billed}`)
			const orders = sub.related_orders as Row[]
			const paid = orders.filter((order) => order.payment_status === 'paid').length
			must(paid === Number(charged), `${paid} paid orders attached, the database says ${charged}`)
			must(!/ip_address|vendor_response/.test(text), 'the customer IP or gateway blob is back')
			ctx.note(`never cancelled, billed ${billed} time(s), ${charged} paid order(s) attached`)
		},
	},
	{
		id: 'subs/churn-loss',
		question: 'How much recurring revenue am I losing to cancellations?',
		discovery: { query: 'churn', expect: 'fluentcart_report_subscription_retention' },
		budget: 2_000,
		run: async (ctx) => {
			const window = { startDate: '2026-04-01', endDate: '2026-07-31' }
			const { isError, body } = await ctx.call('fluentcart_report_subscription_retention', window)
			must(!isError, 'subscription_retention failed')
			const rows = body.retention_data as Record<string, number>[]
			const lost = rows.reduce((sum, row) => sum + Number(row.churned_subscriptions_mrr), 0)
			const churned = rows.reduce((sum, row) => sum + Number(row.churned_subscriptions), 0)
			const [[gone]] = ctx.db(
				"select count(*) from wp_fct_subscriptions where canceled_at is not null or status in ('canceled','cancelled','expired');",
			)
			must(churned === Number(gone), `report churned ${churned}, the database holds ${gone}`)
			must(lost === 0 || churned > 0, `${lost} of churned MRR with nothing churned`)
			ctx.note(`${churned} cancellations across the window, ${lost} MRR lost`)
		},
	},
	{
		id: 'subs/customer-plan',
		question: 'What plan is this customer on, and when did they start?',
		discovery: { query: 'customer subscription plan', expect: 'fluentcart_subscription_list' },
		budget: 1_600,
		run: async (ctx) => {
			const call = { search: 'hello@vcode.sh', per_page: 50 }
			const { isError, body } = await ctx.call('fluentcart_subscription_list', call)
			must(!isError, 'subscription_list failed')
			const rows = (body.data as Page).data
			const [[owned]] = ctx.db(
				"select count(*) from wp_fct_subscriptions s join wp_fct_customers c on c.id=s.customer_id where c.email='hello@vcode.sh';",
			)
			must(rows.length === Number(owned), `search found ${rows.length}, the customer has ${owned}`)
			const [[plan, started]] = ctx.db(
				"select item_name, created_at from wp_fct_subscriptions where status='active' limit 1;",
			)
			const active = rows.find((row) => row.status === 'active') as Record<string, string>
			must(active.item_name === plan, `plan "${active.item_name}" is not "${plan}"`)
			const day = started.slice(0, 10)
			must(active.created_at.startsWith(day), `started ${active.created_at}, stored ${started}`)
			ctx.note(`on "${plan}" since ${started}`)
		},
	},
	{
		id: 'subs/never-billed',
		question: 'This subscription is on my list but I never got the money. What happened?',
		discovery: { query: 'subscription that never billed', expect: 'fluentcart_subscription_get' },
		budget: 1_400,
		run: async (ctx) => {
			const [[id]] = ctx.db('select id from wp_fct_subscriptions where bill_count=0 limit 1;')
			const call = { subscription_id: Number(id) }
			const { isError, body } = await ctx.call('fluentcart_subscription_get', call)
			must(!isError, 'subscription_get failed')
			const sub = body.subscription as Row
			must(String(sub.bill_count) === '0', `subscription ${id} billed ${sub.bill_count} times`)
			must(sub.next_billing_date === null, 'a never-billed subscription advertised a renewal')
			const [[paid]] = ctx.db(
				`select payment_status from wp_fct_orders where id=(select parent_order_id from wp_fct_subscriptions where id=${id});`,
			)
			const order = (sub.related_orders as Row[])[0]
			must(
				order?.payment_status === paid,
				`owning order reads ${order?.payment_status}, not ${paid}`,
			)
			ctx.note(`subscription ${id} is ${sub.status}; owning order ${order?.id} is ${paid}`)
		},
	},
	{
		id: 'subs/unknown-id',
		question: 'What is on subscription 999999?',
		discovery: { query: 'subscription details', expect: 'fluentcart_subscription_get' },
		// Measured, not rounded to fit. 146 characters to carry a 72-character fact: "Error
		// [NOT_FOUND]: Subscription not found (fluent_cart_entity_not_found)". The rest is a generic
		// "Resource not found" sitting in front of the specific one, and the sentence a second time
		// inside the detail JSON, because formatError appends error.detail to a message that already
		// contains it. 250 is slack; the scenario fails on the repetition, not on the cost.
		budget: 250,
		run: async (ctx) => {
			const call = { subscription_id: 999_999 }
			const { isError, text } = await ctx.call('fluentcart_subscription_get', call)
			must(isError, 'an unknown subscription must be an error, not an empty success')
			must(/not found/i.test(text), 'the error never says the subscription is missing')
			const [[exists]] = ctx.db('select count(*) from wp_fct_subscriptions where id=999999;')
			must(exists === '0', 'the store grew a subscription 999999; pick another id')
			const copies = text.split('Subscription not found').length - 1
			ctx.note(`${text.length} characters for a 72-character fact; the sentence appears ${copies}x`)
			must(copies === 1, `the same error body is printed ${copies} times`)
		},
	},
	{
		id: 'subs/chart-total',
		question:
			'The subscription chart shows a different total from my subscription list. Which is right?',
		discovery: { query: 'subscription chart', expect: 'fluentcart_report_subscription_chart' },
		budget: 900,
		run: async (ctx) => {
			const { isError, body } = await ctx.call('fluentcart_report_subscription_chart', WIDE)
			must(!isError, 'subscription_chart failed')
			const claimed = String((body.summary as Row).total_subscriptions)
			const [[total]] = ctx.db('select count(*) from wp_fct_subscriptions;')
			const [[orders]] = ctx.db(
				"select count(*) from wp_fct_orders where type='subscription' and status in ('completed','processing','shipped','delivered');",
			)
			// The figure counts orders and will not become a subscription count. What has to hold is that
			// it counts what it claims to, and that the description says so and names the right tool.
			must(claimed === orders, `chart says ${claimed}, subscription-type paid orders are ${orders}`)
			const said = tools.find((e) => e.name === 'fluentcart_report_subscription_chart')?.description
			must(/ORDERS/.test(said ?? ''), 'the description stopped saying it counts orders')
			must(/subscription_list/.test(said ?? ''), 'nothing points at the tool that does count them')
			ctx.note(`chart ${claimed} against ${total} subscriptions — disagrees, and says why`)
		},
	},
]

describe('subscription scenarios', () => {
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
