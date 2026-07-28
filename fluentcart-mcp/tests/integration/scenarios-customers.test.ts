// Scenarios: the questions a merchant or a support agent asks about people.
//
// Scored on discovery, answer and cost together — see support/scenario.ts for why a green HTTP 200
// proves none of the three. Ground truth is MySQL, never a second endpoint.
//
// Two things this sweep is built to catch, because both were found here:
//
//  - A tool that answers the question but cannot be found. `fluentcart_customer_list` was invisible
//    to "find customer by email" — the top five were customer_create, customer_update,
//    customer_address_update and two email settings tools — because the word "email" lived only in
//    a schema field and the ranker reads descriptions.
//  - A tool that is found first and answers with silence. `fluentcart_report_repeat_customers`
//    ranks #1 for "repeat customers" and returns an empty list on a store that has two.
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

/** The paginated envelope every list tool returns, whatever key it hides under. */
interface Envelope<Row = Record<string, unknown>> {
	data: Row[]
	total: number
	current_page: number
	last_page: number
	per_page: number
}

function envelope<Row = Record<string, unknown>>(
	body: Record<string, unknown>,
	key: string,
): Envelope<Row> {
	const wrapper = (body[key] ?? body) as Envelope<Row>
	must(Array.isArray(wrapper?.data), `${key} did not come back as a paginated envelope`)
	return wrapper
}

/** The store owner's own record: 19 orders, three of them refunded, the only real history here. */
const BIG_SPENDER = 'hello@vcode.sh'

