import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { getTool, putTool, type ToolDefinition } from './_factory.js'

export function subscriptionTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_subscription_list',
			title: 'List Subscriptions',
			description:
				'Retrieve a paginated list of subscriptions with optional filtering. ' +
				'Returns subscription summaries with status, billing interval, and amounts. ' +
				'Monetary values in smallest currency unit (cents). ' +
				'Statuses: active, trialing, paused, intended, failing, past_due, expiring, canceled, expired, completed.',
			schema: z.object({
				page: z.number().optional().describe('Page number (default: 1)'),
				per_page: z.number().optional().describe('Results per page (default: 10)'),
				search: z.string().optional().describe('Search subscriptions by keyword'),
			}),
			endpoint: '/subscriptions',
		}),

		getTool(client, {
			name: 'fluentcart_subscription_get',
			title: 'Get Subscription',
			description:
				'Retrieve detailed information about a specific subscription including billing dates, ' +
				'gateway info, and payment history. Monetary values in smallest currency unit (cents). ' +
				'Statuses: active, trialing, paused, intended, failing, past_due, expiring, canceled, expired, completed.',
			schema: z.object({
				subscription_id: z.number().describe('Subscription ID'),
			}),
			endpoint: '/subscriptions/:subscription_id',
		}),

		putTool(client, {
			name: 'fluentcart_subscription_cancel',
			title: 'Cancel Subscription',
			description:
				'Cancel a subscription associated with an order. Can cancel immediately or at end of billing period. ' +
				'Side effect: may trigger refund depending on gateway and cancel_immediately flag.',
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
				'Fetch and synchronise subscription data from the payment gateway. ' +
				'Use when subscription state may be out of sync with the gateway (e.g. after manual gateway changes).',
			schema: z.object({
				order_id: z.number().describe('Order ID that owns the subscription'),
				subscription_id: z.number().describe('Subscription ID to sync'),
			}),
			endpoint: '/orders/:order_id/subscriptions/:subscription_id/fetch',
		}),

		putTool(client, {
			name: 'fluentcart_subscription_pause',
			title: 'Pause Subscription',
			description:
				'Pause an active subscription. Optionally set a date to auto-resume. ' +
				'Only active subscriptions can be paused.',
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
			description:
				'Reactivate a cancelled or expired subscription. ' +
				'Creates a new billing cycle from the reactivation date.',
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
