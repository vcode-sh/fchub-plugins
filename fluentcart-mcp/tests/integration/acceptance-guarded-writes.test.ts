import { afterAll, beforeAll, describe, expect, it } from 'vitest'
import type { FluentCartClient } from '../../src/api/client.js'
import type { GuardRuntime } from '../../src/security/guard-config.js'
import type { ToolDefinition } from '../../src/tools/_factory.js'
import { orderRefundTools } from '../../src/tools/orders-refunds.js'
import { subscriptionCancellationTools } from '../../src/tools/subscriptions-cancellation.js'
import { CleanupLedger } from './support/cleanup-ledger.js'
import {
	describeCancellationFixture,
	describeRefundFixture,
} from './support/guarded-payment-fixture.js'
import { getLiveClient } from './support/live-client.js'
import { getLiveRun } from './support/live-run.js'

/**
 * Guarded money actions against the live store.
 *
 * This lane creates nothing and writes nothing. Both guarded actions need a fixture the API
 * cannot clean up, so both are recorded as BLOCKED with their exact prerequisites rather than
 * run against records that would outlive the test.
 *
 * BLOCKER — guarded refund, execution and preview alike. A refund needs a persisted succeeded
 * charge: `OrderController::refundOrder` resolves `refund_info.transaction_id` with `findOrFail`,
 * and the preview refuses an order without one. Creating the order persists that charge row,
 * `DELETE /orders/{id}` does not cascade to it, and FluentCart 1.5.5 registers no DELETE route
 * for a transaction — so the row can be neither removed nor proven gone. Earlier revisions of
 * this suite built exactly that fixture and left an orphan transaction behind on every run.
 *
 * BLOCKER — guarded cancellation. No admin REST route creates a subscription; only
 * `POST /checkout/place-order` with a completed gateway payment flow does, and a pre-existing
 * subscription belongs to a real customer and may never be substituted.
 *
 * BLOCKER — refund amount units. `refund_info.amount` passes through `Helper::toCent()`
 * (`round(floatval($amount) * 100)`), so the route takes major units, while
 * `fluentcart_order_refund` documents, validates and sends the smallest unit and compares it
 * against `total_paid`/`total_refund`, which FluentCart reports in cents. A preview approving
 * "refund 100 of 10000 remaining" would send 100 and the store would refund 10000 cents. That
 * divergence must be fixed in src/tools/orders-refunds.ts before any execution is attempted.
 *
 * What is proved elsewhere: the durable-claim guarantees in tests/acceptance/guard-state.test.mjs
 * (real processes, real SIGKILL, real state directory), and the token, fingerprint and refusal
 * mechanics in tests/security and tests/tools, which need no store fixture.
 */

const run = getLiveRun()
const ledger = new CleanupLedger()

let writeCount = 0
let client: FluentCartClient
let guard: GuardRuntime

/** Counts every non-GET request so an accidental mutation in this lane cannot pass unnoticed. */
function countingClient(inner: FluentCartClient): FluentCartClient {
	const wrap =
		<T extends (...args: never[]) => unknown>(call: T) =>
		(...args: Parameters<T>) => {
			writeCount += 1
			return call(...args)
		}
	return {
		...inner,
		get: inner.get,
		post: wrap(inner.post),
		put: wrap(inner.put),
		delete: wrap(inner.delete),
	} as FluentCartClient
}

beforeAll(() => {
	expect(process.env.FLUENTCART_ALLOW_LIVE_GATEWAY_ACTIONS).toBeUndefined()
	client = countingClient(getLiveClient())
	guard = {
		secret: new Uint8Array(32).fill(7),
		// Deliberately inert: this lane must never open or complete a claim.
		ledger: {
			inspect: async () => ({ kind: 'none' }),
			lockEntity: async () => ({ kind: 'ambiguous' }),
			begin: async () => {
				throw new Error('this lane never begins a claim')
			},
			complete: async () => {
				throw new Error('this lane never completes a claim')
			},
			releaseEntity: async () => undefined,
		},
		allowLiveGatewayActions: false,
		now: () => Date.now(),
		randomUUID: () => `00000000-0000-4000-8000-${String(Date.now()).slice(-12)}`,
	}
})

afterAll(async () => {
	// Nothing should ever be registered, but the ledger runs regardless: if a future change adds
	// a fixture, cleanup failure must fail the suite rather than pass quietly.
	await ledger.cleanup()
})

describe('guarded fixtures', () => {
	it('records why a refundable test-mode order cannot be owned by this run', () => {
		const blocked = describeRefundFixture()

		expect(blocked.kind).toBe('blocked')
		expect(blocked.prerequisite).toBe(
			'Executing a guarded refund requires a persisted test-mode charge, and FluentCart 1.5.5 exposes no route to remove a transaction, so the fixture cannot prove cleanup.',
		)
		expect(blocked.evidence).toContain(
			'route inventory: no DELETE route exists for a transaction in FluentCart 1.5.5 + Pro 1.5.4',
		)
	})

	it('records why a subscription cannot be owned by this run', () => {
		const blocked = describeCancellationFixture()

		expect(blocked.kind).toBe('blocked')
		expect(blocked.prerequisite).toContain('run-owned subscription')
		expect(blocked.evidence).toContain(
			'no POST /subscriptions route exists in the captured 1.5.5 + Pro 1.5.4 inventory',
		)
	})

	it('registers nothing for cleanup, because it creates nothing', () => {
		expect(ledger.size).toBe(0)
		expect(run.id).toMatch(/^mcp-/)
	})
})

describe('guarded tools stay classified and unexecuted', () => {
	it('exposes both guarded actions as real money needing the guard', () => {
		const [refund] = orderRefundTools(client, guard)
		const [cancel] = subscriptionCancellationTools(client, guard)

		for (const tool of [refund, cancel] as ToolDefinition[]) {
			expect(tool.safety.risk).toBe('real-money')
			expect(tool.safety.idempotency).toBe('guard-required')
			expect(tool.annotations.readOnlyHint).toBe(false)
		}
		expect(refund?.name).toBe('fluentcart_order_refund')
		expect(cancel?.name).toBe('fluentcart_subscription_cancel')
	})

	it.skip('previews a guarded refund (BLOCKED: transaction rows cannot be cleaned up)', () => {
		expect.unreachable('Blocked until FluentCart exposes a route that removes a transaction.')
	})

	it.skip('executes one approved test-mode refund (BLOCKED: transaction cleanup and amount units)', () => {
		expect.unreachable('Blocked until transaction cleanup exists and the amount unit is fixed.')
	})

	it.skip('executes one approved test-mode cancellation (BLOCKED: no run-owned subscription)', () => {
		expect.unreachable('Blocked until a subscription can be created by the running test.')
	})
})

describe('the lane itself is inert', () => {
	it('issues no write request to the store', () => {
		expect(writeCount).toBe(0)
	})
})
