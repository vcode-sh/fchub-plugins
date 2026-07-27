import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import type { GuardRuntime } from '../security/guard-config.js'
import {
	type ActionState,
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
 * The guarded replacement for the raw refund call.
 *
 * The removed handler took an amount, guessed a transaction when none was given and posted
 * straight to the gateway: nothing stopped a retried tool call from refunding twice, and
 * nothing rechecked that the amount was still refundable when the call arrived.
 *
 * Amounts are in the smallest currency unit, the unit FluentCart reports `total_paid` and
 * `total_refund` in. Accepting major units would mean guessing each currency's exponent, and a
 * wrong guess is a hundredfold refund.
 */

interface RefundFields {
	order_id: number
	amount: number
	transaction_id?: number
	reason?: string
}

interface RefundState extends ActionState {
	transactionId: number
}

const refundSchema = z.object({
	dry_run: z
		.boolean()
		.describe(
			'True previews the refund and returns a confirm_token. False executes it and additionally requires confirm_token and idempotency_key.',
		),
	order_id: z.number().int().positive().describe('Order ID to refund.'),
	amount: z
		.number()
		.describe(
			'Refund amount in the smallest currency unit (e.g. 4000 = 40.00 PLN). Must be a positive whole number and no greater than the remaining refundable amount.',
		),
	transaction_id: z
		.number()
		.int()
		.positive()
		.optional()
		.describe(
			'Succeeded charge transaction on this order to refund. Required when the order has more than one; never guessed across orders.',
		),
	reason: z.string().max(500).optional().describe('Reason recorded against the refund.'),
	confirm_token: z
		.string()
		.optional()
		.describe('Token from the matching dry run. Required when dry_run is false.'),
	idempotency_key: z
		.string()
		.optional()
		.describe(
			'Unique key for this attempt. Required when dry_run is false. Reusing a key replays the recorded result instead of refunding again.',
		),
})

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

interface LoadedOrder {
	order: Record<string, unknown>
	charges: Record<string, unknown>[]
}

async function loadOrder(client: FluentCartClient, orderId: number): Promise<LoadedOrder> {
	const response = await client.get(`/orders/${orderId}`)
	const wrapper = (response.data ?? {}) as Record<string, unknown>
	const order = (wrapper.order ?? wrapper) as Record<string, unknown>
	if (readNumber(order.id) === null) {
		throw new GuardedActionError('INVALID_REQUEST', `Order ${orderId} was not found.`)
	}

	const transactions = Array.isArray(order.transactions)
		? (order.transactions as Record<string, unknown>[])
		: []
	// Only charges on this order, and only succeeded ones. A refund against another order's
	// transaction is the kind of mistake that is very hard to unwind.
	const charges = transactions.filter(
		(entry) => entry.transaction_type === 'charge' && entry.status === 'succeeded',
	)
	return { order, charges }
}

function selectCharge(
	charges: Record<string, unknown>[],
	requested: number | undefined,
): Record<string, unknown> {
	const ids = charges.map((entry) => readNumber(entry.id)).filter((id) => id !== null)

	if (requested !== undefined) {
		const match = charges.find((entry) => readNumber(entry.id) === requested)
		if (!match) {
			throw new GuardedActionError(
				'INVALID_REQUEST',
				`Transaction ${requested} is not a succeeded charge on this order. Eligible transactions: ${ids.join(', ') || 'none'}.`,
			)
		}
		return match
	}

	if (charges.length === 0) {
		const detail = 'This order has no succeeded charge transaction to refund.'
		throw new GuardedActionError('INVALID_REQUEST', detail)
	}
	if (charges.length > 1) {
		// Picking "the first" among several charges is how you refund the wrong payment.
		const detail = `This order has several succeeded charges (${ids.join(', ')}). Pass transaction_id to choose one.`
		throw new GuardedActionError('INVALID_REQUEST', detail)
	}
	return charges[0] as Record<string, unknown>
}

/** Unknown mode counts as live: a missing field must never unlock a real gateway call. */
function isLive(order: Record<string, unknown>, charge: Record<string, unknown>): boolean {
	const mode =
		readText(charge.payment_mode) ??
		readText(charge.mode) ??
		readText(order.payment_mode) ??
		readText(order.mode)
	return mode === null || mode.toLowerCase() !== 'test'
}

function assertRefundable(amount: number, remaining: number): void {
	if (!Number.isInteger(amount) || amount <= 0) {
		const detail = `amount must be a positive whole number in the smallest currency unit; received ${amount}.`
		throw new GuardedActionError('INVALID_REQUEST', detail)
	}
	if (remaining <= 0) {
		throw new GuardedActionError('INVALID_REQUEST', 'This order has nothing left to refund.')
	}
	if (amount > remaining) {
		const detail = `amount ${amount} exceeds the remaining refundable ${remaining} (smallest currency unit).`
		throw new GuardedActionError('INVALID_REQUEST', detail)
	}
}

function refundAction(client: FluentCartClient): GuardedAction<RefundFields, RefundState> {
	return {
		tool: 'fluentcart_order_refund',
		entityRef: (fields) => `order:${fields.order_id}`,

		async loadState(fields) {
			const { order, charges } = await loadOrder(client, fields.order_id)
			const charge = selectCharge(charges, fields.transaction_id)

			const paid = readNumber(order.total_paid)
			const refunded = readNumber(order.total_refund) ?? 0
			if (paid === null) {
				throw new GuardedActionError(
					'INVALID_REQUEST',
					'This order does not report a paid total, so the refundable amount cannot be established.',
				)
			}

			const remaining = paid - refunded
			assertRefundable(fields.amount, remaining)

			const transactionId = readNumber(charge.id) as number
			const paymentStatus = readText(order.payment_status)
			const chargeStatus = readText(charge.status)

			return {
				transactionId,
				live: isLive(order, charge),
				stateFingerprint: fingerprint('order-refund', {
					order_id: fields.order_id,
					payment_status: paymentStatus,
					total_paid: paid,
					total_refund: refunded,
					transaction_id: transactionId,
					transaction_status: chargeStatus,
					amount: fields.amount,
				}),
				preview: {
					action: 'refund',
					order_id: fields.order_id,
					currency: readText(order.currency),
					payment_status: paymentStatus,
					amount_unit: 'smallest currency unit (e.g. 4000 = 40.00 in a two-decimal currency)',
					total_paid: paid,
					total_refunded: refunded,
					remaining_refundable: remaining,
					requested_amount: fields.amount,
					remaining_after_refund: remaining - fields.amount,
					reason: fields.reason ?? null,
					transaction: {
						id: transactionId,
						status: chargeStatus,
						payment_method: readText(charge.payment_method) ?? readText(order.payment_method),
						gateway_mode: readText(charge.payment_mode) ?? readText(order.payment_mode),
					},
					effect:
						'Executing sends one refund request to the payment gateway. It is not reversible.',
				},
			}
		},

		async mutate(fields, state) {
			const refundInfo: Record<string, unknown> = {
				transaction_id: state.transactionId,
				amount: fields.amount,
			}
			if (fields.reason !== undefined) refundInfo.reason = fields.reason
			await client.post(`/orders/${fields.order_id}/refund`, { refund_info: refundInfo })
		},

		async reread(fields): Promise<Record<string, GuardedResultValue>> {
			const { order } = await loadOrder(client, fields.order_id)
			return {
				order_id: fields.order_id,
				refunded_amount: fields.amount,
				currency: readText(order.currency),
				payment_status: readText(order.payment_status),
				total_paid: readNumber(order.total_paid),
				total_refunded: readNumber(order.total_refund),
			}
		},
	}
}

export function orderRefundTools(
	client: FluentCartClient,
	guard: GuardRuntime | null,
): ToolDefinition[] {
	const action = refundAction(client)

	return [
		createTool(client, {
			name: 'fluentcart_order_refund',
			title: 'Refund Order',
			// Composite: the preview reads the order and its transactions to build the state
			// fingerprint, and only the execute call issues the refund. Every route the handler
			// may touch is declared, so a store missing any of them omits the tool rather than
			// failing halfway through a money movement.
			routes: {
				kind: 'composite',
				variants: [
					{ method: 'GET', path: '/orders/{param}' },
					{ method: 'GET', path: '/orders/{param}/transactions' },
					{ method: 'POST', path: '/orders/{param}/refund' },
				],
			},
			description:
				'Refund an order through the payment gateway. Two calls: dry_run:true returns a preview with ' +
				'the refundable balance, the selected charge transaction, the gateway mode and a confirm_token ' +
				'valid for 300 seconds; dry_run:false with the identical fields, that token and a fresh ' +
				'idempotency_key executes exactly one refund. Amounts are in the smallest currency unit ' +
				'(e.g. 4000 = 40.00 PLN). Reusing an idempotency key replays the recorded result rather than ' +
				'refunding again. Moves real money and cannot be undone.',
			schema: refundSchema,
			handler: async (_client, input) => {
				const parsed = parseGuardedInput(refundSchema, input)
				const fields: RefundFields = {
					order_id: parsed.order_id,
					amount: parsed.amount,
					...(parsed.transaction_id !== undefined ? { transaction_id: parsed.transaction_id } : {}),
					...(parsed.reason !== undefined ? { reason: parsed.reason } : {}),
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
