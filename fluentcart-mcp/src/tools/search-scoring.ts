/**
 * What a match is worth.
 *
 * The vocabulary — stemming, synonyms, stopwords, and turning a query into words — lives in
 * search-vocabulary.ts. This file decides how strongly a tool answers a prepared question.
 */
import type { ToolDefinition } from './_factory.js'
import { inferCategory, type PreparedQuery, prepareQuery, stem } from './search-vocabulary.js'

/**
 * A description that opens by disowning itself should not outrank a working tool.
 *
 * Several tools are labelled DIAGNOSTIC or DEPRECATED in their first words, because the endpoint
 * behind them ignores its dates, mixes currencies or returns nothing at all. That judgement is
 * already written down; this simply stops such a tool winning a tie against one that works.
 *
 * REFERENCE DATA earns the same treatment for the same reason. `tax_config_rates` reads a static
 * table shipped inside FluentCart rather than the store — and it ranked FIRST for "what tax do I
 * charge in Poland", above `tax_rate_list`, which reads the store's actual rates. Answering a
 * question about this store from a file that has never heard of it is the most confident kind of
 * wrong, so a tool that says it is not about the store starts from behind on questions that are.
 */
function isDisowned(description: string): boolean {
	return /^(DIAGNOSTIC|DEPRECATED|REFERENCE DATA)/.test(description)
}

/**
 * Entry points beat specialisations when nothing else separates them.
 *
 * `shipping_zone_list`, `shipping_zone_get` and `shipping_zone_countries` all match "shipping
 * zones" identically, and alphabetical order put `countries` first — a tool for reading the
 * countries inside one zone, offered to someone who asked to see their zones. A `_list` or `_get`
 * is where you start with a subsystem; everything else is somewhere you arrive later.
 */
function isEntryPoint(name: string): boolean {
	// `_list_all` is the store-wide form of a `_list`, so it is more of an entry point than the
	// per-parent one, not less. Reading only the last segment made `variant_list_all` — the only
	// tool that can answer a stock question about the whole catalogue — score as a specialisation.
	return name.endsWith('_list') || name.endsWith('_get') || name.endsWith('_list_all')
}

/**
 * Whether the tool can be called with no arguments at all.
 *
 * A tool demanding an id the question never mentioned is a poor first suggestion: asked "what is
 * low on stock", an agent offered `variant_list` has to invent a `product_id` before it can even
 * try. `variant_list_all` needs nothing and answers the question as put. The penalty is smaller
 * than the singular/plural nudge on purpose, so "shipping zone" still reaches `shipping_zone_get`
 * — wanting one record is a good reason to be asked which one.
 *
 * Memoised because it is a property of the tool, not the query, and `searchTools` would otherwise
 * re-parse an empty object against every schema in the registry on every keystroke.
 */
const callableWithNoArguments = new WeakMap<ToolDefinition, boolean>()

function needsAnArgument(tool: ToolDefinition): boolean {
	const cached = callableWithNoArguments.get(tool)
	if (cached !== undefined) return !cached

	// Asking the schema is the only honest test: "does it have required keys" has to survive
	// defaults, effects and refinements, and this is precisely the question a caller faces.
	const callable = tool.schema.safeParse({}).success
	callableWithNoArguments.set(tool, callable)
	return !callable
}

/**
 * A tool whose text contains the exact phrase asked for is almost always the answer.
 *
 * "refund rate" is two words that FluentCart's vocabulary pulls apart: `rate` is a name segment of
 * four tax tools, so each scored a full segment hit for half the question and buried
 * `report_sales_summary`, which carries `refunded_orders` and `order_count` — the two numbers a
 * refund rate is made of. Weighted above a segment hit because matching both words in order says
 * more than matching one word perfectly.
 */
const PHRASE_HIT = 6

/**
 * How much a partial match is worth.
 *
 * A tool matching one word of a two-word question is answering half of it, and should not beat a
 * tool that accounts for all of it. Full coverage keeps the score intact; explaining half the
 * question costs 30% of it. Kept as a multiplier rather than a bonus so it cannot manufacture a
 * match out of a tool that scored nothing.
 */
function coverageFactor(matched: number, asked: number): number {
	if (asked === 0) return 1
	return 0.4 + 0.6 * (matched / asked)
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

	if (needsAnArgument(tool)) bonus -= 0.15
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
	// A description that points at a sibling — "use fluentcart_customer_get instead" — must not
	// thereby compete with it. Naming the better tool is the most useful sentence a description can
	// carry, and scoring it made every cross-reference a small act of self-harm: `order_get` names
	// three siblings. The referenced names go before matching; a caller who types a tool name still
	// reaches it through its own name and segments, which score far higher than prose anyway.
	const body = tool.description.toLowerCase().replace(/fluentcart_[a-z0-9_]+/g, ' ')

	let score = 0
	let matched = 0
	for (const word of prepared.words) {
		let hit = 0
		if (segments.has(word)) hit += 5
		else if (name.includes(word)) hit += 2
		if (title.includes(word)) hit += 2
		if (body.includes(word)) hit += 1
		if (hit > 0) matched += 1
		score += hit
	}

	if (score === 0) return 0

	// A field name is a phrase with the spaces taken out. `report_sales_summary` returns
	// `refunded_orders` and `order_count`, which is exactly what "refunded orders total" asks for,
	// and it scored no phrase hit at all because of the underscore — losing to a tool whose prose
	// happened to contain the words with a space between them. Both spellings are checked so a
	// caller can type either the words or the field name.
	const spaced = `${title} ${body}`.replace(/_/g, ' ')
	for (const phrase of prepared.phrases) {
		if (title.includes(phrase) || body.includes(phrase) || spaced.includes(phrase)) {
			score += PHRASE_HIT
		}
	}

	// Coverage scales what the words earned; the tie-breakers are added afterwards so a nudge
	// meant to settle a draw is never itself scaled up or down.
	return (
		score * coverageFactor(Math.min(matched, prepared.asked), prepared.asked) +
		tieBreakers(tool, name, prepared.plural)
	)
}

/** Convenience wrapper for a single tool; `searchTools` prepares the query once instead. */
export function matchScore(tool: ToolDefinition, query: string, category?: string): number {
	return scoreTool(tool, prepareQuery(query), category)
}
