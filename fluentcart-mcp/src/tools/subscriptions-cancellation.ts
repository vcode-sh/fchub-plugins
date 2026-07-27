import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { sha256Hex } from '../security/encoding.js'
import type { GuardRuntime } from '../security/guard-config.js'
import {
	executeGuardedAction,
	fingerprint,
	type GuardedAction,
	GuardedActionError,
	parseGuardedInput,
	previewGuardedAction,
} from '../security/guarded-action.js'
import type { GuardedResultValue } from '../security/idempotency-ledger.js'
import { createTool, type ToolDefinition } from './_factory.js'

/**
 * The guarded replacement for the raw subscription cancel call.
 *
 * The removed handler advertised `cancel_immediately:false` as "cancel at the end of the
 * billing period". The verified 1.5.5 route cancels immediately and always has, so the flag was
 * a promise the store could not keep. It is now rejected rather than quietly ignored: an agent
 * that wanted an end-of-period cancellation must be told it is unavailable, not handed an
 * immediate one.
 */

interface CancelFields {
	order_id: number
	subscription_id: number
	cancel_reason: string
}

/** Statuses with nothing left to cancel. */
const ENDED_STATUSES = ['canceled', 'cancelled', 'expired', 'completed']

const cancelSchema = z.object({
	dry_run: z
		.boolean()
		.describe(
			'True previews the cancellation and returns a confirm_token. False executes it and additionally requires confirm_token and idempotency_key.',
		),
	order_id: z.number().int().positive().describe('Order ID that owns the subscription.'),
	subscription_id: z.number().int().positive().describe('Subscription ID to cancel.'),
	cancel_reason: z
		.string()
		.min(1)
		.max(500)
		.describe('Reason recorded against the cancellation. Required, and must not be empty.'),
	cancel_immediately: z
		.boolean()
		.optional()
		.describe(
			'Only true is accepted. FluentCart 1.5.5 cancels immediately; end-of-period cancellation is not supported by this route.',
		),
	confirm_token: z
		.string()
		.optional()
		.describe('Token from the matching dry run. Required when dry_run is false.'),
	idempotency_key: z
		.string()
		.optional()
		.describe(
			'Unique key for this attempt. Required when dry_run is false. Reusing a key replays the recorded result instead of cancelling again.',
		),
})

function parseInput(input: Record<string, unknown>) {
	const parsed = parseGuardedInput(cancelSchema, input)
	if (parsed.cancel_immediately === false) {
		throw new GuardedActionError(
			'INVALID_REQUEST',
			'cancel_immediately:false is not supported. This route cancels immediately, so the request would not do what it says.',
		)
	}
	return parsed
}

function readNumber(value: unknown): number | null {
	if (typeof value === 'number' && Number.isFinite(value)) return value
	if (typeof value === 'string' && value.trim() !== '' && Number.isFinite(Number(value))) {
		return Number(value)
	}
	return null
}

function readText(value: unknown): string | null {
	return typeof value === 'string' && value !== '' ? value : null
}

/** Unknown mode counts as live: a missing field must never unlock a real gateway call. */
function isLive(subscription: Record<string, unknown>, order: Record<string, unknown>): boolean {
	const mode =
		readText(subscription.payment_mode) ??
		readText(subscription.mode) ??
		readText(order.payment_mode) ??
		readText(order.mode)
	return mode === null || mode.toLowerCase() !== 'test'
}

/** The gateway's own subscription id never enters a token, a preview or the ledger. */
function vendorIdentity(subscription: Record<string, unknown>): string {
	const vendor = readText(subscription.vendor_subscription_id) ?? ''
	return sha256Hex('fluentcart-mcp/vendor-subscription/v1', vendor)
}

async function loadSubscription(
	client: FluentCartClient,
	subscriptionId: number,
): Promise<Record<string, unknown>> {
	const response = await client.get(`/subscriptions/${subscriptionId}`)
	const wrapper = (response.data ?? {}) as Record<string, unknown>
	const subscription = (wrapper.subscription ?? wrapper) as Record<string, unknown>
	if (readNumber(subscription.id) === null) {
		throw new GuardedActionError('INVALID_REQUEST', `Subscription ${subscriptionId} was not found.`)
	}
	return subscription
}

async function loadOrder(
	client: FluentCartClient,
	orderId: number,
): Promise<Record<string, unknown>> {
	const response = await client.get(`/orders/${orderId}`)
	const wrapper = (response.data ?? {}) as Record<string, unknown>
	const order = (wrapper.order ?? wrapper) as Record<string, unknown>
	if (readNumber(order.id) === null) {
		throw new GuardedActionError('INVALID_REQUEST', `Order ${orderId} was not found.`)
	}
	return order
}

