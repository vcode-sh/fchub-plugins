/**
 * Compact views over FluentCart's two whole-world tax rate routes.
 *
 * `GET /tax/rates` and `GET /tax/configuration/rates` both return every country on earth with
 * every rate it has, and neither accepts a single parameter. Measured on the development store:
 * 62,732 and 59,814 characters. Both therefore failed outright — past the 40,000 emergency cap —
 * and the refusal told the caller to "retry with a narrower query", advice no caller could take
 * because the tools had `z.object({})` for a schema. Asking "what is my tax setup" was impossible.
 *
 * The size is not an accident of this store. FluentCart's `TaxManager::generateTaxClasses()` seeds
 * the full ISO country list, so "which countries do I charge tax in" answers "all 250" on any
 * store that has ever configured tax. A list of 250 countries is not an answer; a summary with a
 * way to drill into one is.
 *
 * So: no filter gives group totals, `group` gives that region's countries, and `country` gives one
 * country's rates in full. Every path is served from the single upstream response, which was
 * transferred whole regardless.
 */

/** One rate as `/tax/rates` returns it; `/tax/configuration/rates` swaps class_id for type. */
interface RawRate {
	class_id?: unknown
	type?: unknown
	name?: unknown
	rate?: unknown
	compound?: unknown
	for_shipping?: unknown
}

interface RawCountry {
	country_code?: unknown
	country_name?: unknown
	rates?: unknown
	total_rates?: unknown
}

interface RawGroup {
	group_name?: unknown
	group_code?: unknown
	countries?: unknown
	total_countries?: unknown
}

function asRecord(value: unknown): Record<string, unknown> | null {
	return value !== null && typeof value === 'object' && !Array.isArray(value)
		? (value as Record<string, unknown>)
		: null
}

/**
 * Both routes' groups, as a flat list.
 *
 * `/tax/rates` returns `tax_rates` as an array of groups; `/tax/configuration/rates` returns it as
 * an object keyed by group code holding the same records. Reading both here rather than writing
 * the walk twice — and reading the shape rather than assuming it, because a payload that changes
 * silently should degrade to "no groups", not to a wrong answer.
 */
export function taxGroups(payload: unknown): RawGroup[] {
	const body = asRecord(payload)
	const groups = body?.tax_rates
	if (Array.isArray(groups))
		return groups.filter((entry): entry is RawGroup => asRecord(entry) !== null)

	const keyed = asRecord(groups)
	if (!keyed) return []
	return Object.values(keyed).filter((entry): entry is RawGroup => asRecord(entry) !== null)
}

function countriesOf(group: RawGroup): RawCountry[] {
	return Array.isArray(group.countries)
		? group.countries.filter((entry): entry is RawCountry => asRecord(entry) !== null)
		: []
}

function ratesOf(country: RawCountry): RawRate[] {
	return Array.isArray(country.rates)
		? country.rates.filter((entry): entry is RawRate => asRecord(entry) !== null)
		: []
}

/** `group_code` is null for the ungrouped bucket, whose `group_name` is "Other". */
function groupCode(group: RawGroup): string {
	const code = group.group_code
	if (typeof code === 'string' && code !== '') return code
	const name = group.group_name
	return typeof name === 'string' && name !== '' ? name : 'UNGROUPED'
}

/** The headline rate a person means by "the tax rate here": the standard one, else the highest. */
function headlineRate(rates: RawRate[]): number | null {
	const numeric = rates
		.map((rate) => (typeof rate.rate === 'string' ? Number(rate.rate) : rate.rate))
		.filter((rate): rate is number => typeof rate === 'number' && Number.isFinite(rate))
	if (numeric.length === 0) return null

	const standardIndex = rates.findIndex((rate) => rate.type === 'standard')
	if (standardIndex >= 0 && typeof numeric[standardIndex] === 'number') {
		return numeric[standardIndex] as number
	}
	return Math.max(...numeric)
}

