import { createRequire } from 'node:module'
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js'
import type { FluentCartClient } from './api/client.js'
import { createClient } from './api/client.js'
import { resolveConfig } from './config/resolver.js'
import { resolveApiUrls } from './config/types.js'
import { createLogger } from './logging.js'
import { registerPrompts } from './prompts.js'
import { registerResources } from './resources.js'
import { canExposeTool, parseWriteMode, type WritePolicyConfig } from './security/write-policy.js'
import type { ToolDefinition } from './tools/_factory.js'
import { DYNAMIC_TOOL_COUNT, registerDynamicTools } from './tools/dynamic.js'
import { createAllTools } from './tools/index.js'

const require = createRequire(import.meta.url)
const { version } = require('../package.json') as { version: string }

export type ToolsetMode = 'static' | 'dynamic'

export interface ServerContext {
	client: FluentCartClient
	/** Already filtered by the exposure policy. Hidden tools are absent, not merely disabled. */
	tools: ToolDefinition[]
	version: string
	configSource: string
	writePolicy: WritePolicyConfig
}

/**
 * Read the write-exposure policy from the environment.
 *
 * Guard availability is reported honestly: without a signing secret and a persistent state
 * directory there is no way to prevent a replayed refund, so guarded tools stay hidden even
 * when the operator asked for guarded mode.
 */
export function resolveWritePolicy(): WritePolicyConfig {
	return {
		writeMode: parseWriteMode(process.env.FLUENTCART_WRITE_MODE),
		guard: {
			persistentState: Boolean(process.env.FLUENTCART_GUARD_STATE_DIR),
			signingSecret: (process.env.FLUENTCART_GUARD_SECRET ?? '').length >= 32,
		},
	}
}

export function resolveServerContext(): ServerContext {
	const config = resolveConfig()
	const resolved = resolveApiUrls(config)
	const client = createClient(resolved)
	const writePolicy = resolveWritePolicy()

	// One filtered, immutable registry shared by every exposure mode. A tool removed here
	// cannot be listed, searched, described or called by name in any mode.
	const tools = createAllTools(client).filter((tool) => canExposeTool(tool.safety, writePolicy))
	const configSource = process.env.FLUENTCART_URL ? 'env' : 'file'

	return { client, tools, version, configSource, writePolicy }
}

export function createServerFromContext(
	ctx: ServerContext,
	mode: ToolsetMode = 'static',
): McpServer {
	const server = new McpServer({
		name: 'fluentcart-mcp',
		version: ctx.version,
	})

	if (mode === 'dynamic') {
		registerDynamicTools(server, ctx.tools)
	} else {
		for (const tool of ctx.tools) {
			server.registerTool(
				tool.name,
				{
					title: tool.title,
					description: tool.description,
					inputSchema: tool.schema,
					annotations: tool.annotations,
				},
				tool.handler,
			)
		}
	}

	registerResources(server, ctx.client)
	registerPrompts(server)

	const logger = createLogger(server)
	const toolCount = mode === 'dynamic' ? DYNAMIC_TOOL_COUNT : ctx.tools.length
	logger.info(`fluentcart-mcp v${version} started — ${toolCount} tools registered (${mode} mode)`)
	logger.debug(`config source: ${ctx.configSource}`)

	return server
}

export function createServer(mode: ToolsetMode = 'static'): McpServer {
	return createServerFromContext(resolveServerContext(), mode)
}
