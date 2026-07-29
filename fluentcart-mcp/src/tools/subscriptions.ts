import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { getTool, putTool, type ToolDefinition } from './_factory.js'

/**
 * The subscription rows in a list response, whichever envelope the store used.
 *
 * `GET /subscriptions` answers `{data: {current_page, data: [...]}}` — the rows are two levels
 * down. The list transform used to guard on `Array.isArray(resp.data)`, which is false for that
 * shape, so the projection below never ran once: every raw row shipped, including 1,773 characters
 * of gateway `meta` (Redsys references, transaction UUIDs, intent phases) across four
 * subscriptions, 28% of the payload. `fluentcart_subscription_get` was unaffected because it
 * guards on a key that does exist.
 */
function subscriptionRows(payload: Record<string, unknown>): Record<string, unknown>[] | null {
	if (Array.isArray(payload.data)) return payload.data as Record<string, unknown>[]

	const nested = payload.data as Record<string, unknown> | undefined
	if (nested && Array.isArray(nested.data)) return nested.data as Record<string, unknown>[]

	return null
}

/**
 * Reduce each related order to what identifies it.
 *
 * FluentCart returns the full order record for every order attached to a subscription, including
 * `ip_address`, the internal `uuid` and a `config` blob carrying the payment-session id. A caller
 * that wants an order in full has `fluentcart_order_get`; here it needs enough to recognise and
 * fetch one.
 */
function summariseRelatedOrders(value: unknown): unknown {
	if (!Array.isArray(value)) return value

	return value.map((entry) => {
		if (entry === null || typeof entry !== 'object') return entry
		const order = entry as Record<string, unknown>
		return {
			id: order.id,
			status: order.status,
			payment_status: order.payment_status,
			total_amount: order.total_amount,
			currency: order.currency,
			created_at: order.created_at,
		}
	})
}

function transformSubscription(item: Record<string, unknown>): Record<string, unknown> {
	const customer = item.customer as Record<string, unknown> | undefined
	// fct_subscriptions has no currency column — it lives inside the JSON `config` blob, and it is
	// the only place to read it. Lifting it out keeps the one useful field from a payload that is
	// otherwise gateway bookkeeping.
	const config = item.config as Record<string, unknown> | undefined
	return {
		id: item.id,
		currency: config?.currency,
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

/**
 * The nine statuses FluentCart will actually filter on.
 *
 * `SubscriptionFilter::tabsMap()` maps exactly these. Anything else falls through
 * `applyActiveViewFilter` with a null column, the WHERE is never applied, and the store answers
 * with EVERY subscription while nothing in the payload says the filter was ignored. `past_due` and
 * `completed` are real statuses a subscription can hold — `Status::getSubscriptionStatuses()` lists
 * both — but neither is in the map, and `active_view: 'past_due'` returned all four of this
 * store's subscriptions as though every one were overdue. A closed enum is the only defence: a
 * rejected argument is recoverable, a silently unfiltered table read as filtered is not.
 */
const FILTERABLE_VIEWS = [
	'active',
	'pending',
	'intended',
	'paused',
	'trialing',
	'canceled',
	'failing',
	'expiring',
	'expired',
] as const

export function subscriptionTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_subscription_list',
			title: 'List Subscriptions',
			description:
				'List subscriptions with optional filtering. ' +
				'Money fields — recurring_amount, recurring_total, signup_fee — are MINOR UNITS: 99900 with ' +
				'currency EUR is 999.00 EUR, not 99,900. Reading them as decimals overstates a plan by two ' +
				'orders of magnitude. ' +
				'Statuses: active, trialing, paused, intended, failing, past_due, expiring, canceled, expired, ' +
				'completed. Only nine of those are filterable with active_view; for past_due or completed pass ' +
				'the status as search, which matches the status column.',
			schema: z.object({
				page: z.number().optional().describe('Page number (default: 1)'),
				per_page: z.number().max(50).optional().describe('Results per page (default: 10, max: 50)'),
				search: z
					.string()
					.optional()
					.describe(
						'Keyword search. Matches the status column exactly, plus customer email, item name, payment method and gateway ids. This is the only way to select past_due or completed subscriptions.',
					),
				active_view: z
					.enum(FILTERABLE_VIEWS)
					.optional()
					.describe(
						'Filter by status. past_due and completed are deliberately absent: FluentCart maps neither, ignores the filter and returns every subscription unfiltered.',
					),
			}),
			endpoint: '/subscriptions',
			transform: (data: unknown) => {
				const resp = data as Record<string, unknown>
				const rows = resp ? subscriptionRows(resp) : null
				if (!rows) return resp

				const projected = rows.map(transformSubscription)
				if (Array.isArray(resp.data)) {
					resp.data = projected
				} else {
					resp.data = { ...(resp.data as Record<string, unknown>), data: projected }
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
						// Each related order arrives whole, carrying the customer's IP, the internal uuid
						// and a config blob holding the payment-session id. A subscription view needs to
						// name its orders, not restate them.
						related_orders: summariseRelatedOrders(sub.related_orders),
						labels: sub.labels,
					}
				}
				return resp
			},
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

		// NOTE: subscription_pause, subscription_resume and subscription_reactivate are deliberately
		// absent, and this is not an oversight to be corrected by anyone reading the route table.
		//
		// All three routes ARE registered — `PUT /orders/{order}/subscriptions/{subscription}/pause`
		// and siblings, in app/Modules/Subscriptions/Http/subscriptions-api.php lines 31-37 — so any
		// audit that reads route registrations will report them as available and recommend adding
		// them. One did. But every controller is a stub:
		//
		//   public function pauseSubscription(Request $request, Order $order, Subscription $subscription)
		//   {
		//       return $this->sendError(['message' => __('Not available yet', 'fluent-cart')]);
		//   }
		//
		// Verified in FluentCart 1.5.5 at
		// app/Modules/Subscriptions/Http/Controllers/SubscriptionController.php — pauseSubscription,
		// resumeSubscription and reactivateSubscription are identical three-line stubs. Exposing them
		// would add three tools that can only ever fail, which is worse than the gap.
		//
		// Re-check the controller bodies, not the route file, before adding them.
	]
}
