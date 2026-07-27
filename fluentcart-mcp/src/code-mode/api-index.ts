import { toJSONSchema } from 'zod'
import type { ToolDefinition } from '../tools/_factory.js'

/** Code mode never returns more than five declarations from one search. */
export const MAX_SEARCH_RESULTS = 5

/** Longest operation summary kept in a declaration comment. */
const MAX_SUMMARY_CHARACTERS = 180

/** Beyond this many enum members the union is rendered as the base type instead. */
const MAX_ENUM_MEMBERS = 6

export interface ApiOperationDeclaration {
	operation: string
	summary: string
	/** Compact TypeScript signature the agent can copy into sandboxed code. */
	declaration: string
}

/**
 * An immutable, read-only view of the tool registry.
 *
 * The index deliberately keeps only the *names* of excluded writes, never their definitions, so
 * there is no reachable path from code mode to a write executor even by mistake.
 */
export interface ReadOnlyApiIndex {
	readonly size: number
	has(operation: string): boolean
	get(operation: string): ToolDefinition | undefined
	names(): readonly string[]
	/** True when the name exists upstream but was excluded for not being a read. */
	isExcludedWrite(operation: string): boolean
	search(query: string, limit?: number): ApiOperationDeclaration[]
	declare(operation: string): string | undefined
}

interface JsonSchemaNode {
	type?: string | string[]
	description?: string
	enum?: unknown[]
	const?: unknown
	items?: JsonSchemaNode
	properties?: Record<string, JsonSchemaNode>
	required?: string[]
	anyOf?: JsonSchemaNode[]
	oneOf?: JsonSchemaNode[]
}

function collapse(text: string): string {
	return text.replace(/\s+/g, ' ').trim()
}

/** First sentence of the tool description, or a hard-cut prefix when there is no sentence break. */
function summarise(description: string): string {
	const flat = collapse(description)
	if (flat.length <= MAX_SUMMARY_CHARACTERS) return flat
	const cut = flat.slice(0, MAX_SUMMARY_CHARACTERS)
	const lastStop = cut.lastIndexOf('. ')
	if (lastStop > 60) return cut.slice(0, lastStop + 1)
	return `${cut.trimEnd()}…`
}

function renderEnum(values: readonly unknown[], fallback: string): string {
	if (values.length === 0 || values.length > MAX_ENUM_MEMBERS) return fallback
	return values
		.map((value) => (typeof value === 'string' ? `'${value}'` : String(value)))
		.join(' | ')
}

function renderPrimitive(type: string): string {
	if (type === 'integer') return 'number'
	if (type === 'null') return 'null'
	if (type === 'string' || type === 'number' || type === 'boolean') return type
	return 'unknown'
}

/**
 * Render a JSON Schema node as a compact TypeScript type.
 *
 * Depth is capped because declarations are a context-budget item: a deeply nested schema is
 * more useful to an agent as `Record<string, unknown>` than as 40 lines it has to pay for.
 */
function renderType(node: JsonSchemaNode | undefined, depth: number): string {
	if (!node) return 'unknown'

	const variants = node.anyOf ?? node.oneOf
	if (variants && variants.length > 0) {
		const rendered = [...new Set(variants.map((variant) => renderType(variant, depth)))]
		return rendered.length === 1 ? (rendered[0] ?? 'unknown') : rendered.join(' | ')
	}

	const type = Array.isArray(node.type) ? node.type[0] : node.type

	if (node.enum && node.enum.length > 0) {
		return renderEnum(node.enum, type ? renderPrimitive(type) : 'unknown')
	}

	if (type === 'array') return `${renderType(node.items, depth + 1)}[]`

	if (type === 'object') {
		if (depth >= 1 || !node.properties) return 'Record<string, unknown>'
		return renderObject(node, depth + 1)
	}

	return type ? renderPrimitive(type) : 'unknown'
}

