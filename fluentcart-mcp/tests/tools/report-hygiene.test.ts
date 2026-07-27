// Report tools must describe their own endpoint, and must be able to send what they ask for.
//
// Both halves of that had counter-examples on 2026-07-27:
//
//   fluentcart_report_top_sold_products carried "UPSTREAM BUG: Crashes with array_intersect_key()
//   on null (UB-006)". Its endpoint, /reports/fetch-top-sold-products, returns 2,273 bytes of real
//   product rows. The crash belongs to the deprecated /reports/top-products-sold, one tool over.
//
//   fluentcart_report_summary carried "Crashes with 'Unknown column discount_total' (UB-004)".
//   /reports/report-overview answers 200 with real totals. What it actually does wrong is ignore
//   the caller's dates entirely — three different ranges returned byte-identical payloads.
//
//   fluentcart_report_retention_snapshots_status declared z.object({}) while its controller reads
//   params.job_id, so every call it could possibly make returned {"success":false,
//   "message":"Job ID required"} with HTTP 200.
//
// A wrong bug annotation is worse than none: it routes a model away from a working endpoint and
// towards a broken one, and nothing fails while it does so.
import { describe, expect, it, vi } from 'vitest'
import type { FluentCartClient } from '../../src/api/client.js'
import { createAllTools } from '../../src/tools/index.js'

function stubClient() {
	const get = vi.fn().mockResolvedValue({ data: {}, status: 200 })
	const post = vi.fn().mockResolvedValue({ data: {}, status: 200 })
	return { client: { get, post } as unknown as FluentCartClient, get, post }
}

function toolNamed(client: FluentCartClient, name: string) {
	const tool = createAllTools(client, {}).find((candidate) => candidate.name === name)
	if (!tool) throw new Error(`${name} is not registered`)
	return tool
}

function definitionOf(name: string) {
	return toolNamed(stubClient().client, name)
}

/** The query object a tool actually put on the wire. */
async function queryFor(name: string, input: Record<string, unknown>) {
	const { client, get } = stubClient()
	await toolNamed(client, name).handler(input as never)
	return (get.mock.calls[0]?.[1] ?? {}) as Record<string, unknown>
}

const UPSTREAM_BUG = /UPSTREAM BUG/i

describe('bug annotations point at the endpoint that has the bug', () => {
	it('clears the crash claim off the top-products route that works', () => {
		const tool = definitionOf('fluentcart_report_top_sold_products')
		expect(tool.description).not.toMatch(UPSTREAM_BUG)
		expect(tool.description).not.toMatch(/array_intersect_key/)
		expect(tool.description).not.toMatch(/UB-006/)
	})

	it('stops calling that route cents when it returns decimals', () => {
		const tool = definitionOf('fluentcart_report_top_sold_products')
		expect(tool.description).toMatch(/decimals, not cents/i)
	})

	it('clears the crash claim off the report summary and says what is really wrong', () => {
		const tool = definitionOf('fluentcart_report_summary')
		expect(tool.description).not.toMatch(UPSTREAM_BUG)
		expect(tool.description).not.toMatch(/discount_total|UB-004/)
		expect(tool.description).toMatch(/discarded/i)
		expect(tool.description).toMatch(/minor units/i)
	})

	it('offers no date arguments on a route that cannot filter by date', () => {
		// Offering them is what makes an unfiltered lifetime total look like a period total.
		const tool = definitionOf('fluentcart_report_summary')
		expect(Object.keys(tool.schema.shape)).toEqual([])
		expect(Object.keys(definitionOf('fluentcart_report_dashboard_summary').schema.shape)).toEqual(
			[],
		)
	})

	it('keeps the one upstream-bug annotation that is still true', () => {
		// /reports/sales-growth answers 500, Class "" not found, from ReportService.php line 79.
		expect(definitionOf('fluentcart_report_sales_growth').description).toMatch(UPSTREAM_BUG)
	})
})

