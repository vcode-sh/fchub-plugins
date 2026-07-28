import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { getTool, type ToolDefinition } from './_factory.js'

export function dashboardTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_dashboard_onboarding',
			title: 'Get Onboarding Data',
			description: 'Get store setup wizard status: completed steps and remaining count.',
			schema: z.object({}),
			endpoint: '/dashboard',
		}),

		getTool(client, {
			name: 'fluentcart_dashboard_overview',
			title: 'Get Dashboard Overview',
			description:
				'Store dashboard tiles for the LAST 30 DAYS — product count, order count, revenue and refund totals. The window is fixed and takes no arguments, so these are not all-time figures. For a period you choose, use fluentcart_report_sales_summary. Values in cents.',
			schema: z.object({}),
			endpoint: '/dashboard/stats',
		}),
	]
}
