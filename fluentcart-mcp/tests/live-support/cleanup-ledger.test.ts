import { describe, expect, it, vi } from 'vitest'
import { CleanupLedger } from '../integration/support/cleanup-ledger.js'

function okVerifier() {
	return vi.fn(async () => true)
}

async function capturedError(promise: Promise<unknown>): Promise<Error> {
	try {
		await promise
	} catch (error) {
		if (error instanceof Error) return error
		throw new Error(`Expected Error, received ${String(error)}`)
	}
	throw new Error('Expected promise to reject')
}

describe('CleanupLedger', () => {
	it('removes and independently verifies a tracked record', async () => {
		const ledger = new CleanupLedger()
		const removeProduct = vi.fn(async () => {
			/* removal succeeds */
		})
		const verifyProductMissing = okVerifier()

		ledger.track({
			type: 'product',
			id: 42,
			remove: removeProduct,
			verifyMissing: verifyProductMissing,
		})
		await ledger.cleanup()

		expect(removeProduct).toHaveBeenCalledWith(42)
		expect(verifyProductMissing).toHaveBeenCalledWith(42)
	})

	it('deletes children before parents by reversing registration order', async () => {
		const ledger = new CleanupLedger()
		const order: string[] = []
		const make = (type: string, id: number) => ({
			type,
			id,
			remove: async () => {
				order.push(`${type}:${id}`)
			},
			verifyMissing: async () => true,
		})

		ledger.track(make('product', 1))
		ledger.track(make('variant', 2))
		ledger.track(make('note', 3))
		await ledger.cleanup()

		expect(order).toEqual(['note:3', 'variant:2', 'product:1'])
	})

	it('passes the exact registered id and never a prefix query', async () => {
		const ledger = new CleanupLedger()
		const remove = vi.fn(async () => {
			/* removal succeeds */
		})
		ledger.track({ type: 'coupon', id: 'MCP-RUN-7', remove, verifyMissing: async () => true })
		await ledger.cleanup()

		expect(remove).toHaveBeenCalledTimes(1)
		expect(remove).toHaveBeenCalledWith('MCP-RUN-7')
	})

	it('is idempotent: a second cleanup does not delete anything again', async () => {
		const ledger = new CleanupLedger()
		const remove = vi.fn(async () => {
			/* removal succeeds */
		})
		ledger.track({ type: 'label', id: 9, remove, verifyMissing: async () => true })

		await ledger.cleanup()
		await ledger.cleanup()

		expect(remove).toHaveBeenCalledTimes(1)
	})

	it('fails the suite when a deletion throws', async () => {
		const ledger = new CleanupLedger()
		ledger.track({
			type: 'customer',
			id: 5,
			remove: async () => {
				throw new Error('HTTP 500')
			},
			verifyMissing: async () => true,
		})

		await expect(ledger.cleanup()).rejects.toThrow(/cleanup incomplete/)
	})

	it('fails the suite when the record is still present after deletion', async () => {
		const ledger = new CleanupLedger()
		ledger.track({
			type: 'customer',
			id: 5,
			remove: async () => {
				/* removal succeeds */
			},
			verifyMissing: async () => false,
		})

		await expect(ledger.cleanup()).rejects.toThrow(/cleanup incomplete/)
	})

	it('fails when the verifier cannot distinguish missing from an auth or network error', async () => {
		const ledger = new CleanupLedger()
		ledger.track({
			type: 'order',
			id: 5,
			remove: async () => {
				/* removal succeeds */
			},
			verifyMissing: async () => {
				throw new Error('401 Unauthorized')
			},
		})

		await expect(ledger.cleanup()).rejects.toThrow(/cleanup incomplete/)
	})

	it('attempts every record even when an earlier one fails, and aggregates the failures', async () => {
		const ledger = new CleanupLedger()
		const removeFirst = vi.fn(async () => {
			/* removal succeeds */
		})
		ledger.track({ type: 'product', id: 1, remove: removeFirst, verifyMissing: async () => true })
		ledger.track({
			type: 'variant',
			id: 2,
			remove: async () => {
				throw new Error('boom')
			},
			verifyMissing: async () => true,
		})

		const error = await capturedError(ledger.cleanup())

		expect(removeFirst).toHaveBeenCalledWith(1)
		expect(error.message).toMatch(/cleanup incomplete/)
		expect(error.message).toContain('variant')
		expect(error.message).toContain('2')
	})

	it('reports every failure, not merely the first', async () => {
		const ledger = new CleanupLedger()
		for (const id of [1, 2, 3]) {
			ledger.track({
				type: 'coupon',
				id,
				remove: async () => {
					throw new Error(`fail-${id}`)
				},
				verifyMissing: async () => true,
			})
		}

		const error = await capturedError(ledger.cleanup())
		for (const id of [1, 2, 3]) {
			expect(error.message).toContain(`coupon ${id}`)
		}
	})

	it('exposes the outstanding record count for assertions', async () => {
		const ledger = new CleanupLedger()
		expect(ledger.size).toBe(0)
		ledger.track({
			type: 'product',
			id: 1,
			remove: async () => {
				/* removal succeeds */
			},
			verifyMissing: async () => true,
		})
		expect(ledger.size).toBe(1)
		await ledger.cleanup()
		expect(ledger.size).toBe(0)
	})

	it('rejects a registration without an exact id', () => {
		const ledger = new CleanupLedger()
		expect(() =>
			ledger.track({
				type: 'product',
				id: undefined as unknown as number,
				remove: async () => {
					/* removal succeeds */
				},
				verifyMissing: async () => true,
			}),
		).toThrow(/exact id/)
	})
})
