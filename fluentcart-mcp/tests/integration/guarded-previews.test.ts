import { describe, expect, it } from 'vitest'
import { canExposeTool } from '../../src/security/write-policy.js'
import { createAllTools } from '../../src/tools/index.js'
import { reviewedRisk } from '../../src/tools/risk-registry.js'
import { getLiveClient } from './support/live-client.js'
import { getLiveRun } from './support/live-run.js'

/**
 * Guarded previews against the live store — proving they are unreachable.
 *
 * Plan 02 Task 6 Step 4 asks this lane to run `dry_run:true` against run-owned test-mode
 * fixtures and verify no state changed. That is not the shape the release takes. Refund and
 * cancellation ship as `execution: 'none'`, so they are absent from every registry and there is
 * no preview path to exercise; and the fixture those previews would need cannot be cleaned up.
 *
 * So this lane asserts the withdrawal instead, which is the claim that actually needs proving
 * before shipping: the two money tools cannot be listed, described or called, in any write mode,
 * and this suite touches nothing in the store.
 *
 * BLOCKER — live preview cases. A refund preview requires a persisted succeeded charge:
 * `OrderController::refundOrder` resolves `refund_info.transaction_id` with `findOrFail`, and the
 * preview refuses an order without one. Creating that order persists a charge row,
 * `DELETE /orders/{id}` does not cascade to it, and FluentCart 1.5.5 registers no DELETE route
 * for a transaction — so the fixture cannot be proven gone. Earlier revisions of this suite built
 * it anyway and left an orphan transaction behind on every run.
 *
 * BLOCKER — cancellation previews. No admin REST route creates a subscription, and a
 * pre-existing one belongs to a real customer.
 *
 * Re-enabling either tool to make this lane meaningful is expressly not the fix.
 */

const run = getLiveRun()
const GUARDED_NAMES = ['fluentcart_order_refund', 'fluentcart_subscription_cancel']

const WRITE_MODES = ['disabled', 'reversible', 'guarded'] as const
const GUARD_STATES = [
	{ persistentState: false, signingSecret: false },
	{ persistentState: true, signingSecret: true },
]

/** Exactly the filter src/server.ts applies before registering anything. */
function exposedTools(
	writeMode: (typeof WRITE_MODES)[number],
	guard: (typeof GUARD_STATES)[number],
) {
	return createAllTools(getLiveClient())
		.filter((tool) => canExposeTool(tool.safety, { writeMode, guard }))
		.map((tool) => tool.name)
}

describe('guarded tools are absent from the running server', () => {
	it('classifies both money tools as real money that cannot execute', () => {
		const tools = createAllTools(getLiveClient())

		for (const name of GUARDED_NAMES) {
			const tool = tools.find((entry) => entry.name === name)
			expect(tool, `${name} should still be constructed`).toBeDefined()
			expect(reviewedRisk(name)).toBe('real-money')
			// The withdrawal lives in the risk row: constructed, classified, never executable.
			expect(tool?.safety.execution).toBe('none')
			expect(tool?.safety.idempotency).toBe('guard-required')
		}
	})

	it('drops both from the registry in every write mode, with or without a guard', () => {
		for (const writeMode of WRITE_MODES) {
			for (const guard of GUARD_STATES) {
				const exposed = exposedTools(writeMode, guard)
				expect(exposed.length).toBeGreaterThan(0)
				for (const name of GUARDED_NAMES) {
					expect(exposed, `${name} in ${writeMode}`).not.toContain(name)
				}
			}
		}
	})

	it('leaves no preview path to call in the fullest exposure the server allows', () => {
		const guarded = { persistentState: true, signingSecret: true }
		const exposed = createAllTools(getLiveClient())
			.filter((tool) => canExposeTool(tool.safety, { writeMode: 'guarded', guard: guarded }))
			.filter((tool) => 'dry_run' in tool.schema.shape)

		expect(exposed.map((tool) => tool.name)).toEqual([])
	})

	it('runs against the configured store without touching it', () => {
		expect(run.id).toMatch(/^mcp-/)
		expect(run.target.protocol).toMatch(/^https?:$/)
	})

	it.skip('previews a refund on a run-owned test-mode order (BLOCKED: transaction cleanup)', () => {
		expect.unreachable('Blocked until FluentCart exposes a route that removes a transaction.')
	})

	it.skip('previews a cancellation on a run-owned subscription (BLOCKED: no owned subscription)', () => {
		expect.unreachable('Blocked until a subscription can be created by the running test.')
	})
})
