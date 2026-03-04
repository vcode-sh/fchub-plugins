import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { getTool, putTool, type ToolDefinition } from './_factory.js'

export function subscriptionTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_subscription_list',
			title: 'List Subscriptions',
			description:
				'List subscriptions with optional filtering. ' +
				'Statuses: active, trialing, paused, intended, failing, past_due, expiring, canceled, expired, completed.',
			schema: z.object({
				page: z.number().optional().describe('Page number (default: 1)'),
				per_page: z.number().max(50).optional().describe('Results per page (default: 10, max: 50)'),
				search: z.string().optional().describe('Search subscriptions by keyword'),
			}),
			endpoint: '/subscriptions',
		}),

		getTool(client, {
			name: 'fluentcart_subscription_get',
			title: 'Get Subscription',
			description:
				'Get subscription details including billing dates, gateway info, and payment history. Amounts in cents.',
			schema: z.object({
				subscription_id: z.number().describe('Subscription ID'),
			}),
			endpoint: '/subscriptions/:subscription_id',
		}),

		putTool(client, {
			name: 'fluentcart_subscription_cancel',
			title: 'Cancel Subscription',
			description:
				'Cancel a subscription. Can cancel immediately or at end of billing period. May trigger refund.',
			schema: z.object({
				order_id: z.number().describe('Order ID that owns the subscription'),
				subscription_id: z.number().describe('Subscription ID to cancel'),
				cancel_reason: z
					.string()
					.optional()
					.describe('Cancellation reason — strongly recommended for audit trail'),
				cancel_immediately: z
					.boolean()
					.optional()
					.describe('Cancel immediately (true) or at end of billing period (false)'),
			}),
			endpoint: '/orders/:order_id/subscriptions/:subscription_id/cancel',
		}),

		putTool(client, {
			name: 'fluentcart_subscription_fetch',
			title: 'Fetch Subscription from Gateway',
			description:
				'Sync subscription data from the payment gateway. Use when state may be out of sync.',
			schema: z.object({
				order_id: z.number().describe('Order ID that owns the subscription'),
				subscription_id: z.number().describe('Subscription ID to sync'),
			}),
			endpoint: '/orders/:order_id/subscriptions/:subscription_id/fetch',
		}),

		// NOTE: subscription_pause, subscription_resume, and subscription_reactivate
		// have been removed — the FluentCart backend returns "Not available yet" for all three.
	]
}
