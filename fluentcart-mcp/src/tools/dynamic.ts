import type { McpServer } from '@modelcontextprotocol/server'
import { toJSONSchema, z } from 'zod'
import type { ToolDefinition } from './_factory.js'
import { CATEGORIES, inferCategory, searchTools } from './dynamic-search.js'
import type { ToolRisk } from './risk.js'

/** Dynamic tools that register whenever their matching risk class is available. */
export const DYNAMIC_TOOL_NAMES: readonly string[] = Object.freeze([
	'fluentcart_search_tools',
	'fluentcart_describe_tools',
	'fluentcart_execute_read_tool',
	'fluentcart_execute_reversible_write',
])

export const DYNAMIC_TOOL_COUNT = DYNAMIC_TOOL_NAMES.length

const SEARCH_LIMIT_DEFAULT = 5
const SEARCH_LIMIT_MAX = 10
const DESCRIBE_MAX = 5

/** Each executor may dispatch only its matching reviewed risk class. */
const EXECUTOR_RISKS: Record<'read' | 'reversible', readonly ToolRisk[]> = {
	read: ['read'],
	reversible: ['reversible-write'],
}

function textResult(payload: unknown) {
	return { content: [{ type: 'text' as const, text: JSON.stringify(payload) }] }
}

function errorResult(message: string) {
	return { content: [{ type: 'text' as const, text: message }], isError: true }
}

/**
 * Where to begin when a query matched nothing.
 *
 * Derived rather than listed, so it stays true whatever the exposure policy leaves registered: a
 * tool qualifies when it is an entry point AND callable with no arguments. A hard-coded roster
 * would go stale the first time a tool was renamed or filtered out.
 *
 * Ordered by `CATEGORIES`, which already runs from the centre of a shop outwards — product, order,
 * customer, coupon — rather than alphabetically. Sorting by name put `activity_list` and
 * `email_list` in front of `order_list` and `product_list`, and then cut the two that matter at the
 * limit, which is a worse answer than none.
 */
export function startingPointsFor(tools: ToolDefinition[]): string[] {
	const rank = (name: string) => {
		const index = CATEGORIES.indexOf(inferCategory(name))
		return index < 0 ? CATEGORIES.length : index
	}

	return tools
		.filter((tool) => /_(list|get|list_all)$/.test(tool.name) && tool.schema.safeParse({}).success)
		.map((tool) => tool.name)
		.sort(
			(a, b) =>
				rank(a) - rank(b) || a.split('_').length - b.split('_').length || a.localeCompare(b),
		)
		.slice(0, 6)
}

export function registerDynamicTools(server: McpServer, tools: ToolDefinition[]): string[] {
	const toolMap = new Map(tools.map((tool) => [tool.name, tool]))

	server.registerTool(
		'fluentcart_search_tools',
		{
			title: 'Search FluentCart Tools',
			description:
				'Search available FluentCart tools by keyword and optional category. Returns one compact line per match including its risk class and how it executes. Use this to discover tools before describing or executing them.',
			inputSchema: z.object({
				query: z
					.string()
					.describe('Search keyword(s) matched against tool names, titles and descriptions'),
				category: z.enum(CATEGORIES).optional().describe('Restrict results to one tool category'),
				limit: z
					.number()
					.int()
					.min(1)
					.max(SEARCH_LIMIT_MAX)
					.optional()
					.describe(
						`Maximum matches to return (default: ${SEARCH_LIMIT_DEFAULT}, max: ${SEARCH_LIMIT_MAX})`,
					),
			}),
			annotations: { readOnlyHint: true, openWorldHint: false },
		},
		async (input) => {
			const rows = searchTools(tools, input.query, {
				category: input.category,
				limit: input.limit ?? SEARCH_LIMIT_DEFAULT,
			})
			if (rows.length > 0) {
				return textResult({ total_available: tools.length, matches: rows.length, tools: rows })
			}

			// Search is the dynamic interface, so offer callable entry points when no text matched.
			return textResult({
				total_available: tools.length,
				matches: 0,
				tools: [],
				note: `No tool mentions any word in "${input.query}". Try plainer words, or start from one of the entry points below and filter there — a phrase describing a product or a person will rarely appear in a tool description, but the list tools search the store itself.`,
				starting_points: startingPointsFor(tools),
			})
		},
	)

	server.registerTool(
		'fluentcart_describe_tools',
		{
			title: 'Describe FluentCart Tools',
			description: `Get the full input schema, annotations and risk classification for specific tools by name. Use after searching, to learn exact parameters before executing. Maximum ${DESCRIBE_MAX} tools per call.`,
			inputSchema: z.object({
				tools: z
					.array(z.string())
					.min(1)
					.max(DESCRIBE_MAX)
					.describe(`Exact tool names to describe (max ${DESCRIBE_MAX})`),
			}),
			annotations: { readOnlyHint: true, openWorldHint: false },
		},
		async (input) => {
			const results = input.tools.map((name) => {
				const tool = toolMap.get(name)
				if (!tool) {
					return { name, error: 'Tool not found or not available under the current policy' }
				}
				return {
					name: tool.name,
					title: tool.title,
					description: tool.description,
					inputSchema: toJSONSchema(tool.schema),
					annotations: tool.annotations,
					risk: tool.safety.risk,
					execution: tool.safety.execution,
					idempotency: tool.safety.idempotency,
					executor: executorFor(tool.safety.risk),
				}
			})
			return textResult(results)
		},
	)

	const registered = [
		'fluentcart_search_tools',
		'fluentcart_describe_tools',
		registerExecutor(server, toolMap, 'read'),
	]

	if (tools.some((tool) => tool.safety.risk === 'reversible-write')) {
		registered.push(registerExecutor(server, toolMap, 'reversible'))
	}

	return registered
}

