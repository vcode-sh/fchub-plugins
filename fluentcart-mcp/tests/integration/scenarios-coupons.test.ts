// Scenarios: the questions a merchant asks about discount codes.
//
// The discount half of the sweep in scenarios-subscriptions.test.ts, split out because both
// together exceeded this project's file limit. Same harness, same rule: ground truth is the
// database, and a scenario passes only if the tool was findable, answered, and was affordable.
//
// Two fixtures are created because the store has one coupon and it is somebody's: an expired one
// with a redemption cap, and an active one pinned to a product. Both are removed and proven gone.
import { afterAll, beforeAll, describe, expect, it } from 'vitest'
import type { ToolDefinition } from '../../src/tools/_factory.js'
import { createAllTools } from '../../src/tools/index.js'
import { CleanupLedger } from './support/cleanup-ledger.js'
import { getLiveClient, removeCoupon, verifyCouponMissing } from './support/live-client.js'
import { getLiveRun } from './support/live-run.js'
import { formatOutcomes, runScenario, type Scenario } from './support/scenario.js'

let tools: ToolDefinition[]
const ledger = new CleanupLedger()

/** Coupons this run created. Never a coupon it merely found. */
let expired: { id: number; code: string }
let restricted: { id: number; code: string }

/** The product the restricted fixture is pinned to, and one it deliberately is not. */
const ZIPPER_HOODIE = 67
const MENS_HOODIE = 34

function must(condition: unknown, message: string): asserts condition {
	if (!condition) throw new Error(message)
}

async function createCoupon(
	suffix: string,
	extra: Record<string, unknown>,
): Promise<{ id: number; code: string }> {
	const { prefix } = getLiveRun()
	const code = `${prefix}-${suffix}`.toUpperCase()
	const response = await getLiveClient().post('/coupons', {
		title: `${prefix} ${suffix} scenario`,
		code,
		type: 'percentage',
		amount: 10,
		status: 'active',
		stackable: 'no',
		show_on_checkout: 'no',
		notes: '',
		...extra,
	})
	const wrapper = response.data as Row
	const created = (wrapper.coupon ?? wrapper.data ?? wrapper) as Row
	const id = created.id as number
	must(typeof id === 'number' && id > 0, `coupon create returned no usable id for ${code}`)
	ledger.track({ type: 'coupon', id, remove: removeCoupon, verifyMissing: verifyCouponMissing })
	return { id, code }
}

beforeAll(async () => {
	tools = createAllTools(getLiveClient(), {})
	// An end date in the past rather than status:'inactive' — FluentCart recomputes the status on
	// read, and it is that derivation the expiry scenarios are about.
	expired = await createCoupon('EXPIRED', {
		start_date: '2019-01-01 00:00:00',
		end_date: '2020-01-01 00:00:00',
		conditions: { max_uses: 3 },
	})
	restricted = await createCoupon('HOODIE', {
		conditions: { included_products: [ZIPPER_HOODIE] },
	})
}, 120_000)

afterAll(async () => {
	await ledger.cleanup()
}, 120_000)

type Row = Record<string, unknown>
type Page = { data: Row[]; total: number }

