/**
 * The two tools over FluentCart's whole-world tax rate routes.
 *
 * Split out of tax.ts because they need shared plumbing — one schema, one handler, one note per
 * route — and carrying it there pushed that file past the 280-line limit in this project's
 * CLAUDE.md. The projection they call lives in tax-rate-views.ts; this file is the wiring.
 */
import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { createTool, type ToolDefinition } from './_factory.js'
import { direct } from './endpoints.js'
import { asNumber } from './tax.js'
import {
	taxCountriesInGroup,
	taxCountryDetail,
	taxGroupCodes,
	taxOverview,
} from './tax-rate-views.js'

/** Shared by both whole-world rate routes; see tax-rate-views.ts for why they need one. */
const taxViewShape = z.object({
	country: z
		.string()
		.optional()
		.describe('ISO 3166-1 alpha-2 code, e.g. "PL". Returns that country\'s rates in full'),
	group: z
		.string()
		.optional()
		.describe("Region code, e.g. EU, NA, SA, AS, AF, OC. Returns that region's countries"),
	page: z.number().optional().describe('Page number within a group (default: 1)'),
	per_page: z
		.number()
		.max(50)
		.optional()
		.describe(
			'Countries per page within a group (default: 50, max: 50). Applied here, not by the store',
		),
})

/**
 * Read the tax class titles so a rate can name its class instead of showing a bare id.
 *
 * Best-effort on purpose: a caller asking about one country should still get the rates if the
 * class list is unreachable. Losing the titles costs a nicety; failing the whole call over it
 * would cost the answer.
 */
async function taxClassTitles(client: FluentCartClient): Promise<Map<number, string> | undefined> {
	try {
		const response = await client.get('/tax/classes')
		const body = response.data as Record<string, unknown>
		const classes = Array.isArray(body?.classes) ? (body.classes as Record<string, unknown>[]) : []
		if (classes.length === 0) return undefined

		const titles = new Map<number, string>()
		for (const entry of classes) {
			const id = asNumber(entry.id)
			if (id !== null) titles.set(id, String(entry.title ?? id))
		}
		return titles
	} catch {
		return undefined
	}
}

/**
 * What each route is, in the words the answer needs.
 *
 * They are not two formats of one dataset, which is what the old descriptions implied and what
 * cost an hour to disprove. `GET /tax/rates` reads the store's `fct_tax_rates` table. `GET
 * /tax/configuration/rates` calls `TaxManager::getTaxRatesFromTaxPhp()`, and that method opens
 * with `$rates = $this->rates`, loaded at construction by
 * `require __DIR__ . '/tax.php'` — a static file shipped inside the plugin. It never touches the
 * database.
 *
 * The difference is not academic. On the development store the two disagree about the store's own
 * country: the live table holds four leftover rates named "R4 VAT …" pointing at deleted tax
 * classes, while the reference file says Poland is 23/8/0. An agent asked "what tax am I charging
 * in Poland" and pointed at the reference file answers 23% and sounds certain, and it is wrong
 * about this store in the way that matters most.
 */
const VIEW_NOTES: Readonly<Record<string, string>> = {
	'/tax/rates':
		'These are the rates this store has stored. FluentCart seeds the full ISO country list the first time tax is configured, so a country appearing here does not mean the store deliberately sells there. Pass country for one country in full, or group for a region.',
	'/tax/configuration/rates':
		'REFERENCE DATA, NOT THIS STORE. These are the default rates FluentCart ships in tax.php, the same list it seeds from; they are not read from the store and may differ from what it actually charges. For the configured rates use fluentcart_tax_rate_list.',
}

async function taxRateView(
	client: FluentCartClient,
	endpoint: string,
	input: Record<string, unknown>,
): Promise<unknown> {
	const response = await client.get(endpoint)
	const payload = response.data
	const note = VIEW_NOTES[endpoint] ?? ''

	const country = input.country as string | undefined
	if (country) {
		const detail = taxCountryDetail(payload, country, await taxClassTitles(client))
		if (detail) return { ...detail, note }
		return {
			note,
			country: country.toUpperCase(),
			rates: [],
			message: `No tax rates are configured for ${country.toUpperCase()}.`,
			known_groups: taxGroupCodes(payload),
		}
	}

	const group = input.group as string | undefined
	if (group) {
		const all = taxCountriesInGroup(payload, group)
		if (all.length === 0) {
			return {
				group: group.toUpperCase(),
				countries: [],
				message: `No region called ${group.toUpperCase()}.`,
				known_groups: taxGroupCodes(payload),
			}
		}

		const page = Math.max(1, (input.page as number) ?? 1)
		const perPage = Math.min(50, Math.max(1, (input.per_page as number) ?? 50))
		const from = (page - 1) * perPage
		const countries = all.slice(from, from + perPage)
		return {
			note,
			group: group.toUpperCase(),
			countries,
			page,
			per_page: perPage,
			total: all.length,
			has_more: from + countries.length < all.length,
		}
	}

	return taxOverview(payload, note)
}

/** The pair, spread into taxTools() so the registry order is unchanged. */
export function taxRateViewTools(client: FluentCartClient): ToolDefinition[] {
	return [
		createTool(client, {
			name: 'fluentcart_tax_rate_list',
			routes: direct('GET', '/tax/rates'),
			title: 'List Tax Rates',
			description:
				'The tax this store charges: which regions and countries have rates, and what those rates ' +
				'are. Read from the store, so this is the one to answer what tax is charged where. ' +
				'With no arguments it returns group totals, because FluentCart seeds the whole ISO ' +
				'country list when tax is first configured — a country having a rate does not mean the ' +
				'store chose to sell there. Pass group for a region, or country for one country in full, ' +
				'where each rate names its tax class and a rate pointing at a class that no longer exists ' +
				'is flagged. The upstream route accepts no parameters and returns every country at once, ' +
				'so all of this is applied here.',
			schema: taxViewShape,
			annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: true },
			handler: (apiClient, input) => taxRateView(apiClient, '/tax/rates', input),
		}),
		createTool(client, {
			name: 'fluentcart_tax_config_rates',
			routes: direct('GET', '/tax/configuration/rates'),
			title: 'Look Up Default Tax Rates by Country',
			description:
				'REFERENCE DATA, NOT THIS STORE. FluentCart ships a table of statutory rates per country ' +
				'in tax.php and seeds new countries from it; this route reads that file and never queries ' +
				"the store, so it reports what a country's VAT generally is rather than this store's own " +
				'setup. The two do diverge, and this one always looks tidy because defaults always are. ' +
				'Use it to look up a default before creating a rate. For the rates this store applies, ' +
				'use fluentcart_tax_rate_list. Same arguments: none gives group totals, group gives a ' +
				'region, country gives one country.',
			schema: taxViewShape,
			annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: true },
			handler: (apiClient, input) => taxRateView(apiClient, '/tax/configuration/rates', input),
		}),
	]
}
