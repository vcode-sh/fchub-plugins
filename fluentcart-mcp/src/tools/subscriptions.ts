import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { getTool, putTool, type ToolDefinition } from './_factory.js'

function transformSubscription(item: Record<string, unknown>): Record<string, unknown> {
	const customer = item.customer as Record<string, unknown> | undefined
	return {
		id: item.id,
		status: item.status,
		item_name: item.item_name,
		billing_interval: item.billing_interval,
		recurring_amount: item.recurring_amount,
		recurring_total: item.recurring_total,
		signup_fee: item.signup_fee,
		quantity: item.quantity,
		bill_times: item.bill_times,
		bill_count: item.bill_count,
		trial_days: item.trial_days,
		trial_ends_at: item.trial_ends_at,
		next_billing_date: item.next_billing_date,
		current_period_start: item.current_period_start,
		current_period_end: item.current_period_end,
		expire_at: item.expire_at,
		canceled_at: item.canceled_at,
		current_payment_method: item.current_payment_method,
		parent_order_id: item.parent_order_id,
		product_id: item.product_id,
		variation_id: item.variation_id,
		customer: customer
			? { id: customer.id, full_name: customer.full_name, email: customer.email }
			: undefined,
		created_at: item.created_at,
	}
}

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
				active_view: z
					.string()
					.optional()
					.describe(
						'Filter by status: active, trialing, paused, intended, failing, past_due, expiring, canceled, expired',
					),
			}),
			endpoint: '/subscriptions',
			transform: (data: unknown) => {
				const resp = data as Record<string, unknown>
				if (resp && Array.isArray(resp.data)) {
					resp.data = (resp.data as Record<string, unknown>[]).map(transformSubscription)
				}
				return resp
			},
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
			transform: (data: unknown) => {
				const resp = data as Record<string, unknown>
				if (resp?.subscription) {
					const sub = resp.subscription as Record<string, unknown>
					resp.subscription = {
						...transformSubscription(sub),
						vendor_subscription_id: sub.vendor_subscription_id,
						vendor_customer_id: sub.vendor_customer_id,
						vendor_plan_id: sub.vendor_plan_id,
						collection_method: sub.collection_method,
						restored_at: sub.restored_at,
						original_plan: sub.original_plan,
						payment_info: sub.payment_info,
						billingInfo: sub.billingInfo,
						url: sub.url,
						related_orders: sub.related_orders,
						labels: sub.labels,
					}
				}
				return resp
			},
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
