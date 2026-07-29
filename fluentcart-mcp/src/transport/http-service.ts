import { randomUUID } from 'node:crypto'
import type { Server } from 'node:http'
import { hostHeaderValidation, originValidation } from '@modelcontextprotocol/express'
import { toNodeHandler } from '@modelcontextprotocol/node'
import {
	createMcpHandler,
	isJsonContentType,
	type McpHttpHandler,
	type McpServerFactory,
} from '@modelcontextprotocol/server'
import express, { type ErrorRequestHandler, type Express, type RequestHandler } from 'express'
import { createBearerAuth } from './auth.js'
import { type HttpExposureInput, resolveHttpExposure } from './http-config.js'
import type { ServiceHandle } from './lifecycle.js'
import { reportOperationalError } from './lifecycle.js'
import {
	createRequestCancellationMiddleware,
	RequestCancellationRegistry,
} from './request-cancellation.js'

export type HttpMiddlewareStage = 'correlation' | 'host' | 'origin' | 'auth' | 'json' | 'handler'

export interface HttpServiceHandle extends ServiceHandle {
	url: string
}

interface HttpApplication {
	app: Express
	mcp: McpHttpHandler
}

interface HttpApplicationOptions {
	onStage?: (stage: HttpMiddlewareStage) => void
}

export interface HttpStartOptions extends HttpApplicationOptions {
	drainMs?: number
}

const DEFAULT_HTTP_DRAIN_MS = 10_000

function observed(
	stage: HttpMiddlewareStage,
	middleware: RequestHandler,
	onStage: HttpApplicationOptions['onStage'],
): RequestHandler {
	return (req, res, next) => {
		onStage?.(stage)
		middleware(req, res, next)
	}
}

function correlationMiddleware(onStage: HttpApplicationOptions['onStage']): RequestHandler {
	return (req, res, next) => {
		onStage?.('correlation')
		const supplied = req.headers['x-request-id']
		const candidate = Array.isArray(supplied) ? supplied[0] : supplied
		const correlationId =
			candidate && /^[A-Za-z0-9._:-]{1,128}$/.test(candidate) ? candidate : randomUUID()
		req.headers['x-request-id'] = correlationId
		res.set('X-Request-ID', correlationId)
		res.set('Cache-Control', 'no-store')
		next()
	}
}

function boundedErrors(): ErrorRequestHandler {
	return (error, _req, res, _next) => {
		const status =
			typeof error === 'object' && error !== null && 'status' in error ? error.status : undefined
		const type =
			typeof error === 'object' && error !== null && 'type' in error ? error.type : undefined
		if (status === 413 || type === 'entity.too.large') {
			res.status(413).json({ error: 'Payload Too Large' })
			return
		}
		if (status === 400 || (error instanceof SyntaxError && 'body' in error)) {
			res.status(400).json({ error: 'Invalid JSON' })
			return
		}
		reportOperationalError(error)
		res.status(500).json({ error: 'Internal Server Error' })
	}
}

function requireJsonContentType(): RequestHandler {
	return (req, res, next) => {
		if (req.method === 'POST' && !isJsonContentType(req.headers['content-type'] ?? null)) {
			res.status(415).json({ error: 'Unsupported Media Type' })
			return
		}
		next()
	}
}

/**
 * Compose an MCP factory over Express without importing a production registry or credentials.
 */
export function createHttpApplication(
	factory: McpServerFactory,
	input: HttpExposureInput,
	options: HttpApplicationOptions = {},
): HttpApplication {
	const config = resolveHttpExposure(input)
	const app = express()
	const mcp = createMcpHandler(factory, {
		legacy: 'stateless',
		onerror: reportOperationalError,
	})
	const cancellations = new RequestCancellationRegistry()
	const closeMcp = mcp.close
	mcp.close = async () => {
		cancellations.close()
		await closeMcp()
	}
	const nodeHandler = toNodeHandler(
		{
			fetch: async (...args: Parameters<typeof mcp.fetch>) => {
				const response = await mcp.fetch(...args)
				response.headers.set('Cache-Control', 'no-store')
				return response
			},
		},
		{ onerror: reportOperationalError },
	)

	app.get('/health', (_req, res) => {
		res.json({ status: 'ok' })
	})
	app.use('/mcp', correlationMiddleware(options.onStage))
	app.use('/mcp', observed('host', hostHeaderValidation([...config.allowedHosts]), options.onStage))
	app.use('/mcp', observed('origin', originValidation([...config.allowedOrigins]), options.onStage))
	app.use('/mcp', observed('auth', createBearerAuth(config), options.onStage))
	app.use('/mcp', observed('json', requireJsonContentType(), options.onStage))
	app.use('/mcp', express.json({ limit: '100kb' }))
	app.use('/mcp', createRequestCancellationMiddleware(cancellations))
	app.all('/mcp', async (req, res, next) => {
		options.onStage?.('handler')
		try {
			await nodeHandler(req, res, req.body)
		} catch (error) {
			next(error)
		}
	})
	app.use('/mcp', boundedErrors())

	return { app, mcp }
}

function validateDrainMs(value: number): void {
	if (!Number.isSafeInteger(value) || value < 0) {
		throw new RangeError('HTTP drainMs must be a non-negative whole number.')
	}
}

function listen(app: Express, port: number, host: string): Promise<Server> {
	return new Promise((resolve, reject) => {
		const server = app.listen(port, host)
		const onError = (error: Error) => reject(error)
		server.once('error', onError)
		server.once('listening', () => {
			server.off('error', onError)
			resolve(server)
		})
	})
}

function listenerUrl(server: Server, host: string): string {
	const address = server.address()
	if (!address || typeof address === 'string') throw new Error('HTTP listener has no TCP address.')
	const urlHost = host.includes(':') && !host.startsWith('[') ? `[${host}]` : host
	return `http://${urlHost}:${address.port}`
}

export async function startHttpService(
	factory: McpServerFactory,
	port: number,
	input: HttpExposureInput,
	options: HttpStartOptions = {},
): Promise<HttpServiceHandle> {
	const config = resolveHttpExposure(input)
	const drainMs = options.drainMs ?? DEFAULT_HTTP_DRAIN_MS
	validateDrainMs(drainMs)
	const { app, mcp } = createHttpApplication(factory, config, options)
	const server = await listen(app, port, config.host)
	let closing: Promise<void> | undefined

	return {
		url: listenerUrl(server, config.host),
		close() {
			closing ??= (async () => {
				const stopped = new Promise<void>((resolve, reject) => {
					server.close((error) => (error ? reject(error) : resolve()))
				})
				let drainTimer: NodeJS.Timeout | undefined
				const deadline = new Promise<false>((resolve) => {
					drainTimer = setTimeout(() => resolve(false), drainMs)
					drainTimer.unref()
				})
				const noError = Symbol('no MCP close error')
				let mcpError: unknown = noError
				const mcpClosed = Promise.resolve()
					.then(() => mcp.close())
					.catch((error: unknown) => {
						mcpError = error
					})
				const drained = await Promise.race([
					Promise.all([stopped, mcpClosed]).then(() => true),
					deadline,
				])
				if (drainTimer) clearTimeout(drainTimer)
				if (!drained) server.closeAllConnections()
				await stopped
				if (mcpError !== noError) throw mcpError
			})()
			return closing
		},
	}
}
