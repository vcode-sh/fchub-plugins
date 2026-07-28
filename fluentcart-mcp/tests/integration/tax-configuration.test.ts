// Live lane: the tax configuration path, which could not be walked at all.
//
// `fluentcart_tax_rate_list` and `fluentcart_tax_config_rates` both declared `z.object({})` and
// both returned every country on earth — 51,773 and 59,814 characters against a 40,000 cap. Both
// therefore refused every call, advising "retry with a narrower query" when neither accepted a
// parameter. "Show me my tax setup" had no answer.
//
// This lane is read-only by construction: it calls no write tool, creates no fixture and needs no
// cleanup. It asserts the shape of the answer and that the answer fits, not the store's particular
// numbers, so it stays true as the store's tax data changes.
import { beforeAll, describe, expect, it } from 'vitest'
import type { ToolDefinition } from '../../src/tools/_factory.js'
import { createAllTools } from '../../src/tools/index.js'
import { getLiveClient } from './support/live-client.js'

let tools: ToolDefinition[]

beforeAll(() => {
	tools = createAllTools(getLiveClient(), {})
})

async function call(name: string, input: Record<string, unknown> = {}) {
	const tool = tools.find((candidate) => candidate.name === name)
	if (!tool) throw new Error(`${name} is not registered`)
	const result = (await tool.handler(input as never, {} as never)) as {
		isError?: boolean
		content: { text: string }[]
	}
	const text = result.content[0]?.text ?? ''
	return { isError: Boolean(result.isError), text, body: JSON.parse(text || '{}') }
}

/** The paged budget. Both tools used to clear the 40,000 emergency cap, never mind this one. */
const BUDGET = 24_000

describe('the tax setup can be read without an argument', () => {
	for (const name of ['fluentcart_tax_rate_list', 'fluentcart_tax_config_rates']) {
		it(`${name.replace('fluentcart_', '')} answers, and fits`, async () => {
			const { isError, text, body } = await call(name)

			expect(isError, text.slice(0, 300)).toBe(false)
			expect(text.length).toBeLessThan(BUDGET)
			expect(body.total_countries).toBeGreaterThan(0)
			expect(Array.isArray(body.groups)).toBe(true)
			expect(body.note.length).toBeGreaterThan(0)
		})
	}

	it('pages a region rather than returning it whole', async () => {
		const { body } = await call('fluentcart_tax_rate_list', { group: 'EU', per_page: 5 })

		expect(body.countries.length).toBeLessThanOrEqual(5)
		expect(body.total).toBeGreaterThanOrEqual(body.countries.length)
		for (const row of body.countries) expect(row).toHaveProperty('standard_rate')
	})

	it('returns one country small enough to read', async () => {
		const { isError, text, body } = await call('fluentcart_tax_rate_list', { country: 'DE' })

		expect(isError).toBe(false)
		expect(text.length).toBeLessThan(2_000)
		expect(body.country).toBe('DE')
		expect(body.rates.length).toBeGreaterThan(0)
	})

	it('names the regions it knows when handed one it does not', async () => {
		const { body } = await call('fluentcart_tax_rate_list', { group: 'NOT-A-REGION' })
		expect(body.countries).toEqual([])
		expect(body.known_groups.length).toBeGreaterThan(0)
	})
})

