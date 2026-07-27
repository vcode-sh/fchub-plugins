import { describe, expect, it } from 'vitest'
import type { AcceptedReport } from '../../src/commerce/report-contracts.js'
import {
	ABSENT_ROUTES,
	acceptedReports,
	contractDefects,
	isAccepted,
	REPORT_CONTRACTS,
	REPORT_NAMES,
	REQUIRED_ACCEPTED_FIELDS,
} from '../../src/commerce/report-contracts.js'

/**
 * The four endpoints that began returning 200 on 2026-07-27 are not represented here as metrics.
 * A route that stopped erroring has proved it can respond, which is not the same as having proved
 * what its numbers mean.
 */
const NEWLY_RESPONDING_ROUTES = [
	'/reports/report-overview',
	'/reports/revenue-by-group',
	'/reports/fetch-order-by-group',
	'/reports/quick-order-stats',
]

const ACCEPTED = ['sales_summary', 'sales_trend', 'top_products']
const DIAGNOSTIC = ['refund_summary', 'future_renewals', 'order_sources']

function accepted(name: string): AcceptedReport {
	const contract = REPORT_CONTRACTS[name as (typeof REPORT_NAMES)[number]]
	if (!isAccepted(contract)) throw new Error(`${name} is not accepted`)
	return contract
}

describe('report registry', () => {
	it('covers every declared report name exactly once', () => {
		expect(Object.keys(REPORT_CONTRACTS).sort()).toEqual([...REPORT_NAMES].sort())
		expect(new Set(REPORT_NAMES).size).toBe(REPORT_NAMES.length)
	})

	it('accepts only the three reports whose semantics are proven', () => {
		expect(acceptedReports().map((contract) => contract.name)).toEqual(ACCEPTED)
	})

	it('keeps the rest diagnostic-only with a stated reason', () => {
		for (const name of DIAGNOSTIC) {
			const contract = REPORT_CONTRACTS[name as (typeof REPORT_NAMES)[number]]
			expect(contract.status).toBe('diagnostic-only')
			expect('rejection' in contract && contract.rejection.length).toBeGreaterThan(40)
		}
	})

	it('names a controller for every report, accepted or not', () => {
		for (const name of REPORT_NAMES) {
			const { evidence } = REPORT_CONTRACTS[name]
			expect(evidence.controllerFile).toMatch(/^app\/Http\/Controllers\/Reports\/.+\.php$/)
			expect(evidence.controllerMethod).toMatch(/^[a-zA-Z]+$/)
		}
	})
})

describe('accepted contracts are complete', () => {
	it('reports no defects', () => {
		for (const contract of acceptedReports()) {
			expect(contractDefects(contract), contract.name).toEqual([])
		}
	})

	it('carries every required field', () => {
		for (const contract of acceptedReports()) {
			for (const field of REQUIRED_ACCEPTED_FIELDS) {
				expect(contract[field], `${contract.name}.${field}`).toBeDefined()
			}
		}
	})

	it('rejects a contract with any single field removed', () => {
		for (const field of REQUIRED_ACCEPTED_FIELDS) {
			const stripped = { ...accepted('sales_summary') } as Record<string, unknown>
			delete stripped[field]
			const defects = contractDefects(stripped as unknown as AcceptedReport)
			expect(defects.join(' '), `removing ${field} must be caught`).toContain(field)
		}
	})

	it('cites a service method, not only a controller', () => {
		for (const contract of acceptedReports()) {
			expect(contract.evidence.serviceFile).toMatch(/\.php$/)
			expect(contract.evidence.serviceMethod.length).toBeGreaterThan(0)
			expect(contract.evidence.notes.length).toBeGreaterThan(40)
		}
	})
})

describe('currency behaviour', () => {
	it('never accepts a report that sums across currencies', () => {
		for (const contract of acceptedReports()) {
			expect(contract.currency.handling).not.toBe('summed-across-currencies')
		}
	})

	it('pins a currency through a named request field', () => {
		for (const contract of acceptedReports()) {
			expect(contract.currency.handling).toBe('caller-scoped')
			expect(contract.currency.scopedBy).toBe('params[currency]')
			expect(contract.requestFields).toContain('params[currency]')
		}
	})

	it('rejects a caller-scoped claim with no field behind it', () => {
		const broken = {
			...accepted('sales_summary'),
			currency: { handling: 'caller-scoped' as const, scopedBy: null },
		}
		expect(contractDefects(broken).join(' ')).toContain('names no request field')
	})

	it('rejects a summed-currency contract outright', () => {
		const broken = {
			...accepted('sales_summary'),
			currency: { handling: 'summed-across-currencies' as const, scopedBy: null },
		}
		expect(contractDefects(broken).join(' ')).toContain('sums across currencies')
	})
})

