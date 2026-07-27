// Read-only integration lane. Reachable only through scripts/run-live-tests.mjs, which owns
// credential loading, target policy and the run identity. Nothing here mutates the store.
//
// This lane exists to retire a specific disclaimer. Until now every accepted report shipped with
// "the query semantics are established from the FluentCart controller source but have NOT been
// confirmed by a seeded assertion" — meaning we had read the SQL and believed it. Reading SQL
// tells you what a query says, not what the endpoint returns after the controller, the ORM, the
// timezone conversion and the Pro licence check have all had their turn.
//
// So the report is reconciled against a second, independent view of the same facts: the order
// list. Two endpoints, two code paths, one set of orders. If they agree the semantics hold; if
// they disagree, one of them is wrong and the disclaimer was hiding it.
//
// Every assertion is derived from whatever the store actually contains, so this lane is portable
// to any FluentCart store rather than pinned to the fixtures of one dev machine.
import { beforeAll, describe, expect, it } from 'vitest'
import type { FluentCartClient } from '../../src/api/client.js'
import { FluentCartApiError } from '../../src/api/errors.js'
import { ABSENT_ROUTES } from '../../src/commerce/report-contracts.js'
import { salesSummary, salesTrend, topProducts } from '../../src/commerce/reporting.js'
import { getLiveClient } from './support/live-client.js'
import { getLiveRun } from './support/live-run.js'

getLiveRun()

let client: FluentCartClient

/** Payment statuses the revenue report counts, per the contract in report-contracts.ts. */
const COUNTED_STATUSES = new Set(['paid', 'refunded', 'partially_paid', 'partially_refunded'])

interface OrderRow {
	created_at: string
	currency: string
	payment_status: string
	total_amount: number
}

function asRecord(value: unknown): Record<string, unknown> {
	return value !== null && typeof value === 'object' ? (value as Record<string, unknown>) : {}
}

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

/**
 * Every order the store holds, paged to exhaustion.
 *
 * The report aggregates server-side over the whole table, so a truthful comparison has to see
 * the whole table too. Paging stops on a short page or a repeated first id, so a store that
 * ignores the page parameter cannot spin this forever.
 */
async function fetchAllOrders(): Promise<OrderRow[]> {
	const rows: OrderRow[] = []
	const perPage = 50
	let seenFirstId: string | null = null

	for (let page = 1; page <= 40; page++) {
		const res = await client.get('/orders', { page, per_page: perPage })
		const batch = firstCollection(res.data)
		if (batch.length === 0) break

		const firstId = String(asRecord(batch[0]).id ?? '')
		if (firstId !== '' && firstId === seenFirstId) break
		seenFirstId = firstId

		for (const row of batch) {
			rows.push({
				created_at: stringOf(row, 'created_at'),
				currency: stringOf(row, 'currency').toUpperCase(),
				payment_status: stringOf(row, 'payment_status'),
				total_amount: numberOf(row, 'total_amount'),
			})
		}
		if (batch.length < perPage) break
	}
	return rows
}

let orders: OrderRow[] = []

beforeAll(async () => {
	client = getLiveClient()
	orders = await fetchAllOrders()
}, 60_000)

/** Orders the revenue report should count for a currency, within an inclusive day range. */
function countedOrders(currency: string, from: string, to: string): OrderRow[] {
	return orders.filter((order) => {
		if (order.currency !== currency) return false
		if (!COUNTED_STATUSES.has(order.payment_status)) return false
		const day = order.created_at.slice(0, 10)
		return day >= from && day <= to
	})
}

/** The currency with the most countable orders, so the comparison runs on real volume. */
function busiestCurrency(): string | null {
	const tally = new Map<string, number>()
	for (const order of orders) {
		if (!COUNTED_STATUSES.has(order.payment_status)) continue
		if (order.currency === '') continue
		tally.set(order.currency, (tally.get(order.currency) ?? 0) + 1)
	}
	let best: string | null = null
	let bestCount = 0
	for (const [currency, count] of tally) {
		if (count > bestCount) {
			best = currency
			bestCount = count
		}
	}
	return best
}

/** A range wide enough to hold every order the store has, so nothing falls outside it. */
function fullRange(): { from: string; to: string } {
	const days = orders
		.map((order) => order.created_at.slice(0, 10))
		.filter((day) => day.length === 10)
	days.sort()
	return { from: days[0] ?? '2000-01-01', to: days.at(-1) ?? '2100-01-01' }
}

const TZ = 'UTC'