describe('a rate whose tax class is gone does not read as a working rate', () => {
	it('flags every unresolved class, or none if the store has none', async () => {
		// Asserted as an implication rather than against a fixed country: this store currently has
		// four such rates on its own country, left behind by an earlier run, but the point is the
		// rule, not those rows. If the data is cleaned up the test still means something.
		const classes = await call('fluentcart_tax_class_list')
		const known = new Set(
			(classes.body.classes as { id: number }[]).map((entry) => String(entry.id)),
		)

		const { body: overview } = await call('fluentcart_tax_rate_list')
		const groups = (overview.groups as { group: string }[]).map((row) => row.group)

		let checked = 0
		for (const group of groups) {
			const { body } = await call('fluentcart_tax_rate_list', { group, per_page: 50 })
			for (const row of (body.countries as { country: string; rates: number }[]).slice(0, 4)) {
				if (row.rates === 0) continue
				const detail = await call('fluentcart_tax_rate_list', { country: row.country })
				const unknown = (detail.body.rates as { tax_class?: string }[]).filter((rate) =>
					String(rate.tax_class ?? '').startsWith('unknown'),
				)
				checked += 1

				if (unknown.length > 0) {
					expect(
						detail.body.warnings?.length,
						`${row.country} has ${unknown.length} rates on a missing class but no warning`,
					).toBe(unknown.length)
				} else {
					expect(detail.body.warnings, `${row.country} warned with nothing missing`).toBeUndefined()
				}
			}
		}

		expect(checked, 'the sweep must actually have checked something').toBeGreaterThan(0)
		expect(known.size).toBeGreaterThan(0)
	})
})

describe('the reference table is labelled as not being the store', () => {
	it('says so in the payload, on every shape', async () => {
		for (const input of [{}, { country: 'DE' }, { group: 'EU' }]) {
			const { body } = await call('fluentcart_tax_config_rates', input)
			expect(body.note).toContain('REFERENCE DATA, NOT THIS STORE')
		}
	})

	it('really is a different dataset from the store rates', async () => {
		// Not a style preference. `/tax/configuration/rates` runs
		// TaxManager::getTaxRatesFromTaxPhp(), which reads `require __DIR__ . '/tax.php'` and never
		// queries the database, so the two can disagree about the store's own country while both
		// look authoritative. Asserted loosely — the two may legitimately agree on a clean store —
		// but the labels must never claim they are the same thing.
		const store = await call('fluentcart_tax_rate_list')
		const reference = await call('fluentcart_tax_config_rates')

		expect(store.body.note).not.toContain('REFERENCE DATA')
		expect(reference.body.note).toContain('REFERENCE DATA')
		expect(store.body.total_countries).toBeGreaterThan(0)
		expect(reference.body.total_countries).toBeGreaterThan(0)
	})
})

// Shipping configuration shares the failure mode this lane was written for: a parameter the store
// does not read, answered with an empty list and HTTP 200. Kept here rather than in a lane of its
// own because both are "can a merchant see how the store is set up", and both are read-only.
describe('shipping destinations are asked about correctly', () => {
	it("returns a country's subdivisions instead of an empty list", async () => {
		const { isError, body } = await call('fluentcart_shipping_zone_states', { country_code: 'US' })

		expect(isError).toBe(false)
		// The parameter used to be spelled `country`, which the route ignores; every country came
		// back with zero. The exact count is FluentCart's business, but a country with fifty states
		// must not report none.
		expect(body.data.country_code).toBe('US')
		expect(body.data.states.length).toBeGreaterThan(40)
	})

	it('distinguishes countries rather than serving one answer for all', async () => {
		const us = await call('fluentcart_shipping_zone_states', { country_code: 'US' })
		const de = await call('fluentcart_shipping_zone_states', { country_code: 'DE' })

		expect(de.body.data.country_code).toBe('DE')
		expect(de.body.data.states.length).toBeGreaterThan(0)
		expect(de.body.data.states.length).not.toBe(us.body.data.states.length)
	})

	it('finds a method applicable to a destination that was previously filed as unreachable', async () => {
		// With no country the controller short-circuits: shipping_methods empty, every enabled
		// method under other_shipping_methods, which means "does not reach here".
		const blind = await call('fluentcart_order_shipping_methods', {})
		const aimed = await call('fluentcart_order_shipping_methods', { country_code: 'PL' })

		expect(blind.body.shipping_methods).toEqual([])
		expect(
			aimed.body.shipping_methods.length + aimed.body.other_shipping_methods.length,
			'the same methods must be accounted for either way',
		).toBe(blind.body.other_shipping_methods.length)
	})
})
