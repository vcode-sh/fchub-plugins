import type { Server } from 'node:http'
import { Client } from '@modelcontextprotocol/sdk/client/index.js'
import { StreamableHTTPClientTransport } from '@modelcontextprotocol/sdk/client/streamableHttp.js'
import { afterAll, afterEach, beforeAll, describe, expect, it, vi } from 'vitest'
import { resolveServerContext } from '../../src/server.js'
import { createApp, createAppFromContext } from '../../src/transport/http.js'

const STORE = 'https://example.com'

function setStoreEnv(): void {
	process.env.FLUENTCART_URL = STORE
	process.env.FLUENTCART_USERNAME = 'admin'
	process.env.FLUENTCART_APP_PASSWORD = 'test-pass'
}

function clearStoreEnv(): void {
	process.env.FLUENTCART_URL = undefined
	process.env.FLUENTCART_USERNAME = undefined
	process.env.FLUENTCART_APP_PASSWORD = undefined
}

/** Start an app on an ephemeral loopback port and hand back its base URL. */
async function listen(app: ReturnType<typeof createAppFromContext>): Promise<{
	server: Server
	baseUrl: string
}> {
	return new Promise((resolve) => {
		const server: Server = app.listen(0, '127.0.0.1', () => {
			const addr = server.address()
			const port = addr && typeof addr === 'object' ? addr.port : 0
			resolve({ server, baseUrl: `http://127.0.0.1:${port}` })
		})
	})
}

/**
 * A WordPress REST index carrying exactly the FluentCart routes named.
 *
 * Deliberately partial: the point of every discovery test below is what happens to a tool whose
 * route the store does not serve.
 */
function restIndex(paths: string[]): unknown {
	const routes: Record<string, unknown> = {
		'/': { endpoints: [{ methods: ['GET'] }] },
		'/fluent-cart/v2': { endpoints: [{ methods: ['GET'] }] },
	}
	for (const path of paths) {
		routes[path] = { endpoints: [{ methods: ['GET', 'POST', 'PUT', 'DELETE'] }] }
	}
	return { namespaces: ['wp/v2', 'fluent-cart/v2'], routes }
}

function jsonResponse(body: unknown, status = 200): Response {
	return {
		ok: status >= 200 && status < 300,
		status,
		headers: new Headers(),
		text: () => Promise.resolve(JSON.stringify(body)),
	} as unknown as Response
}

/**
 * Run `build` with the REST index stubbed, then take the stub away again.
 *
 * Discovery happens once, while the app is being constructed, so the stub can be removed before
 * any HTTP request is made — which matters, because the MCP client below needs a real `fetch`.
 */
async function withStubbedIndex<T>(response: Response, build: () => Promise<T>): Promise<T> {
	const stub = vi.fn(() => Promise.resolve(response))
	vi.stubGlobal('fetch', stub)
	try {
		return await build()
	} finally {
		vi.unstubAllGlobals()
	}
}

