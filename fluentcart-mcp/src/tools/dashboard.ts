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
				'Dashboard overview: product count, order count, revenue, and refund totals. Values in cents.',
			schema: z.object({}),
			endpoint: '/dashboard/stats',
		}),
	]
}
