import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { getTool, type ToolDefinition } from './_factory.js'

export function dashboardTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_dashboard_onboarding',
			title: 'Get Onboarding Data',
			description:
				'Retrieve the store onboarding/setup wizard status. ' +
				'Returns which setup steps have been completed and how many remain.',
			schema: z.object({}),
			endpoint: '/dashboard',
		}),

		getTool(client, {
			name: 'fluentcart_dashboard_overview',
			title: 'Get Dashboard Overview',
			description:
				'Retrieve dashboard overview including product count, order count, revenue, and refund totals. ' +
				'Monetary values are in the smallest currency unit (cents) when has_currency is true.',
			schema: z.object({}),
			endpoint: '/dashboard/stats',
		}),
	]
}
