import { describe, expect, it } from 'vitest'
import { buildEnvelope } from '../../src/commerce/envelopes.js'
import {
	assertResponseBudget,
	assertWithinEmergencyCap,
	DEFAULT_RESPONSE_BUDGET,
	EMERGENCY_RESPONSE_CAP,
	ResponseTooLargeError,
} from '../../src/commerce/response-budget.js'
import { truncateResponse } from '../../src/tools/_factory.js'

describe('assertResponseBudget', () => {
	it('accepts a page that fits', () => {
		const envelope = buildEnvelope([{ id: 1 }, { id: 2 }], { page: 1, perPage: 10, total: 2 })
		expect(() => assertResponseBudget(envelope)).not.toThrow()
	})

	it('rejects a page with too many items and names a smaller per_page', () => {
		const rows = Array.from({ length: 500 }, (_, i) => ({ id: i }))
		const envelope = buildEnvelope(rows, { page: 1, perPage: 500 })

		expect(() => assertResponseBudget(envelope)).toThrow(ResponseTooLargeError)
		expect(() => assertResponseBudget(envelope)).toThrow(/per_page/)
	})

	it('rejects an oversized payload rather than shortening it', () => {
		const rows = Array.from({ length: 50 }, (_, i) => ({ id: i, blob: 'x'.repeat(2_000) }))
		const envelope = buildEnvelope(rows, { page: 1, perPage: 50 })

		expect(() => assertResponseBudget(envelope)).toThrow(/RESPONSE_TOO_LARGE|over the/)
	})

	it('tells the caller a single record is the problem when it is', () => {
		const envelope = buildEnvelope([{ id: 1, blob: 'x'.repeat(30_000) }], { page: 1, perPage: 1 })

		const error = (() => {
			try {
				assertResponseBudget(envelope)
			} catch (e) {
				return e as ResponseTooLargeError
			}
		})()

		expect(error?.code).toBe('RESPONSE_TOO_LARGE')
		expect(error?.remedies.join(' ')).toMatch(/one record alone is oversized/)
		expect(error?.remedies.join(' ')).toMatch(/include\[\]|fields\[\]/)
	})

	it('never reports a truncated page as a success', () => {
		const rows = Array.from({ length: 40 }, (_, i) => ({ id: i, blob: 'y'.repeat(2_000) }))
		const envelope = buildEnvelope(rows, { page: 1, perPage: 40 })

		let threw = false
		try {
			assertResponseBudget(envelope)
		} catch {
			threw = true
		}

		expect(threw).toBe(true)
		// The envelope itself is untouched: nothing was dropped behind the caller's back.
		expect(envelope.data).toHaveLength(40)
		expect(JSON.stringify(envelope)).not.toContain('_truncated')
	})

	it('exposes the documented default budget', () => {
		expect(DEFAULT_RESPONSE_BUDGET.maxCharacters).toBe(24_000)
		expect(EMERGENCY_RESPONSE_CAP).toBe(40_000)
	})
})

describe('envelope totals', () => {
	it('never invents a total from one page', () => {
		const rows = Array.from({ length: 10 }, (_, i) => ({ id: i }))
		const envelope = buildEnvelope(rows, { page: 1, perPage: 10 })

		expect(envelope.total).toBeNull()
		// A full page with no upstream total means "there may be more", not "there are exactly 10".
		expect(envelope.hasMore).toBe(true)
		expect(envelope.nextPage).toBe(2)
	})

	it('uses the upstream total when the endpoint reports one', () => {
		const envelope = buildEnvelope([{ id: 1 }], { page: 1, perPage: 10, total: 1 })
		expect(envelope.total).toBe(1)
		expect(envelope.hasMore).toBe(false)
		expect(envelope.nextPage).toBeNull()
	})

	it('stops offering a next page once the total is consumed', () => {
		const envelope = buildEnvelope([{ id: 3 }], { page: 3, perPage: 1, total: 3 })
		expect(envelope.hasMore).toBe(false)
		expect(envelope.nextPage).toBeNull()
	})
})

describe('emergency cap', () => {
	it('passes a payload of unknown shape that fits', () => {
		expect(() => assertWithinEmergencyCap({ ok: true }, 'orders')).not.toThrow()
	})

	it('rejects an oversized single record instead of returning it as a success', () => {
		// The exact defect this replaced: one record larger than the cap could not be shrunk,
		// so it was returned whole and flagged `_truncated: true` as though it had succeeded.
		const monster = [{ blob: 'x'.repeat(200_000) }]

		expect(() => truncateResponse(monster)).toThrow(ResponseTooLargeError)
		expect(() => assertWithinEmergencyCap(monster, 'orders')).toThrow(/RESPONSE_TOO_LARGE|over the/)
	})

	it('returns a fitting payload unchanged', () => {
		const payload = { orders: [{ id: 1 }] }
		expect(truncateResponse(payload)).toBe(payload)
	})

	it('names the context so the caller knows what to narrow', () => {
		const error = (() => {
			try {
				assertWithinEmergencyCap('z'.repeat(60_000), 'customer list')
			} catch (e) {
				return e as ResponseTooLargeError
			}
		})()

		expect(error?.remedies.join(' ')).toContain('customer list')
	})
})
