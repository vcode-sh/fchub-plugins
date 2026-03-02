import { createRequire } from 'node:module'
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js'
import type { FluentCartClient } from './api/client.js'
import { createClient } from './api/client.js'
import { resolveConfig } from './config/resolver.js'
import { resolveApiUrls } from './config/types.js'
import { createLogger } from './logging.js'
import { registerPrompts } from './prompts.js'
import { registerResources } from './resources.js'
import type { ToolDefinition } from './tools/_factory.js'
import { registerDynamicTools } from './tools/dynamic.js'
import { createAllTools } from './tools/index.js'

const require = createRequire(import.meta.url)
const { version } = require('../package.json') as { version: string }

export type ToolsetMode = 'static' | 'dynamic'

export interface ServerContext {
	client: FluentCartClient
	tools: ToolDefinition[]
	version: string
	configSource: string
}

export function resolveServerContext(): ServerContext {
	const config = resolveConfig()
	const resolved = resolveApiUrls(config)
	const client = createClient(resolved)
	const tools = createAllTools(client)
	const configSource = process.env.FLUENTCART_URL ? 'env' : 'file'

	return { client, tools, version, configSource }
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
	const toolCount = mode === 'dynamic' ? 3 : ctx.tools.length
	logger.info(`fluentcart-mcp v${version} started — ${toolCount} tools registered (${mode} mode)`)
	logger.debug(`config source: ${ctx.configSource}`)

	return server
}

export function createServer(mode: ToolsetMode = 'static'): McpServer {
	return createServerFromContext(resolveServerContext(), mode)
}
