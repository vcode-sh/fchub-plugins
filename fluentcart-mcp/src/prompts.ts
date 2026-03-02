import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js'
import { z } from 'zod'

export function registerPrompts(server: McpServer): void {
	server.registerPrompt(
		'analyze-store-performance',
		{
			title: 'Analyze Store Performance',
			description:
				'Analyze store performance over a date range with revenue, KPIs, and best sellers.',
			argsSchema: {
				startDate: z.string().describe('Start date (YYYY-MM-DD)'),
				endDate: z.string().describe('End date (YYYY-MM-DD)'),
			},
		},
		({ startDate, endDate }) => ({
			messages: [
				{
					role: 'user' as const,
					content: {
						type: 'text' as const,
						text: `Analyze store performance from ${startDate} to ${endDate}. Use these tools in order:\n1. fluentcart_report_overview — get financial overview\n2. fluentcart_report_revenue — get revenue breakdown\n3. fluentcart_report_dashboard_stats — get KPI metrics\n4. fluentcart_report_top_products_sold — find best sellers\n\nSummarize findings with trends and recommendations.`,
					},
				},
			],
		}),
	)

	server.registerPrompt(
		'investigate-order',
		{
			title: 'Investigate Order',
			description:
				'Deep-dive into a specific order: payment status, transactions, and activity timeline.',
			argsSchema: {
				order_id: z.string().describe('Order ID to investigate'),
			},
		},
		({ order_id }) => ({
			messages: [
				{
					role: 'user' as const,
					content: {
						type: 'text' as const,
						text: `Investigate order #${order_id}. Use these tools in order:\n1. fluentcart_order_get — get order details\n2. fluentcart_order_transactions — get payment transactions\n3. fluentcart_activity_list — get activity timeline for this order\n\nCheck payment status, look for disputes or failed transactions, and summarize the complete order timeline.`,
					},
				},
			],
		}),
	)

	server.registerPrompt(
		'customer-overview',
		{
			title: 'Customer Overview',
			description:
				'Get a complete customer profile including stats, addresses, and spending history.',
			argsSchema: {
				customer_id: z.string().describe('Customer ID to look up'),
			},
		},
		({ customer_id }) => ({
			messages: [
				{
					role: 'user' as const,
					content: {
						type: 'text' as const,
						text: `Get a complete overview of customer #${customer_id}. Use these tools in order:\n1. fluentcart_customer_get — get customer profile\n2. fluentcart_customer_stats — get spending and order statistics\n3. fluentcart_customer_addresses — get all addresses on file\n\nSummarize the customer profile, lifetime value, and any notable patterns in their purchasing history.`,
					},
				},
			],
		}),
	)

	server.registerPrompt(
		'catalog-summary',
		{
			title: 'Catalog Summary',
			description:
				'Report on catalog health: product count, top sellers, and overall store dashboard.',
		},
		() => ({
			messages: [
				{
					role: 'user' as const,
					content: {
						type: 'text' as const,
						text: `Generate a catalog health summary. Use these tools in order:\n1. fluentcart_product_list — get all products with their statuses\n2. fluentcart_report_top_products_sold — find best and worst sellers\n3. fluentcart_dashboard_overview — get overall store metrics\n\nReport on catalog size, product status distribution, top performers, and any items that may need attention (out of stock, low sales, etc.).`,
					},
				},
			],
		}),
	)

	server.registerPrompt(
		'subscription-health',
		{
			title: 'Subscription Health',
			description: 'Analyze subscription metrics: churn, renewals, and revenue forecast.',
			argsSchema: {
				startDate: z.string().describe('Start date (YYYY-MM-DD)'),
				endDate: z.string().describe('End date (YYYY-MM-DD)'),
			},
		},
		({ startDate, endDate }) => ({
			messages: [
				{
					role: 'user' as const,
					content: {
						type: 'text' as const,
						text: `Analyze subscription health from ${startDate} to ${endDate}. Use these tools in order:\n1. fluentcart_subscription_list — get active and recent subscriptions\n2. fluentcart_report_subscription_chart — get subscription trends over time\n3. fluentcart_report_future_renewals — forecast upcoming renewal revenue\n\nAnalyze churn rate, renewal success rate, and provide a revenue forecast with recommendations for reducing churn.`,
					},
				},
			],
		}),
	)
}
