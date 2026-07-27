import { createMcpExpressApp } from '@modelcontextprotocol/sdk/server/express.js'
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js'
import type { Express } from 'express'
import type { ToolsetMode } from '../server.js'
import { createServerFromContext, resolveServerContext } from '../server.js'
import { assertSafeHttpExposure, createBearerAuth } from './auth.js'

export function createApp(host: string, mode: ToolsetMode = 'static'): Express {
	const app = createMcpExpressApp({ host })
	const ctx = resolveServerContext()

	const auth = createBearerAuth()
	app.use('/mcp', auth)

	app.post('/mcp', async (req, res) => {
		const server = createServerFromContext(ctx, mode)
		const transport = new StreamableHTTPServerTransport({ sessionIdGenerator: undefined })

		res.on('close', () => {
			transport.close()
			server.close()
		})

		await server.connect(transport)
		await transport.handleRequest(req, res, req.body)
	})

	app.get('/mcp', async (req, res) => {
		const server = createServerFromContext(ctx, mode)
		const transport = new StreamableHTTPServerTransport({ sessionIdGenerator: undefined })

		res.on('close', () => {
			transport.close()
			server.close()
		})

		await server.connect(transport)
		await transport.handleRequest(req, res)
	})

	app.delete('/mcp', async (_req, res) => {
		res.status(405).json({ error: 'Session termination not supported in stateless mode' })
	})

	app.get('/health', (_req, res) => {
		res.json({ status: 'ok' })
	})

	return app
}

export async function startHttpServer(
	port: number,
	host: string,
	mode: ToolsetMode = 'static',
): Promise<void> {
	// Fail before a socket exists, not after the first unauthenticated request arrives.
	assertSafeHttpExposure(host, process.env.FLUENTCART_MCP_API_KEY)

	const app = createApp(host, mode)

	return new Promise((resolve) => {
		app.listen(port, host, () => {
			// stdout belongs to JSON-RPC; operational messages go to stderr.
			console.error(`FluentCart MCP server listening on http://${host}:${port}/mcp`)
			resolve()
		})
	})
}
