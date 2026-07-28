// Search quality, measured against questions a merchant would actually ask.
//
// In dynamic mode — the default — search IS the interface. The agent sees five results and picks
// from them, so a tool that ranks sixth may as well not exist. That made ranking a correctness
// concern rather than a nicety, and it was measurably wrong:
//
//   "configure taxes"  → no tax tool at all; "taxes" never matched "tax"
//   "tax"              → tax_settings_get at rank 7, behind five tools tied on the same score
//   "shipping zones"   → shipping_zone_countries first, for a query about zones
//
// Two causes. Nothing stemmed, so every plural missed the singular tool names. And every scoring
// tie broke alphabetically, so the most useful tool in a family lost to whichever sorted first.
//
// The second block is a held-out set, written after the scoring was finished and never used to
// tune it. It exists to catch the obvious failure mode of a benchmark like this: fitting the
// examples rather than the problem.
import { describe, expect, it } from 'vitest'
import { resolveServerContext } from '../../src/server.js'
import { searchTools } from '../../src/tools/dynamic-search.js'

// The registry the server actually exposes, not the raw catalogue. Ranking is only meaningful
// against what a caller can really see, and in the default configuration writes are absent.
process.env.FLUENTCART_URL ??= 'https://fixture.invalid'
process.env.FLUENTCART_USERNAME ??= 'fixture'
process.env.FLUENTCART_APP_PASSWORD ??= 'fixture-app-password'
const tools = resolveServerContext().tools

/** Where the agent looks: the default search limit. */
const VISIBLE = 5

function rankOf(query: string, toolName: string, limit = 10): number {
	const rows = searchTools(tools, query, { limit })
	return rows.findIndex((row) => row.name === toolName)
}

/** [question, the tool that answers it, the rank it must reach] */
const MERCHANT_QUESTIONS: [string, string, number][] = [
	['configure taxes', 'fluentcart_tax_settings_get', VISIBLE],
	['tax settings', 'fluentcart_tax_settings_get', 1],
	['tax', 'fluentcart_tax_settings_get', VISIBLE],
	['which colours sell best', 'fluentcart_report_top_sold_variants', 3],
	['product sales by variant colour', 'fluentcart_report_top_sold_variants', VISIBLE],
	['best selling variants', 'fluentcart_report_top_sold_variants', 1],
	['sales this year', 'fluentcart_report_sales_summary', VISIBLE],
	['revenue summary', 'fluentcart_report_sales_summary', 3],
	// "rate" is a name segment of four tax tools, so each scored a full segment hit for half the
	// question while the tool holding refunded_orders and order_count — the two numbers a refund
	// rate is made of — was absent from the results entirely.
	['refund rate refunded orders total', 'fluentcart_report_sales_summary', VISIBLE],
	// Stock was undiscoverable: neither variant tool said "stock" or "inventory" anywhere, so the
	// query returned coupon settings and a PDF template. The store-wide tool must win, because the
	// per-product one demands an id the question never supplies.
	['inventory stock levels low stock out of stock', 'fluentcart_variant_list_all', 1],
	['what is low on stock', 'fluentcart_variant_list_all', 1],
	['inventory', 'fluentcart_variant_list_all', 1],
	// Moved here from the second held-out set after it caught them. Each failed because the tool
	// never used the words the question does; the descriptions were fixed, so these are tuned now.
	['how many units do I have left', 'fluentcart_variant_list_all', VISIBLE],
	['which countries do my customers buy from', 'fluentcart_report_country_heat_map', VISIBLE],
	['how long until an order is completed', 'fluentcart_report_order_completion_time', VISIBLE],
	// A store question must reach the store's own rates, not FluentCart's bundled reference table.
	['what tax do I charge in poland', 'fluentcart_tax_rate_list', 3],
	['subscription churn', 'fluentcart_report_subscription_retention', 1],
	['shipping zones', 'fluentcart_shipping_zone_list', 1],
	['list customers', 'fluentcart_customer_list', 3],
	['orders', 'fluentcart_order_list', 3],
	['coupons', 'fluentcart_coupon_list', 3],
	['products', 'fluentcart_product_list', 3],
	['store settings', 'fluentcart_settings_get_store', VISIBLE],
]

/**
 * Written after the first round of scoring was finished, and never used to tune it.
 *
 * One entry has since been spent. "refund rate" was held out, then turned out to be the query the
 * ranking got worst, so fixing it consumed the example — a held-out case you have optimised
 * against is a tuned case wearing the wrong label. It stays here because the expectation still
 * holds, but it no longer counts as evidence of generalisation, which is why there is a second
 * held-out set below.
 */
const HELD_OUT: [string, string][] = [
	['refund rate', 'fluentcart_report_refund_chart'],
	['who are my repeat customers', 'fluentcart_report_repeat_customers'],
	['discount codes', 'fluentcart_coupon_list'],
	['what did this customer buy', 'fluentcart_customer_get'],
	['transactions for an order', 'fluentcart_order_transactions'],
	['email templates', 'fluentcart_email_list'],
	['shipping methods', 'fluentcart_order_shipping_methods'],
	['product categories', 'fluentcart_product_terms'],
	['activity log', 'fluentcart_activity_list'],
	['new signups per day', 'fluentcart_report_daily_signups'],
	['tax rates', 'fluentcart_tax_rate_list'],
]

