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

export function matchScore(tool: ToolDefinition, query: string, category?: string): number {
	if (category && inferCategory(tool.name) !== category) return -1

	const words = query.toLowerCase().split(/\s+/).filter(Boolean)
	const haystack = `${tool.name} ${tool.title} ${tool.description}`.toLowerCase()

	let score = 0
	for (const word of words) {
		if (haystack.includes(word)) score += 1
		if (tool.name.toLowerCase().includes(word)) score += 2
		if (tool.title.toLowerCase().includes(word)) score += 1
	}
	return score
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
	return tools
		.map((tool) => ({ tool, score: matchScore(tool, query, options.category) }))
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
