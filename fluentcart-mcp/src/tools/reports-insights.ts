import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { getTool, type ToolDefinition } from './_factory.js'

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
			description:
				'Retrieve a comprehensive product performance report with sales data. ' +
				'Revenue values in smallest currency unit (cents).',
			schema: z.object({ ...dateRange }),
			endpoint: '/reports/product-report',
		}),

		getTool(client, {
			name: 'fluentcart_report_product_performance',
			title: 'Get Product Performance',
			description:
				'Retrieve individual product performance metrics including conversion rates and revenue trends. ' +
				'Revenue values in smallest currency unit (cents). Optionally filter by product ID.',
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
				'Retrieve the top-selling products ranked by total units sold. ' +
				'Revenue values in smallest currency unit (cents).',
			schema: z.object({ ...dateRangeWithPerPage }),
			endpoint: '/reports/top-products-sold',
		}),

		getTool(client, {
			name: 'fluentcart_report_top_sold_products',
			title: 'Get Top Sold Products',
			description:
				'Retrieve top-selling products with revenue and quantity data. ' +
				'Alternative ranking to top-products-sold. Revenue values in smallest currency unit (cents).',
			schema: z.object({ ...dateRangeWithPerPage }),
			endpoint: '/reports/fetch-top-sold-products',
		}),

		getTool(client, {
			name: 'fluentcart_report_top_sold_variants',
			title: 'Get Top Sold Variants',
			description:
				'Retrieve top-selling product variants with revenue and quantity data. ' +
				'Revenue values in smallest currency unit (cents).',
			schema: z.object({ ...dateRangeWithPerPage }),
			endpoint: '/reports/fetch-top-sold-variants',
		}),

		getTool(client, {
			name: 'fluentcart_report_customer',
			title: 'Get Customer Report',
			description:
				'Retrieve customer analytics including acquisition, lifetime value, and activity metrics. ' +
				'Monetary values in smallest currency unit (cents).',
			schema: z.object({ ...dateRange }),
			endpoint: '/reports/customer-report',
		}),

		getTool(client, {
			name: 'fluentcart_report_new_vs_returning',
			title: 'Get New vs Returning Customers',
			description:
				'Retrieve analytics comparing new customer orders against returning customer orders over a date range.',
			schema: z.object({ ...dateRange }),
			endpoint: '/reports/fetch-new-vs-returning-customer',
		}),

		getTool(client, {
			name: 'fluentcart_report_daily_signups',
			title: 'Get Daily Signups',
			description: 'Retrieve daily customer signup counts over a date range.',
			schema: z.object({ ...dateRange }),
			endpoint: '/reports/daily-signups',
		}),

		getTool(client, {
			name: 'fluentcart_report_repeat_customers',
			title: 'Search Repeat Customers',
			description:
				'Search and retrieve customers who have made multiple purchases. Supports pagination.',
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
				'Retrieve refund data over time for charting, including refund amounts and counts. ' +
				'Refund amounts in smallest currency unit (cents).',
			schema: z.object({ ...dateRangeWithGroup }),
			endpoint: '/reports/refund-chart',
		}),

		getTool(client, {
			name: 'fluentcart_report_refund_by_group',
			title: 'Get Refund Data by Group',
			description:
				'Retrieve refund data segmented by grouping dimension. ' +
				'Refund amounts in smallest currency unit (cents).',
			schema: z.object({ ...dateRange }),
			endpoint: '/reports/refund-data-by-group',
		}),

		getTool(client, {
			name: 'fluentcart_report_subscription_chart',
			title: 'Get Subscription Chart',
			description:
				'Retrieve subscription metrics over time including new subscriptions, renewals, and churn.',
			schema: z.object({ ...dateRangeWithGroup }),
			endpoint: '/reports/subscription-chart',
		}),

		getTool(client, {
			name: 'fluentcart_report_future_renewals',
			title: 'Get Future Renewals',
			description:
				'Retrieve upcoming subscription renewal dates and expected revenue. ' +
				'Revenue values in smallest currency unit (cents).',
			schema: z.object({ ...dateRange }),
			endpoint: '/reports/future-renewals',
		}),

		getTool(client, {
			name: 'fluentcart_report_license_summary',
			title: 'Get License Summary',
			description:
				'Retrieve a summary of license statistics including total issued, active, expired, and revoked.',
			schema: z.object({ ...dateRange }),
			endpoint: '/reports/license-summary',
		}),
	]
}
