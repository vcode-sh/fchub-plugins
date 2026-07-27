import { describe, expect, it, vi } from 'vitest'
import type { FluentCartClient } from '../../src/api/client.js'
import {
	assertValidRequest,
	ReportRequestError,
	salesSummary,
	salesTrend,
	topProducts,
} from '../../src/commerce/reporting.js'

const PERIOD = {
	from: '2026-07-01',
	to: '2026-07-27',
	currency: 'PLN',
	timezone: 'Europe/Warsaw',
}

/** A client that records what was asked for and answers with a canned body. */
function stubClient(body: unknown) {
	const get = vi.fn().mockResolvedValue({ data: body, status: 200 })
	return { client: { get } as unknown as FluentCartClient, get }
}

// The field names the store really sends in the summary block, per the captured response
// fixture. They differ from the ones in each trend row of the very same response — see
// report-source-fields.test.ts. Stubbing the convenient names here instead of the real ones is
// exactly how four permanently-null fields shipped, so this object must stay in step with the
// fixture rather than with the trend rows below.
const SUMMARY_BODY = {
	data: {
		summary: {
			gross_sale: 400,
			net_revenue: 320,
			tax_total: 80,
			shipping_total: 0,
			total_refunded_amount: 0,
			order_count: 2,
			refunded_orders: 0,
		},
	},
}

describe('request validation', () => {
	it('accepts a well-formed period', () => {
		expect(() => assertValidRequest(PERIOD)).not.toThrow()
	})

	it('rejects a date that is not YYYY-MM-DD', () => {
		expect(() => assertValidRequest({ ...PERIOD, from: '01/07/2026' })).toThrow(ReportRequestError)
	})

	it('rejects a reversed range rather than quietly swapping it', () => {
		expect(() => assertValidRequest({ ...PERIOD, from: '2026-07-27', to: '2026-07-01' })).toThrow(
			/is after/,
		)
	})

	it('refuses to run without a currency, because totals would mix currencies', () => {
		expect(() => assertValidRequest({ ...PERIOD, currency: '' })).toThrow(/never summed/)
		expect(() => assertValidRequest({ ...PERIOD, currency: 'zloty' })).toThrow(ReportRequestError)
	})
})

describe('sales summary', () => {
	it('pins the period and currency in the request it sends', async () => {
		const { client, get } = stubClient(SUMMARY_BODY)
		await salesSummary(client, PERIOD)

		expect(get).toHaveBeenCalledWith('/reports/revenue', {
			'params[startDate]': '2026-07-01',
			'params[endDate]': '2026-07-27',
			'params[currency]': 'PLN',
		})
	})

	it('returns the period, payment scope and currency alongside the numbers', async () => {
		const { client } = stubClient(SUMMARY_BODY)
		const result = await salesSummary(client, PERIOD)

		expect(result.period).toEqual({
			from: '2026-07-01',
			to: '2026-07-27',
			timezone: 'Europe/Warsaw',
		})
		expect(result.paymentMode).toBe('live-and-test-combined')
		expect(result.currency).toBe('PLN')
		expect(result.data.gross_sales).toBe(400)
	})

	it('reads the summary field names the store sends, not the trend row names', async () => {
		// The regression this whole mapping exists for: `gross_sale` in the summary block versus
		// `total_sales` in a trend row of the same response. Reading the row name here yields null.
		const { client } = stubClient(SUMMARY_BODY)
		const result = await salesSummary(client, PERIOD)

		expect(result.data.gross_sales).toBe(400)
		expect(result.data.tax).toBe(80)
		expect(result.data.refunded_amount).toBe(0)
		expect(result.warnings.join(' ')).not.toMatch(/returned no value/)
	})

	it('derives the average order value the store never sends', async () => {
		const { client } = stubClient(SUMMARY_BODY)
		const result = await salesSummary(client, PERIOD)
		expect(result.data.average_order_value).toBe(200)
	})

	it('leaves the average null rather than dividing by no orders', async () => {
		const { client } = stubClient({ data: { summary: { gross_sale: 0, order_count: 0 } } })
		const result = await salesSummary(client, PERIOD)
		expect(result.data.average_order_value).toBeNull()
	})

	it('carries the contract warnings on every result, including an empty one', async () => {
		const { client } = stubClient({ data: { summary: {} } })
		const result = await salesSummary(client, PERIOD)

		expect(result.warnings.join(' ')).toMatch(/test-mode/)
		expect(result.warnings.join(' ')).toMatch(/Pro/)
	})

	it('reports an absent figure as null and says so, rather than as zero', async () => {
		const { client } = stubClient({ data: { summary: { gross_sale: 400 } } })
		const result = await salesSummary(client, PERIOD)

		expect(result.data.gross_sales).toBe(400)
		expect(result.data.net_revenue).toBeNull()
		expect(result.warnings.join(' ')).toContain('net_revenue')
		expect(result.warnings.join(' ')).toMatch(/rather than zero/)
	})

	it('projects by allowlist, so an extra field the store adds never leaks', async () => {
		const { client } = stubClient({
			data: { summary: { ...SUMMARY_BODY.data.summary, customer_email: 'a@b.test', profit: 99 } },
		})
		const result = await salesSummary(client, PERIOD)

		expect(Object.keys(result.data)).not.toContain('customer_email')
		expect(Object.keys(result.data)).not.toContain('profit')
		expect(JSON.stringify(result)).not.toContain('a@b.test')
	})

	it('accepts an unwrapped body as well as a data-wrapped one', async () => {
		const { client } = stubClient({ summary: { gross_sale: 150 } })
		const result = await salesSummary(client, PERIOD)
		expect(result.data.gross_sales).toBe(150)
	})

	it('lets a permission failure travel outward instead of returning empty data', async () => {
		const get = vi.fn().mockRejectedValue(new Error('Permission denied: reports/view'))
		const client = { get } as unknown as FluentCartClient
		await expect(salesSummary(client, PERIOD)).rejects.toThrow(/Permission denied/)
	})
})

