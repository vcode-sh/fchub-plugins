import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { createTool, getTool, postTool, type ToolDefinition } from './_factory.js'
import { direct } from './endpoints.js'

const dateRange = {
	startDate: z.string().optional().describe('Start date (YYYY-MM-DD)'),
	endDate: z.string().optional().describe('End date (YYYY-MM-DD)'),
}

const dateRangeWithPerPage = {
	...dateRange,
	per_page: z.number().max(50).optional().describe('Number of results to return (max: 50)'),
}

/**
 * A date that exists only to be truthy.
 *
 * `ReportHelper::processParams` will not build a comparison period unless both `compareType` and
 * `compareDate` are non-empty, but `getCompareRange` reads `compareDate` for the `custom` type
 * alone. For the other four the value is inert, so this stands in when the caller has not supplied
 * one and the comparison would otherwise be dropped in silence.
 */
const COMPARE_GATE_PLACEHOLDER = '1970-01-01'

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

/**
 * Turn "the licensing module is not installed" into a sentence that says so.
 *
 * The three license reports are registered routes on every store, but their tables only exist when
 * the licensing module is active. Without it FluentCart answers **HTTP 200** carrying a PHP fatal,
 * which the client can only classify as CONNECTION_ERROR — so a caller was told the store was
 * unreachable, and handed raw SQL plus an absolute server path out of the plugin's stack trace:
 *
 *   Error [CONNECTION_ERROR]: Table 'wordpress.wp_fct_licenses' doesn't exist (SQL: select
 *   count(*) ...): {"recovered":{"code":"plugin_exception","data":{"file":"/var/www/html/...
 *
 * Route pruning cannot catch this: the route is genuinely served, it is the storage behind it that
 * is missing, so the REST index says yes and the query says no. Detecting it here is the only
 * place the distinction is visible. The internals are dropped rather than forwarded — a missing
 * table is a fact about the store's configuration, not something a caller can act on, and the file
 * path is nobody's business.
 */
function licenseTool(client: FluentCartClient, config: Parameters<typeof getTool>[1]) {
	const tool = getTool(client, config)
	const inner = tool.handler

	return {
		...tool,
		handler: async (input: Record<string, unknown>) => {
			const result = await inner(input)
			if (!result.isError) return result

			const text = result.content[0]?.text ?? ''
			if (!/wp_fct_licenses|fct_licenses/i.test(text)) return result

			return {
				content: [
					{
						type: 'text' as const,
						text: 'FluentCart licensing is not active on this store, so there are no license records to report. The route exists but its tables were never created. Enable the licensing module in FluentCart if you expected data here.',
					},
				],
				isError: true,
			}
		},
	}
}

/**
 * Drop the image URL each sales row carries.
 *
 * These reports answer "what sold"; the image belongs to a catalogue view, not a figure. On the
 * seeded store the URLs were 34% of the top-variants payload and 43% of top-products — nearly half
 * the tokens spent on links nobody asked for, in the two tools an agent reaches for most when
 * asked which colour or size is selling. The contract-backed `fluentcart_report_top_products`
 * never had the problem because its allowlist projection excludes anything it did not name.
 */
export function dropImageUrls(collection: string) {
	return (data: unknown): unknown => {
		const body = data as Record<string, unknown> | null
		const rows = body?.[collection]
		if (!Array.isArray(rows)) return data

		return {
			...body,
			[collection]: rows.map((row) => {
				if (row === null || typeof row !== 'object') return row
				const { media, media_url, thumbnail, ...rest } = row as Record<string, unknown>
				return rest
			}),
		}
	}
}

