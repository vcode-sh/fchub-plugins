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
				reason: z.string().optional().describe('Cancellation reason'),
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

		putTool(client, {
			name: 'fluentcart_subscription_pause',
			title: 'Pause Subscription',
			description: 'Pause an active subscription. Optionally set a date to auto-resume.',
			schema: z.object({
				order_id: z.number().describe('Order ID that owns the subscription'),
				subscription_id: z.number().describe('Subscription ID to pause'),
				pause_until: z
					.string()
					.optional()
					.describe('ISO 8601 date to auto-resume the subscription'),
				reason: z.string().optional().describe('Reason for pausing'),
			}),
			endpoint: '/orders/:order_id/subscriptions/:subscription_id/pause',
		}),

		putTool(client, {
			name: 'fluentcart_subscription_reactivate',
			title: 'Reactivate Subscription',
			description: 'Reactivate a cancelled or expired subscription. Creates a new billing cycle.',
			schema: z.object({
				order_id: z.number().describe('Order ID that owns the subscription'),
				subscription_id: z.number().describe('Subscription ID to reactivate'),
				reactivation_date: z.string().optional().describe('ISO 8601 reactivation date'),
			}),
			endpoint: '/orders/:order_id/subscriptions/:subscription_id/reactivate',
		}),

		putTool(client, {
			name: 'fluentcart_subscription_resume',
			title: 'Resume Subscription',
			description: 'Resume a paused subscription. Only paused subscriptions can be resumed.',
			schema: z.object({
				order_id: z.number().describe('Order ID that owns the subscription'),
				subscription_id: z.number().describe('Subscription ID to resume'),
			}),
			endpoint: '/orders/:order_id/subscriptions/:subscription_id/resume',
		}),
	]
}