describe('period and payment scope are stated, not assumed', () => {
	it('names the exact column and boundary rule', () => {
		for (const contract of acceptedReports()) {
			expect(contract.dates.column).toBe('fct_orders.created_at')
			expect(contract.dates.boundaries).toBe('inclusive-both-ends')
		}
	})

	it('records that test-mode orders are counted and cannot be filtered out', () => {
		for (const contract of acceptedReports()) {
			expect(contract.paymentMode.scope).toBe('live-and-test-combined')
			expect(contract.paymentMode.filterable).toBe(false)
		}
	})

	it('warns about the test-mode inclusion on every accepted report', () => {
		for (const contract of acceptedReports()) {
			expect(contract.warnings.join(' ')).toMatch(/test-mode/)
		}
	})

	it('warns that date ranges depend on FluentCart Pro being active', () => {
		for (const contract of acceptedReports()) {
			expect(contract.warnings.join(' ')).toMatch(/Pro/)
		}
	})

	/**
	 * Revenue attributes a refund to the order's creation date, so a sealed month can still move.
	 * Recorded as a warning rather than silently corrected, because correcting it client-side would
	 * be exactly the kind of unreviewed formula this contract exists to forbid.
	 */
	it('marks the revenue reports retroactive and says so out loud', () => {
		for (const name of ['sales_summary', 'sales_trend']) {
			const contract = accepted(name)
			expect(contract.dates.retroactive).toBe(true)
			expect(contract.warnings.join(' ')).toMatch(/creation date/)
		}
	})

	it('states empty-set behaviour for every accepted report', () => {
		for (const contract of acceptedReports()) {
			expect(contract.emptySet.length).toBeGreaterThan(20)
		}
	})

	/**
	 * The ORM binds period bounds as store-local wall clock, but FluentCart's own DateTime subclass
	 * overrides now() to UTC while inheriting a wp_timezone constructor, so the two disagree inside
	 * one class. Until a seeded order proves which bucket it lands in, this stays source-only and
	 * every result says so. Flipping it to seeded-assertion without a live test would be the exact
	 * unproven claim these contracts exist to prevent.
	 */
	it('does not claim seeded proof of timezone until a seeded assertion exists', () => {
		for (const contract of acceptedReports()) {
			expect(contract.timezone.basis).toBe('store-local')
			expect(contract.timezone.provenBy, `${contract.name} claims proof it does not have`).toBe(
				'source-only',
			)
			expect(contract.warnings.join(' ')).toMatch(/no seeded assertion has confirmed/)
		}
	})
})

describe('routes this runtime does not serve', () => {
	it('lists the unfulfilled-orders route as absent', () => {
		expect(ABSENT_ROUTES).toContain('/reports/get-unfulfilled-orders')
	})

	it('accepts no report pointing at an absent route', () => {
		for (const contract of acceptedReports()) {
			expect(ABSENT_ROUTES).not.toContain(contract.path)
		}
	})

	it('rejects a contract retargeted at an absent route', () => {
		const broken = { ...accepted('sales_summary'), path: '/reports/get-unfulfilled-orders' }
		expect(contractDefects(broken).join(' ')).toContain('does not serve')
	})
})

describe('newly responding endpoints stay diagnostic', () => {
	it('promotes none of the four to a metric', () => {
		const acceptedPaths = acceptedReports().map((contract) => contract.path)
		for (const path of NEWLY_RESPONDING_ROUTES) {
			expect(acceptedPaths, `${path} returns 200 but its meaning is unproven`).not.toContain(path)
		}
	})

	it('does not reach for the report-overview route, whose window is hardcoded', () => {
		const paths = acceptedReports().map((contract) => contract.path)
		expect(paths).not.toContain('/reports/overview')
		expect(paths).not.toContain('/reports/report-overview')
	})

	/**
	 * `/reports/sales-report` looks like the obvious source for a sales summary and is not: it
	 * inner-joins a subquery that filters `fct_order_items.created_at`, so an order whose items
	 * fall outside the window loses its revenue, not merely its item count.
	 */
	it('does not source any metric from the double-date-filtered sales-report route', () => {
		const paths = acceptedReports().map((contract) => contract.path)
		expect(paths).not.toContain('/reports/sales-report')
	})
})
