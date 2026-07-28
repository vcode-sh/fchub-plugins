// Why the store answers "how much did we sell" with six different numbers, and which one is right.
//
// An audit recorded two disagreements and never chased them down: `fluentcart_report_product`
// reporting 4,962.70 where the contract-backed summary reported 4,738.69, and `fluentcart_order_list`
// showing 34 orders where a report showed 25. Both were reproduced exactly on 2026-07-28 against
// FluentCart 1.5.5 with Pro 1.5.4, and neither is a rounding artefact.
//
// The order-count gap is not a defect. Nine of the 34 orders carry payment_status `pending`; no
// report counts them. Two right answers to two different questions.
//
// The money gap is a defect, and it is upstream. `ProductReportService::getProductReportData`
// joins a subquery grouped by `(order_id, object_id)` and then sums the ORDER-level column
// `o.total_paid`, so an order is added once per distinct variation it contains. The same join is
// an INNER one over a date-filtered item subquery, so an order with no line items is dropped
// outright. `DefaultReportService::getSalesReport` and `OrderReportService::getOrderLineChart`
// share the second fault without the first. `RevenueReportService::getRevenueData` — the route
// behind the contract-backed reports — touches one table and joins nothing, which is the entire
// reason it is the figure that reconciles.
//
// So this lane does not assert the numbers seen that day. It re-derives each family's arithmetic
// from the order list, which reaches the same rows by an independent path, and checks the store
// against the derivation. A store with different data produces different figures and still passes;
// a store whose report SQL has changed shape fails and says which family moved.
import { beforeAll, describe, expect, it } from 'vitest'
import type { FluentCartClient } from '../../src/api/client.js'
import { salesSummary } from '../../src/commerce/reporting.js'
import { getLiveClient } from './support/live-client.js'
import { getLiveRun } from './support/live-run.js'

getLiveRun()

/** `Status::getReportStatuses()`. A caller-supplied payment status is discarded for this set. */
const COUNTED_STATUSES = new Set(['paid', 'refunded', 'partially_paid', 'partially_refunded'])

interface Order {
	id: number
	currency: string
	paymentStatus: string
	/** Minor units. The revenue reports sum this column, not total_amount. */
	totalPaid: number
	day: string
	/** Distinct `object_id` values across the order's line items. 0 when it has none. */
	variations: number
}

let client: FluentCartClient
let orders: Order[] = []

function asRecord(value: unknown): Record<string, unknown> {
	return value !== null && typeof value === 'object' ? (value as Record<string, unknown>) : {}
}

function numberOf(source: Record<string, unknown>, key: string): number {
	const value = source[key]
	if (typeof value === 'number' && Number.isFinite(value)) return value
	if (typeof value === 'string' && value.trim() !== '' && Number.isFinite(Number(value))) {
		return Number(value)
	}
	return 0
}

function stringOf(source: Record<string, unknown>, key: string): string {
	const value = source[key]
	return typeof value === 'string' ? value : ''
}

/** Find the first array of objects in a payload, whatever wrapper the controller used. */
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

/** How many distinct variations an order holds. This is what the product report fans out on. */
async function distinctVariations(id: number): Promise<number> {
	const res = await client.get(`/orders/${id}`)
	const order = asRecord(asRecord(res.data).order)
	const items = Array.isArray(order.order_items) ? order.order_items : []
	return new Set(items.map((item) => String(asRecord(item).object_id ?? ''))).size
}

async function fetchOrders(): Promise<Order[]> {
	const rows: Record<string, unknown>[] = []
	const perPage = 50
	let seenFirstId = ''

	for (let page = 1; page <= 40; page++) {
		const res = await client.get('/orders', { page, per_page: perPage })
		const batch = firstCollection(res.data)
		if (batch.length === 0) break
		const firstId = String(asRecord(batch[0]).id ?? '')
		if (firstId !== '' && firstId === seenFirstId) break
		seenFirstId = firstId
		rows.push(...batch)
		if (batch.length < perPage) break
	}

	const collected: Order[] = []
	for (const row of rows) {
		const paymentStatus = stringOf(row, 'payment_status')
		const id = numberOf(row, 'id')
		collected.push({
			id,
			currency: stringOf(row, 'currency').toUpperCase(),
			paymentStatus,
			totalPaid: numberOf(row, 'total_paid'),
			day: stringOf(row, 'created_at').slice(0, 10),
			// Only countable orders reach a report aggregate, so only those need the extra request.
			variations: COUNTED_STATUSES.has(paymentStatus) ? await distinctVariations(id) : 0,
		})
	}
	return collected
}

