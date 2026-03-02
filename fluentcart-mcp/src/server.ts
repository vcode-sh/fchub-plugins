import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js'
import { createClient } from './api/client.js'
import { resolveConfig } from './config/resolver.js'
import { resolveApiUrls } from './config/types.js'
import { createAllTools } from './tools/index.js'

export function createServer(): McpServer {
	const config = resolveConfig()
	const resolved = resolveApiUrls(config)
	const client = createClient(resolved)

	const server = new McpServer({
		name: 'fluentcart-mcp',
		version: '0.1.0',
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

	return server
}