describe('HTTP transport', () => {
	let baseUrl: string
	let server: Server

	beforeAll(async () => {
		setStoreEnv()

		// Built from an explicitly supplied context: this suite is about transport plumbing, and the
		// unit lane performs no network I/O, so it must not run capability discovery. The production
		// entry point is `createApp`, which does — see the discovery suite below.
		const started = await listen(createAppFromContext('127.0.0.1', resolveServerContext()))
		server = started.server
		baseUrl = started.baseUrl
	})

	afterAll(() => {
		server?.close()
		clearStoreEnv()
	})

	it('GET /health returns { status: ok }', async () => {
		const res = await fetch(`${baseUrl}/health`)
		const body = await res.json()
		expect(res.status).toBe(200)
		expect(body).toEqual({ status: 'ok' })
	})

	it('POST /mcp with valid JSON-RPC initialize request returns 200', async () => {
		const res = await fetch(`${baseUrl}/mcp`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json, text/event-stream',
			},
			body: JSON.stringify({
				jsonrpc: '2.0',
				id: 1,
				method: 'initialize',
				params: {
					protocolVersion: '2025-03-26',
					capabilities: {},
					clientInfo: { name: 'test-client', version: '1.0.0' },
				},
			}),
		})

		expect(res.status).toBe(200)

		const text = await res.text()
		const contentType = res.headers.get('content-type') ?? ''

		let body: Record<string, unknown>
		if (contentType.includes('text/event-stream')) {
			const dataLine = text.split('\n').find((line) => line.startsWith('data: '))
			expect(dataLine).toBeDefined()
			body = JSON.parse(dataLine!.slice(6))
		} else {
			body = JSON.parse(text)
		}

		expect(body).toHaveProperty('result')
		const result = body.result as Record<string, unknown>
		expect(result).toHaveProperty('serverInfo')
		const serverInfo = result.serverInfo as Record<string, string>
		expect(serverInfo.name).toBe('fluentcart-mcp')
	})

	it('POST /mcp with invalid JSON-RPC returns 400', async () => {
		const res = await fetch(`${baseUrl}/mcp`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json, text/event-stream',
			},
			body: JSON.stringify({ not: 'a valid rpc message' }),
		})

		expect(res.status).toBe(400)
	})

	it('POST /mcp without Accept header returns 406', async () => {
		const res = await fetch(`${baseUrl}/mcp`, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({
				jsonrpc: '2.0',
				id: 1,
				method: 'initialize',
				params: {
					protocolVersion: '2025-03-26',
					capabilities: {},
					clientInfo: { name: 'test-client', version: '1.0.0' },
				},
			}),
		})

		expect(res.status).toBe(406)
	})

	it('DELETE /mcp returns 405', async () => {
		const res = await fetch(`${baseUrl}/mcp`, {
			method: 'DELETE',
		})

		expect(res.status).toBe(405)
		const body = await res.json()
		expect(body).toHaveProperty('error')
	})
})

/**
 * Capability discovery on the HTTP path.
 *
 * `createApp` used to call the synchronous `resolveServerContext()`, so HTTP skipped discovery
 * altogether and served tools whose routes the store may not have had. stdio, resolving the same
 * context asynchronously, served fewer. These assertions exist so the two transports cannot drift
 * apart again.
 */
describe('HTTP transport capability discovery', () => {
	afterEach(() => {
		vi.unstubAllGlobals()
		clearStoreEnv()
	})

	it('refuses to build an app when the store will not describe itself', async () => {
		setStoreEnv()

		await expect(
			withStubbedIndex(jsonResponse({}, 403), () => createApp('127.0.0.1')),
		).rejects.toThrow(/REST index request failed with status 403/)
	})

	it('prunes tools whose routes the store does not serve', async () => {
		setStoreEnv()
		let served: string[] = []
		let server: Server | undefined

		try {
			const app = await withStubbedIndex(jsonResponse(restIndex(['/fluent-cart/v2/orders'])), () =>
				createApp('127.0.0.1', 'full'),
			)
			const started = await listen(app)
			server = started.server

			const client = new Client({ name: 'discovery-test-client', version: '1.0.0' })
			await client.connect(new StreamableHTTPClientTransport(new URL(`${started.baseUrl}/mcp`)))
			served = (await client.listTools()).tools.map((tool) => tool.name)
			await client.close()
		} finally {
			server?.close()
		}

		// The store answered for GET /orders and nothing else, so the order list survives and every
		// tool bound to an unserved route is absent rather than present and doomed to 404.
		expect(served).toContain('fluentcart_order_list')
		expect(served).not.toContain('fluentcart_product_list')
		expect(served).not.toContain('fluentcart_customer_list')

		// The pruned list must also be strictly smaller than the undiscovered one, or the filter is
		// running without actually filtering.
		const undiscovered = resolveServerContext().tools.length
		expect(served.length).toBeLessThan(undiscovered)
	}, 30_000)
})
