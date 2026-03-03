import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { TTL } from '../cache.js'
import { getTool, type ToolDefinition } from './_factory.js'

const dateRange = {
	startDate: z.string().optional().describe('Start date (YYYY-MM-DD)'),
	endDate: z.string().optional().describe('End date (YYYY-MM-DD)'),
}

const dateRangeWithGroup = {
	...dateRange,
	groupKey: z
		.enum(['daily', 'weekly', 'monthly'])
		.optional()
		.describe('Grouping interval: daily, weekly, or monthly'),
}

const dateRangeWithCompare = {
	...dateRangeWithGroup,
	compare_startDate: z.string().optional().describe('Comparison period start date (YYYY-MM-DD)'),
	compare_endDate: z.string().optional().describe('Comparison period end date (YYYY-MM-DD)'),
}

export function reportCoreTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_report_overview',
			title: 'Get Reports Overview',
			description:
				'Financial overview: gross/net revenue by month/quarter with YoY growth and top countries. Values in cents. ' +
				"Best for 'what's my revenue this month/quarter/year' questions.",
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
				'Dashboard stats: total orders, paid orders, paid items, and paid amounts with comparison. Values in cents. ' +
				"Use for 'how many orders today/this week/this month' questions.",
			schema: z.object({ ...dateRange }),
			endpoint: '/reports/dashboard-stats',
		}),

		getTool(client, {
			name: 'fluentcart_report_revenue',
			title: 'Get Revenue Report',
			description:
				'Revenue grouped by day/week/month: net revenue, shipping, tax, refunds, order counts with comparison. Values in cents.',
			schema: z.object({ ...dateRangeWithCompare }),
			endpoint: '/reports/revenue',
		}),

		getTool(client, {
			name: 'fluentcart_report_revenue_by_group',
			title: 'Get Revenue by Group',
			description: 'Revenue segmented by product group or category. Values in cents.',
			schema: z.object({ ...dateRangeWithGroup }),
			endpoint: '/reports/revenue-by-group',
		}),

		getTool(client, {
			name: 'fluentcart_report_sales',
			title: 'Get Sales Report',
			description: 'Comprehensive sales report for a date range.',
			schema: z.object({ ...dateRange }),
			endpoint: '/reports/sales-report',
		}),

		getTool(client, {
			name: 'fluentcart_report_sales_growth',
			title: 'Get Sales Growth',
			description: 'Sales growth metrics over time for a date range.',
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
			description: 'Order data grouped by dimension (e.g. payment method, product type).',
			schema: z.object({
				...dateRange,
				groupKey: z.string().optional().describe('Grouping dimension key'),
			}),
			endpoint: '/reports/fetch-order-by-group',
		}),

		getTool(client, {
			name: 'fluentcart_report_quick_order_stats',
			title: 'Get Quick Order Stats',
			description:
				"Quick order statistics for a given lookback period. Use day_range '1' for today, '7' for this week, '30' for this month.",
			schema: z.object({
				day_range: z
					.string()
					.optional()
					.describe('Number of days to look back (e.g. "7", "30", "90")'),
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

		getTool(client, {
			name: 'fluentcart_report_unfulfilled_orders',
			title: 'Get Unfulfilled Orders',
			description: 'Orders not yet fulfilled or shipped. Supports pagination.',
			schema: z.object({
				page: z.number().optional().describe('Page number (default: 1)'),
				per_page: z.number().max(50).optional().describe('Results per page (max: 50)'),
			}),
			endpoint: '/reports/get-unfulfilled-orders',
		}),

		getTool(client, {
			name: 'fluentcart_report_recent_activities',
			title: 'Get Recent Activities',
			description: 'Most recent activity log entries for the dashboard.',
			schema: z.object({}),
			endpoint: '/reports/get-recent-activities',
		}),
	]
}
