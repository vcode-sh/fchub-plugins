// The two tax rate routes, which could not be called and did not mean what they said.
//
// `fluentcart_tax_rate_list` and `fluentcart_tax_config_rates` both had `z.object({})` for a
// schema and both returned every country on earth: 51,773 and 59,814 characters live, past the
// 40,000 emergency cap. So both failed outright, and the refusal advised "retry with a narrower
// query" — impossible advice, since neither tool took a parameter. "What is my tax setup" had no
// answer at all.
//
// Worse than the size was the labelling. `/tax/rates` reads the store's fct_tax_rates table;
// `/tax/configuration/rates` calls TaxManager::getTaxRatesFromTaxPhp(), which reads
// `require __DIR__ . '/tax.php'` — a static file inside the plugin — and never touches the
// database. The old description called that one "tax configuration with all rate overviews". On
// the development store the two disagree about the store's own country: the table holds four
// leftover rates pointing at deleted tax classes, while the file reports a tidy 23/8/0 for Poland.
import { describe, expect, it, vi } from 'vitest'
import type { FluentCartClient } from '../../src/api/client.js'
import { createAllTools } from '../../src/tools/index.js'
import { taxCountryDetail, taxGroups, taxOverview } from '../../src/tools/tax-rate-views.js'

/** `/tax/rates` shape: groups in an array, rates carrying class_id. */
const RATES_ROUTE = {
	tax_rates: [
		{
			group_name: 'Europe',
			group_code: 'EU',
			total_countries: 2,
			countries: [
				{
					country_code: 'DE',
					country_name: 'Germany',
					total_rates: 2,
					rates: [
						{ class_id: '6', name: 'DE Standard Tax', rate: '19' },
						{ class_id: '7', name: 'DE Reduced Tax', rate: '7' },
					],
				},
				{
					country_code: 'FR',
					country_name: 'France',
					total_rates: 1,
					rates: [{ class_id: '6', name: 'FR Standard', rate: '20' }],
				},
			],
		},
		{
			group_name: 'Other',
			group_code: null,
			total_countries: 1,
			countries: [
				{
					country_code: 'PL',
					country_name: 'Poland',
					total_rates: 1,
					rates: [{ class_id: '2', name: 'R4 VAT 1772581316241', rate: '23' }],
				},
			],
		},
	],
	country_enabled_map: { DE: true, FR: true, PL: true },
}

/** `/tax/configuration/rates` shape: groups keyed by code, rates carrying type. */
const CONFIG_ROUTE = {
	tax_rates: {
		EU: {
			group_name: 'European Union',
			group_code: 'EU',
			total_countries: 1,
			countries: [
				{
					country_code: 'PL',
					country_name: 'Poland',
					total_rates: 3,
					rates: [
						{ rate: 23, compound: false, type: 'standard', name: 'VAT' },
						{ rate: 8, compound: false, type: 'reduced' },
						{ rate: 0, compound: false, type: 'zero' },
					],
				},
			],
		},
	},
}

const CLASSES = {
	classes: [
		{ id: 6, title: 'Standard' },
		{ id: 7, title: 'Reduced' },
	],
}

function toolFor(payload: unknown, name: string) {
	const get = vi.fn().mockImplementation(async (path: string) => {
		if (path === '/tax/classes') return { data: CLASSES, status: 200 }
		return { data: payload, status: 200 }
	})
	const client = { get } as unknown as FluentCartClient
	const tool = createAllTools(client, {}).find((candidate) => candidate.name === name)
	if (!tool) throw new Error(`${name} is not registered`)
	return tool
}

async function call(payload: unknown, name: string, input: Record<string, unknown>) {
	const tool = toolFor(payload, name)
	const result = (await tool.handler(input as never, {} as never)) as {
		isError?: boolean
		content: { text: string }[]
	}
	return { isError: Boolean(result.isError), body: JSON.parse(result.content[0]?.text ?? '{}') }
}

