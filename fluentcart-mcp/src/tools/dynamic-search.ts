import type { ToolDefinition } from './_factory.js'

export const CATEGORIES = [
	'product',
	'order',
	'customer',
	'coupon',
	'report',
	'subscription',
	'integration',
	'setting',
	'shipping',
	'tax',
	'email',
	'role',
	'file',
	'label',
	'activity',
	'note',
	'dashboard',
	'application',
	'public',
	'misc',
] as const

export type Category = (typeof CATEGORIES)[number]

export function inferCategory(toolName: string): Category {
	const name = toolName.replace(/^fluentcart_/, '')
	for (const category of CATEGORIES) {
		if (name.startsWith(category)) return category
	}
	return 'misc'
}

/**
 * Reduce an English plural to the stem the tool names use.
 *
 * Tool names are singular — `tax_settings_get`, `order_list`, `product_list` — and people ask in
 * the plural. Without this, "configure taxes" matched no tax tool at all and fell through to
 * whatever happened to mention "configure", while "orders" and "products" scored only on prose in
 * a description rather than on the name.
 *
 * Deliberately blunt: strip a trailing `es` or `s` and keep at least three characters. Words
 * ending `ss` or `us` are left alone, so `status` does not become `statu` and `address` does not
 * become `addres`.
 */
function stem(word: string): string {
	if (word.length < 4) return word
	if (word.endsWith('ss') || word.endsWith('us')) return word
	// `es` only comes off after a sibilant, where English added it to be pronounceable:
	// taxes → tax, boxes → box, classes → class. Everywhere else the `e` belongs to the word, and
	// stripping it does real damage — `sales` became `sal`, which matched nothing and silently
	// disabled the sales/sold synonym that the variant sales report depends on.
	if (/(?:s|x|z|ch|sh)es$/.test(word)) return word.slice(0, -2)
	if (word.endsWith('s')) return word.slice(0, -1)
	return word
}

/**
 * The words merchants use, mapped to the words the tool names use.
 *
 * Kept deliberately short, and every entry is vocabulary rather than tuning: someone asking to
 * "configure taxes" wants the tax settings, and nothing in any tool name contains the word
 * "configure". Without this the query matched only the prose of `tax_class_list`, whose
 * description happens to say "configured in the store", so the one tool that actually configures
 * tax ranked below it.
 *
 * Synonyms are added to the query, never substituted, so an exact word still scores on its own.
 */
const SYNONYMS: Readonly<Record<string, string>> = {
	configure: 'settings',
	configuration: 'settings',
	setup: 'settings',
	option: 'settings',
	churn: 'retention',
	inventory: 'stock',
	category: 'term',
	vat: 'tax',
	// "sales" and "sold" are the same idea, and the tools are split across both spellings:
	// `report_sales_summary` beside `report_top_sold_variants`. Without this, asking about sales
	// by variant never reaches the variant sales report at all.
	sale: 'sold',
	revenue: 'sales',
	// A merchant says "discount code"; FluentCart calls it a coupon.
	discount: 'coupon',
	voucher: 'coupon',
}

/**
 * A description that opens by disowning itself should not outrank a working tool.
 *
 * Several tools are labelled DIAGNOSTIC or DEPRECATED in their first words, because the endpoint
 * behind them ignores its dates, mixes currencies or returns nothing at all. That judgement is
 * already written down; this simply stops such a tool winning a tie against one that works.
 */
function isDisowned(description: string): boolean {
	return /^(DIAGNOSTIC|DEPRECATED)/.test(description)
}

/**
 * Words that carry no intent, dropped before scoring.
 *
 * They are not merely useless, they actively mislead: `by` is a segment of
 * `product_search_variant_by_name`, so "product sales by variant colour" handed that tool a full
 * exact-segment score for a preposition and it beat the variant sales report the question was
 * actually about. Question words do the same to anything named `get`.
 */
const STOPWORDS: ReadonlySet<string> = new Set([
	'a',
	'an',
	'and',
	'are',
	'as',
	'at',
	'be',
	'by',
	'can',
	'do',
	'doe',
	'for',
	'from',
	'how',
	'i',
	'in',
	'is',
	'it',
	'me',
	'my',
	'of',
	'on',
	'or',
	'our',
	'that',
	'the',
	'their',
	'them',
	'there',
	'thi',
	'to',
	'what',
	'when',
	'where',
	'which',
	'who',
	'with',
	'you',
	'your',
])

/**
 * Entry points beat specialisations when nothing else separates them.
 *
 * `shipping_zone_list`, `shipping_zone_get` and `shipping_zone_countries` all match "shipping
 * zones" identically, and alphabetical order put `countries` first — a tool for reading the
 * countries inside one zone, offered to someone who asked to see their zones. A `_list` or `_get`
 * is where you start with a subsystem; everything else is somewhere you arrive later.
 */
function isEntryPoint(name: string): boolean {
	return name.endsWith('_list') || name.endsWith('_get')
}

