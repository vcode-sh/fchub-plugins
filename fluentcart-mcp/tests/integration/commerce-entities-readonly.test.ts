// Read-only integration lane. Reachable only through scripts/run-live-tests.mjs, which owns
// credential loading, target policy and the run identity. Nothing here mutates the store.
//
// The unit tests prove the projections against synthetic rows. This lane proves the same
// invariants against whatever the store actually returns, which is the only place a renamed
// column or a newly nested wrapper shows up.
import { beforeAll, describe, expect, it } from 'vitest'
import type { FluentCartClient } from '../../src/api/client.js'
import {
	projectCustomer,
	projectOrder,
	projectProduct,
	projectSubscription,
	projectTransaction,
} from '../../src/commerce/entity-projections.js'
import { getLiveClient } from './support/live-client.js'
import { getLiveRun } from './support/live-run.js'

getLiveRun()

let client: FluentCartClient

beforeAll(() => {
	client = getLiveClient()
})

/** Find the first array of objects in a list payload, whatever wrapper the controller used. */
function firstCollection(payload: unknown, depth = 0): Record<string, unknown>[] {
	if (!payload || typeof payload !== 'object' || depth > 4) return []
	const record = payload as Record<string, unknown>

	if (Array.isArray(record.data)) {
		return record.data.filter((row): row is Record<string, unknown> => typeof row === 'object')
	}
	for (const value of Object.values(record)) {
		const found = firstCollection(value, depth + 1)
		if (found.length > 0) return found
	}
	return []
}

async function fetchRows(path: string): Promise<Record<string, unknown>[]> {
	const res = await client.get(path, { per_page: 5 })
	return firstCollection(res.data)
}

/** An amount is either absent or an exact integer of minor units. Never a rounded float. */
function expectMinorUnits(amount: number | null): void {
	if (amount === null) return
	expect(Number.isInteger(amount)).toBe(true)
}

const RAW_LEAKS = [
	'ip_address',
	'note',
	'notes',
	'config',
	'uuid',
	'vendor_response',
	'post_content',
	'edit_url',
	'meta',
]

function expectNoRawLeak(row: object): void {
	const keys = Object.keys(row)
	for (const leaked of RAW_LEAKS) expect(keys).not.toContain(leaked)
}

describe('order projection against the live store', () => {
	it('projects real rows to the allowlist', async ({ skip }) => {
		const rows = await fetchRows('/orders')
		if (rows.length === 0) skip('the live store has no orders to project')

		for (const raw of rows) {
			const row = projectOrder(raw)
			expect(Object.keys(row).sort()).toEqual([
				'createdAt',
				'customerName',
				'id',
				'paymentMethod',
				'paymentStatus',
				'receiptNumber',
				'status',
				'total',
			])
			expectNoRawLeak(row)
			expectMinorUnits(row.total.amount)
			expect(typeof row.id === 'number' || row.id === null).toBe(true)
		}
	})

	it('reads a currency for any order that reports a total', async () => {
		const rows = await fetchRows('/orders')
		for (const row of rows.map((raw) => projectOrder(raw))) {
			// A bare amount with no currency is the input to a wrong cross-currency sum, so the
			// pair travels together or the amount does not travel at all.
			if (row.total.amount !== null) expect(row.total.currency).not.toBeNull()
		}
	})

	it('returns detail collections only when asked', async ({ skip }) => {
		const rows = await fetchRows('/orders')
		if (rows.length === 0) skip('the live store has no order details to project')
		const first = rows[0] as Record<string, unknown>

		expect(projectOrder(first).items).toBeUndefined()
		if (first.items !== undefined) {
			expect(projectOrder(first, ['items']).items).toBeDefined()
		}
	})
})

describe('product projection against the live store', () => {
	it('projects the WordPress post shape and its detail row', async ({ skip }) => {
		const rows = await fetchRows('/products')
		if (rows.length === 0) skip('the live store has no products to project')

		for (const raw of rows) {
			const row = projectProduct(raw)
			expect(Object.keys(row).sort()).toEqual([
				'fulfilment',
				'id',
				'slug',
				'status',
				'title',
				'type',
			])
			expectNoRawLeak(row)
		}

		// At least one live product must resolve an id and title, or the projection is reading
		// the wrong keys and every row would be silently null.
		expect(rows.map((raw) => projectProduct(raw)).some((row) => row.id !== null)).toBe(true)
		expect(rows.map((raw) => projectProduct(raw)).some((row) => row.title !== null)).toBe(true)
	})
})

describe('customer projection against the live store', () => {
	it('withholds email unless authorised', async ({ skip }) => {
		const rows = await fetchRows('/customers')
		if (rows.length === 0) skip('the live store has no customers for the privacy projection')

		for (const raw of rows) {
			expect('email' in projectCustomer(raw)).toBe(false)
		}
	})

	it('reports lifetime value in minor units and never invents a count', async () => {
		const rows = await fetchRows('/customers')
		for (const raw of rows) {
			const row = projectCustomer(raw)
			expectMinorUnits(row.lifetimeValue.amount)
			expect(row.orderCount === null || Number.isInteger(row.orderCount)).toBe(true)
			expectNoRawLeak(row)
		}
	})
})

describe('subscription projection against the live store', () => {
	it('projects the allowlist and keeps recurring amounts exact', async ({ skip }) => {
		const rows = await fetchRows('/subscriptions')
		if (rows.length === 0) skip('the live store has no subscriptions to project')

		for (const raw of rows) {
			const row = projectSubscription(raw)
			expect(Object.keys(row).sort()).toEqual([
				'billingInterval',
				'canceledAt',
				'id',
				'nextBillingDate',
				'parentOrderId',
				'productId',
				'recurring',
				'status',
				'variationId',
			])
			expectNoRawLeak(row)
			expectMinorUnits(row.recurring.amount)
		}
	})
})

describe('transaction projection against the live store', () => {
	it('projects transactions reached through an order', async ({ skip }) => {
		const orders = await fetchRows('/orders')
		if (orders.length === 0) skip('the live store has no orders for a transaction projection')

		const orderId = projectOrder(orders[0] as Record<string, unknown>).id
		if (orderId === null) skip('the first live order has no projectable identifier')

		const res = await client.get(`/orders/${orderId}`)
		const transactions = firstCollection(
			(res.data as Record<string, unknown>)?.transactions ?? res.data,
		)
		if (transactions.length === 0) skip('the selected live order has no transactions')

		for (const raw of transactions) {
			if (raw.transaction_type === undefined && raw.payment_mode === undefined) continue
			const row = projectTransaction(raw)
			expectMinorUnits(row.amount.amount)
			expectNoRawLeak(row)
			// Payment mode is read per row; it is never inherited from a sibling transaction.
			expect(row.paymentMode === null || typeof row.paymentMode === 'string').toBe(true)
		}
	})
})