function assertOwnership(
	subscription: Record<string, unknown>,
	order: Record<string, unknown>,
	fields: CancelFields,
): void {
	const parent = readNumber(subscription.parent_order_id)
	const orderId = readNumber(order.id)
	if (parent === null || orderId === null || parent !== orderId) {
		throw new GuardedActionError(
			'INVALID_REQUEST',
			`Subscription ${fields.subscription_id} does not belong to order ${fields.order_id}.`,
		)
	}
}

function assertCancellable(subscription: Record<string, unknown>): string | null {
	const status = readText(subscription.status)
	if (status !== null && ENDED_STATUSES.includes(status.toLowerCase())) {
		throw new GuardedActionError(
			'INVALID_REQUEST',
			`This subscription is already ${status}; there is nothing to cancel.`,
		)
	}
	return status
}

function cancelAction(client: FluentCartClient): GuardedAction<CancelFields> {
	return {
		tool: 'fluentcart_subscription_cancel',
		entityRef: (fields) => `subscription:${fields.subscription_id}`,

		async loadState(fields) {
			const subscription = await loadSubscription(client, fields.subscription_id)
			const order = await loadOrder(client, fields.order_id)
			assertOwnership(subscription, order, fields)

			const status = assertCancellable(subscription)
			const canceledAt = readText(subscription.canceled_at)

			return {
				live: isLive(subscription, order),
				stateFingerprint: fingerprint('subscription-cancel', {
					action: 'cancel',
					order_id: fields.order_id,
					subscription_id: fields.subscription_id,
					status,
					canceled_at: canceledAt,
					vendor_identity: vendorIdentity(subscription),
				}),
				preview: {
					action: 'cancel',
					order_id: fields.order_id,
					subscription_id: fields.subscription_id,
					status,
					canceled_at: canceledAt,
					item_name: readText(subscription.item_name),
					billing_interval: readText(subscription.billing_interval),
					recurring_amount: readNumber(subscription.recurring_amount),
					next_billing_date: readText(subscription.next_billing_date),
					payment_method:
						readText(subscription.current_payment_method) ?? readText(order.payment_method),
					gateway_mode: readText(subscription.payment_mode) ?? readText(order.payment_mode),
					cancels_immediately: true,
					cancel_reason: fields.cancel_reason,
					effect:
						'Executing cancels the subscription at the gateway immediately. Future renewals stop; no refund is issued.',
				},
			}
		},

		async mutate(fields) {
			await client.put(
				`/orders/${fields.order_id}/subscriptions/${fields.subscription_id}/cancel`,
				{
					cancel_reason: fields.cancel_reason,
				},
			)
		},

		async reread(fields): Promise<Record<string, GuardedResultValue>> {
			const subscription = await loadSubscription(client, fields.subscription_id)
			return {
				subscription_id: fields.subscription_id,
				order_id: fields.order_id,
				status: readText(subscription.status),
				canceled_at: readText(subscription.canceled_at),
				cancels_immediately: true,
			}
		},
	}
}

export function subscriptionCancellationTools(
	client: FluentCartClient,
	guard: GuardRuntime | null,
): ToolDefinition[] {
	const action = cancelAction(client)

	return [
		createTool(client, {
			name: 'fluentcart_subscription_cancel',
			title: 'Cancel Subscription',
			// Composite: the preview loads both the subscription and its owning order to verify the
			// relationship and pin state before the cancellation is issued.
			routes: {
				kind: 'composite',
				variants: [
					{ method: 'GET', path: '/orders/{param}' },
					{ method: 'GET', path: '/subscriptions/{param}' },
					{ method: 'PUT', path: '/orders/{param}/subscriptions/{param}/cancel' },
				],
			},
			description:
				'Cancel a subscription at the payment gateway. Two calls: dry_run:true returns a preview with ' +
				'the current status, payment method, gateway mode and a confirm_token valid for 300 seconds; ' +
				'dry_run:false with the identical fields, that token and a fresh idempotency_key executes the ' +
				'cancellation. Cancellation is immediate — FluentCart 1.5.5 has no end-of-period option, so ' +
				'cancel_immediately:false is rejected. Stops future renewals; issues no refund.',
			schema: cancelSchema,
			handler: async (_client, input) => {
				const parsed = parseInput(input)
				const fields: CancelFields = {
					order_id: parsed.order_id,
					subscription_id: parsed.subscription_id,
					cancel_reason: parsed.cancel_reason,
				}

				if (parsed.dry_run) return previewGuardedAction(action, fields, guard)
				return executeGuardedAction(action, fields, guard, {
					confirmToken: parsed.confirm_token ?? '',
					idempotencyKey: parsed.idempotency_key ?? '',
				})
			},
		}),
	]
}
