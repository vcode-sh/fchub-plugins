import { createMcpExpressApp } from '@modelcontextprotocol/sdk/server/express.js'
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js'
import type { Express } from 'express'
import type { ServerContext, ToolsetMode } from '../server.js'
import {
	createServerFromContextAsync,
	DEFAULT_TOOLSET_MODE,
	resolveServerContextAsync,
} from '../server.js'
import { assertSafeHttpExposure, createBearerAuth } from './auth.js'

/**
 * Build the Express app around a context somebody else resolved.
 *
 * Exists so a test can exercise the transport without a store, and so the discovery step has a
 * single caller. Production must go through `createApp`: a context built without capability
 * evidence prunes nothing, which is precisely the defect this split was introduced to close.
 */
export function createAppFromContext(
	host: string,
	ctx: ServerContext,
	mode: ToolsetMode = DEFAULT_TOOLSET_MODE,
): Express {
	const app = createMcpExpressApp({ host })

	const auth = createBearerAuth()
	app.use('/mcp', auth)

	app.post('/mcp', async (req, res) => {
		const server = await createServerFromContextAsync(ctx, mode)
		const transport = new StreamableHTTPServerTransport({ sessionIdGenerator: undefined })

		res.on('close', () => {
			transport.close()
			server.close()
		})

		await server.connect(transport)
		await transport.handleRequest(req, res, req.body)
	})

	app.get('/mcp', async (req, res) => {
		const server = await createServerFromContextAsync(ctx, mode)
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

/**
 * Production HTTP startup: discover what the store serves, then build the app.
 *
 * This used to call the synchronous `resolveServerContext()`, which skips `discoverApiCapabilities`
 * entirely and therefore skips route pruning. The same server in the same mode listed one set of
 * tools over stdio and a wider one over HTTP, and the extra ones 404'd mid-workflow against a store
 * missing those routes. Both transports now resolve their context the same way, so a route this
 * server offers is a route the store answered for. Discovery failure stays fatal, with the message
 * `discoverApiCapabilities` already writes for the operator.
 */
export async function createApp(
	host: string,
	mode: ToolsetMode = DEFAULT_TOOLSET_MODE,
): Promise<Express> {
	return createAppFromContext(host, await resolveServerContextAsync(), mode)
}

export async function startHttpServer(
	port: number,
	host: string,
	mode: ToolsetMode = DEFAULT_TOOLSET_MODE,
): Promise<void> {
	// Fail before a socket exists, not after the first unauthenticated request arrives.
	assertSafeHttpExposure(host, process.env.FLUENTCART_MCP_API_KEY)

	const app = await createApp(host, mode)

	return new Promise((resolve) => {
		app.listen(port, host, () => {
			// stdout belongs to JSON-RPC; operational messages go to stderr.
			console.error(`FluentCart MCP server listening on http://${host}:${port}/mcp`)
			resolve()
		})
	})
}