const SCENARIOS: Scenario[] = [
	{
		id: 'coupons/active-codes',
		question: 'Which discount codes are currently active?',
		discovery: { query: 'active discount codes', expect: 'fluentcart_coupon_list' },
		budget: 1_600,
		run: async (ctx) => {
			const { isError, body } = await ctx.call('fluentcart_coupon_list', { per_page: 50 })
			must(!isError, 'coupon_list failed')
			const page = body.coupons as Page
			const [[all, live]] = ctx.db("select count(*), sum(status='active') from wp_fct_coupons;")
			must(String(page.total) === all, `list holds ${page.total} coupons, database ${all}`)
			const active = page.data.filter((row) => row.status === 'active')
			must(active.length === Number(live), `${active.length} read active, database says ${live}`)
			const named = active.every((row) => typeof row.code === 'string' && row.code !== '')
			must(named, 'an active discount code with no code on it is not an answer')
			ctx.note(`${live} of ${all} coupons are spendable right now`)
		},
	},
	{
		id: 'coupons/expired-or-capped',
		question: 'Which coupons have expired or hit their usage limit?',
		discovery: { query: 'expired coupons', expect: 'fluentcart_coupon_list' },
		budget: 1_800,
		run: async (ctx) => {
			const { isError, body } = await ctx.call('fluentcart_coupon_list', { per_page: 50 })
			must(!isError, 'coupon_list failed')
			const row = (body.coupons as Page).data.find((entry) => entry.id === expired.id)
			must(row !== undefined, `this run's own coupon ${expired.code} is missing from the list`)
			const [[status, endDate, used]] = ctx.db(
				`select status, end_date, use_count from wp_fct_coupons where id=${expired.id};`,
			)
			must(row.status === status, `list says ${row.status}, database says ${status}`)
			must(row.status === 'expired', 'a coupon past its end date must read expired')
			must(
				typeof row.end_date === 'string' && row.end_date.startsWith(endDate.slice(0, 10)),
				`the row must carry an end date; got ${String(row.end_date)} against ${endDate}`,
			)
			// The other half of the question. Without both numbers on the row the only way to answer
			// it is one coupon_get per coupon, at roughly 1,100 characters each.
			must(
				String(row.use_count) === used && Number(row.max_uses) === 3,
				`usage must be readable from the list: got ${String(row.use_count)}/${String(row.max_uses)}, expected ${used}/3`,
			)
			ctx.note(`${expired.code}: expired ${endDate}, used ${used} of ${row.max_uses}`)
		},
	},
	{
		id: 'coupons/alt-hides-expired',
		question: 'Why does the coupon picker show fewer codes than my coupon list?',
		discovery: { query: 'coupon picker list', expect: 'fluentcart_coupon_list_alt' },
		budget: 1_800,
		run: async (ctx) => {
			const alt = await ctx.call('fluentcart_coupon_list_alt', {})
			must(!alt.isError, 'coupon_list_alt failed')
			const codes = alt.body.coupons as string[]
			must(!codes.includes(expired.code), `${expired.code} is expired but the picker offers it`)
			must(codes.includes(restricted.code), `${restricted.code} is active but the picker hides it`)
			const full = await ctx.call('fluentcart_coupon_list', { per_page: 50 })
			const rows = (full.body.coupons as Page).data
			must(
				rows.some((row) => row.code === expired.code),
				'the full list must still show the expired coupon; an empty picker is not an empty store',
			)
			const [[live]] = ctx.db("select count(*) from wp_fct_coupons where status='active';")
			must(codes.length === Number(live), `picker offers ${codes.length}, ${live} are active`)
			ctx.note(`picker offers ${codes.length} of ${rows.length}; the gap is expiry, not absence`)
		},
	},
	{
		id: 'coupons/applies-to-product',
		question: 'Does this coupon apply to the Zipper Hoodie?',
		discovery: { query: 'coupon rules for a product', expect: 'fluentcart_coupon_get' },
		budget: 2_000,
		run: async (ctx) => {
			const { isError, body } = await ctx.call('fluentcart_coupon_get', {
				coupon_id: restricted.id,
			})
			must(!isError, 'coupon_get failed')
			const conditions = (body.coupon as Row).conditions as Row
			const included = (conditions.included_products as unknown[]).map(Number)
			must(included.includes(ZIPPER_HOODIE), `${restricted.code} does not name ${ZIPPER_HOODIE}`)
			must(!included.includes(MENS_HOODIE), 'the restriction must exclude every other hoodie')
			const [[stored]] = ctx.db(`select conditions from wp_fct_coupons where id=${restricted.id};`)
			must(
				(JSON.parse(stored).included_products as unknown[]).map(Number).includes(ZIPPER_HOODIE),
				'the stored condition does not match what the tool reported',
			)
			ctx.note(`${restricted.code} is pinned to product ${ZIPPER_HOODIE} and to nothing else`)
		},
	},
	{
		id: 'coupons/eligibility-is-a-trap',
		question: 'Can I just ask the store whether this coupon is eligible for that product?',
		discovery: {
			query: 'coupon eligibility for a product',
			expect: 'fluentcart_coupon_check_eligibility',
		},
		budget: 600,
		run: async (ctx) => {
			// The tool that ranks first here is the one that cannot answer. Its description is all
			// that stands between an agent and an HTTP 500, so check the warning is still there.
			const tool = tools.find((entry) => entry.name === 'fluentcart_coupon_check_eligibility')
			must(tool !== undefined, 'coupon_check_eligibility is no longer registered')
			must(
				/WARNING/.test(tool.description) && /coupon_get/.test(tool.description),
				'the description no longer warns, nor points at the tool that does work',
			)
			const { isError } = await ctx.call('fluentcart_coupon_check_eligibility', {
				coupon_id: restricted.id,
				product_id: ZIPPER_HOODIE,
			})
			must(isError, 'the endpoint answered; the warning in the description is now wrong')
			ctx.note('checkProductEligibility answers HTTP 500 outside a checkout session, as documented')
		},
	},
	{
		id: 'coupons/unknown-code',
		question: 'Does the code SUMMER20 exist?',
		discovery: { query: 'coupon details', expect: 'fluentcart_coupon_get' },
		budget: 700,
		run: async (ctx) => {
			const search = await ctx.call('fluentcart_coupon_list', { search: 'SUMMER20' })
			must(!search.isError, 'coupon_list search failed')
			const found = (search.body.coupons as Page).total
			must(found === 0, `this store now has ${found} SUMMER20; the scenario needs another code`)
			const [[exists]] = ctx.db("select count(*) from wp_fct_coupons where code='SUMMER20';")
			must(exists === '0', 'the database disagrees that SUMMER20 is absent')
			// The trap: an unknown id is HTTP 200 with a null coupon rather than a 404. An agent
			// that checks only isError reports "it exists, but has no details".
			const detail = await ctx.call('fluentcart_coupon_get', { coupon_id: 999_999 })
			must(!detail.isError, 'coupon_get now errors on an unknown id; the description must change')
			must(detail.body.coupon === null, `expected null, got ${JSON.stringify(detail.body)}`)
			const tool = tools.find((entry) => entry.name === 'fluentcart_coupon_get')
			must(
				/coupon:null/.test(tool?.description ?? ''),
				'the description must warn that absence arrives as a 200',
			)
			ctx.note('no SUMMER20; an unknown coupon id answers 200 with coupon:null, never a 404')
		},
	},
	{
		id: 'coupons/given-away',
		question: 'How much have I given away in discounts?',
		// Search lands on coupon_list, which holds no money total at all. fluentcart_report_summary
		// is the only tool that answers this, and it never surfaces for the question.
		discovery: {
			query: 'how much have I given away in discounts',
			expect: 'fluentcart_coupon_list',
		},
		budget: 1_800,
		run: async (ctx) => {
			const listed = await ctx.call('fluentcart_coupon_list', { per_page: 50 })
			must(!listed.isError, 'coupon_list failed')
			const money = /discount_total|total_discount|discounted/
			must(!money.test(listed.text), 'coupon_list carries a discount total now; search is right')
			const { isError, body } = await ctx.call('fluentcart_report_summary', {})
			must(!isError, 'report_summary failed')
			const given = ctx.db(
				'select sum(coupon_discount_total) + sum(manual_discount_total) from wp_fct_orders;',
			)[0]?.[0]
			const claimed = (body.data as Record<string, string>).total_discounts
			must(Number(claimed) === Number(given), `report claims ${claimed}, the orders total ${given}`)
			ctx.note(`${given} minor units given away; only report_summary knows, and search hides it`)
		},
	},
	{
		id: 'coupons/counters',
		question: 'How many coupons are live and how many have run out?',
		discovery: { query: 'expired coupon count', expect: 'fluentcart_report_dashboard_summary' },
		budget: 400,
		run: async (ctx) => {
			const { isError, body } = await ctx.call('fluentcart_report_dashboard_summary', {})
			must(!isError, 'dashboard_summary failed')
			const summary = body.summaryData as Record<string, number>
			const [[active, gone]] = ctx.db(
				"select sum(status='active'), sum(status='expired') from wp_fct_coupons;",
			)
			must(
				String(summary.active_coupons) === active && String(summary.expired_coupons) === gone,
				`counters say ${summary.active_coupons}/${summary.expired_coupons}, database ${active}/${gone}`,
			)
			ctx.note(`${active} active, ${gone} expired`)
		},
	},
]

describe('coupon scenarios', () => {
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
