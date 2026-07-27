import { z } from 'zod'
import type { ApiCapabilities } from '../api/capabilities.js'
import type { FluentCartClient } from '../api/client.js'
import { TTL } from '../cache.js'
import { getTool, type ToolDefinition } from './_factory.js'
import { dropImageUrls } from './reports-insights.js'

/** Withdrawn after 1.3.9, so these register only where the store proves it still serves them. */
const retiredReport = (capabilities: ApiCapabilities | undefined, path: string): boolean =>
	capabilities?.has('GET', path) === true

const dateRange = {
	startDate: z.string().optional().describe('Start date (YYYY-MM-DD)'),
	endDate: z.string().optional().describe('End date (YYYY-MM-DD)'),
}

/**
 * `weekly` is not offered because the store cannot serve it.
 *
 * `ReportHelper::sanitizeGroupKey` whitelists monthly and yearly among the time buckets and
 * rewrites everything else to `payment_method`, which `processGroup` then treats as its default
 * and formats as `%Y-%m-%d`. So `weekly` came back as a daily series with no indication that the
 * bucket had changed — the one failure mode a caller cannot detect from the payload.
 */
const dateRangeWithGroup = {
	...dateRange,
	// Only the two values FluentCart actually whitelists.
	//
	// `ReportHelper::sanitizeGroupKey` accepts billing_country, shipping_country, payment_method,
	// payment_status, default, monthly and yearly — and rewrites anything else to `payment_method`
	// rather than rejecting it. So `daily` and `weekly` did not produce finer buckets; they
	// produced a payment-method breakdown wearing a time series' clothes. Verified live on a
	// 364-day range: `daily` and `weekly` both returned the same 8 rows labelled "2026" from
	// /reports/order-chart, where `monthly` returned 12 real month buckets.
	//
	// Finer than monthly is reached by OMITTING this, which triggers ReportHelper::defineGroupKey
	// and picks from the range width: daily to 91 days, monthly to 365, yearly beyond.
	groupKey: z
		.enum(['monthly', 'yearly'])
		.optional()
		.describe(
			'Time bucket. Omit for the store to pick from the range width — daily up to 91 days, then monthly, then yearly. Only monthly and yearly may be named; other values are silently reinterpreted as a payment-method breakdown',
		),
}

const dateRangeWithCompare = {
	...dateRangeWithGroup,
	compare_startDate: z.string().optional().describe('Comparison period start date (YYYY-MM-DD)'),
	compare_endDate: z.string().optional().describe('Comparison period end date (YYYY-MM-DD)'),
}

/**
 * The two by-group routes segment by an order column, not by time.
 *
 * `ReportHelper::sanitizeGroupKey` replaces anything outside its whitelist with `payment_method`
 * rather than rejecting it, so the old `daily | weekly | monthly` enum on these two tools was
 * wrong in every direction: `daily` and `weekly` came back grouped by payment method while still
 * looking like a time series, and `monthly` reached the SQL builder intact and produced
 * `Unknown column 'o.monthly'`. Verified live on 2026-07-27. Only the four order columns below
 * are both accepted by the sanitiser and real columns on `fct_orders`.
 */
const orderGroupKey = {
	groupKey: z
		.enum(['payment_method', 'payment_status', 'billing_country', 'shipping_country'])
		.optional()
		.describe('Order column to segment by (default: payment_method). Not a time bucket'),
}