describe('sales trend', () => {
	const TREND_BODY = {
		data: {
			revenueReport: [
				{ period: '2026-07-01', total_sales: 100, net_revenue: 80, order_count: 1 },
				{ period: '2026-07-02', total_sales: 0, net_revenue: 0, order_count: 0 },
			],
		},
	}

	it('omits groupKey for daily, because sending the word means group-by-payment-method', async () => {
		// FluentCart replaces any unwhitelisted groupKey with `payment_method` instead of rejecting
		// it, so `groupKey=daily` returns revenue grouped by payment method wearing a time series'
		// clothes. Verified live: a 364-day range answered appliedGroupKey=payment_method.
		const { client, get } = stubClient(TREND_BODY)
		await salesTrend(client, { ...PERIOD, granularity: 'daily' })

		expect(get.mock.calls[0]?.[1]).not.toHaveProperty('params[groupKey]')
	})

	it('reports the granularity the store applied, not the one that was asked for', async () => {
		const { client } = stubClient({
			data: { appliedGroupKey: 'monthly', revenueReport: [{ group: '2026-07', total_sales: 1 }] },
		})
		const result = await salesTrend(client, { ...PERIOD, granularity: 'daily' })

		expect(result.granularity).toEqual({ requested: 'daily', applied: 'monthly' })
		expect(result.warnings.join(' ')).toMatch(/store grouped by monthly/)
	})

	it('stays quiet when the store grouped the way it was asked to', async () => {
		const { client } = stubClient({
			data: { appliedGroupKey: 'monthly', revenueReport: [{ group: '2026-07', total_sales: 1 }] },
		})
		const result = await salesTrend(client, { ...PERIOD, granularity: 'monthly' })

		expect(result.granularity).toEqual({ requested: 'monthly', applied: 'monthly' })
		expect(result.warnings.join(' ')).not.toMatch(/store grouped by/)
	})

	it('falls back to the documented range rule when the store omits appliedGroupKey', async () => {
		const { client } = stubClient({ data: { revenueReport: [] } })
		// 2026-07-01 to 2026-07-27 is 26 days, inside the 91-day daily band.
		const result = await salesTrend(client, { ...PERIOD, granularity: 'daily' })
		expect(result.granularity.applied).toBe('daily')
	})

	it('sends groupKey only for the two values the store actually whitelists', async () => {
		for (const granularity of ['monthly', 'yearly'] as const) {
			const { client, get } = stubClient(TREND_BODY)
			await salesTrend(client, { ...PERIOD, granularity })
			expect(get.mock.calls[0]?.[1]).toMatchObject({ 'params[groupKey]': granularity })
		}
	})

	it('keeps zero buckets rather than dropping them', async () => {
		const { client } = stubClient(TREND_BODY)
		const result = await salesTrend(client, PERIOD)

		expect(result.data).toHaveLength(2)
		expect(result.data[1]).toEqual({
			period: '2026-07-02',
			gross_sales: 0,
			net_revenue: 0,
			order_count: 0,
		})
	})

	it('returns an empty series without inventing buckets when the store returns none', async () => {
		const { client } = stubClient({ data: { revenueReport: [] } })
		const result = await salesTrend(client, PERIOD)
		expect(result.data).toEqual([])
	})

	it('warns when a bucket arrives with no period label', async () => {
		const { client } = stubClient({ data: { revenueReport: [{ total_sales: 5 }] } })
		const result = await salesTrend(client, PERIOD)

		expect(result.data[0]?.period).toBe('')
		expect(result.warnings.join(' ')).toMatch(/no period label/)
	})
})

describe('top products', () => {
	const TOP_BODY = {
		data: {
			topSoldProducts: [
				{ product_id: 7, product_name: 'Widget', quantity_sold: 9, total_amount: 450, media: {} },
			],
		},
	}

	it('projects only the four allowlisted fields', async () => {
		const { client } = stubClient(TOP_BODY)
		const result = await topProducts(client, PERIOD)

		expect(Object.keys(result.data[0] ?? {}).sort()).toEqual([
			'product_id',
			'product_name',
			'quantity_sold',
			'total_amount',
		])
	})

	it('warns that the ranking is by units, not money', async () => {
		const { client } = stubClient(TOP_BODY)
		const result = await topProducts(client, PERIOD)
		expect(result.warnings.join(' ')).toMatch(/units sold, not revenue/)
	})

	it('returns an empty list for a period with no sales', async () => {
		const { client } = stubClient({ data: { topSoldProducts: [] } })
		const result = await topProducts(client, PERIOD)
		expect(result.data).toEqual([])
		expect(result.currency).toBe('PLN')
	})
})

describe('diagnostic reports have no adapter', () => {
	it('refuses to answer for a report whose semantics were rejected', async () => {
		const module = await import('../../src/commerce/reporting.js')
		expect(Object.keys(module.REPORT_ADAPTERS).sort()).toEqual([
			'sales_summary',
			'sales_trend',
			'top_products',
		])
	})
})
