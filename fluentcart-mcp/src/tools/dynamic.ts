import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js'
import { toJSONSchema, z } from 'zod'
import type { ToolDefinition } from './_factory.js'

const CATEGORIES = [
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

type Category = (typeof CATEGORIES)[number]

function inferCategory(toolName: string): Category {
	const name = toolName.replace(/^fluentcart_/, '')
	for (const cat of CATEGORIES) {
		if (name.startsWith(cat)) return cat
	}
	return 'misc'
}

function matchScore(tool: ToolDefinition, query: string, category?: string): number {
	if (category && inferCategory(tool.name) !== category) return -1

	const q = query.toLowerCase()
	const words = q.split(/\s+/)
	const haystack = `${tool.name} ${tool.title} ${tool.description}`.toLowerCase()

	let score = 0
	for (const word of words) {
		if (haystack.includes(word)) score += 1
		if (tool.name.toLowerCase().includes(word)) score += 2
		if (tool.title.toLowerCase().includes(word)) score += 1
	}
	return score
}

export function registerDynamicTools(server: McpServer, tools: ToolDefinition[]): void {
	const toolMap = new Map<string, ToolDefinition>()
	for (const tool of tools) {
		toolMap.set(tool.name, tool)
	}

	// 1. fluentcart_search_tools
	server.registerTool(
		'fluentcart_search_tools',
		{
			title: 'Search FluentCart Tools',
			description:
				'Search available FluentCart tools by keyword and optional category. Returns matching tool names, titles, and descriptions. Use this to discover which tools are available before calling them.',
			inputSchema: z.object({
				query: z
					.string()
					.describe('Search keyword(s) to match against tool names and descriptions'),
				category: z.enum(CATEGORIES).optional().describe('Filter by tool category'),
			}),
			annotations: {
				readOnlyHint: true,
				openWorldHint: false,
			},
		},
		async (input) => {
			const scored = tools
				.map((t) => ({ tool: t, score: matchScore(t, input.query, input.category) }))
				.filter((s) => s.score > 0)
				.sort((a, b) => b.score - a.score)
				.slice(0, 20)

			const results = scored.map((s) => ({
				name: s.tool.name,
				title: s.tool.title,
				description: s.tool.description,
				category: inferCategory(s.tool.name),
			}))

			return {
				content: [
					{
						type: 'text' as const,
						text: JSON.stringify({
							total_available: tools.length,
							matches: results.length,
							tools: results,
						}),
					},
				],
			}
		},
	)

	// 2. fluentcart_describe_tools
	server.registerTool(
		'fluentcart_describe_tools',
		{
			title: 'Describe FluentCart Tools',
			description:
				'Get full details (input schema, annotations) for specific tools by name. Use after search_tools to get the exact input parameters before executing a tool. Max 10 tools per request.',
			inputSchema: z.object({
				tools: z.array(z.string()).max(10).describe('Tool names to describe (max 10)'),
			}),
			annotations: {
				readOnlyHint: true,
				openWorldHint: false,
			},
		},
		async (input) => {
			const results = input.tools.map((name) => {
				const tool = toolMap.get(name)
				if (!tool) {
					return { name, error: 'Tool not found' }
				}
				return {
					name: tool.name,
					title: tool.title,
					description: tool.description,
					inputSchema: toJSONSchema(tool.schema),
					annotations: tool.annotations,
				}
			})

			return {
				content: [{ type: 'text' as const, text: JSON.stringify(results) }],
			}
		},
	)

	// 3. fluentcart_execute_tool
	server.registerTool(
		'fluentcart_execute_tool',
		{
			title: 'Execute FluentCart Tool',
			description:
				'Execute a FluentCart tool by name with the given input. Use describe_tools first to learn the required input schema.',
			inputSchema: z.object({
				tool_name: z.string().describe('Name of the tool to execute'),
				input: z
					.record(z.string(), z.unknown())
					.optional()
					.default({})
					.describe('Input parameters for the tool'),
			}),
			annotations: {
				openWorldHint: true,
			},
		},
		async (args) => {
			const tool = toolMap.get(args.tool_name)
			if (!tool) {
				return {
					content: [
						{
							type: 'text' as const,
							text: `Error: Tool "${args.tool_name}" not found. Use fluentcart_search_tools to discover available tools.`,
						},
					],
					isError: true,
				}
			}

			const parsed = tool.schema.safeParse(args.input)
			if (!parsed.success) {
				return {
					content: [
						{
							type: 'text' as const,
							text: `Validation error: ${JSON.stringify(parsed.error.issues)}`,
						},
					],
					isError: true,
				}
			}

			return tool.handler(parsed.data as Record<string, unknown>)
		},
	)
}
