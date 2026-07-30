import { request as nodeRequest } from 'node:http'
import type { AddressInfo } from 'node:net'
import {
	type McpRequestContext,
	McpServer,
	type McpServerFactory,
} from '@modelcontextprotocol/server'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { z } from 'zod'
import {
	createHttpApplication,
	type HttpMiddlewareStage,
	startHttpService,
} from '../../src/transport/http.js'
import type { HttpExposureConfig } from '../../src/transport/http-config.js'

const KEY = 'private-http-key-0123456789abcdef'
const LOCAL: HttpExposureConfig = {
	profile: 'local',
	host: '127.0.0.1',
	allowedHosts: ['localhost', '127.0.0.1', '[::1]'],
	allowedOrigins: ['localhost', '127.0.0.1', '[::1]'],
}
const AUTHENTICATED_LOCAL: HttpExposureConfig = { ...LOCAL, bearerKey: KEY }
const PRIVATE: HttpExposureConfig = {
	profile: 'private',
	host: '127.0.0.1',
	allowedHosts: ['mcp.internal'],
	allowedOrigins: ['console.internal'],
	bearerKey: KEY,
}
const CLIENT_INFO = { name: 'http-test', version: '1.0.0' }
const LEGACY = {
	jsonrpc: '2.0',
	id: 1,
	method: 'initialize',
	params: {
		protocolVersion: '2025-11-25',
		capabilities: {},
		clientInfo: CLIENT_INFO,
	},
}
const MODERN_META = {
	'io.modelcontextprotocol/protocolVersion': '2026-07-28',
	'io.modelcontextprotocol/clientInfo': CLIENT_INFO,
	'io.modelcontextprotocol/clientCapabilities': {},
}
const MODERN = {
	jsonrpc: '2.0',
	id: 2,
	method: 'server/discover',
	params: { _meta: MODERN_META },
}

interface RunningApp {
	url: string
	close(): Promise<void>
}

const running = new Set<RunningApp>()

function recordingFactory(contexts: McpRequestContext[] = []): McpServerFactory {
	return async (context) => {
		contexts.push(context)
		return new McpServer({ name: 'http-fixture', version: '1.0.0' })
	}
}

async function listen(
	config: HttpExposureConfig,
	options: Parameters<typeof createHttpApplication>[2] = {},
	contexts: McpRequestContext[] = [],
): Promise<RunningApp> {
	const { app, mcp } = createHttpApplication(recordingFactory(contexts), config, options)
	const server = await new Promise<ReturnType<typeof app.listen>>((resolve) => {
		const listener = app.listen(0, config.host, () => resolve(listener))
	})
	const port = (server.address() as AddressInfo).port
	const handle = {
		url: `http://127.0.0.1:${port}`,
		async close() {
			await new Promise<void>((resolve, reject) => {
				server.close((error) => (error ? reject(error) : resolve()))
			})
			await mcp.close()
			running.delete(handle)
		},
	}
	running.add(handle)
	return handle
}

function request(
	url: string,
	body: unknown,
	headers: Record<string, string> = {},
): Promise<Response> {
	const payload = typeof body === 'string' ? body : JSON.stringify(body)
	return new Promise((resolve, reject) => {
		const outgoing = nodeRequest(`${url}/mcp`, {
			method: 'POST',
			headers: {
				Host: 'mcp.internal',
				'Content-Type': 'application/json',
				Accept: 'application/json, text/event-stream',
				Authorization: `Bearer ${KEY}`,
				'Content-Length': Buffer.byteLength(payload),
				...headers,
			},
		})
		outgoing.on('response', (incoming) => {
			const chunks: Buffer[] = []
			incoming.on('data', (chunk) => chunks.push(Buffer.from(chunk)))
			incoming.on('end', () => {
				resolve(
					new Response(Buffer.concat(chunks), {
						status: incoming.statusCode,
						headers: incoming.headers as Record<string, string>,
					}),
				)
			})
		})
		outgoing.on('error', reject)
		outgoing.end(payload)
	})
}

async function jsonRpcBody(response: Response): Promise<Record<string, unknown>> {
	const text = await response.text()
	if ((response.headers.get('content-type') ?? '').includes('text/event-stream')) {
		const data = text.split('\n').find((line) => line.startsWith('data: '))
		if (!data) throw new Error(`Missing SSE data frame: ${text}`)
		return JSON.parse(data.slice(6))
	}
	return JSON.parse(text)
}

afterEach(async () => {
	await Promise.all([...running].map((handle) => handle.close()))
	vi.restoreAllMocks()
})