beforeAll(async () => {
	client = getLiveClient()
	orders = await fetchOrders()
}, 60_000)

const counted = (currency?: string) =>
	orders.filter(
		(order) =>
			COUNTED_STATUSES.has(order.paymentStatus) &&
			(currency === undefined || order.currency === currency),
	)

/** A range that comfortably contains every order, so nothing falls out on a boundary. */
function fullRange(): { from: string; to: string } {
	const days = orders.map((order) => order.day).filter((day) => day.length === 10)
	days.sort()
	const shift = (day: string, byDays: number) =>
		new Date(Date.parse(`${day}T00:00:00Z`) + byDays * 86_400_000).toISOString().slice(0, 10)
	return { from: shift(days[0] ?? '2000-01-01', -2), to: shift(days.at(-1) ?? '2100-01-01', 2) }
}

function params(currency?: string): Record<string, string> {
	const { from, to } = fullRange()
	return {
		'params[startDate]': from,
		'params[endDate]': to,
		...(currency === undefined ? {} : { 'params[currency]': currency }),
	}
}

/** The currencies worth checking: those with at least one countable order. */
function currencies(): string[] {
	return [...new Set(counted().map((order) => order.currency))].filter((code) => code !== '')
}

async function figureFrom(path: string, currency: string | undefined, key: string) {
	const res = await client.get(path, params(currency))
	const body = asRecord(res.data)
	const summary = asRecord(body.summary ?? body.summaryData ?? body.data ?? body)
	return numberOf(summary, key)
}

describe('the store holds enough to reconcile against', () => {
	it('returns orders, and at least one countable currency', () => {
		expect(orders.length, 'no orders; this lane would prove nothing').toBeGreaterThan(0)
		expect(currencies().length).toBeGreaterThan(0)
	})
})

describe('/reports/revenue is the figure that reconciles', () => {
	it('counts exactly the countable orders, per currency', async () => {
		for (const currency of currencies()) {
			const { from, to } = fullRange()
			const result = await salesSummary(client, { from, to, currency })
			expect(result.data.order_count, `${currency} order count`).toBe(counted(currency).length)
		}
	})

	it('sums exactly the total_paid the order list reports, per currency', async () => {
		for (const currency of currencies()) {
			const { from, to } = fullRange()
			const expected = counted(currency).reduce((sum, order) => sum + order.totalPaid, 0) / 100
			const result = await salesSummary(client, { from, to, currency })
			// total_paid, not total_amount: the report sums money collected. The two coincide on a
			// store with no partially-paid orders, which is why comparing against total_amount
			// looked correct for as long as it did.
			expect(result.data.gross_sales, `${currency} gross`).toBeCloseTo(expected, 2)
		}
	})
})

describe('the order list is larger than the report, and says why', () => {
	it('accounts for every order the report leaves out', () => {
		const uncounted = orders.filter((order) => !COUNTED_STATUSES.has(order.paymentStatus))
		expect(counted().length + uncounted.length).toBe(orders.length)
		// The gap must be entirely payment status. Anything else means a report is dropping orders
		// for a reason nothing has written down.
		for (const order of uncounted) {
			expect(COUNTED_STATUSES.has(order.paymentStatus)).toBe(false)
		}
	})

	it('warns the caller that the two totals differ, and why', async () => {
		const { from, to } = fullRange()
		const result = await salesSummary(client, { from, to, currency: currencies()[0] as string })
		const warnings = result.warnings.join(' ')
		expect(warnings).toMatch(/fluentcart_order_list/)
		expect(warnings).toMatch(/total_paid/)
	})
})

