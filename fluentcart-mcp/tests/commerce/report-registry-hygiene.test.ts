// What the report registry is allowed to claim about routes it does not serve, and about the one
// report whose payload is far better than its contract.
//
// Two things this pins that nothing else did:
//
// 1. ABSENT_ROUTES listed only /reports/get-unfulfilled-orders. /reports/cart-report answers 404
//    rest_no_route on FluentCart 1.5.5 as well, so a contract could have pointed at it and passed
//    the completeness check that exists precisely to stop that.
//
// 2. /reports/subscription-retention returns the richest analytics payload in the plugin — a full
//    monthly MRR and churn series — and is still rejected, for the same reason future_renewals is:
//    fct_subscriptions has no currency column. Recording the rejection here keeps the two from
//    drifting apart, because the temptation to promote this one will come back.
import { describe, expect, it } from 'vitest'
import {
	ABSENT_ROUTES,
	acceptedReports,
	isAccepted,
	REPORT_CONTRACTS,
	REPORT_NAMES,
} from '../../src/commerce/report-contracts.js'
import { diagnosticReportReasons } from '../../src/tools/commerce-reporting.js'

describe('absent routes', () => {
	it('lists both routes this runtime answers 404 for', () => {
		expect([...ABSENT_ROUTES].sort()).toEqual([
			'/reports/cart-report',
			'/reports/get-unfulfilled-orders',
		])
	})

	it('keeps every accepted report off them', () => {
		for (const contract of acceptedReports()) {
			expect(ABSENT_ROUTES, `${contract.name} points at an absent route`).not.toContain(
				contract.path,
			)
		}
	})

	it('keeps every diagnostic report off them too', () => {
		// A diagnostic report still gets called. Pointing one at a route that does not exist would
		// turn "we cannot vouch for this number" into "this endpoint 404s", which is a different
		// problem with a different fix.
		for (const name of REPORT_NAMES) {
			const contract = REPORT_CONTRACTS[name]
			expect(ABSENT_ROUTES, `${name} points at an absent route`).not.toContain(contract.path)
		}
	})
})

describe('subscription retention stays diagnostic', () => {
	const contract = REPORT_CONTRACTS.subscription_retention

	it('is registered and not accepted', () => {
		expect(REPORT_NAMES).toContain('subscription_retention')
		expect(contract.status).toBe('diagnostic-only')
		expect(isAccepted(contract)).toBe(false)
	})

	it('reads the endpoint the MRR series actually lives on', () => {
		expect(contract.path).toBe('/reports/subscription-retention')
		expect(contract.method).toBe('GET')
	})

	it('rejects it for the currency column, not for something vaguer', () => {
		const rejection = 'rejection' in contract ? contract.rejection : ''
		// The specific, checkable claim: currency is unreachable, so money is summed across it.
		expect(rejection).toMatch(/currency/i)
		expect(rejection).toMatch(/config/)
		expect(rejection.length).toBeGreaterThan(120)
	})

	it('gives the same reason future_renewals was given, so the two cannot drift', () => {
		const renewals = REPORT_CONTRACTS.future_renewals
		const both = [contract, renewals].map((entry) => ('rejection' in entry ? entry.rejection : ''))
		for (const reason of both) {
			expect(reason).toMatch(/currenc/i)
		}
	})

	it('cites the controller a reviewer can open', () => {
		expect(contract.evidence.controllerFile).toBe(
			'app/Http/Controllers/Reports/SubscriptionReportController.php',
		)
		expect(contract.evidence.controllerMethod).toBe('getRetentionData')
	})

	it('surfaces through the caller-facing list of what was refused', () => {
		const listed = diagnosticReportReasons().find(
			(entry) => entry.name === 'subscription_retention',
		)
		expect(listed).toBeDefined()
		expect(listed?.path).toBe('/reports/subscription-retention')
		expect(listed?.reason.length).toBeGreaterThan(120)
	})
})
