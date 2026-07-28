// What each report tool claims to be counting, checked against what it actually counts.
//
// Every claim below was measured live on 2026-07-28 (FluentCart 1.5.5, Pro 1.5.4) and three of
// them were false at the time. They are the quiet kind of false: the tool answers HTTP 200, the
// shape is right, and the number is off by a factor of a hundred or is a different number
// altogether from the one the name promises.
//
//  - `report_order_chart` said "Values in cents". The route divides by 100 in SQL and returned
//    gross_sale 4712.7 — reading that as cents gives 47.13 for a store that sold 4,712.70.
//  - `report_order_value_distribution` said "Values in cents". No money is returned at all; the
//    bands are labelled in whole currency units while the SQL compares minor units, so "0-100"
//    means orders up to 100.00.
//  - `report_order_completion_time` said "Average time". It returns a histogram — one row per
//    whole-hour gap with an order count — and no average anywhere.
//
// tests/integration/report-family-reconciliation.test.ts proves the underlying figures live. This
// file only stops the descriptions from drifting back.
import { describe, expect, it } from 'vitest'
import type { FluentCartClient } from '../../src/api/client.js'
import { createAllTools } from '../../src/tools/index.js'

const tools = createAllTools({ get: async () => ({ data: {} }) } as unknown as FluentCartClient, {})

function describeOf(name: string): string {
	const tool = tools.find((candidate) => candidate.name === name)
	if (!tool) throw new Error(`${name} is not registered`)
	return tool.description ?? ''
}

describe('money units are stated the way the store actually sends them', () => {
	it('order_chart says decimals and does not say cents', () => {
		const text = describeOf('fluentcart_report_order_chart')
		expect(text).toMatch(/decimal/i)
		expect(text, 'the claim that cost a factor of a hundred').not.toMatch(/values in cents/i)
	})

	it('order_value_distribution does not present its band labels as cents', () => {
		const text = describeOf('fluentcart_report_order_value_distribution')
		expect(text).not.toMatch(/values in cents/i)
		expect(text, 'the band boundary a reader has to get right').toMatch(/100\.00/)
	})

	it('report_overview says minor units, unlike its decimal neighbours', () => {
		expect(describeOf('fluentcart_report_overview')).toMatch(/minor unit/i)
	})

	it('dashboard_stats still says cents, because that one really is cents', () => {
		// is_cents is in the payload. The point of this file is accuracy, not a blanket rewrite.
		expect(describeOf('fluentcart_report_dashboard_stats')).toMatch(/cents/i)
	})
})

describe('a tool that returns a distribution does not call itself an average', () => {
	it('order_completion_time says histogram, not average', () => {
		const text = describeOf('fluentcart_report_order_completion_time')
		expect(text).toMatch(/histogram/i)
		expect(text).not.toMatch(/^average|\baverage time\b/i)
	})
})

describe('tools that disagree about revenue say so, and name the one that reconciles', () => {
	for (const name of ['fluentcart_report_sales', 'fluentcart_report_order_chart']) {
		it(`${name} admits it drops orders with no line items`, () => {
			const text = describeOf(name)
			expect(text).toMatch(/line item/i)
			expect(text, 'the caller needs somewhere to go, not just a warning').toContain(
				'fluentcart_report_sales_summary',
			)
		})
	}

	it('dashboard_stats says its counters do not share one scope', () => {
		// 34 orders, 22 paid, 468,399 cents — three numbers, three populations, one payload.
		const text = describeOf('fluentcart_report_dashboard_stats')
		expect(text).toMatch(/total_orders/)
		expect(text).toMatch(/paid_orders/)
		expect(text).toMatch(/refunded/i)
		expect(text).toContain('fluentcart_report_sales_summary')
	})
})

// The three that were left unowned when the reconciliation finished, measured live the same night
// and corrected afterwards. Each was wrong in a way that reads as a working answer:
//
//  - `report_product` returns gross_sale 4962.7 where the store took 4738.69. The query joins
//    order items grouped by (order_id, object_id) and then sums the ORDER-level total, so order 59
//    — three variations, total_paid 12500 — is counted three times, and order 55 — paid, 2599, no
//    line items at all — is dropped by the inner join. 473869 - 2599 + 25000 = 496270, to the cent.
//    The overstatement grows with the number of multi-line orders, so it is unbounded on a real
//    store rather than a fixed 224.01.
//  - `report_product_performance` claimed "conversion rates and revenue trends. Revenue in cents."
//    It returns {name, post_title, value, variation_id} per month, and `value` is a unit count.
//    No revenue, no conversion rate, nothing in cents.
//  - `report_refund_by_group` claimed "Amounts in cents" and returns {total: 49.7, average:
//    16.56666667}.
describe('the reports that were wrong about what they return', () => {
	const tools = createAllTools({ get: async () => ({ data: {} }) } as never, {})
	const describes = (name: string) => tools.find((tool) => tool.name === name)?.description ?? ''

	it('report_product leads with the overstatement rather than burying it', () => {
		const text = describes('fluentcart_report_product')

		expect(text.startsWith('OVERSTATES REVENUE')).toBe(true)
		expect(text, 'the mechanism is what stops someone re-deriving the number').toMatch(
			/once for every distinct variation/i,
		)
		expect(text, 'the dropped item-less order is the other half').toMatch(/no line items/i)
		// units_sold survives the fan-out because it is summed from the items themselves.
		expect(text).toMatch(/units_sold and\s+customer_count are computed from the items/i)
		expect(text).toContain('fluentcart_report_sales_summary')
	})

	it('report_product_performance does not promise revenue or conversion', () => {
		const text = describes('fluentcart_report_product_performance')

		expect(text).toMatch(/UNITS SOLD/)
		expect(text).toMatch(/no revenue/i)
		expect(text).toMatch(/no conversion rate/i)
		expect(text).not.toMatch(/revenue in cents/i)
	})

	it('report_refund_by_group says decimals, and warns about the blank group key', () => {
		const text = describes('fluentcart_report_refund_by_group')

		expect(text).toMatch(/DECIMAL/)
		expect(text).not.toMatch(/in cents/i)
		expect(text, 'a row keyed by "" is unreadable unless you are told').toMatch(/empty-string key/i)
	})

	it('leaves no report claiming cents while returning decimals', () => {
		// The 100x class of error, swept across every report tool at once rather than one at a time.
		const decimalReports = [
			'fluentcart_report_product',
			'fluentcart_report_product_performance',
			'fluentcart_report_refund_by_group',
			'fluentcart_report_order_chart',
			'fluentcart_report_order_value_distribution',
		]
		for (const name of decimalReports) {
			expect(describes(name), `${name} must not claim cents`).not.toMatch(/amounts? in cents/i)
			expect(describes(name), `${name} must not claim cents`).not.toMatch(/values? in cents/i)
			expect(describes(name), `${name} must not claim cents`).not.toMatch(/revenue in cents/i)
		}
	})
})