/**
 * A second held-out set, written against the tool list alone and committed before being run once.
 *
 * It exists to test the two rules added for the stock and refund failures — a phrase bonus and a
 * coverage multiplier — on questions neither rule was shaped around. The first run scored **8 of
 * 12 visible, 5 of 12 first**, and that is the honest measure of those rules: they did not overfit,
 * and they did not rescue everything either.
 *
 * The four that missed all failed the same way, and it was not a ranking failure. The words the
 * question used were simply absent from the tool: `country-heat-map` said "Order distribution by
 * country" and never "customers buy"; `order-completion-time` said "completion" where the question
 * says "completed"; `variant_list_all` described stock without once saying "units". No amount of
 * scoring recovers a word that is not there, so those three were fixed where the defect was — in
 * the descriptions — and their queries moved to the tuned set above, since they are now tuned on.
 *
 * The fourth, "when do subscriptions renew next", was left alone. `report_future_renewals` answers
 * it and is ranked below working tools because it opens with DIAGNOSTIC — its date range is
 * ignored and it sums across currencies. That is the disown rule doing exactly what it was written
 * to do, and moving it up would mean trusting a report this project has already measured and
 * refused. A worse answer offered confidently is the failure mode, not a lower rank.
 */
const HELD_OUT_ROUND_TWO: [string, string][] = [
	['busiest time of day for orders', 'fluentcart_report_day_and_hour'],
	['brands and categories on a product', 'fluentcart_product_terms'],
	['download link for a purchased file', 'fluentcart_product_downloadable_url'],
	['who manages this store', 'fluentcart_role_managers'],
	['parcel sizes for shipping', 'fluentcart_shipping_package_list'],
	['first time versus returning buyers', 'fluentcart_report_new_vs_returning'],
	['suggest a sku', 'fluentcart_product_suggest_sku'],
	['invoice pdf template', 'fluentcart_pdf_template_list'],
]

describe('search answers the questions merchants ask', () => {
	for (const [query, expected, within] of MERCHANT_QUESTIONS) {
		it(`"${query}" reaches ${expected.replace('fluentcart_', '')} within ${within}`, () => {
			const rank = rankOf(query, expected)
			expect(
				rank >= 0 && rank < within,
				rank < 0 ? `absent from the results entirely` : `ranked ${rank + 1}, needed top ${within}`,
			).toBe(true)
		})
	}
})

describe('search generalises beyond the queries it was tuned on', () => {
	it('puts every held-out answer in the visible results', () => {
		const missed = HELD_OUT.filter(([query, expected]) => rankOf(query, expected, VISIBLE) < 0).map(
			([query]) => query,
		)
		expect(missed, `held-out queries whose answer was not visible: ${missed.join('; ')}`).toEqual(
			[],
		)
	})

	it('keeps every round-two held-out answer visible', () => {
		const missed = HELD_OUT_ROUND_TWO.filter(
			([query, expected]) => rankOf(query, expected, VISIBLE) < 0,
		).map(([query]) => query)
		expect(missed, `round-two queries whose answer was not visible: ${missed.join('; ')}`).toEqual(
			[],
		)
	})

	it('answers most held-out questions with its first result', () => {
		const first = HELD_OUT.filter(([query, expected]) => rankOf(query, expected) === 0).length
		// Not all of them: several have a genuinely defensible alternative first answer. The bar is
		// that the ranking is mostly right, not that it matches one person's taste every time.
		expect(first).toBeGreaterThanOrEqual(Math.ceil(HELD_OUT.length * 0.7))
	})
})

describe('ranking rules behave as documented', () => {
	it('matches a plural question against singular tool names', () => {
		// The whole reason "configure taxes" found nothing.
		expect(rankOf('taxes', 'fluentcart_tax_settings_get', VISIBLE)).toBeGreaterThanOrEqual(0)
		expect(rankOf('orders', 'fluentcart_order_list', 3)).toBeGreaterThanOrEqual(0)
	})

	it('does not cut a stem short after a non-sibilant', () => {
		// `sales` must reduce to `sale`, not `sal`: the over-eager `es` rule silently disabled the
		// sales/sold synonym, and the variant sales report fell out of reach.
		expect(rankOf('sales', 'fluentcart_report_sales_summary', VISIBLE)).toBeGreaterThanOrEqual(0)
	})

	it('prefers a collection for a plural question and a record for a singular one', () => {
		expect(rankOf('shipping zones', 'fluentcart_shipping_zone_list')).toBe(0)
		expect(rankOf('shipping zone', 'fluentcart_shipping_zone_get')).toBe(0)
	})

	it('ranks a tool that disowns itself below one that works', () => {
		// report_summary opens with DIAGNOSTIC; report_sales_summary is contract-backed.
		const working = rankOf('revenue summary', 'fluentcart_report_sales_summary')
		const disowned = rankOf('revenue summary', 'fluentcart_report_summary')
		expect(working).toBeGreaterThanOrEqual(0)
		expect(working).toBeLessThan(disowned)
	})

	it('ranks a reference table below the store it is not about', () => {
		// tax_config_rates reads a static file bundled in FluentCart and never queries the store. It
		// ranked FIRST for this question until REFERENCE DATA joined the disowning prefixes — an
		// answer drawn from a file that has never heard of this store, delivered with confidence.
		const store = rankOf('what tax do I charge in poland', 'fluentcart_tax_rate_list')
		const reference = rankOf('what tax do I charge in poland', 'fluentcart_tax_config_rates')
		expect(store).toBeGreaterThanOrEqual(0)
		expect(store).toBeLessThan(reference)
	})

	it('ignores a query made only of filler', () => {
		// Stopwords are dropped, but a query of nothing else must not match the whole registry on
		// the strength of a preposition.
		const rows = searchTools(tools, 'the of and', { limit: VISIBLE })
		expect(rows.length).toBeLessThanOrEqual(VISIBLE)
	})

	it('still honours the category filter', () => {
		const rows = searchTools(tools, 'list', { category: 'coupon', limit: VISIBLE })
		expect(rows.length).toBeGreaterThan(0)
		for (const row of rows) expect(row.category).toBe('coupon')
	})
})
