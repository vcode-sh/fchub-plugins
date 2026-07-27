// A tool may claim idempotency only if repeating it really is a no-op.
//
// Every reversible write used to be stamped `inherent` — "repeating the call cannot double-apply
// it" — which `_factory.ts` turns into `idempotentHint: true` on the wire. For the updates that
// is true. For the 14 creates it was false: FluentCart accepts no idempotency key on create, so a
// second POST makes a second record. `idempotentHint` is precisely the signal a client consults
// before retrying a timed-out call, so the annotation was inviting silent duplicates of products,
// customers, coupons and orders.
//
// The registry conflated *reversible* (there is a DELETE, which is what governs exposure) with
// *idempotent* (repeating changes nothing further). These tests keep the two apart.
import { describe, expect, it } from 'vitest'
import type { FluentCartClient } from '../../src/api/client.js'
import { createAllTools } from '../../src/tools/index.js'

const client = { get: async () => ({ data: {} }) } as unknown as FluentCartClient
const tools = createAllTools(client, {})

const reversible = tools.filter((tool) => tool.safety.risk === 'reversible-write')

describe('idempotency claims match reality', () => {
	it('has reversible writes to check', () => {
		expect(reversible.length).toBeGreaterThan(0)
	})

	it('never claims a create is safe to repeat', () => {
		const creates = reversible.filter((tool) => tool.name.endsWith('_create'))
		expect(creates.length).toBeGreaterThan(0)

		for (const tool of creates) {
			expect(
				tool.safety.idempotency,
				`${tool.name} claims repetition is safe; a retry would create a second record`,
			).not.toBe('inherent')
			expect(tool.annotations.idempotentHint, `${tool.name} advertises idempotentHint`).toBe(false)
		}
	})

	it('keeps the genuine idempotency of updates', () => {
		// Overcorrecting would be its own defect: a client that cannot retry a failed update has to
		// escalate a transient network error to a human.
		const updates = reversible.filter(
			(tool) => tool.name.endsWith('_update') || tool.name.endsWith('_save'),
		)
		expect(updates.length).toBeGreaterThan(0)

		for (const tool of updates) {
			expect(tool.safety.idempotency, `${tool.name} lost its idempotency claim`).toBe('inherent')
			expect(tool.annotations.idempotentHint).toBe(true)
		}
	})

	it('leaves reversibility, and therefore exposure, untouched', () => {
		// Only the retry-safety claim changed. Every create must still be a reversible-write with a
		// REST execution path, or this would have quietly withdrawn tools from reversible mode.
		for (const tool of reversible.filter((candidate) => candidate.name.endsWith('_create'))) {
			expect(tool.safety.risk).toBe('reversible-write')
			expect(tool.safety.execution).toBe('rest')
		}
	})

	it('never claims idempotency for a read-modify-write it cannot deduplicate', () => {
		// A read tool is inherently repeatable; anything that writes has to have earned the claim.
		for (const tool of tools) {
			if (tool.safety.risk === 'read') continue
			if (tool.safety.idempotency !== 'inherent') continue
			expect(
				tool.name.endsWith('_update') ||
					tool.name.endsWith('_save') ||
					tool.name.endsWith('_toggle') ||
					tool.name.endsWith('_preview') ||
					tool.name.endsWith('_reapply') ||
					tool.name.endsWith('_cancel') ||
					tool.name.includes('_update_') ||
					tool.name.includes('_change_'),
				`${tool.name} claims inherent idempotency but is not an update-shaped write`,
			).toBe(true)
		}
	})
})
