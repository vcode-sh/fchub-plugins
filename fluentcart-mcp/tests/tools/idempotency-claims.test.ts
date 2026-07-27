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

	it('never claims idempotency for an operation that accumulates', () => {
		// The property that matters is whether repeating the call changes anything further, not what
		// the tool is named. A write that names the end state — set these terms, set this quantity,
		// set this field — lands the same row on a second call. A write that appends a new record
		// does not, and `_create` is the only shape in this registry that appends.
		//
		// An earlier version of this test guessed from name suffixes and flagged
		// `fluentcart_product_taxonomy_sync`, which is idempotent by definition: syncing to an
		// exact set twice leaves that exact set. The heuristic was wrong, not the classification.
		const accumulating = tools.filter(
			(tool) => tool.safety.risk !== 'read' && tool.safety.idempotency === 'inherent',
		)
		const offenders = accumulating
			.filter((tool) => tool.name.endsWith('_create') || tool.name.endsWith('_add'))
			.map((tool) => tool.name)

		expect(offenders, `these append a record but claim repetition is safe: ${offenders}`).toEqual(
			[],
		)
	})
})
