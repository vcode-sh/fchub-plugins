// The caveats that stop two right answers from looking like one wrong one.
//
// FluentCart answers "how much did the store sell" from seven routes and they do not agree.
// Measured live on 2026-07-28 (FluentCart 1.5.5, Pro 1.5.4, 34 seeded orders in EUR and PLN),
// over a range covering everything the store holds:
//
//   4,738.69 / 25 orders  /reports/revenue, /reports/revenue-by-group, /reports/fetch-order-by-group
//   4,712.70 / 24 orders  /reports/sales-report, /reports/order-chart
//   4,962.70              /reports/product-report
//   4,683.99 / 22 orders  /reports/dashboard-stats
//          — / 34 orders  /orders
//
// 4,738.69 over 25 orders is the correct answer and the database says so: 25 orders carry a
// countable payment status and their `total_paid` sums to 473,869 minor units. The other figures
// are not alternative opinions. 34 counts orders nobody was paid for; 4,712.70 drops an order that
// has no line items; 4,962.70 adds one order three times because the query fans out on variations.
//
// An agent cannot see any of that. It sees two numbers and picks one. So the reconciliation travels
// with every result as a warning rather than living in a source comment, and this file is what
// stops the warning from being tidied away by someone who finds it wordy.
//
// tests/integration/report-family-reconciliation.test.ts re-derives all of the above from live
// data. This file only pins that we still say it.
import { describe, expect, it, vi } from 'vitest'
import type { FluentCartClient } from '../../src/api/client.js'
import { acceptedReports, REPORT_CONTRACTS } from '../../src/commerce/report-contracts.js'
import { salesSummary, salesTrend, topProducts } from '../../src/commerce/reporting.js'

const PERIOD = { from: '2026-01-01', to: '2026-12-31', currency: 'EUR' }

function stubClient(body: unknown) {
	return {
		get: vi.fn().mockResolvedValue({ data: body, status: 200 }),
	} as unknown as FluentCartClient
}

/** Reports whose `order_count` a caller will compare against the order list. */
const ORDER_COUNTING = ['sales_summary', 'sales_trend'] as const

describe('the gap against the order list is stated, not left to be discovered', () => {
	for (const name of ORDER_COUNTING) {
		const contract = REPORT_CONTRACTS[name]

		it(`${name} names the tool whose total will be larger`, () => {
			const warnings = ('warnings' in contract ? contract.warnings : []).join(' ')
			// Naming the tool matters more than describing it. An agent holding 34 from
			// fluentcart_order_list and 25 from here needs the two joined up by name.
			expect(warnings).toContain('fluentcart_order_list')
			expect(warnings).toMatch(/pending/i)
		})

		it(`${name} says the money is what was collected, not what was ordered`, () => {
			const warnings = ('warnings' in contract ? contract.warnings : []).join(' ')
			// gross_sale is SUM(o.total_paid). On a store with partially-paid orders that is not
			// the same as the order value, and "gross sales" reads like it should be.
			expect(warnings).toContain('total_paid')
		})

		it(`${name} names the routes that answer the same question differently`, () => {
			const warnings = ('warnings' in contract ? contract.warnings : []).join(' ')
			expect(warnings).toContain('fluentcart_report_product')
			expect(warnings).toMatch(/overstates/i)
			expect(warnings).toContain('fluentcart_report_sales')
		})
	}

	it('top_products is left out of all three, because none of it applies', () => {
		// Its total_amount sums order-item line totals, so the total_paid caveat would be wrong and
		// the order_count caveat has nothing to attach to. A caveat pasted where it does not hold
		// is how a caveat stops being read.
		const warnings = REPORT_CONTRACTS.top_products
		const text = ('warnings' in warnings ? warnings.warnings : []).join(' ')
		expect(text).not.toContain('order_count counts only')
		expect(text).toContain('line totals')
		expect(text).toContain('fluentcart_report_sales_summary')
	})
})

describe('the caveats reach the caller, not just the contract', () => {
	it('salesSummary ships them on a real result', async () => {
		const client = stubClient({
			data: { summary: { gross_sale: 4530.69, order_count: 17, net_revenue: 4480.99 } },
		})
		const result = await salesSummary(client, PERIOD)

		expect(result.data.gross_sales).toBe(4530.69)
		expect(result.data.order_count).toBe(17)
		const warnings = result.warnings.join(' ')
		expect(warnings).toContain('fluentcart_order_list')
		expect(warnings).toContain('fluentcart_report_product')
	})

	it('salesTrend ships them too, since its buckets carry the same order counts', async () => {
		const client = stubClient({
			data: { revenueReport: [{ period: '2026-01', total_sales: 10, order_count: 1 }] },
		})
		const result = await salesTrend(client, PERIOD)
		expect(result.warnings.join(' ')).toContain('fluentcart_order_list')
	})

	it('topProducts ships its own caveat about not adding up', async () => {
		const client = stubClient({
			data: { topSoldProducts: [{ product_id: 1, product_name: 'Tee', quantity_sold: 3 }] },
		})
		const result = await topProducts(client, PERIOD)
		expect(result.warnings.join(' ')).toContain('do not add up to gross_sales')
	})
})

describe('every accepted report carries its caveats at all', () => {
	it('none ships an empty warning list', () => {
		// A report with no warnings is either perfect or unexamined, and none of these is perfect.
		for (const contract of acceptedReports()) {
			expect(contract.warnings.length, `${contract.name} carries no warnings`).toBeGreaterThan(0)
		}
	})

	it('states the payment statuses it counts, which is where the order-count gap comes from', () => {
		for (const contract of acceptedReports()) {
			const warnings = contract.warnings.join(' ')
			expect(warnings, `${contract.name}`).toContain('partially_refunded')
		}
	})
})
