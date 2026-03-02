import { createMcpExpressApp } from '@modelcontextprotocol/sdk/server/express.js'
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js'
import type { Express } from 'express'
import { createServer } from '../server.js'
import { createBearerAuth } from './auth.js'

export function createApp(host: string): Express {
	const app = createMcpExpressApp({ host })

	const auth = createBearerAuth()
	app.use('/mcp', auth)

	app.post('/mcp', async (req, res) => {
		const server = createServer()
		const transport = new StreamableHTTPServerTransport({ sessionIdGenerator: undefined })

		res.on('close', () => {
			transport.close()
			server.close()
		})

		await server.connect(transport)
		await transport.handleRequest(req, res, req.body)
	})

	app.get('/mcp', async (req, res) => {
		const server = createServer()
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

export async function startHttpServer(port: number, host: string): Promise<void> {
	const app = createApp(host)

	return new Promise((resolve) => {
		app.listen(port, host, () => {
			console.log(`FluentCart MCP server listening on http://${host}:${port}/mcp`)
			resolve()
		})
	})
}