describe('ordered HTTP composition', () => {
	it('runs security before parsing and passes a legacy initialise body to the shared factory', async () => {
		const order: HttpMiddlewareStage[] = []
		const contexts: McpRequestContext[] = []
		const app = await listen(PRIVATE, { onStage: (stage) => order.push(stage) }, contexts)
		const response = await request(app.url, LEGACY)
		const body = await jsonRpcBody(response)

		expect(order).toEqual(['correlation', 'host', 'origin', 'auth', 'json', 'handler'])
		expect(response.status).toBe(200)
		expect(body).toMatchObject({
			id: 1,
			result: {
				protocolVersion: '2025-11-25',
				serverInfo: { name: 'http-fixture', version: '1.0.0' },
			},
		})
		expect(contexts.map(({ era }) => era)).toEqual(['legacy'])
	})

	it('passes a modern envelope body through real Express to the same factory contract', async () => {
		const contexts: McpRequestContext[] = []
		const app = await listen(PRIVATE, {}, contexts)
		const response = await request(app.url, MODERN, { 'Mcp-Method': 'server/discover' })
		const body = await jsonRpcBody(response)

		expect(response.status).toBe(200)
		expect(body).toMatchObject({
			id: 2,
			result: {
				supportedVersions: ['2026-07-28'],
				_meta: {
					'io.modelcontextprotocol/serverInfo': {
						name: 'http-fixture',
						version: '1.0.0',
					},
				},
			},
		})
		expect(contexts.map(({ era }) => era)).toEqual(['modern'])
	})
})

describe('HTTP request guards', () => {
	it('does not revive the removed authenticated GET event stream', async () => {
		const app = await listen(PRIVATE)
		const response = await fetch(`${app.url}/mcp`, {
			method: 'GET',
			headers: {
				Host: 'mcp.internal',
				Authorization: `Bearer ${KEY}`,
				Accept: 'text/event-stream',
			},
		})

		expect(response.status).not.toBe(200)
		expect(response.headers.get('content-type')).not.toMatch(/text\/event-stream/)
		await response.body?.cancel()
	})

	it('rejects an invalid Host before the JSON parser sees malformed input', async () => {
		const order: HttpMiddlewareStage[] = []
		const app = await listen(PRIVATE, { onStage: (stage) => order.push(stage) })
		const response = await request(app.url, '{"broken":', { Host: 'evil.example' })

		expect(response.status).toBe(403)
		expect(order).toEqual(['correlation', 'host'])
		expect(response.headers.get('cache-control')).toBe('no-store')
		expect((await response.text()).length).toBeLessThan(512)
	})

	it('rejects a present invalid Origin but allows an absent Origin', async () => {
		const app = await listen(PRIVATE)
		const rejected = await request(app.url, LEGACY, { Origin: 'https://evil.example' })
		const allowed = await request(app.url, LEGACY)

		expect(rejected.status).toBe(403)
		expect(rejected.headers.get('cache-control')).toBe('no-store')
		expect(allowed.status).toBe(200)
	})

	it('makes every bearer rejection identical and challenges with Bearer', async () => {
		const app = await listen(PRIVATE)
		const headers = [
			{ Authorization: '' },
			{ Authorization: KEY },
			{ Authorization: `Basic ${KEY}` },
			{ Authorization: `Bearer ${'x'.repeat(KEY.length)}` },
		]
		const outcomes = await Promise.all(
			headers.map(async (header) => {
				const response = await request(app.url, LEGACY, header)
				return {
					status: response.status,
					challenge: response.headers.get('www-authenticate'),
					cache: response.headers.get('cache-control'),
					body: await response.text(),
				}
			}),
		)

		expect(new Set(outcomes.map(({ body }) => body)).size).toBe(1)
		for (const outcome of outcomes) {
			expect(outcome).toEqual({
				status: 401,
				challenge: 'Bearer',
				cache: 'no-store',
				body: '{"error":"Unauthorized"}',
			})
		}
	})

	it('rejects an unauthorised oversized body before parsing it', async () => {
		const order: HttpMiddlewareStage[] = []
		const app = await listen(PRIVATE, { onStage: (stage) => order.push(stage) })
		const response = await request(app.url, 'x'.repeat(150 * 1024), { Authorization: '' })

		expect(response.status).toBe(401)
		expect(order).toEqual(['correlation', 'host', 'origin', 'auth'])
		expect(response.headers.get('cache-control')).toBe('no-store')
	})

	it('rejects authenticated oversized non-JSON input before the SDK handler reads it', async () => {
		const order: HttpMiddlewareStage[] = []
		const app = await listen(AUTHENTICATED_LOCAL, { onStage: (stage) => order.push(stage) })
		const response = await request(app.url, 'x'.repeat(150 * 1024), {
			Host: '127.0.0.1',
			'Content-Type': 'text/plain',
		})

		expect(response.status).toBe(415)
		expect(order).toEqual(['correlation', 'host', 'origin', 'auth', 'json'])
		expect(response.headers.get('cache-control')).toBe('no-store')
		expect((await response.text()).length).toBeLessThan(256)
	})

	it('never forwards the static key into logs, principal fields, or the MCP context', async () => {
		const contexts: McpRequestContext[] = []
		const errors = vi.spyOn(console, 'error').mockImplementation(() => undefined)
		const app = await listen(PRIVATE, {}, contexts)
		const response = await request(app.url, LEGACY)
		const responseText = await response.text()
		const context = contexts[0]

		expect(response.status).toBe(200)
		expect(responseText).not.toContain(KEY)
		expect(errors.mock.calls.flat().join(' ')).not.toContain(KEY)
		expect(context?.authInfo).toBeUndefined()
		expect(context?.requestInfo?.headers.get('authorization')).toBeNull()
		expect(JSON.stringify({ era: context?.era, authInfo: context?.authInfo })).not.toContain(KEY)
	})
})