export function reportInsightTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_report_product',
			title: 'Get Store Sales Report',
			description:
				'OVERSTATES REVENUE. Do not use gross_sale, net_sale or average_selling_price from this ' +
				'tool: the query joins order items grouped by (order_id, object_id) and then sums the ' +
				'ORDER-level total, so an order is added once for every distinct variation it contains, ' +
				'while an order carrying no line items is dropped entirely. units_sold and ' +
				'customer_count are computed from the items and are unaffected. Use ' +
				'fluentcart_report_sales_summary for any money figure. Store-wide despite the name — it ' +
				'takes no product filter; amounts are decimals, not cents.',
			schema: z.object({ ...dateRange }),
			endpoint: '/reports/product-report',
		}),

		getTool(client, {
			name: 'fluentcart_report_product_performance',
			title: 'Get Product Performance',
			// Measured live over 2020-2027: every row is {name, post_title, value, variation_id}, keyed
			// by month. `value` is a unit count — "Forest Green" reads 12, matching
			// report_top_sold_variants. There is no revenue field and no conversion rate anywhere in
			// the payload, so the old description was wrong three times in eleven words.
			description:
				'UNITS SOLD per product variation, bucketed by month: each row is {name, post_title, ' +
				'value, variation_id} where value is a QUANTITY. There is no revenue in this response ' +
				'and no conversion rate, despite the tool name — for revenue use ' +
				'fluentcart_report_sales_summary, and for a ranked best-seller list use ' +
				'fluentcart_report_top_sold_variants.',
			schema: z.object({
				...dateRange,
				product_id: z.number().optional().describe('Specific product ID to analyse'),
			}),
			endpoint: '/reports/product-performance',
		}),

		getTool(client, {
			name: 'fluentcart_report_top_products_sold',
			title: 'Get Top Products Sold',
			description:
				'DEPRECATED UPSTREAM, returns nothing. FluentCart has deprecated /reports/top-products-sold since 1.4; ' +
				'on 1.5.5 it answers HTTP 200 with an empty top_products_sold list and a notice pointing at ' +
				'/reports/fetch-top-sold-products. Verified live, not inferred: the list is empty because the query ' +
				'appends HAVING total_sold with an operator and a bound this server has no way to supply, so nothing ' +
				'can satisfy it. ' +
				'Use fluentcart_report_top_products, which reads that endpoint and returns real rows with a stated ' +
				'period, currency and payment scope, or fluentcart_report_top_sold_products for the same rows raw. ' +
				'Kept only so a caller who asks for this route by name gets a straight answer about why it is empty.',
			schema: z.object({ ...dateRangeWithPerPage }),
			endpoint: '/reports/top-products-sold',
		}),

		createTool(client, {
			name: 'fluentcart_report_top_sold_variants',
			routes: direct('GET', '/reports/fetch-top-sold-variants'),
			title: 'Get Top Sold Variants',
			description:
				'Units sold and revenue per product VARIANT, ranked by units — the tool for "which size, ' +
				'colour or option sells best". A currency is required: without one the store adds every ' +
				'currency into a single figure, which is not a number about anything. Amounts are ' +
				'decimals. The store returns at most 10 rows and offers no paging, so this is a top-10, ' +
				'not a catalogue. Includes test-mode orders.',
			schema: z.object({
				startDate: z.string().optional().describe('Start date (YYYY-MM-DD)'),
				endDate: z.string().optional().describe('End date (YYYY-MM-DD)'),
				currency: z
					.string()
					.regex(/^[A-Z]{3}$/)
					.describe(
						'Three-letter ISO currency to report on, e.g. EUR. Required: totals are meaningless summed across currencies',
					),
			}),
			annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: true },
			// Three faults, all measured live on a two-currency store.
			//
			// Currency: unscoped, "Casual Classic Hoodie / Cadet Blue" reported quantity 12 and amount
			// 96 — that is 6 units at EUR 48 plus 6 units at PLN 48 added together. The sibling
			// fluentcart_report_sales_summary refuses to run without a currency precisely because such
			// a total "would be meaningless"; this tool was doing the thing that one forbids.
			//
			// Order: the store breaks ties on quantity arbitrarily, so two identical calls minutes
			// apart returned different tails — a one-unit variant present in one answer and absent from
			// the next, both presented as complete. Sorting here makes the same question give the same
			// answer.
			//
			// Paging: `per_page` did nothing. 3 and 50 both returned 10 rows, and there is no second
			// page, so rows beyond the tenth are unreachable. Advertising the parameter implied a
			// completeness the endpoint cannot deliver.
			handler: async (apiClient, input) => {
				const params: Record<string, unknown> = { 'params[currency]': input.currency }
				if (input.startDate !== undefined) params['params[startDate]'] = input.startDate
				if (input.endDate !== undefined) params['params[endDate]'] = input.endDate

				const response = await apiClient.get('/reports/fetch-top-sold-variants', params)
				const body = response.data as Record<string, unknown> | null
				const rows = Array.isArray(body?.topSoldVariants)
					? (body.topSoldVariants as Record<string, unknown>[])
					: []

				const ranked = rows
					.map(({ media_url: _media, media: _m, thumbnail: _t, ...rest }) => rest)
					.sort((a, b) => {
						const byQuantity = Number(b.quantity ?? 0) - Number(a.quantity ?? 0)
						if (byQuantity !== 0) return byQuantity
						// Deterministic tie-break, so the same question gives the same answer.
						return `${a.product_name}/${a.variation_name}`.localeCompare(
							`${b.product_name}/${b.variation_name}`,
						)
					})

				return {
					currency: input.currency,
					period: { from: input.startDate ?? null, to: input.endDate ?? null },
					variants: ranked,
					ranked_by: 'units sold',
					limit: 'the store returns at most 10 rows and offers no paging',
					includes_test_mode: true,
				}
			},
		}),

		/**
		 * Customer acquisition, and only that. The previous description promised more.
		 *
		 * It read "acquisition, lifetime value, and activity. Values in cents", and there is no
		 * money in this report at all. `CustomerReportService::getCustomerReportData` is a single
		 * `COUNT(*)` over `fct_customers` grouped by period; the only figure it can produce is
		 * `customer_count`. Measured live over 2026-01-01 → 2026-07-28 the whole payload was
		 * `{"summary":{"customer_count":97},"currentMetrics":[{"year":2026,"group":"2026-03",
		 * "customer_count":86}, …]}` — seven buckets, one integer each. An agent asked for lifetime
		 * value and told the values were cents would have reported 97 as a sum of money.
		 *
		 * Two live capabilities were unreachable and now are not:
		 *
		 * `groupKey` pins the bucket, which was otherwise chosen from the range width and therefore
		 * changed under the caller without saying so. Measured over 2026-03-01 → 2026-04-30 (61
		 * days): omitted → 61 rows, a dense daily series; `monthly` → 2; `yearly` → 1. `daily` and
		 * `weekly` → 6 rows, five real days plus a leading bucket labelled "2026" holding zero.
		 * They are rewritten to `payment_method` by `sanitizeParams`, so `processGroup` still
		 * formats `%Y-%m-%d` — the data is daily — but `getPeriodRange` is handed the rewritten key,
		 * cannot build a skeleton from it, and emits one junk bucket instead of zero-filling the
		 * empty days. So the dense daily series is reached by omitting the argument, and only
		 * `monthly` and `yearly` may be named. Note this is a different failure from the one
		 * `dateRangeWithGroup` documents for the order charts, which is why it is spelled out again.
		 *
		 * `compareType` fills `previousSummary` and `fluctuations`, which were permanently `[]` —
		 * three of the five response keys, dead. All five types verified live for April 2026:
		 * previous_period and previous_month → 86, previous_quarter and previous_year → 0, custom →
		 * whatever compareDate names. An unrecognised value degrades to no comparison rather than
		 * erroring.
		 */
		getTool(client, {
			name: 'fluentcart_report_customer',
			title: 'Get Customer Acquisition Report',
			description:
				'Customer ACQUISITION counts over a date range, and nothing else: one customer_count per ' +
				'period bucket plus a total. Counted from when the customer RECORD was created, not from a ' +
				'purchase, so a store that imports history acquires everybody on import day. There is no ' +
				'money in this report — no lifetime value, no spend, nothing in cents; the query is a ' +
				'COUNT(*) over the customers table. For lifetime value sort fluentcart_customer_list by ltv ' +
				'DESC, or read ltv off fluentcart_customer_get. previousSummary and fluctuations stay empty ' +
				'unless compareType is passed. A store without FluentCart Pro discards your dates entirely ' +
				'and reports the last month.',
			schema: z.object({
				...dateRange,
				groupKey: z
					.enum(['monthly', 'yearly'])
					.optional()
					.describe(
						'Bucket width. Omit to let the range decide — daily up to 91 days, monthly to 365, yearly beyond. Only monthly and yearly may be named; daily and weekly are rewritten upstream and return a sparse series with a bogus leading bucket',
					),
				compareType: z
					.enum([
						'previous_period',
						'previous_month',
						'previous_quarter',
						'previous_year',
						'custom',
					])
					.optional()
					.describe(
						'Compare the range against an earlier one, filling previousSummary and fluctuations (a percentage change). Omit for no comparison',
					),
				compareDate: z
					.string()
					.optional()
					.describe(
						'First day of the comparison range, YYYY-MM-DD. Read only when compareType is custom, and required in that case; ignored otherwise',
					),
			}),
			// ReportHelper::processParams gates the entire comparison on `$compareType &&
			// $compareDate`, even though getCompareRange reads compareDate for the `custom` type
			// alone. So compareType on its own answered HTTP 200 with previousSummary [] — a
			// comparison that silently did not happen, which is the failure this module exists to
			// stop. The placeholder opens the gate and is then discarded: verified live,
			// previous_period returned the same 86 for compareDate 2026-01-15 and for 2025-06-30.
			//
			// `custom` is left alone, because it is the one type that reads the value. Without a
			// compareDate there is nothing to compare it against and no date this side could invent
			// would be the caller's intent, so the store returns no comparison — which is true.
			query: (input) => {
				const compareType = input['params[compareType]']
				if (compareType === undefined || compareType === 'custom') return input
				if (input['params[compareDate]'] !== undefined) return input
				return { ...input, 'params[compareDate]': COMPARE_GATE_PLACEHOLDER }
			},
			endpoint: '/reports/customer-report',
		}),

		getTool(client, {
			name: 'fluentcart_report_new_vs_returning',
			title: 'Get New vs Returning Customers',
			description: 'New vs returning customer order comparison over a date range.',
			schema: z.object({ ...dateRange }),
			endpoint: '/reports/fetch-new-vs-returning-customer',
		}),

		getTool(client, {
			name: 'fluentcart_report_daily_signups',
			title: 'Get Daily Signups',
			description:
				'Daily SUBSCRIPTION signup counts over a date range — not customer registrations. The route goes to SubscriptionReportController::getDailySignups, so a store with no subscriptions returns zeros however many customers it gained. For customer acquisition use fluentcart_report_customer.',
			schema: z.object({ ...dateRange }),
			endpoint: '/reports/daily-signups',
		}),

		getTool(client, {
			name: 'fluentcart_report_repeat_customers',
			title: 'Search Repeat Customers',
			// order_status is not optional in practice, whatever its type says.
			// CustomerHelper::getRepeatCustomerBySearch line 97 runs unconditionally:
			//   $params["status"] = ["column"=>"status","operator"=>"in","value"=>[Arr::get($params,'order_status')]]
			// With nothing passed, that value is [null], the whereHas becomes `status IN (NULL)`, and
			// no customer can match. The tool did not expose the key at all, so it returned an empty
			// list on every store forever — measured here as 0 customers against 2 who qualify.
			// Live: params[order_status]=processing returns both, =completed returns one, omitted
			// returns none.
			description:
				'Customers who have ordered more than once. order_status is REQUIRED IN PRACTICE: ' +
				'FluentCart filters the underlying orders by it unconditionally, so omitting it matches ' +
				'nothing and answers with an empty list rather than an error. It takes ONE status, not ' +
				'a list, so a customer with two orders in different statuses is only found under the ' +
				'status both share — run it per status and combine. For a plain count of repeat purchasers, ' +
				'fluentcart_customer_list carries purchase_count on every row.',
			schema: z.object({
				...dateRange,
				order_status: z
					.string()
					.describe(
						'The single order status to count repeats within, e.g. "completed", "processing", "on-hold". Omitting it returns nobody',
					),
				per_page: z.number().max(50).optional().describe('Results per page (max: 50)'),
				current_page: z.number().optional().describe('Page number'),
			}),
			endpoint: '/reports/search-repeat-customer',
		}),

		getTool(client, {
			name: 'fluentcart_report_refund_chart',
			title: 'Get Refund Chart',
			description:
				'DIAGNOSTIC, not a metric. Refund amounts and counts over time. ' +
				'The date range filters when the ORDER was created, not when the refund happened, so "refunds in July" really means "refunds against orders created in July, whenever they occurred". The refund rate moves in periods where nothing was refunded. Amounts are decimals, not cents.',
			schema: z.object({ ...dateRangeWithGroup }),
			endpoint: '/reports/refund-chart',
		}),

		getTool(client, {
			name: 'fluentcart_report_refund_by_group',
			title: 'Get Refund Data by Group',
			// Measured live: {"":"PL", totalRefunded: 3, totalRefundedAmount: {total: 49.7, average:
			// 16.56666667}}. Decimals, not cents, and the grouping column arrives under an EMPTY
			// STRING key because the service aliases it to whatever it grouped by and that alias is
			// blank here.
			description:
				'Refunds segmented by a grouping dimension: totalRefunded is a COUNT of refunds and ' +
				'totalRefundedAmount holds total and average in DECIMAL currency units, not cents. The ' +
				'group each row belongs to arrives under an empty-string key rather than a named one.',
			schema: z.object({ ...dateRange }),
			endpoint: '/reports/refund-data-by-group',
		}),

		getTool(client, {
			name: 'fluentcart_report_subscription_chart',
			title: 'Get Subscription Chart',
			description:
				// `total_subscriptions` does not count subscriptions. SubscriptionReportService::getChartData
				// builds its query `from('fct_orders as o')->where('o.type', $subscriptionType)` with a
				// success-status filter, so it counts ORDERS of subscription type. Measured on this
				// store: the chart reports 3 while fct_subscriptions holds 4 — one active, three
				// pending — because a pending subscription has no order in a success status yet.
				'total_subscriptions counts subscription-type ORDERS in a success status, NOT rows in ' +
				'the subscriptions table, so it will disagree with fluentcart_subscription_list and the ' +
				'list is the one to trust for how many subscriptions exist. Also returns projected ' +
				'future installments. Despite the name there is no churn here and no per-period series. ' +
				'For churn and MRR month by month use fluentcart_report_subscription_retention.',
			schema: z.object({ ...dateRangeWithGroup }),
			endpoint: '/reports/subscription-chart',
		}),

		getTool(client, {
			name: 'fluentcart_report_future_renewals',
			title: 'Get Future Renewals',
			description:
				'DIAGNOSTIC, not a metric. Upcoming subscription renewals and projected revenue. ' +
				'Any date range you pass is ignored: the window is hardcoded to today plus one quarter. Amounts here really are minor units, unlike every neighbouring report, which returns decimals. Subscriptions carry neither a currency nor a mode column, so projections sum across currencies and always include test-mode subscriptions.',
			schema: z.object({ ...dateRange }),
			endpoint: '/reports/future-renewals',
		}),

		licenseTool(client, {
			name: 'fluentcart_report_license_summary',
			title: 'Get License Summary',
			description: 'License stats summary: total issued, active, expired, and revoked.',
			schema: z.object({ ...dateRange }),
			endpoint: '/reports/license-summary',
		}),

		licenseTool(client, {
			name: 'fluentcart_report_license_chart',
			title: 'Get License Chart',
			description: 'License issuance and activation trends over time.',
			schema: z.object({ ...dateRangeWithGroup }),
			endpoint: '/reports/license-chart',
		}),

		licenseTool(client, {
			name: 'fluentcart_report_license_pie_chart',
			title: 'Get License Pie Chart',
			description: 'License distribution by status (active, expired, revoked).',
			schema: z.object({ ...dateRange }),
			endpoint: '/reports/license-pie-chart',
		}),

		getTool(client, {
			name: 'fluentcart_report_retention_chart',
			title: 'Get Retention Chart',
			description:
				'How long subscriptions last, as a histogram rather than a time series: one count per survival band — day_7, day_15, day_30, day_90, day_180, day_365, more_than_year. ' +
				'There is no month-by-month movement here and no MRR. For that use fluentcart_report_subscription_retention; for retention by sign-up cohort use fluentcart_report_subscription_cohorts.',
			schema: z.object({ ...dateRange }),
			endpoint: '/reports/retention-chart',
		}),

		/**
		 * The richest analytics payload FluentCart serves, and deliberately still a raw tool.
		 *
		 * `subscription_retention` is registered in report-contracts.ts as diagnostic-only: the
		 * money columns cannot be scoped to a currency, because `fct_subscriptions` keeps the
		 * currency inside a JSON `config` blob where a SUM cannot reach it. Everything else about
		 * the report holds up, so the description states the semantics precisely instead of
		 * shrugging, and tests/integration/report-semantics.test.ts reconciles the MRR figure
		 * against the subscription list so the claims below are checked rather than asserted.
		 */
		getTool(client, {
			name: 'fluentcart_report_subscription_retention',
			title: 'Get Subscription MRR and Churn Series',
			description:
				'MRR and churn, one row per month: mrr, new_subscriptions, new_subscriptions_mrr, churned_subscriptions, churned_subscriptions_mrr, active_paid_subscriptions, retention_rate, retention_rate_money. ' +
				'Amounts are decimals, not cents. Yearly, weekly and daily plans are normalised to a monthly equivalent. Caller dates are honoured. Rows count what is active at month end, so future months are projections. Excludes pending and intended; keeps cancelled and expired. ' +
				'WARNING: money columns add every currency together. No currency column exists and a currency argument is discarded, so on a multi-currency store trust the counts and retention_rate, not the MRR. ' +
				'mrr and active_* counts are strings; an empty month sends an empty string, not a zero.',
			schema: z.object({
				startDate: z
					.string()
					.optional()
					.describe('First month to report, YYYY-MM-DD. Buckets step monthly from this day'),
				endDate: z
					.string()
					.optional()
					.describe(
						'Last month to report, YYYY-MM-DD. A final month ending before the start day-of-month is dropped',
					),
			}),
			endpoint: '/reports/subscription-retention',
		}),

		getTool(client, {
			name: 'fluentcart_report_subscription_cohorts',
			title: 'Get Subscription Cohorts',
			description:
				'Retention by sign-up cohort, read from the pre-computed snapshot table rather than from the subscriptions themselves. ' +
				'Returns an empty cohorts list until snapshots exist: run fluentcart_report_retention_snapshots_generate and poll fluentcart_report_retention_snapshots_status first. Both dates are required — omit either and the controller returns nothing at all. ' +
				'For a month-by-month MRR and churn series that needs no snapshots, use fluentcart_report_subscription_retention.',
			schema: z.object({
				...dateRange,
				groupBy: z
					.enum(['month', 'year'])
					.optional()
					.describe('Cohort period (default: year; anything else is coerced to year)'),
				metric: z
					.enum(['subscribers', 'mrr'])
					.optional()
					.describe('Value each cohort cell reports (default: subscribers)'),
			}),
			// getCohortData reads groupBy and metric off `$request->get('params')` directly rather
			// than through the report filter allowlist, so the factory does not relocate them.
			query: (input) => {
				const { groupBy, metric, ...rest } = input
				return {
					...rest,
					...(groupBy === undefined ? {} : { 'params[groupBy]': groupBy }),
					...(metric === undefined ? {} : { 'params[metric]': metric }),
				}
			},
			endpoint: '/reports/subscription-cohorts',
		}),

		getTool(client, {
			name: 'fluentcart_report_retention_snapshots_status',
			title: 'Get Retention Snapshots Status',
			description:
				'Status of one retention-snapshot generation job: pending, running, completed or failed, with the row counts it wrote. ' +
				'job_id is required and comes from the job_id field that fluentcart_report_retention_snapshots_generate returns; there is no way to list jobs, so a job whose id was not kept cannot be found again. ' +
				'Snapshots are what fluentcart_report_subscription_cohorts reads, so generate then poll here before expecting cohorts to be populated.',
			schema: z.object({
				job_id: z
					.string()
					.describe(
						'Job identifier returned by fluentcart_report_retention_snapshots_generate (a Unix timestamp)',
					),
			}),
			// RetentionSnapshotController::checkStatus reads `params.job_id`, and job_id is not one of
			// the report filter keys the factory relocates, so it is nested here. Sent flat the
			// endpoint answers 200 with {"success":false,"message":"Job ID required"} — a success
			// status carrying a failure, which is why the previous empty schema looked harmless.
			query: (input) => {
				const { job_id: jobId, ...rest } = input
				return jobId === undefined ? rest : { ...rest, 'params[job_id]': jobId }
			},
			endpoint: '/reports/retention-snapshots/status',
		}),

		postTool(client, {
			name: 'fluentcart_report_retention_snapshots_generate',
			title: 'Generate Retention Snapshots',
			description:
				'Trigger generation of retention snapshot data. Long-running — check status afterwards.',
			schema: z.object({}),
			endpoint: '/reports/retention-snapshots/generate',
		}),

		getTool(client, {
			name: 'fluentcart_report_sources',
			title: 'Get Report Sources',
			description:
				'DIAGNOSTIC, not a metric. UTM attribution sources for orders. ' +
				'The query selects utm_term, utm_content and utm_id without grouping or aggregating them, so under MySQL default settings it errors outright and otherwise returns an arbitrary row per group. Orders with no UTM source are dropped entirely, so these totals never reconcile with revenue.',
			schema: z.object({ ...dateRange }),
			endpoint: '/reports/sources',
		}),
	]
}