describe('/reports/product-report overstates, by an amount that is fully explained', () => {
	/**
	 * Fan-out plus drop, in one expression.
	 *
	 * The subquery emits one row per (order, variation), so an order contributes `total_paid`
	 * once per distinct variation. An order with no items contributes nothing, because the join
	 * is inner.
	 */
	const predicted = (currency?: string) =>
		counted(currency).reduce((sum, order) => sum + order.totalPaid * order.variations, 0) / 100

	it('matches the fan-out prediction to the cent', async () => {
		for (const currency of [undefined, ...currencies()]) {
			const label = currency ?? 'all currencies'
			const actual = await figureFrom('/reports/product-report', currency, 'gross_sale')
			const truth = counted(currency).reduce((sum, order) => sum + order.totalPaid, 0) / 100

			expect(
				actual,
				`${label}: /reports/product-report returned ${actual}. The fan-out model predicts ` +
					`${predicted(currency)} and the order list says the true figure is ${truth}. If actual ` +
					'now equals the true figure, FluentCart has fixed the join and every description ' +
					'calling this tool an over-count is stale. If it matches neither, the query has ' +
					'changed shape and the fault must be re-derived before anything is said about it.',
			).toBeCloseTo(predicted(currency), 2)
		}
	})

	it('overstates outright once a multi-line order exists', async () => {
		// The descriptions say this tool reports too much, not merely something else. That is only
		// safe to say while the fan-out actually dominates, so the direction is checked and not
		// assumed — and skipped honestly on a store where every order holds a single variation,
		// because there the claim has nothing to bite on.
		const fannedOut = counted().filter((order) => order.variations > 1 && order.totalPaid > 0)
		if (fannedOut.length === 0) return

		const actual = await figureFrom('/reports/product-report', undefined, 'gross_sale')
		const truth = counted().reduce((sum, order) => sum + order.totalPaid, 0) / 100
		expect(actual, 'the over-count is what the tool descriptions warn about').toBeGreaterThan(truth)
	})
})

describe('the item-joining reports drop orders that carry no line items', () => {
	/** Same inner join, no fan-out: one row per order, and only for orders that have items. */
	const predicted = (currency?: string) =>
		counted(currency)
			.filter((order) => order.variations > 0)
			.reduce((sum, order) => sum + order.totalPaid, 0) / 100

	const predictedCount = (currency?: string) =>
		counted(currency).filter((order) => order.variations > 0).length

	for (const path of ['/reports/sales-report', '/reports/order-chart']) {
		it(`${path} matches the drop prediction`, async () => {
			const gross = await figureFrom(path, undefined, 'gross_sale')
			const count = await figureFrom(path, undefined, 'order_count')
			expect(gross, `${path} gross`).toBeCloseTo(predicted(), 2)
			expect(count, `${path} order count`).toBe(predictedCount())
		})
	}

	it('reports those amounts as decimals, not as cents', async () => {
		// This tool's description claimed cents until 2026-07-28. On any store with real revenue
		// the decimal figure is two orders of magnitude below the minor-unit total, and that gap
		// is the whole assertion.
		const gross = await figureFrom('/reports/order-chart', undefined, 'gross_sale')
		if (gross <= 0) return
		const minorUnits = predicted() * 100
		expect(gross).toBeLessThan(minorUnits / 10)
	})
})

describe('the by-group reports agree with the summary', () => {
	for (const path of ['/reports/revenue-by-group', '/reports/fetch-order-by-group']) {
		it(`${path} segments add back up to the same total`, async () => {
			const res = await client.get(path, params())
			const rows = firstCollection(res.data)
			if (rows.length === 0) return // Nothing segmented; nothing to add up.

			const gross = rows.reduce((sum, row) => sum + numberOf(row, 'gross_sale'), 0)
			const segmentOrders = rows.reduce((sum, row) => sum + numberOf(row, 'orders'), 0)
			const truth = counted().reduce((sum, order) => sum + order.totalPaid, 0) / 100

			// These two join a subquery grouped by order_id alone, so they neither fan out nor drop.
			expect(gross, `${path} gross`).toBeCloseTo(truth, 2)
			expect(segmentOrders, `${path} order count`).toBe(counted().length)
		})
	}
})

describe('/reports/dashboard-stats counts something narrower again', () => {
	it('counts every order, but only paid money', async () => {
		for (const currency of currencies()) {
			const res = await client.get('/reports/dashboard-stats', params(currency))
			const stats = asRecord(asRecord(res.data).dashBoardStats)
			const total = numberOf(asRecord(stats.total_orders), 'current_count')
			const paidCount = numberOf(asRecord(stats.paid_orders), 'current_count')
			const paidValue = numberOf(asRecord(stats.total_paid_amounts), 'current_count')

			const all = orders.filter((order) => order.currency === currency)
			const paidOnly = all.filter((order) => order.paymentStatus === 'paid')

			// Three scopes in one payload, which is why the tool now says so: total_orders is the
			// order list, the other two exclude anything refunded or partially paid.
			expect(total, `${currency} total_orders`).toBe(all.length)
			expect(paidCount, `${currency} paid_orders`).toBe(paidOnly.length)
			expect(paidValue, `${currency} paid value in minor units`).toBe(
				paidOnly.reduce((sum, order) => sum + order.totalPaid, 0),
			)
		}
	})
})
