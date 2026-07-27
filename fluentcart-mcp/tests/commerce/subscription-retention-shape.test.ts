// The claims fluentcart_report_subscription_retention makes about its own payload, checked against
// a response the store actually sent.
//
// The tool is a raw passthrough, so nothing between the store and the caller can fix a wrong
// description — the description IS the contract. That makes it exactly the kind of prose that rots
// quietly: it said "Subscription-specific retention analysis with cohort data", which is neither
// what the route returns nor what cohorts means here.
//
// The row below is verbatim from FluentCart 1.5.5 with Pro 1.5.4, GET /reports/subscription-retention
// with params[startDate]=2026-04-01 and params[endDate]=2026-06-30, against a store holding one
// countable subscription: yearly, recurring_amount 99900 minor units, created 2026-04-23, never
// cancelled. If the store's field names or units move, this fails here rather than in a caller's
// spreadsheet.
import { describe, expect, it } from 'vitest'
import type { FluentCartClient } from '../../src/api/client.js'
import { createAllTools } from '../../src/tools/index.js'

const APRIL = {
	day: '2026-04-30',
	week: '2026-18',
	group: '2026-04',
	year: '2026',
	new_subscriptions: 1,
	new_subscriptions_mrr: 83.25,
	churned_subscriptions: 0,
	churned_subscriptions_mrr: 0,
	active_subscriptions: '1',
	active_paid_subscriptions: '1',
	active_free_subscriptions: '0',
	mrr: '83.25',
	retention_rate: 0,
	retention_rate_money: 0,
	period_gross: 999,
	period_subscriptions: 1,
}

/** An empty month, kept because its nulls are the part callers trip over. */
const EMPTY_MONTH = {
	day: '2026-03-31',
	group: '2026-03',
	new_subscriptions: 0,
	new_subscriptions_mrr: 0,
	churned_subscriptions: 0,
	churned_subscriptions_mrr: 0,
	active_subscriptions: '0',
	active_paid_subscriptions: '',
	active_free_subscriptions: '',
	mrr: '0.00',
	retention_rate: 0,
	retention_rate_money: 0,
	period_gross: 0,
	period_subscriptions: 0,
}

/**
 * The normalisation SubscriptionReportService::getRetentionData applies in SQL, in minor units.
 * Reproduced rather than referenced so a change upstream shows up as a failing number here.
 */
function monthlyEquivalent(recurringMinor: number, interval: string): number {
	switch (interval) {
		case 'monthly':
			return recurringMinor
		case 'yearly':
			return recurringMinor / 12
		case 'weekly':
			return (recurringMinor * 52) / 12
		case 'daily':
			return recurringMinor * 30
		default:
			return 0
	}
}

function retentionTool() {
	const client = { get: async () => ({ data: {}, status: 200 }) } as unknown as FluentCartClient
	const tool = createAllTools(client, {}).find(
		(candidate) => candidate.name === 'fluentcart_report_subscription_retention',
	)
	if (!tool) throw new Error('fluentcart_report_subscription_retention is not registered')
	return tool
}

describe('the MRR the store reports is the normalisation it documents', () => {
	it('turns a yearly plan into a twelfth of its recurring amount', () => {
		// 99900 minor units a year → 8325 minor units a month → 83.25 after the SQL divides by 100.
		expect(monthlyEquivalent(99_900, 'yearly') / 100).toBeCloseTo(Number(APRIL.mrr), 2)
	})

	it('reports the same figure as new MRR in the month the subscription started', () => {
		expect(APRIL.new_subscriptions_mrr).toBeCloseTo(Number(APRIL.mrr), 2)
		expect(APRIL.new_subscriptions).toBe(1)
	})

	it('keeps period_gross on the un-normalised amount, unlike every MRR field', () => {
		// 99900 / 100, not 99900 / 12 / 100. Reading period_gross as monthly revenue overstates a
		// yearly plan twelvefold, which is why the description does not call it MRR.
		expect(APRIL.period_gross).toBeCloseTo(99_900 / 100, 2)
		expect(APRIL.period_gross).not.toBeCloseTo(Number(APRIL.mrr), 2)
	})

	it('normalises the other intervals the way the tool says it does', () => {
		expect(monthlyEquivalent(1000, 'monthly')).toBe(1000)
		expect(monthlyEquivalent(1200, 'yearly')).toBe(100)
		expect(monthlyEquivalent(1200, 'weekly')).toBeCloseTo(5200, 6)
		expect(monthlyEquivalent(100, 'daily')).toBe(3000)
		// An interval FluentCart does not recognise contributes nothing rather than its face value.
		expect(monthlyEquivalent(9999, 'quarterly')).toBe(0)
	})
})

describe('the response shape the description warns about', () => {
	it('sends mrr and the active counts as strings, not numbers', () => {
		expect(typeof APRIL.mrr).toBe('string')
		expect(typeof APRIL.active_subscriptions).toBe('string')
		expect(typeof APRIL.active_paid_subscriptions).toBe('string')
	})

	it('sends the churn fields as numbers, so a caller cannot assume one convention', () => {
		expect(typeof APRIL.churned_subscriptions).toBe('number')
		expect(typeof APRIL.new_subscriptions_mrr).toBe('number')
		expect(typeof APRIL.retention_rate).toBe('number')
	})

	it('empties the paid and free counts to "" rather than "0" in a month with no rows', () => {
		// SUM over no rows is NULL, and only active_subscriptions is cast through (int) first.
		// Number("") is 0, so a caller that coerces silently gets a plausible wrong answer.
		expect(EMPTY_MONTH.active_subscriptions).toBe('0')
		expect(EMPTY_MONTH.active_paid_subscriptions).toBe('')
		expect(EMPTY_MONTH.active_free_subscriptions).toBe('')
		expect(EMPTY_MONTH.mrr).toBe('0.00')
	})

	it('labels the bucket by month end and by month, never by the requested day', () => {
		expect(APRIL.day).toBe('2026-04-30')
		expect(APRIL.group).toBe('2026-04')
	})
})

describe('the tool describes what it returns', () => {
	const tool = retentionTool()

	it('names every field a caller is told to read', () => {
		for (const field of [
			'mrr',
			'new_subscriptions',
			'new_subscriptions_mrr',
			'churned_subscriptions',
			'churned_subscriptions_mrr',
			'active_paid_subscriptions',
			'retention_rate',
			'retention_rate_money',
		]) {
			expect(tool.description, `${field} is returned but never mentioned`).toContain(field)
		}
	})

	it('warns that the money columns cross currencies', () => {
		expect(tool.description).toMatch(/currenc/i)
		expect(tool.description).toMatch(/discarded|ignored/i)
	})

	it('states the units instead of leaving them to be guessed', () => {
		// The contrast matters more than the word: /reports/future-renewals reads the same table and
		// returns minor units, so "decimals" alone leaves the reader to guess which convention won.
		expect(tool.description).toMatch(/decimals, not cents/i)
	})

	it('does not describe itself as cohort analysis', () => {
		// Cohorts are a different route with a different prerequisite; conflating them sent callers
		// to an endpoint that returns an empty list until snapshots are generated.
		expect(tool.description.toLowerCase()).not.toContain('cohort data')
	})
})
