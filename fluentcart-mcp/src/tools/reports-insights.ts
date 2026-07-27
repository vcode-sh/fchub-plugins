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
	groupKey: z
		.enum(['daily', 'weekly', 'monthly'])
		.optional()
		.describe('Time grouping: daily, weekly, or monthly'),
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
				'/reports/fetch-top-sold-products. Verified live, not inferred. ' +
				'Use fluentcart_report_top_products, which reads that endpoint and returns real rows with a stated ' +
				'period, currency and payment scope. Kept only so a caller who asks for this route by name gets a ' +
				'straight answer about why it is empty.',
			schema: z.object({ ...dateRangeWithPerPage }),
			endpoint: '/reports/top-products-sold',
		}),

		getTool(client, {
			name: 'fluentcart_report_top_sold_variants',
			title: 'Get Top Sold Variants',
			description:
				"Top-selling product variants with revenue and quantity. Revenue in cents. Use per_page to control count. Best for 'top sellers by revenue'.",
			schema: z.object({ ...dateRangeWithPerPage }),
			endpoint: '/reports/fetch-top-sold-variants',
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

		getTool(client, {
			name: 'fluentcart_report_license_summary',
			title: 'Get License Summary',
			description: 'License stats summary: total issued, active, expired, and revoked.',
			schema: z.object({ ...dateRange }),
			endpoint: '/reports/license-summary',
		}),

		getTool(client, {
			name: 'fluentcart_report_license_chart',
			title: 'Get License Chart',
			description: 'License issuance and activation trends over time.',
			schema: z.object({ ...dateRangeWithGroup }),
			endpoint: '/reports/license-chart',
		}),

		getTool(client, {
			name: 'fluentcart_report_license_pie_chart',
			title: 'Get License Pie Chart',
			description: 'License distribution by status (active, expired, revoked).',
			schema: z.object({ ...dateRange }),
			endpoint: '/reports/license-pie-chart',
		}),

		getTool(client, {
			name: 'fluentcart_report_retention_chart',
			title: 'Get Retention Chart',
			description: 'Customer retention rates over time periods.',
			schema: z.object({ ...dateRange }),
			endpoint: '/reports/retention-chart',
		}),

		getTool(client, {
			name: 'fluentcart_report_subscription_retention',
			title: 'Get Subscription Retention',
			description: 'Subscription-specific retention analysis with cohort data.',
			schema: z.object({ ...dateRange }),
			endpoint: '/reports/subscription-retention',
		}),

		getTool(client, {
			name: 'fluentcart_report_subscription_cohorts',
			title: 'Get Subscription Cohorts',
			description: 'Subscription cohort analysis: retention by sign-up period.',
			schema: z.object({ ...dateRange }),
			endpoint: '/reports/subscription-cohorts',
		}),

		getTool(client, {
			name: 'fluentcart_report_retention_snapshots_status',
			title: 'Get Retention Snapshots Status',
			description: 'Check the status of retention snapshot generation jobs.',
			schema: z.object({}),
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