describe('revenue report semantics, reconciled against the order list', () => {
	it('the store holds enough orders to reconcile against', () => {
		// A green run on an empty store would prove nothing at all, so say so out loud.
		expect(orders.length, 'no orders returned; this lane cannot prove anything').toBeGreaterThan(0)
		expect(busiestCurrency(), 'no countable order carried a currency').not.toBeNull()
	})

	it('counts exactly the orders the contract says it counts', async () => {
		const currency = busiestCurrency()
		if (!currency) return
		const { from, to } = fullRange()

		const expected = countedOrders(currency, from, to)
		const result = await salesSummary(client, { from, to, currency, timezone: TZ })

		// order_count is the least ambiguous field the report returns: an integer of rows, with no
		// division, rounding or currency conversion between the table and the response.
		expect(result.data.order_count).toBe(expected.length)
	})

	it('sums the same money the order list does', async () => {
		const currency = busiestCurrency()
		if (!currency) return
		const { from, to } = fullRange()

		const expectedMinor = countedOrders(currency, from, to).reduce(
			(sum, order) => sum + order.total_amount,
			0,
		)
		const result = await salesSummary(client, { from, to, currency, timezone: TZ })

		// The report divides by 100 in SQL while the order list reports minor units, so the
		// comparison is done in decimals. A cent of float drift is tolerated; a missing or
		// double-counted order is not.
		expect(result.data.gross_sales).not.toBeNull()
		expect(result.data.gross_sales as number).toBeCloseTo(expectedMinor / 100, 2)
	})

	it('honours the caller date range rather than a fixed rolling window', async () => {
		const currency = busiestCurrency()
		if (!currency) return
		const { from, to } = fullRange()

		// WARN_PRO claims the store silently substitutes a rolling 30-day window when Pro is
		// inactive. If that is happening, a deliberately empty ancient window returns the same
		// figures as the full range instead of zero.
		const empty = await salesSummary(client, {
			from: '1990-01-01',
			to: '1990-01-02',
			currency,
			timezone: TZ,
		})
		const full = await salesSummary(client, { from, to, currency, timezone: TZ })

		expect(empty.data.order_count).toBe(0)
		expect(full.data.order_count as number).toBeGreaterThan(0)
	})

	it('scopes to the requested currency instead of summing across all of them', async () => {
		const currencies = [
			...new Set(
				orders.filter((o) => COUNTED_STATUSES.has(o.payment_status)).map((o) => o.currency),
			),
		].filter((c) => c !== '')
		if (currencies.length < 2) return // Single-currency store: nothing to mix up.

		const { from, to } = fullRange()
		for (const currency of currencies) {
			const result = await salesSummary(client, { from, to, currency, timezone: TZ })
			expect(
				result.data.order_count,
				`${currency} count must match the order list, not the whole store`,
			).toBe(countedOrders(currency, from, to).length)
		}
	})

	it('returns trend buckets that add back up to the summary', async () => {
		const currency = busiestCurrency()
		if (!currency) return
		const { from, to } = fullRange()

		const summary = await salesSummary(client, { from, to, currency, timezone: TZ })
		const trend = await salesTrend(client, {
			from,
			to,
			currency,
			timezone: TZ,
			granularity: 'monthly',
		})

		const bucketed = trend.data.reduce((sum, point) => sum + (point.order_count ?? 0), 0)
		expect(bucketed).toBe(summary.data.order_count)
	})

	it('admits when the store widened the bucket instead of claiming daily', async () => {
		const currency = busiestCurrency()
		if (!currency) return

		// Deliberately wider than FluentCart's 91-day daily band, so the store auto-selects a
		// coarser bucket. The tool used to present the result as daily regardless.
		const trend = await salesTrend(client, {
			from: '2026-01-01',
			to: '2026-12-31',
			currency,
			timezone: TZ,
			granularity: 'daily',
		})

		expect(trend.granularity.requested).toBe('daily')
		expect(trend.granularity.applied).not.toBe('daily')
		expect(trend.warnings.join(' ')).toMatch(/store grouped by/)
		// A daily series over a year would have hundreds of buckets; a widened one has far fewer.
		expect(trend.data.length).toBeLessThan(100)
	})

	it('never asks for a group key the store would read as payment method', async () => {
		const currency = busiestCurrency()
		if (!currency) return

		// The trap: FluentCart maps any unwhitelisted groupKey to payment_method, so a time series
		// silently becomes a payment-method breakdown. Buckets must still look like dates.
		const trend = await salesTrend(client, {
			from: '2026-01-01',
			to: '2026-12-31',
			currency,
			timezone: TZ,
			granularity: 'daily',
		})

		expect(trend.granularity.applied).not.toBe('payment_method')
		for (const point of trend.data) {
			expect(point.period, `"${point.period}" is not a date bucket`).toMatch(/^\d{4}(-\d{2}){0,2}$/)
		}
	})

	it('ranks top products the way the tool says it does', async () => {
		const currency = busiestCurrency()
		if (!currency) return
		const { from, to } = fullRange()

		const result = await topProducts(client, { from, to, currency, timezone: TZ })
		if (result.data.length === 0) return // Nothing sold in range; nothing to order.

		// The tool tells callers the ranking is by units sold, not revenue, and warns them not to
		// read it as a revenue league table. That claim is only safe if the order really is by
		// units descending — otherwise the warning describes a ranking the store is not applying.
		const quantities = result.data.map((row) => row.quantity_sold ?? 0)
		for (let i = 1; i < quantities.length; i++) {
			expect(
				quantities[i - 1] as number,
				`row ${i} has more units than row ${i - 1}, so the ranking is not by units sold`,
			).toBeGreaterThanOrEqual(quantities[i] as number)
		}

		// Named products with real sales, not a list of nulls that happens to be the right length.
		for (const row of result.data) {
			expect(row.product_id).not.toBeNull()
			expect(row.product_name).not.toBeNull()
			expect(row.quantity_sold as number).toBeGreaterThan(0)
		}
	})

	it('reports top-product revenue in the same units as the sales summary', async () => {
		const currency = busiestCurrency()
		if (!currency) return
		const { from, to } = fullRange()

		const top = await topProducts(client, { from, to, currency, timezone: TZ })
		if (top.data.length === 0) return
		const summary = await salesSummary(client, { from, to, currency, timezone: TZ })

		// Both are decimals, not minor units. If one silently switched, a single product would
		// appear to out-earn the entire store by a factor of a hundred.
		const best = Math.max(...top.data.map((row) => row.total_amount ?? 0))
		const gross = summary.data.gross_sales
		if (gross === null || gross === undefined || gross <= 0) return
		expect(best).toBeLessThanOrEqual(gross * 1.01)
	})

	/**
	 * Two tools carried an "UPSTREAM BUG: crashes" annotation for an endpoint that answers 200 with
	 * real data. That is worse than no annotation: it steers a model off a working route, and no
	 * test failed while it did so, because nothing here had ever called them.
	 */
	it('serves the top-products route that was labelled as crashing', async () => {
		const res = await client.get('/reports/fetch-top-sold-products', {
			'params[startDate]': '2026-01-01',
			'params[endDate]': '2026-12-31',
		})
		const rows = (res.data as { topSoldProducts?: unknown[] }).topSoldProducts ?? []
		expect(rows.length, 'the route labelled UB-006 returned nothing after all').toBeGreaterThan(0)
	})

	it('serves the report summary that was labelled as crashing, and ignores the dates', async () => {
		const range = async (from: string, to: string) =>
			JSON.stringify(
				(
					await client.get('/reports/report-overview', {
						'params[startDate]': from,
						'params[endDate]': to,
					})
				).data,
			)

		const wide = await range('2026-01-01', '2026-12-31')
		const narrow = await range('2026-03-01', '2026-03-05')
		const ancient = await range('1990-01-01', '1990-01-02')

		expect(wide.length, 'the route labelled UB-004 returned nothing after all').toBeGreaterThan(100)
		// Identical bodies for three disjoint windows is the whole finding: the controller reads
		// created_at, status and payment_status out of params, and startDate is not among them.
		expect(narrow).toBe(wide)
		expect(ancient).toBe(wide)
	})

	it('404s on exactly the routes the contract calls absent', async () => {
		for (const path of ABSENT_ROUTES) {
			const status = await client
				.get(path, {})
				.then(() => 200)
				.catch((error: unknown) => (error instanceof FluentCartApiError ? error.status : -1))
			expect(status, `${path} is listed as absent but answered ${status}`).toBe(404)
		}
	})
})