function renderObject(node: JsonSchemaNode, depth: number): string {
	const properties = node.properties ?? {}
	const required = new Set(node.required ?? [])
	const fields = Object.entries(properties).map(([name, child]) => {
		const optional = required.has(name) ? '' : '?'
		return `${name}${optional}: ${renderType(child, depth)}`
	})
	if (fields.length === 0) return 'Record<string, unknown>'
	return `{ ${fields.join('; ')} }`
}

function schemaOf(tool: ToolDefinition): JsonSchemaNode {
	try {
		return toJSONSchema(tool.schema) as JsonSchemaNode
	} catch {
		// An unrepresentable schema must not remove the operation from the index; describing the
		// input loosely is better than pretending the read does not exist.
		return { type: 'object' }
	}
}

/** Build the `operation(input): Promise<T>` line an agent copies into sandboxed code. */
function buildDeclaration(tool: ToolDefinition): string {
	const schema = schemaOf(tool)
	const properties = schema.properties ?? {}
	const propertyNames = Object.keys(properties)
	const summary = summarise(tool.description)

	if (propertyNames.length === 0) {
		return `/** ${summary} */\nfluentcart.call('${tool.name}'): Promise<unknown>`
	}

	const required = schema.required ?? []
	const marker = required.length === 0 ? '?' : ''
	const shape = renderObject(schema, 0)
	return `/** ${summary} */\nfluentcart.call('${tool.name}', input${marker}: ${shape}): Promise<unknown>`
}

/**
 * Score a tool against a free-text query.
 *
 * Name matches outrank title matches, which outrank description matches, because an agent that
 * already half-knows the operation name should get it back first.
 */
function matchScore(tool: ToolDefinition, query: string): number {
	if (typeof query !== 'string') return 0
	const words = query.toLowerCase().split(/\s+/).filter(Boolean)
	if (words.length === 0) return 0

	const name = tool.name.toLowerCase()
	const title = tool.title.toLowerCase()
	const haystack = `${name} ${title} ${tool.description.toLowerCase()}`

	let score = 0
	for (const word of words) {
		if (name.includes(word)) score += 3
		if (title.includes(word)) score += 2
		if (haystack.includes(word)) score += 1
	}
	return score
}

/**
 * Build the read-only operation index.
 *
 * Filtering is on `safety.risk === 'read'` rather than on annotations: annotations are a
 * presentation hint, while the risk row is the reviewed decision. A tool whose annotations
 * claim `readOnlyHint` but whose reviewed risk is a write stays out.
 */
export function buildApiIndex(tools: readonly ToolDefinition[]): ReadOnlyApiIndex {
	const reads = new Map<string, ToolDefinition>()
	const excludedWrites = new Set<string>()

	for (const tool of tools) {
		if (tool.safety.risk === 'read') {
			reads.set(tool.name, tool)
		} else {
			excludedWrites.add(tool.name)
		}
	}

	const declarations = new Map<string, string>()
	for (const [name, tool] of reads) {
		declarations.set(name, buildDeclaration(tool))
	}

	const sortedNames = Object.freeze([...reads.keys()].sort())

	const index: ReadOnlyApiIndex = {
		size: reads.size,
		has: (operation) => reads.has(operation),
		get: (operation) => reads.get(operation),
		names: () => sortedNames,
		isExcludedWrite: (operation) => excludedWrites.has(operation),
		declare: (operation) => declarations.get(operation),
		search(query, limit = MAX_SEARCH_RESULTS) {
			const capped = Math.min(Math.max(Math.trunc(limit) || 1, 1), MAX_SEARCH_RESULTS)
			return [...reads.values()]
				.map((tool) => ({ tool, score: matchScore(tool, query) }))
				.filter((entry) => entry.score > 0)
				.sort((a, b) => b.score - a.score || a.tool.name.localeCompare(b.tool.name))
				.slice(0, capped)
				.map((entry) => ({
					operation: entry.tool.name,
					summary: summarise(entry.tool.description),
					declaration: declarations.get(entry.tool.name) ?? '',
				}))
		},
	}

	return Object.freeze(index)
}
