import { createRequire } from 'node:module'
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js'
import { createClient } from './api/client.js'
import { resolveConfig } from './config/resolver.js'
import { resolveApiUrls } from './config/types.js'
import { createLogger } from './logging.js'
import { createAllTools } from './tools/index.js'

const require = createRequire(import.meta.url)
const { version } = require('../package.json') as { version: string }

export function createServer(): McpServer {
	const config = resolveConfig()
	const resolved = resolveApiUrls(config)
	const client = createClient(resolved)

	const server = new McpServer({
		name: 'fluentcart-mcp',
		version,
	})

	const tools = createAllTools(client)

	for (const tool of tools) {
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

	const logger = createLogger(server)
	logger.info(`fluentcart-mcp v${version} started — ${tools.length} tools registered`)
	logger.debug(`config source: ${process.env.FLUENTCART_URL ? 'env' : 'file'}`)

	return server
}