const SCENARIOS: Scenario[] = [
	{
		id: 'customers/find-by-email',
		question: 'Find the customer with the email hello@vcode.sh.',
		discovery: { query: 'find customer by email', expect: 'fluentcart_customer_list' },
		budget: 700,
		run: async (ctx) => {
			const { isError, body } = await ctx.call('fluentcart_customer_list', { search: BIG_SPENDER })
			must(!isError, 'customer_list failed')
			const page = envelope(body, 'customers')
			const [[id, name]] = ctx.db(
				`select id, concat(first_name,' ',last_name) from wp_fct_customers where email='${BIG_SPENDER}';`,
			)
			must(page.total === 1, `an exact email matched ${page.total} customers, not 1`)
			must(
				String(page.data[0]?.id) === id,
				`search returned customer ${page.data[0]?.id}, not ${id}`,
			)
			must(page.data[0]?.email === BIG_SPENDER, 'the row is not the customer that was searched for')
			ctx.note(`${BIG_SPENDER} is customer ${id}, ${name}`)
		},
	},

	{
		id: 'customers/top-by-ltv',
		question: 'Who are my top 10 customers by lifetime value?',
		discovery: { query: 'top customers by lifetime value', expect: 'fluentcart_customer_list' },
		budget: 3_500,
		run: async (ctx) => {
			const { isError, body } = await ctx.call('fluentcart_customer_list', {
				per_page: 10,
				sort_by: 'ltv',
				sort_type: 'DESC',
			})
			must(!isError, 'customer_list failed')
			const page = envelope<{ id: number; ltv: string; email: string }>(body, 'customers')
			// Only the customers who have actually spent are compared. Everyone else ties on zero,
			// and asserting an order over a tie would be asserting an implementation detail.
			const spenders = ctx.db(
				'select id, ltv from wp_fct_customers where ltv > 0 order by ltv desc;',
			)
			const ranked = page.data.filter((row) => Number(row.ltv) > 0)
			must(
				ranked.length === spenders.length,
				`page one shows ${ranked.length} paying customers, the store has ${spenders.length}`,
			)
			for (const [index, [id, ltv]] of spenders.entries()) {
				const row = ranked[index]
				must(
					String(row?.id) === id && String(row?.ltv) === ltv,
					`rank ${index + 1} is customer ${row?.id} at ${row?.ltv}, database says ${id} at ${ltv}`,
				)
			}
			ctx.note(`top spender: customer ${spenders[0]?.[0]} at ${spenders[0]?.[1]} cents`)
		},
	},

	{
		id: 'customers/charged-twice',
		question: 'A customer emailed saying they were charged twice — what did they actually buy?',
		discovery: { query: 'find orders for an email address', expect: 'fluentcart_order_list' },
		// Measured 10,442: 5,358 for nineteen order summaries and 5,084 for the one order opened in
		// full. Two calls is the floor — the list has to be wide enough to spot the repeat, and the
		// repeat has to be opened to say what was in it.
		budget: 11_500,
		run: async (ctx) => {
			const list = await ctx.call('fluentcart_order_list', { search: BIG_SPENDER, per_page: 50 })
			must(!list.isError, 'order_list failed')
			const page = envelope<{ id: number; total_amount: number; currency: string }>(
				list.body,
				'orders',
			)
			const [[customerId]] = ctx.db(`select id from wp_fct_customers where email='${BIG_SPENDER}';`)
			const owned = ctx.db(`select count(*) from wp_fct_orders where customer_id=${customerId};`)
			must(
				page.total === Number(owned[0]?.[0]),
				`searching the email found ${page.total} orders, the customer has ${owned[0]?.[0]}`,
			)

			// The duplicate the customer is complaining about: same amount, same currency, more than
			// once. It has to be visible in the list payload, or the agent has to fetch every order.
			const suspects = ctx.db(
				`select group_concat(id order by id) from wp_fct_orders where customer_id=${customerId} group by total_amount, currency having count(*) > 1;`,
			)
			must(
				suspects.length > 0,
				'this store no longer has a repeated charge; the scenario needs new data',
			)
			const expected = suspects.flatMap((row) => (row[0] ?? '').split(','))
			const byAmount = new Map<string, number[]>()
			for (const row of page.data) {
				const key = `${row.total_amount}-${row.currency}`
				byAmount.set(key, [...(byAmount.get(key) ?? []), row.id])
			}
			const found = [...byAmount.values()]
				.filter((ids) => ids.length > 1)
				.flat()
				.map(String)
				.sort()
			must(
				found.join(',') === [...expected].sort().join(','),
				`list payload shows repeated charges on ${found.join(',')}, database says ${expected.join(',')}`,
			)

			const first = Number(expected[0])
			const detail = await ctx.call('fluentcart_order_get', { order_id: first })
			must(!detail.isError, 'order_get failed')
			const order = (detail.body.order ?? detail.body) as {
				order_items?: { post_title?: string; quantity?: string }[]
			}
			const bought = ctx.db(`select post_title from wp_fct_order_items where order_id=${first};`)
			must(
				order.order_items?.length === bought.length,
				`order ${first} shows ${order.order_items?.length} lines, database has ${bought.length}`,
			)
			must(
				order.order_items?.[0]?.post_title === bought[0]?.[0],
				`order ${first} says they bought "${order.order_items?.[0]?.post_title}", database says "${bought[0]?.[0]}"`,
			)
			ctx.note(
				`orders ${expected.join(', ')} are the same charge repeated; all of them are "${bought[0]?.[0]}"`,
			)
		},
	},

	{
		id: 'customers/new-vs-last-month',
		question: 'How many new customers did I get in April, against March?',
		discovery: {
			query: 'new customers compared with last month',
			expect: 'fluentcart_report_customer',
		},
		budget: 900,
		run: async (ctx) => {
			const { isError, body } = await ctx.call('fluentcart_report_customer', {
				startDate: '2026-04-01',
				endDate: '2026-04-30',
				groupKey: 'monthly',
				compareType: 'previous_month',
			})
			must(!isError, 'report_customer failed')
			const summary = body.summary as { customer_count?: number }
			const previous = body.previousSummary as { customer_count?: number } | unknown[]
			const [[april]] = ctx.db(
				"select count(*) from wp_fct_customers where created_at >= '2026-04-01' and created_at < '2026-05-01';",
			)
			const [[march]] = ctx.db(
				"select count(*) from wp_fct_customers where created_at >= '2026-03-01' and created_at < '2026-04-01';",
			)
			must(
				String(summary?.customer_count) === april,
				`report says ${summary?.customer_count} new customers in April, database says ${april}`,
			)
			must(
				!Array.isArray(previous),
				'previousSummary came back as an empty array — the comparison did not happen',
			)
			must(
				String((previous as { customer_count?: number }).customer_count) === march,
				`report says ${(previous as { customer_count?: number }).customer_count} for March, database says ${march}`,
			)
			ctx.note(
				`April ${april} against March ${march}, counted from the customer record's created_at`,
			)
		},
	},

	{
		id: 'customers/ship-order-to',
		question: 'What address should I ship order 82 to?',
		discovery: {
			query: 'what address should i ship this order to',
			expect: 'fluentcart_order_get',
		},
		budget: 5_500,
		run: async (ctx) => {
			const { isError, body } = await ctx.call('fluentcart_order_get', { order_id: 82 })
			must(!isError, 'order_get failed')
			const order = (body.order ?? body) as {
				shipping_address?: Record<string, unknown>
				billing_address?: Record<string, unknown>
			}
			const [[name, line1, city, postcode, country]] = ctx.db(
				"select name, address_1, city, postcode, country from wp_fct_order_addresses where order_id=82 and type='shipping';",
			)
			const shipping = order.shipping_address
			must(shipping !== undefined, 'order_get returned no shipping address at all')
			must(
				shipping.name === name &&
					shipping.address_1 === line1 &&
					shipping.city === city &&
					shipping.postcode === postcode &&
					shipping.country === country,
				`shipping address disagrees with the database: got ${JSON.stringify(shipping)}`,
			)
			// A shipping question answered with the billing address is the wrong answer confidently.
			must(
				order.billing_address?.type === 'billing' && shipping.type === 'shipping',
				'the two addresses are not distinguishable by type',
			)
			ctx.note(`order 82 ships to ${line1}, ${postcode} ${city}, ${country}`)
			const rank =
				ctx.search('what address should i ship this order to').indexOf('fluentcart_order_get') + 1
			ctx.note(
				`order_get ranks #${rank} for the question; everything above it edits an address or prices a shipping method rather than reading the one already on the order`,
			)
		},
	},

	{
		id: 'customers/everything-on-file',
		question: 'This customer wants their data — show me everything on file.',
		discovery: { query: 'everything on file about a customer', expect: 'fluentcart_customer_get' },
		budget: 8_000,
		run: async (ctx) => {
			const [[customerId]] = ctx.db(
				'select customer_id from wp_fct_orders where customer_id > 0 group by customer_id having count(*) between 2 and 6 order by customer_id limit 1;',
			)
			const profile = await ctx.call('fluentcart_customer_get', { customer_id: Number(customerId) })
			const addresses = await ctx.call('fluentcart_customer_addresses', {
				customer_id: Number(customerId),
			})
			const orders = await ctx.call('fluentcart_order_customer_orders', {
				customer_id: Number(customerId),
				per_page: 50,
			})
			must(
				!(profile.isError || addresses.isError || orders.isError),
				'one of the three calls failed',
			)

			const [[email, purchases]] = ctx.db(
				`select email, purchase_count from wp_fct_customers where id=${customerId};`,
			)
			const customer = (profile.body.customer ?? profile.body) as Record<string, unknown>
			must(customer.email === email, `profile says ${customer.email}, database says ${email}`)
			must(
				String(customer.purchase_count) === purchases,
				`profile says ${customer.purchase_count} purchases, database says ${purchases}`,
			)

			const [[addressCount]] = ctx.db(
				`select count(*) from wp_fct_customer_addresses where customer_id=${customerId};`,
			)
			const held = (addresses.body.addresses ?? []) as unknown[]
			must(
				held.length === Number(addressCount),
				`addresses returned ${held.length}, database holds ${addressCount}`,
			)

			const [[orderCount]] = ctx.db(
				`select count(*) from wp_fct_orders where customer_id=${customerId};`,
			)
			const page = envelope(orders.body, 'orders')
			must(
				page.total === Number(orderCount),
				`order history says ${page.total}, database says ${orderCount}`,
			)
			ctx.note(
				`customer ${customerId}: profile, ${held.length} addresses and ${page.total} orders in ${ctx.spent()} characters`,
			)
		},
	},

	{
		id: 'customers/repeat-advertised',
		question: 'Which of my customers have bought more than once?',
		discovery: { query: 'repeat customers', expect: 'fluentcart_report_repeat_customers' },
		// One call per status is FluentCart's constraint, so the honest budget covers the sweep. The
		// sibling scenario answers the same question from customer_list in a single call for less;
		// that comparison is the point, and hiding this cost behind a looser assertion would lose it.
		budget: 6_000,
		run: async (ctx) => {
			// This scenario found the defect and now guards the fix. The report could not answer at
			// all: CustomerHelper::getRepeatCustomerBySearch line 97 runs unconditionally —
			//   $params["status"] = ["column"=>"status","operator"=>"in","value"=>[Arr::get($params,'order_status')]]
			// so with no order_status the whereHas became `status IN (NULL)` and matched nobody, on
			// every store, forever. The tool did not expose the key, and `order_status` was missing
			// from the report-parameter allowlist that nests filters under `params[...]`, so even
			// passing it would have been dropped. Both are fixed; this proves it end to end.
			//
			// One status at a time is FluentCart's constraint, not ours: the helper wraps the value in
			// a single-element array. A customer whose two orders sit in different statuses is only
			// found under a status they share, which is why this sweeps every status the store uses.
			const statuses = ctx.db('select distinct status from wp_fct_orders;').map((row) => row[0])
			must(statuses.length > 0, 'the store has no order statuses at all')

			const found = new Set<number>()
			for (const status of statuses) {
				const { isError, body } = await ctx.call('fluentcart_report_repeat_customers', {
					startDate: '2020-01-01',
					endDate: '2027-12-31',
					order_status: status,
					per_page: 50,
				})
				must(!isError, `report_repeat_customers failed for status ${status}`)
				for (const row of envelope<{ id?: number }>(body, 'repeat_customers').data) {
					if (typeof row.id === 'number') found.add(row.id)
				}
			}

			const expected = ctx
				.db('select id from wp_fct_customers where purchase_count > 1 order by id;')
				.map((row) => Number(row[0]))
			must(
				expected.length > 0,
				'this store has no repeat customers left; the scenario needs new data',
			)
			for (const id of expected) {
				must(
					found.has(id),
					`customer ${id} has more than one purchase but no status found them; the report is empty again`,
				)
			}
			ctx.note(
				`${found.size} repeat customers found, but it took ${statuses.length} calls — one per order status. fluentcart_customer_list answers the same question in one, carrying purchase_count on every row`,
			)
		},
	},
	{
		id: 'customers/repeat-actual',
		question: 'Which of my customers have bought more than once? (without the report)',
		discovery: { query: 'repeat customers', expect: 'fluentcart_customer_list', within: 3 },
		budget: 3_500,
		run: async (ctx) => {
			const { isError, body } = await ctx.call('fluentcart_customer_list', {
				per_page: 10,
				sort_by: 'purchase_count',
				sort_type: 'DESC',
			})
			must(!isError, 'customer_list failed')
			const page = envelope<{ id: number; purchase_count: string }>(body, 'customers')
			const expected = ctx.db(
				'select id from wp_fct_customers where purchase_count > 1 order by purchase_count desc, id;',
			)
			const repeat = page.data.filter((row) => Number(row.purchase_count) > 1)
			// Sorted descending, so the first row below the threshold proves the rest are too. If
			// the whole page is above it, the answer is truncated and this assertion says so.
			must(
				repeat.length < page.data.length,
				`every row on page one is a repeat customer, so the list is cut off at ${page.data.length}`,
			)
			must(
				repeat
					.map((row) => String(row.id))
					.sort()
					.join(',') ===
					expected
						.map((row) => row[0])
						.sort()
						.join(','),
				`list found repeat customers ${repeat.map((row) => row.id).join(',')}, database says ${expected.map((row) => row[0]).join(',')}`,
			)
			ctx.note(
				`${repeat.length} customers bought more than once: ${repeat.map((row) => `${row.id} (${row.purchase_count})`).join(', ')}`,
			)
		},
	},

	{
		id: 'customers/never-bought',
		question: 'How many customers have never bought anything?',
		discovery: { query: 'customers who never bought anything', expect: 'fluentcart_customer_list' },
		budget: 3_500,
		run: async (ctx) => {
			// There is no "has ordered" filter and no aggregate, so the cheap chain is to sort by
			// purchase_count descending and subtract: once a zero appears, everything after it is a
			// zero as well. That holds only while fewer paying customers exist than fit on a page,
			// which is asserted rather than assumed.
			const { isError, body } = await ctx.call('fluentcart_customer_list', {
				per_page: 10,
				sort_by: 'purchase_count',
				sort_type: 'DESC',
			})
			must(!isError, 'customer_list failed')
			const page = envelope<{ purchase_count: string }>(body, 'customers')
			const buyers = page.data.filter((row) => Number(row.purchase_count) > 0)
			must(
				buyers.length < page.data.length,
				`page one is all buyers, so the count is truncated at ${page.data.length}`,
			)
			const [[never]] = ctx.db('select count(*) from wp_fct_customers where purchase_count = 0;')
			const [[total]] = ctx.db('select count(*) from wp_fct_customers;')
			must(
				String(page.total) === total,
				`list total ${page.total} disagrees with the database ${total}`,
			)
			must(
				page.total - buyers.length === Number(never),
				`chain says ${page.total - buyers.length} never bought, database says ${never}`,
			)
			ctx.note(
				`${never} of ${total} customers have never bought — no filter for this, only sort and subtract`,
			)
		},
	},

	{
		id: 'customers/refund-history',
		question: 'Has this customer ever had a refund, and how much did we give back?',
		discovery: {
			query: 'refunded orders for one customer',
			expect: 'fluentcart_order_customer_orders',
		},
		budget: 14_000,
		run: async (ctx) => {
			const [[customerId]] = ctx.db(`select id from wp_fct_customers where email='${BIG_SPENDER}';`)
			const { isError, body } = await ctx.call('fluentcart_order_customer_orders', {
				customer_id: Number(customerId),
				per_page: 50,
			})
			must(!isError, 'order_customer_orders failed')
			const page = envelope<{ id: number; total_refund: string }>(body, 'orders')
			const expected = ctx.db(
				`select id, total_refund from wp_fct_orders where customer_id=${customerId} and total_refund > 0 order by id;`,
			)
			const refunded = page.data
				.filter((row) => Number(row.total_refund) > 0)
				.sort((left, right) => left.id - right.id)
			must(
				refunded.map((row) => `${row.id}:${Number(row.total_refund)}`).join(',') ===
					expected.map((row) => `${row[0]}:${Number(row[1])}`).join(','),
				`refunds found ${refunded.map((row) => `${row.id}:${row.total_refund}`).join(',')}, database says ${expected.map((row) => `${row[0]}:${row[1]}`).join(',')}`,
			)
			const given = refunded.reduce((sum, row) => sum + Number(row.total_refund), 0)
			const [[fromDatabase]] = ctx.db(
				`select sum(total_refund) from wp_fct_orders where customer_id=${customerId};`,
			)
			must(given === Number(fromDatabase), `summed ${given}, database says ${fromDatabase}`)
			ctx.note(
				`${refunded.length} refunds totalling ${given} cents — reached only by pulling all ${page.total} orders, because nothing filters a customer's history by refund`,
			)
		},
	},

	{
		id: 'customers/average-order-value',
		question: "What is this customer's average order value?",
		discovery: { query: 'average order value for a customer', expect: 'fluentcart_customer_get' },
		budget: 1_200,
		run: async (ctx) => {
			const [[customerId]] = ctx.db(`select id from wp_fct_customers where email='${BIG_SPENDER}';`)
			const { isError, body } = await ctx.call('fluentcart_customer_get', {
				customer_id: Number(customerId),
			})
			must(!isError, 'customer_get failed')
			const customer = (body.customer ?? body) as Record<string, unknown>
			const [[aov, ltv, purchases]] = ctx.db(
				`select aov, ltv, purchase_count from wp_fct_customers where id=${customerId};`,
			)
			must(
				Number(customer.aov) === Number(aov),
				`customer_get says aov ${customer.aov}, database says ${aov}`,
			)
			// aov is ltv divided by purchase_count, so it is cents like ltv — not the decimal its
			// two-place formatting suggests. An agent reading 28143.75 as currency is out by 100.
			must(
				Math.abs(Number(aov) * Number(purchases) - Number(ltv)) < 1,
				`aov ${aov} times ${purchases} purchases is not ltv ${ltv}; the units are not what the description claims`,
			)
			ctx.note(`aov ${aov} cents over ${purchases} purchases, ltv ${ltv} cents`)
		},
	},

	{
		id: 'customers/zero-orders',
		question: 'This customer has never ordered — what does the store say?',
		discovery: { query: 'customer with zero orders', expect: 'fluentcart_order_customer_orders' },
		budget: 1_500,
		run: async (ctx) => {
			const [[customerId]] = ctx.db(
				'select c.id from wp_fct_customers c where not exists (select 1 from wp_fct_orders o where o.customer_id = c.id) order by c.id limit 1;',
			)
			const orders = await ctx.call('fluentcart_order_customer_orders', {
				customer_id: Number(customerId),
			})
			must(!orders.isError, 'order_customer_orders failed')
			const page = envelope(orders.body, 'orders')
			must(
				page.total === 0 && page.data.length === 0,
				`expected an empty history, got ${page.total}`,
			)

			// An empty history is only meaningful once the customer is known to exist, and this is
			// the one customer route that tells the difference.
			const profile = await ctx.call('fluentcart_customer_get', { customer_id: Number(customerId) })
			must(!profile.isError, `customer ${customerId} should exist, customer_get says otherwise`)
			const customer = (profile.body.customer ?? profile.body) as Record<string, unknown>
			must(String(customer.id) === customerId, 'customer_get returned somebody else')
			must(
				Number(customer.purchase_count) === 0 && customer.first_purchase_date === null,
				`customer ${customerId} has no orders but reports purchase_count ${customer.purchase_count}`,
			)
			ctx.note(`customer ${customerId} exists and has bought nothing; both facts needed two calls`)
		},
	},

	{
		id: 'customers/unknown-id',
		question: 'Customer 999999 — is that a real customer?',
		discovery: { query: 'does this customer exist', expect: 'fluentcart_customer_get' },
		budget: 1_500,
		run: async (ctx) => {
			const [[present]] = ctx.db('select count(*) from wp_fct_customers where id=999999;')
			must(present === '0', 'customer 999999 exists after all; the scenario needs a different id')

			const profile = await ctx.call('fluentcart_customer_get', { customer_id: 999999 })
			must(profile.isError, 'customer_get answered successfully for a customer that does not exist')
			must(
				/not found/i.test(profile.text),
				`the error does not say what went wrong: ${profile.text.slice(0, 120)}`,
			)
			must(
				!/app_password|application password|Basic |authorization/i.test(profile.text),
				'the error payload is echoing credentials back',
			)

			// The sibling route does not agree, which is why its description now says so.
			const addresses = await ctx.call('fluentcart_customer_addresses', { customer_id: 999999 })
			must(
				!addresses.isError,
				'customer_addresses started erroring; the description needs updating',
			)
			must(
				((addresses.body.addresses ?? []) as unknown[]).length === 0,
				'customer_addresses invented an address for a customer that does not exist',
			)
			ctx.note(
				'customer_get 404s on an unknown id; customer_addresses answers 200 with an empty list, indistinguishable from a real customer with no address',
			)
		},
	},

	{
		id: 'customers/search-no-match',
		question: 'Search for a customer who is not there.',
		discovery: { query: 'find customer by email', expect: 'fluentcart_customer_list' },
		budget: 500,
		run: async (ctx) => {
			const needle = 'zz-no-such-person-9f3a1c@nowhere.invalid'
			const [[present]] = ctx.db(`select count(*) from wp_fct_customers where email='${needle}';`)
			must(present === '0', 'the scenario needle exists in the store')

			const { isError, body } = await ctx.call('fluentcart_customer_list', { search: needle })
			// Nothing found is an answer, not a failure: an error here would push a caller into
			// retrying a search that was correct the first time.
			must(!isError, 'an empty search result came back as an error')
			const page = envelope(body, 'customers')
			must(page.total === 0 && page.data.length === 0, `expected nothing, got ${page.total}`)
		},
	},

	{
		id: 'customers/page-past-end',
		question: 'What happens if I page past the last page of customers?',
		budget: 500,
		run: async (ctx) => {
			const { isError, body } = await ctx.call('fluentcart_customer_list', {
				page: 99,
				per_page: 10,
			})
			must(!isError, 'paging past the end came back as an error')
			const page = envelope(body, 'customers')
			const [[total]] = ctx.db('select count(*) from wp_fct_customers;')
			must(page.data.length === 0, `page 99 returned ${page.data.length} rows`)
			// The payload has to say the page was past the end, or the caller cannot tell an
			// overshoot from a store that just emptied.
			must(page.current_page === 99, `current_page came back as ${page.current_page}`)
			must(
				page.last_page === Math.ceil(Number(total) / 10),
				`last_page ${page.last_page} does not match ${total} customers at 10 a page`,
			)
			must(String(page.total) === total, `total ${page.total} disagrees with the database ${total}`)
			ctx.note(`page 99 of ${page.last_page}: empty, and the envelope says so`)
		},
	},

	{
		id: 'customers/count-total',
		question: 'How many customers do I have?',
		discovery: { query: 'count of customers', expect: 'fluentcart_customer_list' },
		budget: 600,
		run: async (ctx) => {
			const { isError, body } = await ctx.call('fluentcart_customer_list', { per_page: 1 })
			must(!isError, 'customer_list failed')
			const page = envelope(body, 'customers')
			const [[total]] = ctx.db('select count(*) from wp_fct_customers;')
			must(
				String(page.total) === total,
				`list says ${page.total} customers, database says ${total}`,
			)
			ctx.note(`${total} customers, for one row's worth of payload`)
		},
	},

	{
		id: 'customers/stats-dead-end',
		question: "Show me this customer's statistics.",
		discovery: { query: 'stats for one customer', expect: 'fluentcart_customer_stats' },
		budget: 1_600,
		run: async (ctx) => {
			const [[customerId, purchases]] = ctx.db(
				`select id, purchase_count from wp_fct_customers where email='${BIG_SPENDER}';`,
			)
			const stats = await ctx.call('fluentcart_customer_stats', { customer_id: Number(customerId) })
			must(!stats.isError, 'customer_stats failed')
			// The route is an extension point nobody extends, so it answers with nothing for the
			// store's biggest customer. That is accurate, and useless, unless it says where to go.
			must(
				stats.text.includes('fluentcart_customer_get'),
				`customer_stats sent the caller nowhere: ${stats.text.slice(0, 160)}`,
			)
			const profile = await ctx.call('fluentcart_customer_get', { customer_id: Number(customerId) })
			must(!profile.isError, 'the tool it redirected to failed')
			const customer = (profile.body.customer ?? profile.body) as Record<string, unknown>
			must(
				String(customer.purchase_count) === purchases,
				`redirect target says ${customer.purchase_count} purchases, database says ${purchases}`,
			)
			ctx.note('customer_stats holds no figures on this store and hands the caller to customer_get')
		},
	},

	{
		id: 'customers/guest-orders',
		question: 'Some orders show no customer at all — who placed them?',
		discovery: { query: 'guest orders', expect: 'fluentcart_order_list' },
		budget: 11_000,
		run: async (ctx) => {
			const [[guests]] = ctx.db('select count(*) from wp_fct_orders where customer_id = 0;')
			must(guests !== '0', 'this store has no customerless orders; the scenario needs new data')

			// There is no filter for them, so the only route is the whole order list.
			const list = await ctx.call('fluentcart_order_list', { per_page: 50 })
			must(!list.isError, 'order_list failed')
			const page = envelope<{ customer_email?: unknown }>(list.body, 'orders')
			const orphans = page.data.filter((row) => !row.customer_email)
			must(
				orphans.length === Number(guests),
				`order_list shows ${orphans.length} orders with no customer, database says ${guests}`,
			)

			// And the per-customer route cannot be pointed at them: customer 0 is rejected as a
			// missing argument, which is the right refusal but leaves the list scan as the only way.
			const byCustomer = await ctx.call('fluentcart_order_customer_orders', { customer_id: 0 })
			must(byCustomer.isError, 'customer_id 0 was accepted as a real customer')
			ctx.note(
				`${guests} customerless orders, found only by pulling all ${page.total} orders — there is no filter for them`,
			)
		},
	},

	{
		id: 'customers/whole-history-unpaginated',
		question: "Give me this customer's whole order history in one go.",
		discovery: {
			query: 'whole order history for a customer',
			expect: 'fluentcart_customer_orders_simple',
		},
		budget: 9_000,
		run: async (ctx) => {
			const [[customerId]] = ctx.db(
				'select customer_id from wp_fct_orders where customer_id > 0 group by customer_id having count(*) between 2 and 6 order by customer_id limit 1;',
			)
			const { isError, body } = await ctx.call('fluentcart_customer_orders_simple', {
				customer_id: Number(customerId),
			})
			must(!isError, 'customer_orders_simple failed')
			const wrapper = (body.data ?? body) as { data?: unknown[] }
			must(Array.isArray(wrapper.data), 'the unpaginated route did not return a list')
			const [[orders]] = ctx.db(
				`select count(*) from wp_fct_orders where customer_id=${customerId};`,
			)
			must(
				wrapper.data.length === Number(orders),
				`returned ${wrapper.data.length} orders, database says ${orders}`,
			)
			ctx.note(
				`${orders} orders for ${ctx.spent()} characters, and this route takes no paging parameter at all — the same history costs about 40% of that through fluentcart_order_customer_orders`,
			)
		},
	},
]

describe('customer scenarios', () => {
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
	}, 240_000)
})