describe('every report tool can send the arguments it advertises', () => {
	it('nests the retention snapshot job id where the controller reads it', async () => {
		const query = await queryFor('fluentcart_report_retention_snapshots_status', { job_id: '42' })
		expect(query['params[job_id]']).toBe('42')
		expect(query).not.toHaveProperty('job_id')
	})

	it('requires the job id rather than accepting a call that cannot succeed', () => {
		const tool = definitionOf('fluentcart_report_retention_snapshots_status')
		expect(tool.schema.safeParse({}).success).toBe(false)
		expect(tool.schema.safeParse({ job_id: '42' }).success).toBe(true)
	})

	it('says where a job id comes from, since no tool lists them', () => {
		const tool = definitionOf('fluentcart_report_retention_snapshots_status')
		expect(tool.description).toContain('fluentcart_report_retention_snapshots_generate')
	})

	it('nests the cohort grouping arguments', async () => {
		const query = await queryFor('fluentcart_report_subscription_cohorts', {
			startDate: '2026-01-01',
			endDate: '2026-12-31',
			groupBy: 'month',
			metric: 'mrr',
		})
		expect(query['params[groupBy]']).toBe('month')
		expect(query['params[metric]']).toBe('mrr')
		expect(query['params[startDate]']).toBe('2026-01-01')
		expect(query).not.toHaveProperty('groupBy')
		expect(query).not.toHaveProperty('metric')
	})

	it('omits the cohort arguments entirely when the caller omits them', async () => {
		const query = await queryFor('fluentcart_report_subscription_cohorts', {
			startDate: '2026-01-01',
			endDate: '2026-12-31',
		})
		expect(query).not.toHaveProperty('params[groupBy]')
		expect(query).not.toHaveProperty('params[metric]')
	})

	it('lets dashboard stats pin a currency, which is what distinguishes it', async () => {
		const query = await queryFor('fluentcart_report_dashboard_stats', {
			startDate: '2026-01-01',
			endDate: '2026-12-31',
			currency: 'PLN',
		})
		expect(query['params[currency]']).toBe('PLN')
	})
})

describe('group keys name dimensions the store will honour', () => {
	const groupTools = ['fluentcart_report_revenue_by_group', 'fluentcart_report_orders_by_group']

	it('accepts the four order columns the sanitiser whitelists', () => {
		for (const name of groupTools) {
			const schema = definitionOf(name).schema
			for (const key of [
				'payment_method',
				'payment_status',
				'billing_country',
				'shipping_country',
			]) {
				expect(schema.safeParse({ groupKey: key }).success, `${name} rejects ${key}`).toBe(true)
			}
		}
	})

	it('refuses the time buckets that these two routes cannot group by', () => {
		// daily and weekly came back grouped by payment method while looking like a time series;
		// monthly reached the SQL builder and produced Unknown column 'o.monthly'.
		for (const name of groupTools) {
			const schema = definitionOf(name).schema
			for (const key of ['daily', 'weekly', 'monthly']) {
				expect(schema.safeParse({ groupKey: key }).success, `${name} still offers ${key}`).toBe(
					false,
				)
			}
		}
	})

	it('drops weekly from the time-series tools, which silently served daily instead', () => {
		for (const name of ['fluentcart_report_revenue', 'fluentcart_report_sales_growth_chart']) {
			const schema = definitionOf(name).schema
			expect(schema.safeParse({ groupKey: 'weekly' }).success, `${name} still offers weekly`).toBe(
				false,
			)
			expect(schema.safeParse({ groupKey: 'monthly' }).success).toBe(true)
		}
	})
})

describe('near-duplicate report tools say what separates them', () => {
	/** Groups that answer adjacent questions and were previously indistinguishable. */
	const NEIGHBOURS: [string, string][] = [
		['fluentcart_report_top_sold_products', 'fluentcart_report_top_products'],
		['fluentcart_report_top_products_sold', 'fluentcart_report_top_products'],
		['fluentcart_report_quick_order_stats', 'fluentcart_report_dashboard_stats'],
		['fluentcart_report_orders_by_group', 'fluentcart_report_revenue_by_group'],
		['fluentcart_report_revenue_by_group', 'fluentcart_report_orders_by_group'],
		['fluentcart_report_retention_chart', 'fluentcart_report_subscription_retention'],
		['fluentcart_report_subscription_cohorts', 'fluentcart_report_subscription_retention'],
	]

	it('names the neighbour a caller should consider instead', () => {
		for (const [tool, neighbour] of NEIGHBOURS) {
			expect(definitionOf(tool).description, `${tool} never mentions ${neighbour}`).toContain(
				neighbour,
			)
		}
	})

	it('gives the dashboard summary a description matching what it returns', () => {
		// It was "key metrics, trends, and period comparisons". It is four catalogue counters.
		const tool = definitionOf('fluentcart_report_dashboard_summary')
		expect(tool.description).toMatch(/products/i)
		expect(tool.description).toMatch(/coupons/i)
		expect(tool.description).toMatch(/no orders, no revenue, no trends/i)
	})

	it('stops telling callers to pass a bare number as a lookback', () => {
		// day_range goes to strtotime; "7" does not parse and the window starts at 1970-01-01.
		const tool = definitionOf('fluentcart_report_quick_order_stats')
		expect(tool.description).toMatch(/strtotime/)
		expect(tool.description).toMatch(/1970/)
	})
})
