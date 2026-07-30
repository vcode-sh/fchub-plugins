import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { FluentCartApiError } from '../api/errors.js'
import { createTool, type ToolDefinition } from './_factory.js'
import { composite, op } from './endpoints.js'

const UPDATABLE_STATUSES = ['active', 'paused', 'trialing', 'past_due'] as const

function asRecord(value: unknown): Record<string, unknown> | null {
	return value !== null && typeof value === 'object' && !Array.isArray(value)
		? (value as Record<string, unknown>)
		: null
}

function subscriptionFromDetail(value: unknown): Record<string, unknown> | null {
	const body = asRecord(value)
	if (!body) return null
	const direct = asRecord(body.subscription)
	if (direct) return direct
	return asRecord(asRecord(body.data)?.subscription)
}

function integerValue(value: unknown): number | null {
	if (typeof value === 'number' && Number.isInteger(value)) return value
	if (typeof value === 'string' && /^\d+$/.test(value)) return Number(value)
	return null
}

function hasLinkedLicences(value: unknown): boolean {
	if (value === undefined || value === null) return false
	if (Array.isArray(value)) return value.length > 0
	const record = asRecord(value)
	if (!record) return true
	if (Array.isArray(record.data)) return record.data.length > 0
	return Object.keys(record).length > 0
}

function guardedUpdateData(
	subscription: Record<string, unknown> | null,
	expectedBillTimes: number,
	expectedBillCount: number,
	billTimes: number,
): { bill_times: number; status: string } {
	if (!subscription) {
		throw new FluentCartApiError(
			'SERVER_ERROR',
			'The subscription detail response did not contain a subscription to guard this update.',
			502,
		)
	}

	const currentBillTimes = integerValue(subscription.bill_times)
	const currentBillCount = integerValue(subscription.bill_count)
	if (currentBillTimes === null || currentBillCount === null) {
		throw new FluentCartApiError(
			'SERVER_ERROR',
			'The subscription detail response did not contain integer bill_times and bill_count values.',
			502,
		)
	}
	if (currentBillTimes !== expectedBillTimes || currentBillCount !== expectedBillCount) {
		throw new FluentCartApiError(
			'VALIDATION_ERROR',
			'Subscription bill_times or bill_count changed since it was read; fetch it again before updating.',
			409,
		)
	}
	if (hasLinkedLicences(subscription.licenses)) {
		throw new FluentCartApiError(
			'VALIDATION_ERROR',
			'Subscriptions with linked licences cannot be updated because FluentCart Pro may change licence state as a side effect.',
			422,
		)
	}
	if (!['manual', 'system'].includes(String(subscription.collection_method))) {
		throw new FluentCartApiError(
			'VALIDATION_ERROR',
			'Only store-billed subscriptions with a manual or system collection method can be updated.',
			422,
		)
	}
	if (currentBillTimes > 0 && currentBillTimes <= currentBillCount) {
		throw new FluentCartApiError(
			'VALIDATION_ERROR',
			'The current finite bill_times ceiling is already at or below bill_count and cannot be safely updated.',
			422,
		)
	}
	if (billTimes !== 0 && billTimes <= currentBillCount) {
		throw new FluentCartApiError(
			'VALIDATION_ERROR',
			'For a finite subscription, bill_times must be strictly greater than bill_count to avoid an end-of-term transition.',
			422,
		)
	}

	const status = subscription.status
	if (
		typeof status !== 'string' ||
		!UPDATABLE_STATUSES.includes(status as (typeof UPDATABLE_STATUSES)[number])
	) {
		throw new FluentCartApiError(
			'VALIDATION_ERROR',
			'The current subscription status cannot safely carry through FluentCart’s update endpoint.',
			422,
		)
	}
	return { bill_times: billTimes, status }
}

function verifiedResult(
	value: unknown,
	orderId: number,
	subscriptionId: number,
	requested: { bill_times: number; status: string },
	expectedBillCount: number,
	expectedCollectionMethod: string,
	previousBillTimes: number,
): Record<string, unknown> {
	const subscription = subscriptionFromDetail(value)
	const billTimes = subscription ? integerValue(subscription.bill_times) : null
	const billCount = subscription ? integerValue(subscription.bill_count) : null
	if (
		!subscription ||
		billTimes !== requested.bill_times ||
		billCount !== expectedBillCount ||
		subscription.status !== requested.status ||
		subscription.collection_method !== expectedCollectionMethod
	) {
		throw new FluentCartApiError(
			'SERVER_ERROR',
			'The subscription update did not read back with the requested bill_times and unchanged bill_count, status, and collection method.',
			502,
		)
	}
	return {
		message: 'Subscription bill times updated and verified.',
		order_id: orderId,
		previous_bill_times: previousBillTimes,
		subscription: {
			id: subscriptionId,
			status: requested.status,
			collection_method: expectedCollectionMethod,
			bill_times: billTimes,
			bill_count: billCount,
		},
	}
}