describe('bounded HTTP answers', () => {
	it('returns bounded JSON for malformed and over-limit bodies', async () => {
		const app = await listen(PRIVATE)
		const malformed = await request(app.url, '{"jsonrpc":')
		const oversized = await request(app.url, JSON.stringify({ pad: 'x'.repeat(101 * 1024) }))

		expect(malformed.status).toBe(400)
		expect(malformed.headers.get('content-type')).toMatch(/application\/json/)
		expect(malformed.headers.get('cache-control')).toBe('no-store')
		expect((await malformed.text()).length).toBeLessThan(256)
		expect(oversized.status).toBe(413)
		expect(oversized.headers.get('content-type')).toMatch(/application\/json/)
		expect(oversized.headers.get('cache-control')).toBe('no-store')
		expect((await oversized.text()).length).toBeLessThan(256)
	})

	it('keeps health minimal and unauthenticated while leaving readiness absent', async () => {
		const app = await listen(PRIVATE)
		const health = await fetch(`${app.url}/health`)
		const ready = await fetch(`${app.url}/ready`)

		expect(health.status).toBe(200)
		expect(await health.json()).toEqual({ status: 'ok' })
		expect(ready.status).toBe(404)
	})
})

describe('HTTP service shutdown', () => {
	it('starts its drain deadline before awaiting a stalled MCP close', async () => {
		let toolEntered: (() => void) | undefined
		let releaseTool: (() => void) | undefined
		let releaseMcpClose: (() => void) | undefined
		const entered = new Promise<void>((resolve) => {
			toolEntered = resolve
		})
		const toolGate = new Promise<void>((resolve) => {
			releaseTool = resolve
		})
		const closeGate = new Promise<void>((resolve) => {
			releaseMcpClose = resolve
		})
		const stalledCloseFactory: McpServerFactory = async () => {
			const server = new McpServer({ name: 'stalled-close-fixture', version: '1.0.0' })
			server.registerTool('hang', { inputSchema: z.object({}) }, async () => {
				toolEntered?.()
				await toolGate
				return { content: [{ type: 'text', text: 'released' }] }
			})
			const close = server.server.close.bind(server.server)
			server.server.close = async () => {
				await closeGate
				await close()
			}
			return server
		}
		const handle = await startHttpService(stalledCloseFactory, 0, LOCAL, { drainMs: 20 })
		const inFlight = request(
			handle.url,
			{
				jsonrpc: '2.0',
				id: 3,
				method: 'tools/call',
				params: { name: 'hang', arguments: {}, _meta: MODERN_META },
			},
			{ Host: '127.0.0.1', 'Mcp-Method': 'tools/call', 'Mcp-Name': 'hang' },
		).catch(() => undefined)
		await entered

		const started = performance.now()
		const closing = handle.close()
		try {
			const outcome = await Promise.race([
				closing.then(() => 'closed' as const),
				new Promise<'timed-out'>((resolve) => setTimeout(() => resolve('timed-out'), 500).unref()),
			])

			expect(outcome).toBe('closed')
			expect(performance.now() - started).toBeGreaterThanOrEqual(15)
			expect(performance.now() - started).toBeLessThan(500)
			await expect(fetch(`${handle.url}/health`)).rejects.toThrow()
		} finally {
			releaseMcpClose?.()
			releaseTool?.()
			await closing
			await inFlight
		}
	})

	it('stops accepting, honours a short drain bound, and destroys a stuck connection', async () => {
		let enteredFactory: (() => void) | undefined
		const entered = new Promise<void>((resolve) => {
			enteredFactory = resolve
		})
		const blockedFactory: McpServerFactory = async () => {
			enteredFactory?.()
			return new Promise<never>(() => undefined)
		}
		const handle = await startHttpService(blockedFactory, 0, LOCAL, { drainMs: 20 })
		const inFlight = fetch(`${handle.url}/mcp`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json',
				'Mcp-Method': 'server/discover',
			},
			body: JSON.stringify(MODERN),
		}).catch(() => undefined)
		await entered

		const started = performance.now()
		await handle.close()
		const elapsed = performance.now() - started

		expect(elapsed).toBeGreaterThanOrEqual(15)
		expect(elapsed).toBeLessThan(500)
		await inFlight
		await expect(fetch(`${handle.url}/health`)).rejects.toThrow()
	})
})
