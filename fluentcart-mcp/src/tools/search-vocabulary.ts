/**
 * The words: what a query means before anything is scored.
 *
 * Stemming, synonyms and stopwords are vocabulary decisions — they say which words are the same
 * word and which carry no intent. Kept apart from search-scoring.ts, which decides what a match is
 * worth, because the two change for different reasons, and together they exceeded this project's
 * 280-line limit.
 */

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
export function stem(word: string): string {
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

export interface PreparedQuery {
	/** Stemmed, stopword-free words plus any synonym they imply. */
	words: string[]
	/** Adjacent word pairs, as written. See `PHRASE_HIT`. */
	phrases: string[]
	/** How many words a tool must match to explain the whole question. */
	asked: number
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
	// `Object.hasOwn` guards the prototype chain: `SYNONYMS['constructor']` would otherwise put the
	// Object function into a `string[]`. It is inert in the current scorer, but the next consumer
	// to call a string method on a query word is the one that breaks.
	const synonyms = asked
		.filter((word) => Object.hasOwn(SYNONYMS, word))
		.map((word) => SYNONYMS[word])
		.filter((word): word is string => typeof word === 'string')

	// Built from the words as written, not their stems, because descriptions are written in English
	// and "refund rate" has to match the prose that says "refund rate".
	const phrases: string[] = []
	for (let index = 1; index < kept.length; index += 1) {
		phrases.push(`${kept[index - 1]?.raw} ${kept[index]?.raw}`)
	}

	const words = [...new Set([...asked, ...synonyms])]
	return {
		words,
		phrases,
		// Coverage is measured against what was asked, not against the synonyms that were added on
		// the caller's behalf: a query of two words stays a question of two words.
		asked: new Set(asked).size,
		plural: kept.some((entry) => entry.stem !== entry.raw),
	}
}
