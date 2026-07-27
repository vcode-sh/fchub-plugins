import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { getTool, postTool, type ToolDefinition } from './_factory.js'

const dateRange = {
	startDate: z.string().optional().describe('Start date (YYYY-MM-DD)'),
	endDate: z.string().optional().describe('End date (YYYY-MM-DD)'),
}

const dateRangeWithPerPage = {
	...dateRange,
	per_page: z.number().max(50).optional().describe('Number of results to return (max: 50)'),
}

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
			title: 'Get Product Report',
			description: 'Product performance report with sales data. Revenue in cents.',
			schema: z.object({ ...dateRange }),
			endpoint: '/reports/product-report',
		}),

		getTool(client, {
			name: 'fluentcart_report_product_performance',
			title: 'Get Product Performance',
			description:
				'Individual product performance: conversion rates and revenue trends. Revenue in cents.',
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

		getTool(client, {
			name: 'fluentcart_report_top_sold_variants',
			title: 'Get Top Sold Variants',
			description:
				'Units sold and revenue per product variant, ranked by units. This is the tool for "which ' +
				'size, colour or option sells best": the variant name carries whatever distinguishes it. ' +
				'Amounts are decimals, not cents. Use per_page to control how many rows come back.',
			schema: z.object({ ...dateRangeWithPerPage }),
			endpoint: '/reports/fetch-top-sold-variants',
			transform: dropImageUrls('topSoldVariants'),
		}),

		getTool(client, {
			name: 'fluentcart_report_customer',
			title: 'Get Customer Report',
			description:
				"Customer analytics: acquisition, lifetime value, and activity. Values in cents. Use for 'how are customers performing' questions.",
			schema: z.object({ ...dateRange }),
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
			description: 'Daily customer signup counts over a date range.',
			schema: z.object({ ...dateRange }),
			endpoint: '/reports/daily-signups',
		}),

		getTool(client, {
			name: 'fluentcart_report_repeat_customers',
			title: 'Search Repeat Customers',
			description:
				"Search customers with multiple purchases. Supports pagination. Use for 'who are my loyal/repeat customers' queries.",
			schema: z.object({
				...dateRange,
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
			description: 'Refund data segmented by grouping dimension. Amounts in cents.',
			schema: z.object({ ...dateRange }),
			endpoint: '/reports/refund-data-by-group',
		}),

		getTool(client, {
			name: 'fluentcart_report_subscription_chart',
			title: 'Get Subscription Chart',
			description: 'Subscription metrics over time: new subscriptions, renewals, and churn.',
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