export function reportCoreTools(
	client: FluentCartClient,
	capabilities?: ApiCapabilities,
): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_report_overview',
			title: 'Get Reports Overview',
			description:
				'DIAGNOSTIC, not a metric. Gross/net revenue by month and quarter with growth and top countries. ' +
				'The controller hardcodes a 30-month UTC window and ignores any date range you pass, so this cannot answer a question about a chosen period. ' +
				'For revenue over a period use fluentcart_report_sales_summary.',
			schema: z.object({ ...dateRange }),
			endpoint: '/reports/overview',
		}),

		getTool(client, {
			name: 'fluentcart_report_meta',
			title: 'Get Report Meta',
			description: 'Report metadata: available date ranges, filter options, and configuration.',
			schema: z.object({}),
			endpoint: '/reports/fetch-report-meta',
			cache: { key: 'report_meta', ttlMs: TTL.MEDIUM },
		}),

		getTool(client, {
			name: 'fluentcart_report_dashboard_stats',
			title: 'Get Report Dashboard Stats',
			description:
				'Order counters for a date range: all orders, paid orders, paid order items and paid order value. Amounts are cents — the payload says so with is_cents. ' +
				'The only counter tool that honours both a date range and a currency: pass currency to pin one, otherwise the store answers for its own base currency rather than for every currency combined. ' +
				"Omit both dates and it spans the first order to now. Use this for 'how many orders and how much did they come to' over a period; use fluentcart_report_sales_summary when you also need tax, shipping, refunds or an average.",
			schema: z.object({
				...dateRange,
				currency: z
					.string()
					.optional()
					.describe('ISO currency to scope the counters to, e.g. EUR. Defaults to the store base'),
			}),
			endpoint: '/reports/dashboard-stats',
		}),

		getTool(client, {
			name: 'fluentcart_report_revenue',
			title: 'Get Revenue Report',
			description:
				'Raw revenue rows grouped by day, month or year: net revenue, shipping, tax, refunds and order counts. ' +
				'Amounts are decimals, not cents — the store divides by 100 in SQL. ' +
				'Amounts are summed across every currency unless params[currency] pins one, and test-mode orders are included. ' +
				'Prefer fluentcart_report_sales_summary or fluentcart_report_sales_trend, which pin a currency and state their period and payment scope.',
			schema: z.object({ ...dateRangeWithCompare }),
			endpoint: '/reports/revenue',
		}),

		getTool(client, {
			name: 'fluentcart_report_revenue_by_group',
			title: 'Get Revenue by Group',
			description:
				'DIAGNOSTIC, not a metric. Orders segmented by one order column — payment method, payment status or billing/shipping country — with orders, refunded orders, gross and net sale, average order gross and net, item count, shipping, tax, refunds and distinct customers per segment. ' +
				'The wider of the two by-group tools: fluentcart_report_orders_by_group returns the first six of those columns and nothing else, from a different route. ' +
				'Amounts are decimals, not cents. No currency filter, so segments add every currency together, which is why this stays diagnostic.',
			schema: z.object({ ...dateRange, ...orderGroupKey }),
			endpoint: '/reports/revenue-by-group',
		}),

		getTool(client, {
			name: 'fluentcart_report_sales',
			title: 'Get Sales Report',
			description:
				'DIAGNOSTIC, not a metric. Sales figures for a date range. ' +
				'The query inner-joins a subquery that filters fct_order_items.created_at as well as the order date, so an order whose line items fall outside the window loses its revenue entirely rather than just its item count. Two different columns govern one number. Use fluentcart_report_sales_summary instead.',
			schema: z.object({ ...dateRange }),
			endpoint: '/reports/sales-report',
		}),

		getTool(client, {
			name: 'fluentcart_report_sales_growth',
			title: 'Get Sales Growth',
			description:
				'Sales growth metrics over time for a date range. ' +
				'\u26a0\ufe0f UPSTREAM BUG: Crashes with missing Status class import (UB-007b).',
			schema: z.object({ ...dateRange }),
			endpoint: '/reports/sales-growth',
		}),

		getTool(client, {
			name: 'fluentcart_report_sales_growth_chart',
			title: 'Get Sales Growth Chart',
			description: 'Sales growth chart data with periodic comparisons, grouped by interval.',
			schema: z.object({ ...dateRangeWithGroup }),
			endpoint: '/reports/sales-growth-chart',
		}),

		getTool(client, {
			name: 'fluentcart_report_order_chart',
			title: 'Get Order Chart',
			description:
				'Order chart: gross sales, net revenue, order/item counts, averages grouped by date with comparison. Values in cents.',
			schema: z.object({ ...dateRangeWithCompare }),
			endpoint: '/reports/order-chart',
		}),

		getTool(client, {
			name: 'fluentcart_report_orders_by_group',
			title: 'Get Orders by Group',
			description:
				'DIAGNOSTIC, not a metric. Orders segmented by one order column, returning order count, gross and net sale and the two averages per segment — a strict subset of what fluentcart_report_revenue_by_group returns for the same segments and the same range, from a different route. Reach for that one unless you specifically want the smaller payload. ' +
				'Amounts are decimals, not cents, and every currency is added together.',
			schema: z.object({ ...dateRange, ...orderGroupKey }),
			endpoint: '/reports/fetch-order-by-group',
		}),

		getTool(client, {
			name: 'fluentcart_report_quick_order_stats',
			title: 'Get Quick Order Stats',
			description:
				'DIAGNOSTIC, not a metric. Order counters for a rolling lookback, echoing the window it used as from_date and to_date. ' +
				'day_range is fed straight to strtotime, so it needs a relative expression such as "-7 days" or "-30 days", or the literals "this_month" or "all_time". A bare number is not a day count: "7" fails to parse and the window silently starts at 1970-01-01, which looks like an all-time total. ' +
				'Only total_orders is trustworthy — paid_orders, paid items and paid value came back 0, 0 and null on a store where fluentcart_report_dashboard_stats reported 14 paid orders worth 447,599 cents over the same span. There is no currency filter, so counts span every currency. ' +
				'Prefer fluentcart_report_dashboard_stats, which takes explicit dates and a currency.',
			schema: z.object({
				day_range: z
					.string()
					.optional()
					.describe(
						'Lookback as a strtotime expression, e.g. "-7 days", "-30 days", or "this_month" / "all_time". Never a bare number',
					),
			}),
			endpoint: '/reports/quick-order-stats',
		}),

		getTool(client, {
			name: 'fluentcart_report_recent_orders',
			title: 'Get Recent Orders',
			description:
				'Most recent orders with amounts and status. Quick snapshot without date filters.',
			schema: z.object({}),
			endpoint: '/reports/get-recent-orders',
		}),
		...(retiredReport(capabilities, '/reports/get-unfulfilled-orders')
			? [
					getTool(client, {
						name: 'fluentcart_report_unfulfilled_orders',
						title: 'Get Unfulfilled Orders',
						description:
							'Orders not yet fulfilled or shipped. Supports pagination. Withdrawn after 1.3.9.',
						schema: z.object({
							page: z.number().optional().describe('Page number (default: 1)'),
							per_page: z.number().max(50).optional().describe('Results per page (max: 50)'),
						}),
						endpoint: '/reports/get-unfulfilled-orders',
					}),
				]
			: []),

		getTool(client, {
			name: 'fluentcart_report_recent_activities',
			title: 'Get Recent Activities',
			description: 'Most recent activity log entries for the dashboard.',
			schema: z.object({}),
			endpoint: '/reports/get-recent-activities',
		}),

		getTool(client, {
			name: 'fluentcart_report_dashboard_summary',
			title: 'Get Dashboard Summary',
			description:
				'Catalogue counters only, despite the name: total products, draft products, active coupons and expired coupons. ' +
				'No orders, no revenue, no trends and no comparisons — the controller takes no arguments at all, so there is nothing to filter by. ' +
				'For order counters use fluentcart_report_dashboard_stats.',
			schema: z.object({}),
			endpoint: '/reports/get-dashboard-summary',
		}),

		getTool(client, {
			name: 'fluentcart_report_summary',
			title: 'Get Report Summary',
			description:
				'DIAGNOSTIC, not a metric. Store-lifetime totals \u2014 sales, net sales, discounts, shipping tax, average order value, order count \u2014 plus a breakdown by payment method. ' +
				'Any date range you pass is discarded: the controller reads only created_at, status and payment_status out of params, and startDate/endDate are not among them, so a five-day window and a twenty-year window return byte-identical payloads. ' +
				'Amounts are minor units as strings, unlike the neighbouring revenue reports which return decimals, and every currency is added together. ' +
				'Deprecated upstream since FluentCart 1.4 in favour of /reports/overview. For revenue over a chosen period use fluentcart_report_sales_summary.',
			// No date arguments: offering a filter the controller never reads invites a caller to
			// believe a total is scoped when it is the whole store's lifetime.
			schema: z.object({}),
			endpoint: '/reports/report-overview',
		}),

		getTool(client, {
			name: 'fluentcart_report_top_sold_products',
			title: 'Get Top Sold Products',
			description:
				'Raw top-selling products from /reports/fetch-top-sold-products: product id, name, units sold, revenue and image URL, ranked by units descending and capped at 20 rows by the controller. ' +
				'Amounts are decimals, not cents \u2014 the query divides by 100 in SQL. ' +
				'Three tools read a top-products route and only this one and its contract-backed sibling return anything: prefer fluentcart_report_top_products, which calls this same endpoint but pins a currency and states its period and payment scope; fluentcart_report_top_products_sold reads the deprecated route and is always empty.',
			schema: z.object({
				...dateRange,
				per_page: z.number().max(50).optional().describe('Number of results (max: 50)'),
			}),
			endpoint: '/reports/fetch-top-sold-products',
			transform: dropImageUrls('topSoldProducts'),
		}),

		getTool(client, {
			name: 'fluentcart_report_country_heat_map',
			title: 'Get Country Heat Map',
			description: 'Order distribution by country for geographic heatmap visualisation.',
			schema: z.object({ ...dateRange }),
			endpoint: '/reports/country-heat-map',
		}),
		...(retiredReport(capabilities, '/reports/cart-report')
			? [
					getTool(client, {
						name: 'fluentcart_report_cart',
						title: 'Get Cart Report',
						description:
							'Cart analytics: abandonment, conversion funnel, cart value. Withdrawn after 1.3.9 — FluentCart 1.5.5 answers 404 rest_no_route, so this registers only on a store that still serves the route.',
						schema: z.object({ ...dateRange }),
						endpoint: '/reports/cart-report',
					}),
				]
			: []),

		getTool(client, {
			name: 'fluentcart_report_order_value_distribution',
			title: 'Get Order Value Distribution',
			description: 'Distribution of orders by value ranges (buckets). Values in cents.',
			schema: z.object({ ...dateRange }),
			endpoint: '/reports/order-value-distribution',
		}),

		getTool(client, {
			name: 'fluentcart_report_day_and_hour',
			title: 'Get Day and Hour Report',
			description: 'Order volume heatmap by day of week and hour of day.',
			schema: z.object({ ...dateRange }),
			endpoint: '/reports/fetch-report-by-day-and-hour',
		}),

		getTool(client, {
			name: 'fluentcart_report_item_count_distribution',
			title: 'Get Item Count Distribution',
			description: 'Distribution of orders by number of items per order.',
			schema: z.object({ ...dateRange }),
			endpoint: '/reports/item-count-distribution',
		}),

		getTool(client, {
			name: 'fluentcart_report_order_completion_time',
			title: 'Get Order Completion Time',
			description: 'Average time from order creation to completion/fulfilment.',
			schema: z.object({ ...dateRange }),
			endpoint: '/reports/order-completion-time',
		}),

		getTool(client, {
			name: 'fluentcart_report_weeks_between_refund',
			title: 'Get Weeks Between Refund',
			description: 'Distribution of time between purchase and refund request.',
			schema: z.object({ ...dateRange }),
			endpoint: '/reports/weeks-between-refund',
		}),
	]
}