function ambiguousMutationError(
	orderId: number,
	subscriptionId: number,
	previousBillTimes: number,
	requestedBillTimes: number,
): FluentCartApiError {
	return new FluentCartApiError(
		'SERVER_ERROR',
		'The subscription update outcome could not be verified. The mutation may have applied.',
		502,
		{
			mutation_may_have_applied: true,
			order_id: orderId,
			subscription_id: subscriptionId,
			previous_bill_times: previousBillTimes,
			requested_bill_times: requestedBillTimes,
			guidance:
				'Fetch fluentcart_subscription_get before deciding what to do next. Do not retry blindly.',
		},
	)
}

/**
 * The only subscription edit with a bounded restore contract in FluentCart 1.6.
 *
 * `recurring_total`, `billing_interval`, `next_billing_date` and status transitions each alter a
 * pending invoice, scheduled charge, billing date or cancellation state. FluentCart exposes no
 * complete restore for those side effects. `bill_times` changes only the finite-plan ceiling; a
 * fresh detail read provides a best-effort preflight and the prior value can be written back.
 * FluentCart 1.6 exposes no atomic version precondition, so the handler never claims the preflight
 * closes every concurrent-update race.
 */
export function subscriptionUpdateTools(client: FluentCartClient): ToolDefinition[] {
	return [
		createTool(client, {
			name: 'fluentcart_subscription_update',
			routes: composite(
				op('GET', '/subscriptions/{param}'),
				op('PUT', '/orders/{param}/subscriptions/{param}/update'),
			),
			title: 'Update Subscription Bill Times',
			description:
				'Change only the total billing-cycle limit for a store-billed subscription. ' +
				'Read the subscription first and pass its current bill_times and bill_count for a best-effort preflight check. FluentCart does not provide an atomic version precondition, so an ambiguous failure must be re-read rather than blindly retried. ' +
				'Subscriptions with linked licences fail closed because FluentCart Pro can change licence state on any subscription update. ' +
				'0 means unlimited billing cycles. Amount, schedule and status changes are intentionally unavailable because FluentCart 1.6 can change invoices or scheduled charges as a side effect.',
			schema: z
				.object({
					order_id: z.number().int().positive().describe('Parent subscription order ID'),
					subscription_id: z.number().int().positive().describe('Subscription ID'),
					expected_bill_times: z
						.number()
						.int()
						.min(0)
						.describe('bill_times returned by a fresh fluentcart_subscription_get read'),
					expected_bill_count: z
						.number()
						.int()
						.min(0)
						.describe('bill_count returned by the same fresh fluentcart_subscription_get read'),
					bill_times: z
						.number()
						.int()
						.min(0)
						.describe('New billing-cycle limit; 0 means unlimited'),
				})
				.strict()
				.refine((input) => input.bill_times !== input.expected_bill_times, {
					message: 'bill_times must differ from expected_bill_times',
					path: ['bill_times'],
				}),
			handler: async (c, input) => {
				const orderId = input.order_id as number
				const subscriptionId = input.subscription_id as number
				const expectedBillTimes = input.expected_bill_times as number
				const expectedBillCount = input.expected_bill_count as number
				const billTimes = input.bill_times as number
				const currentResponse = await c.get(`/subscriptions/${subscriptionId}`, {})
				const currentSubscription = subscriptionFromDetail(currentResponse.data)
				const data = guardedUpdateData(
					currentSubscription,
					expectedBillTimes,
					expectedBillCount,
					billTimes,
				)
				const collectionMethod = String(currentSubscription?.collection_method)

				try {
					await c.put(`/orders/${orderId}/subscriptions/${subscriptionId}/update`, {
						data,
					})
				} catch {
					throw ambiguousMutationError(orderId, subscriptionId, expectedBillTimes, billTimes)
				}

				try {
					const verifiedResponse = await c.get(`/subscriptions/${subscriptionId}`, {})
					return verifiedResult(
						verifiedResponse.data,
						orderId,
						subscriptionId,
						data,
						expectedBillCount,
						collectionMethod,
						expectedBillTimes,
					)
				} catch {
					throw ambiguousMutationError(orderId, subscriptionId, expectedBillTimes, billTimes)
				}
			},
		}),
	]
}
