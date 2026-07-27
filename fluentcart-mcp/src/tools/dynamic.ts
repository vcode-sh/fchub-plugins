import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js'
import { toJSONSchema, z } from 'zod'
import type { ToolDefinition } from './_factory.js'
import { CATEGORIES, inferCategory, searchTools } from './dynamic-search.js'
import type { ToolRisk } from './risk.js'

/** Meta-tools registered in dynamic mode: search, describe and three risk-split executors. */
export const DYNAMIC_TOOL_COUNT = 5

const SEARCH_LIMIT_DEFAULT = 5
const SEARCH_LIMIT_MAX = 10
const DESCRIBE_MAX = 5

/**
 * Which executor may dispatch which risk class.
 *
 * Splitting execution by risk is the whole point: a single generic executor would have to
 * advertise one set of annotations for a product lookup and a refund alike, which tells the
 * caller nothing and lets a client that only permitted reads invoke a write.
 */
const EXECUTOR_RISKS: Record<'read' | 'reversible' | 'guarded', readonly ToolRisk[]> = {
	read: ['read'],
	reversible: ['reversible-write'],
	guarded: ['real-money'],
}

function textResult(payload: unknown) {
	return { content: [{ type: 'text' as const, text: JSON.stringify(payload) }] }
}

function errorResult(message: string) {
	return { content: [{ type: 'text' as const, text: message }], isError: true }
}

export function registerDynamicTools(server: McpServer, tools: ToolDefinition[]): void {
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
			return textResult({ total_available: tools.length, matches: rows.length, tools: rows })
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

	registerExecutor(server, toolMap, 'read')
	registerExecutor(server, toolMap, 'reversible')
	registerExecutor(server, toolMap, 'guarded')
}

function executorFor(risk: ToolRisk): string | null {
	for (const [executor, risks] of Object.entries(EXECUTOR_RISKS)) {
		if (risks.includes(risk))
			return `fluentcart_execute_${executor === 'read' ? 'read_tool' : `${executor}_write`}`
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
			'Execute a FluentCart write that has a verified read-back and a supported delete or restore. Requires FLUENTCART_WRITE_MODE=reversible or guarded. Refuses destructive and real-money actions.',
		annotations: {
			readOnlyHint: false,
			destructiveHint: false,
			idempotentHint: true,
			openWorldHint: true,
		},
	},
	guarded: {
		name: 'fluentcart_execute_guarded_write',
		title: 'Execute a Guarded Real-Money FluentCart Action',
		description:
			'Execute a real-money FluentCart action through the signed-preview and durable-claim guard. Requires a fresh confirmation token and a unique idempotency key. Requires FLUENTCART_WRITE_MODE=guarded.',
		annotations: { readOnlyHint: false, destructiveHint: true, openWorldHint: true },
	},
} as const

function registerExecutor(
	server: McpServer,
	toolMap: Map<string, ToolDefinition>,
	executor: 'read' | 'reversible' | 'guarded',
): void {
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
		async (args) => {
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

			return tool.handler(parsed.data as Record<string, unknown>)
		},
	)
}

export { inferCategory }