describe('both routes are readable at all', () => {
	it('summarises rather than returning every country', async () => {
		const { body } = await call(RATES_ROUTE, 'fluentcart_tax_rate_list', {})

		expect(body.total_countries).toBe(3)
		expect(body.total_rates).toBe(4)
		expect(body.groups.map((row: { group: string }) => row.group)).toEqual(['EU', 'Other'])
	})

	it('reads the keyed group object the configuration route uses', async () => {
		// The same walk over a different container. Getting this wrong would return an empty
		// summary that looks like a store with no tax at all.
		expect(taxGroups(CONFIG_ROUTE)).toHaveLength(1)
		const { body } = await call(CONFIG_ROUTE, 'fluentcart_tax_config_rates', {})
		expect(body.total_countries).toBe(1)
		expect(body.total_rates).toBe(3)
	})

	it('pages a region instead of dumping it', async () => {
		const { body } = await call(RATES_ROUTE, 'fluentcart_tax_rate_list', {
			group: 'EU',
			per_page: 1,
		})

		expect(body.countries).toEqual([
			{ country: 'DE', name: 'Germany', rates: 2, standard_rate: 19 },
		])
		expect(body.total).toBe(2)
		expect(body.has_more).toBe(true)
	})

	it('names the groups it does know when asked for one it does not', async () => {
		const { body } = await call(RATES_ROUTE, 'fluentcart_tax_rate_list', { group: 'NOPE' })
		expect(body.countries).toEqual([])
		expect(body.known_groups).toEqual(['EU', 'Other'])
	})

	it('says so plainly when a country has no rates', async () => {
		const { body } = await call(RATES_ROUTE, 'fluentcart_tax_rate_list', { country: 'ZZ' })
		expect(body.rates).toEqual([])
		expect(body.message).toContain('ZZ')
	})

	it('matches a country code whatever case it arrives in', async () => {
		const { body } = await call(RATES_ROUTE, 'fluentcart_tax_rate_list', { country: 'de' })
		expect(body.name).toBe('Germany')
	})
})

describe('a rate pointing at a deleted tax class is flagged', () => {
	it('warns instead of reporting a clean 23%', async () => {
		// The whole point. Poland reads as a working 23% VAT unless something says the class is gone.
		const { body } = await call(RATES_ROUTE, 'fluentcart_tax_rate_list', { country: 'PL' })

		expect(body.rates[0].tax_class).toBe('unknown (id 2)')
		expect(body.warnings).toHaveLength(1)
		expect(body.warnings[0]).toContain('tax class 2')
		expect(body.warnings[0]).toContain('does not exist')
	})

	it('stays quiet when every class resolves', async () => {
		const { body } = await call(RATES_ROUTE, 'fluentcart_tax_rate_list', { country: 'DE' })
		expect(body.rates.map((rate: { tax_class: string }) => rate.tax_class)).toEqual([
			'Standard',
			'Reduced',
		])
		expect(body).not.toHaveProperty('warnings')
	})

	it('does not invent a warning when the class list could not be read', async () => {
		// Losing the titles is a nicety lost; claiming every class is missing would be a lie.
		const detail = taxCountryDetail(RATES_ROUTE, 'PL', undefined)
		expect(detail?.warnings).toBeUndefined()
	})
})

describe('the reference route says it is not the store', () => {
	it('carries the warning on every shape it returns', async () => {
		for (const input of [{}, { country: 'PL' }, { group: 'EU' }]) {
			const { body } = await call(CONFIG_ROUTE, 'fluentcart_tax_config_rates', input)
			expect(body.note, `note missing for ${JSON.stringify(input)}`).toContain(
				'REFERENCE DATA, NOT THIS STORE',
			)
		}
	})

	it('opens its description with the same warning', () => {
		const tool = toolFor(CONFIG_ROUTE, 'fluentcart_tax_config_rates')
		expect(tool.description.startsWith('REFERENCE DATA, NOT THIS STORE')).toBe(true)
		expect(tool.description).toContain('fluentcart_tax_rate_list')
	})

	it('does not claim the store route is reference data', async () => {
		const { body } = await call(RATES_ROUTE, 'fluentcart_tax_rate_list', {})
		expect(body.note).toContain('this store has stored')
	})
})

describe('seeding countries is described as the addition it is', () => {
	it('refuses an empty array, which would seed every country on earth', () => {
		// FluentCart's saveConfiguredCountries passes [] straight to generateTaxClasses, which then
		// skips Arr::only and walks the entire ISO list. It answers "Countries saved successfully".
		const tool = toolFor(RATES_ROUTE, 'fluentcart_tax_config_countries_save')
		expect(tool.schema.safeParse({ countries: [] }).success).toBe(false)
		expect(tool.schema.safeParse({ countries: ['PL'] }).success).toBe(true)
	})

	it('does not describe itself as saving or replacing a list', () => {
		// It only ever adds: every country already present is skipped, nothing is removed. An agent
		// told "charge tax only in Poland" would otherwise call this and believe it had done so.
		const tool = toolFor(RATES_ROUTE, 'fluentcart_tax_config_countries_save')
		expect(tool.description).toContain('ADDS ONLY')
		expect(tool.description).toMatch(/cannot be used to restrict/i)
		expect(tool.title).not.toMatch(/^Save Tax Countries$/)
	})
})

describe('the summary degrades rather than lying', () => {
	it('reports nothing for a payload it does not recognise', () => {
		expect(taxOverview({ unexpected: true }, 'n').total_countries).toBe(0)
		expect(taxGroups(null)).toEqual([])
		expect(taxGroups({ tax_rates: 'not a container' })).toEqual([])
	})

	it('survives a group whose countries are missing', () => {
		const overview = taxOverview({ tax_rates: [{ group_code: 'EU' }] }, 'n')
		expect(overview.groups).toEqual([{ group: 'EU', name: undefined, countries: 0, rates: 0 }])
	})
})