/**
 * The MRR series, checked rather than believed.
 *
 * `/reports/subscription-retention` is registered diagnostic-only because its money columns cannot
 * be scoped to a currency. That rejection and the tool's description both make specific, falsifiable
 * claims — dates honoured, currency discarded, amounts as decimals, yearly plans divided by twelve —
 * and a raw passthrough has nothing between the store and the caller to catch a wrong one. So each
 * claim is reconciled against the subscription list, which reaches the same rows by a different path.
 */
describe('subscription retention semantics, reconciled against the subscription list', () => {
	interface SubscriptionRow {
		status: string
		interval: string
		recurringMinor: number
		createdDay: string
		canceledAt: string | null
		expireAt: string | null
	}

	interface RetentionRow {
		group: string
		mrr: string | number
		active_paid_subscriptions: string | number
		new_subscriptions: number
	}

	/** Excluded by the controller: `whereNotIn('status', [pending, intended])`. */
	const UNCOUNTED = new Set(['pending', 'intended'])

	/** SubscriptionReportService::getRetentionData's normalisation, in minor units. */
	function monthlyEquivalent(minor: number, interval: string): number {
		if (interval === 'monthly') return minor
		if (interval === 'yearly') return minor / 12
		if (interval === 'weekly') return (minor * 52) / 12
		if (interval === 'daily') return minor * 30
		return 0
	}

	async function fetchSubscriptions(): Promise<SubscriptionRow[]> {
		const res = await client.get('/subscriptions', { per_page: 50 })
		return firstCollection(res.data).map((row) => ({
			status: stringOf(row, 'status'),
			interval: stringOf(row, 'billing_interval'),
			recurringMinor: numberOf(row, 'recurring_amount'),
			createdDay: stringOf(row, 'created_at').slice(0, 10),
			canceledAt: stringOf(row, 'canceled_at') || null,
			expireAt: stringOf(row, 'expire_at') || null,
		}))
	}

	async function retention(from: string, to: string): Promise<RetentionRow[]> {
		const res = await client.get('/reports/subscription-retention', {
			'params[startDate]': from,
			'params[endDate]': to,
		})
		const rows = (res.data as { retention_data?: unknown[] }).retention_data ?? []
		return rows.map((row) => {
			const record = asRecord(row)
			return {
				group: stringOf(record, 'group'),
				mrr: (record.mrr ?? 0) as string | number,
				active_paid_subscriptions: (record.active_paid_subscriptions ?? '') as string | number,
				new_subscriptions: numberOf(record, 'new_subscriptions'),
			}
		})
	}

	it('honours the caller range instead of a fixed window', async () => {
		const narrow = await retention('2026-03-01', '2026-03-05')
		const wide = await retention('2026-01-01', '2026-12-31')

		expect(narrow.length).toBe(1)
		expect(narrow[0]?.group).toBe('2026-03')
		expect(wide.length).toBeGreaterThan(narrow.length)
	})

	it('discards a currency argument, which is why it is not a metric', async () => {
		const scoped = async (currency: string) =>
			JSON.stringify(
				(
					await client.get('/reports/subscription-retention', {
						'params[startDate]': '2026-01-01',
						'params[endDate]': '2026-12-31',
						'params[currency]': currency,
					})
				).data,
			)

		const [first, second] = [...new Set(orders.map((order) => order.currency))].filter(
			(currency) => currency !== '',
		)
		if (!(first && second)) return // Single-currency store: the claim cannot be tested here.

		// Byte-identical answers for two different currencies is the proof the filter is dropped.
		expect(await scoped(first)).toBe(await scoped(second))
	})

	it('reports the monthly equivalent of every countable subscription as MRR', async () => {
		const subscriptions = (await fetchSubscriptions()).filter(
			(row) => !UNCOUNTED.has(row.status) && row.createdDay.length === 10,
		)
		if (subscriptions.length === 0) return // Nothing recurring: no figure to reconcile.

		// A month every countable subscription had already started and none had left.
		const latestStart = subscriptions
			.map((row) => row.createdDay)
			.sort()
			.at(-1) as string
		const stillRunning = subscriptions.filter(
			(row) => row.canceledAt === null && row.expireAt === null,
		)
		if (stillRunning.length !== subscriptions.length) return // Churn in play; pick a simpler store.

		const month = `${latestStart.slice(0, 7)}-01`
		const rows = await retention(month, `${latestStart.slice(0, 7)}-28`)
		const row = rows.at(-1)
		expect(row, 'no bucket returned for the month every subscription was running').toBeDefined()
		if (!row) return

		const expectedMinor = subscriptions.reduce(
			(sum, entry) => sum + monthlyEquivalent(entry.recurringMinor, entry.interval),
			0,
		)
		// The store divides by 100 in SQL and formats mrr as a string, so compare in decimals.
		expect(Number(row.mrr)).toBeCloseTo(expectedMinor / 100, 2)
		expect(Number(row.active_paid_subscriptions)).toBe(
			subscriptions.filter((entry) => entry.recurringMinor > 0).length,
		)
	})

	it('reports mrr as a decimal string, not as minor units', async () => {
		const rows = await retention('2026-01-01', '2026-12-31')
		const funded = rows.find((row) => Number(row.mrr) > 0)
		if (!funded) return

		// future-renewals returns minor units from the same table; this route does not. Confusing
		// the two overstates recurring revenue by a factor of a hundred.
		expect(typeof funded.mrr).toBe('string')
		expect(String(funded.mrr)).toMatch(/^\d+\.\d{2}$/)
	})
})

describe('trailing revenue checks', () => {
	it('labels every trend bucket it returns', async () => {
		const currency = busiestCurrency()
		if (!currency) return
		const { from, to } = fullRange()

		const trend = await salesTrend(client, {
			from,
			to,
			currency,
			timezone: TZ,
			granularity: 'monthly',
		})

		expect(trend.data.length).toBeGreaterThan(0)
		for (const point of trend.data) {
			expect(point.period, 'an unlabelled bucket cannot be plotted or compared').not.toBe('')
		}
	})
})