function executorFor(risk: ToolRisk): string | null {
	for (const [executor, risks] of Object.entries(EXECUTOR_RISKS)) {
		if (risks.includes(risk))
			return `fluentcart_execute_${executor === 'read' ? 'read_tool' : 'reversible_write'}`
	}
	return null
}

const EXECUTOR_META = {
	read: {
		name: 'fluentcart_execute_read_tool',
		title: 'Execute a FluentCart Read Tool',
		description:
			'Execute a read-only FluentCart tool by name. Refuses any tool that changes state; use the matching write executor for those.',
		annotations: { readOnlyHint: true, destructiveHint: false, openWorldHint: true },
	},
	reversible: {
		name: 'fluentcart_execute_reversible_write',
		title: 'Execute a Reversible FluentCart Write',
		description:
			'Execute a FluentCart write that has a verified read-back and a supported delete or restore. Requires FLUENTCART_WRITE_MODE=reversible. Refuses destructive and real-money actions.',
		annotations: {
			readOnlyHint: false,
			destructiveHint: false,
			// The executor may dispatch non-idempotent creates. The selected tool's own metadata
			// remains available through describe_tools, but this generic surface must not invite
			// automatic retries that could create a duplicate record.
			idempotentHint: false,
			openWorldHint: true,
		},
	},
} as const

/** @returns the registered tool name, so the caller can report the roster it actually built. */
function registerExecutor(
	server: McpServer,
	toolMap: Map<string, ToolDefinition>,
	executor: 'read' | 'reversible',
): string {
	const meta = EXECUTOR_META[executor]
	const permitted = EXECUTOR_RISKS[executor]

	server.registerTool(
		meta.name,
		{
			title: meta.title,
			description: meta.description,
			inputSchema: z.object({
				tool_name: z.string().describe('Exact name of the tool to execute'),
				input: z
					.record(z.string(), z.unknown())
					.optional()
					.default({})
					.describe('Input parameters matching the tool input schema'),
			}),
			annotations: meta.annotations,
		},
		async (args, requestContext) => {
			const tool = toolMap.get(args.tool_name)
			if (!tool) {
				return errorResult(
					`Error: tool "${args.tool_name}" is not exposed. Use fluentcart_search_tools to see what is available under the current policy.`,
				)
			}

			if (!permitted.includes(tool.safety.risk)) {
				const correct = executorFor(tool.safety.risk)
				return errorResult(
					correct
						? `Error: wrong executor. "${tool.name}" is classified ${tool.safety.risk}; call ${correct} instead.`
						: `Error: "${tool.name}" is classified ${tool.safety.risk} and is not exposed for execution.`,
				)
			}

			if (tool.safety.execution === 'none') {
				return errorResult(`Error: "${tool.name}" is not executable under the current policy.`)
			}

			// Validate again immediately before dispatch; discovery does not imply a valid call.
			const parsed = tool.schema.safeParse(args.input)
			if (!parsed.success) {
				return errorResult(`Validation error: ${JSON.stringify(parsed.error.issues)}`)
			}

			return tool.handler(parsed.data as Record<string, unknown>, {
				signal: requestContext?.mcpReq.signal,
			})
		},
	)

	return meta.name
}

export { inferCategory }