export interface TaxOverview {
	total_countries: number
	total_rates: number
	groups: { group: string; name: unknown; countries: number; rates: number }[]
	note: string
}

export function taxOverview(payload: unknown, note: string): TaxOverview {
	const groups = taxGroups(payload)
	let countries = 0
	let rates = 0

	const rows = groups.map((group) => {
		const list = countriesOf(group)
		const rateCount = list.reduce((sum, country) => sum + ratesOf(country).length, 0)
		countries += list.length
		rates += rateCount
		return {
			group: groupCode(group),
			name: group.group_name,
			countries: list.length,
			rates: rateCount,
		}
	})

	return {
		total_countries: countries,
		total_rates: rates,
		groups: rows,
		note,
	}
}

export interface CountryRow {
	country: unknown
	name: unknown
	rates: number
	standard_rate: number | null
}

/** Countries in one group, compact: enough to see where a rate looks wrong and drill in. */
export function taxCountriesInGroup(payload: unknown, group: string): CountryRow[] {
	const wanted = group.toUpperCase()
	return taxGroups(payload)
		.filter((entry) => groupCode(entry).toUpperCase() === wanted)
		.flatMap(countriesOf)
		.map((country) => ({
			country: country.country_code,
			name: country.country_name,
			rates: ratesOf(country).length,
			standard_rate: headlineRate(ratesOf(country)),
		}))
}

export function taxGroupCodes(payload: unknown): string[] {
	return taxGroups(payload).map(groupCode)
}

export interface CountryDetail {
	country: unknown
	name: unknown
	group: string
	rates: Record<string, unknown>[]
	warnings?: string[]
}

/**
 * One country's rates, with each rate's tax class named rather than left as a bare id.
 *
 * A rate pointing at a deleted class is the failure this is really for. The development store's
 * own country, Poland, carries four rates referencing class ids 2, 3, 4 and 5, none of which
 * exists — `tax_class_list` returns 1, 6, 7 and 8. Reported as "Poland: 23%" that reads as a
 * working configuration, which is the most expensive kind of wrong answer a tax tool can give.
 * `classTitles` is optional so the caller can skip the extra request when it has no use for it.
 */
/** One rate, with its class resolved. Pushes to `warnings` when the class is gone. */
function describeRate(
	rate: RawRate,
	classTitles: Map<number, string> | undefined,
	warnings: string[],
): Record<string, unknown> {
	const raw = rate.class_id
	const classId = typeof raw === 'string' ? Number(raw) : raw
	const hasId = typeof classId === 'number' && Number.isFinite(classId)
	const title = hasId ? classTitles?.get(classId) : undefined

	if (classTitles && hasId && title === undefined) {
		warnings.push(
			`Rate "${String(rate.name ?? classId)}" references tax class ${classId}, which does not exist in this store.`,
		)
	}

	return {
		name: rate.name,
		rate: rate.rate,
		...(rate.type === undefined ? {} : { type: rate.type }),
		...(hasId ? { tax_class: title ?? `unknown (id ${classId})` } : {}),
		...(rate.for_shipping ? { for_shipping: rate.for_shipping } : {}),
		...(rate.compound ? { compound: rate.compound } : {}),
	}
}

export function taxCountryDetail(
	payload: unknown,
	country: string,
	classTitles?: Map<number, string>,
): CountryDetail | null {
	const wanted = country.toUpperCase()

	for (const group of taxGroups(payload)) {
		const entry = countriesOf(group).find(
			(candidate) => String(candidate.country_code ?? '').toUpperCase() === wanted,
		)
		if (!entry) continue

		const warnings: string[] = []
		const rates = ratesOf(entry).map((rate) => describeRate(rate, classTitles, warnings))

		return {
			country: entry.country_code,
			name: entry.country_name,
			group: groupCode(group),
			rates,
			...(warnings.length > 0 ? { warnings } : {}),
		}
	}

	return null
}