interface PreparedQuery {
	/** Stemmed, stopword-free words plus any synonym they imply. */
	words: string[]
	/** Whether the caller asked in the plural, which hints at a collection over a record. */
	plural: boolean
}

/**
 * Reduce a question to the words worth scoring.
 *
 * Split out from the scorer because it depends only on the query: doing it per tool would repeat
 * the same stemming a few hundred times per search for an identical answer.
 */
export function prepareQuery(query: string): PreparedQuery {
	const raw = query.toLowerCase().split(/\s+/).filter(Boolean)
	const stemmed = raw.map((word) => ({ raw: word, stem: stem(word) }))

	// A query made entirely of filler keeps its filler rather than matching the whole registry.
	const meaningful = stemmed.filter((entry) => !STOPWORDS.has(entry.stem))
	const kept = meaningful.length > 0 ? meaningful : stemmed

	const asked = kept.map((entry) => entry.stem)
	const synonyms = asked
		.map((word) => SYNONYMS[word])
		.filter((word): word is string => Boolean(word))

	return {
		words: [...new Set([...asked, ...synonyms])],
		plural: kept.some((entry) => entry.stem !== entry.raw),
	}
}

/**
 * Ordering nudges, applied only to tools that already matched.
 *
 * Each is smaller than a single body hit, so they settle a tie without ever inventing one.
 */
function tieBreakers(tool: ToolDefinition, name: string, plural: boolean): number {
	let bonus = 1 / (name.split('_').length + 1)
	if (isEntryPoint(name)) bonus += 0.5

	// A plural question wants the collection, a singular one wants the record: "shipping zones"
	// should reach `shipping_zone_list`, not `shipping_zone_get`, which otherwise tie exactly.
	if (plural && name.endsWith('_list')) bonus += 0.25
	if (!plural && name.endsWith('_get')) bonus += 0.25

	if (isDisowned(tool.description)) bonus -= 0.75
	return bonus
}

/**
 * Rank a tool against a prepared query.
 *
 * The weights encode where a match is most meaningful. A query word that is exactly one segment of
 * the tool name — `tax` in `tax_settings_get` — is the strongest signal there is, far better than
 * the same word appearing somewhere in a paragraph of caveats. Scoring those the same is what let
 * six tax tools tie on 4 points and then sort alphabetically, burying the settings tool at rank 7
 * behind `tax_class_list` for no reason but spelling.
 */
export function scoreTool(
	tool: ToolDefinition,
	prepared: PreparedQuery,
	category?: string,
): number {
	if (category && inferCategory(tool.name) !== category) return -1

	const name = tool.name.replace(/^fluentcart_/, '').toLowerCase()
	// Both sides are stemmed, or the halves disagree: the query word `variant` would miss the
	// segment `variants` in `report_top_sold_variants`, which is exactly the tool being asked for.
	const segments = new Set(name.split('_').map(stem))
	const title = tool.title.toLowerCase()
	const body = tool.description.toLowerCase()

	let score = 0
	for (const word of prepared.words) {
		if (segments.has(word)) score += 5
		else if (name.includes(word)) score += 2
		if (title.includes(word)) score += 2
		if (body.includes(word)) score += 1
	}

	return score > 0 ? score + tieBreakers(tool, name, prepared.plural) : score
}

/** Convenience wrapper for a single tool; `searchTools` prepares the query once instead. */
export function matchScore(tool: ToolDefinition, query: string, category?: string): number {
	return scoreTool(tool, prepareQuery(query), category)
}

export interface SearchRow {
	name: string
	title: string
	summary: string
	category: Category
	risk: string
	execution: string
	idempotency: string
}

/** One compact line per tool; the full description belongs to describe, not search. */
function summarise(description: string): string {
	const firstSentence = description.split(/(?<=\.)\s/)[0] ?? description
	return firstSentence.length > 160 ? `${firstSentence.slice(0, 157)}...` : firstSentence
}

/**
 * Rank matches by score descending, then public name ascending so ties are stable.
 *
 * The limit is applied before serialisation, not after, so a large registry cannot produce a
 * large payload that is then trimmed.
 */
export function searchTools(
	tools: readonly ToolDefinition[],
	query: string,
	options: { category?: string; limit: number },
): SearchRow[] {
	const prepared = prepareQuery(query)
	return tools
		.map((tool) => ({ tool, score: scoreTool(tool, prepared, options.category) }))
		.filter((entry) => entry.score > 0)
		.sort((a, b) => b.score - a.score || a.tool.name.localeCompare(b.tool.name))
		.slice(0, options.limit)
		.map((entry) => ({
			name: entry.tool.name,
			title: entry.tool.title,
			summary: summarise(entry.tool.description),
			category: inferCategory(entry.tool.name),
			// Risk travels with every result: a caller must never have to guess whether the tool
			// it just discovered moves money.
			risk: entry.tool.safety.risk,
			execution: entry.tool.safety.execution,
			idempotency: entry.tool.safety.idempotency,
		}))
}
