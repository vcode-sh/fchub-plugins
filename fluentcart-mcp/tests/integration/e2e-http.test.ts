import type { Server } from 'node:http'
import { Client } from '@modelcontextprotocol/sdk/client/index.js'
import { StreamableHTTPClientTransport } from '@modelcontextprotocol/sdk/client/streamableHttp.js'
import { afterAll, beforeAll, describe, expect, it } from 'vitest'
import { createApp } from '../../src/transport/http.js'
import { getLiveRun } from './support/live-run.js'

// Entry through the approved launcher; getLiveRun() throws otherwise.
getLiveRun()

// ---------------------------------------------------------------------------
// Group 1: MCP Protocol over HTTP (no auth)
// ---------------------------------------------------------------------------
describe('E2E: MCP Protocol over HTTP', () => {
	let baseUrl: string
	let server: Server
	let mcpClient: Client

	beforeAll(async () => {
		process.env.FLUENTCART_MCP_API_KEY = ''

		const app = createApp('127.0.0.1', 'full')

		await new Promise<void>((resolve) => {
			server = app.listen(0, '127.0.0.1', () => {
				const addr = server.address()
				if (addr && typeof addr === 'object') {
					baseUrl = `http://127.0.0.1:${addr.port}`
				}
				resolve()
			})
		})

		const transport = new StreamableHTTPClientTransport(new URL(`${baseUrl}/mcp`))
		mcpClient = new Client({ name: 'e2e-test-client', version: '1.0.0' })
		await mcpClient.connect(transport)
	}, 30_000)

	afterAll(async () => {
		try {
			await mcpClient?.close()
		} catch {
			// Client may already be disconnected
		}
		server?.close()
	})

	it('server info has correct name and version', () => {
		const info = mcpClient.getServerVersion()
		expect(info).toBeDefined()
		expect(info!.name).toBe('fluentcart-mcp')
		expect(info!.version).toMatch(/^\d+\.\d+\.\d+$/)
	})

	it('server capabilities include tools', () => {
		const caps = mcpClient.getServerCapabilities()
		expect(caps).toBeDefined()
		expect(caps!.tools).toBeDefined()
	})

	// This group runs in explicit `full` mode so it can address direct tools by name. Dynamic is
	// the default and is covered by its own group below.
	it('lists only read tools under the default disabled write mode', async () => {
		const result = await mcpClient.listTools()
		const names = result.tools.map((t) => t.name)

		expect(names).toContain('fluentcart_dashboard_overview')
		expect(names).toContain('fluentcart_product_list')
		expect(names).toContain('fluentcart_order_list')
		expect(names).toContain('fluentcart_customer_list')

		// Writes are absent by default, not merely disabled: the exposure policy filters the
		// registry before registration, so they cannot be listed or called by name.
		for (const write of [
			'fluentcart_coupon_create',
			'fluentcart_product_delete',
			'fluentcart_order_refund',
			'fluentcart_subscription_cancel',
			'fluentcart_role_update',
		]) {
			expect(names, `${write} must not be listed by default`).not.toContain(write)
		}

		// Every listed tool must annotate itself as read-only for the same reason.
		for (const tool of result.tools) {
			expect(tool.annotations?.readOnlyHint, `${tool.name} should be read-only`).toBe(true)
		}
	}, 30_000)

	it('calls dashboard_overview and gets real store data', async () => {
		const result = await mcpClient.callTool({
			name: 'fluentcart_dashboard_overview',
			arguments: {},
		})

		expect(result.isError).toBeFalsy()
		expect(result.content).toBeDefined()
		expect(Array.isArray(result.content)).toBe(true)

		const text = (result.content as Array<{ type: string; text: string }>)[0]
		expect(text.type).toBe('text')

		const data = JSON.parse(text.text)
		expect(data).toHaveProperty('stats')
		expect(Array.isArray(data.stats)).toBe(true)
	}, 60_000)

	it('calls product_list and gets products', async () => {
		const result = await mcpClient.callTool({
			name: 'fluentcart_product_list',
			arguments: { per_page: 5 },
		})

		expect(result.isError).toBeFalsy()

		const text = (result.content as Array<{ type: string; text: string }>)[0]
		expect(text.type).toBe('text')

		const data = JSON.parse(text.text)
		expect(data).toHaveProperty('products')
	}, 60_000)

	it('calls customer_list and gets customers', async () => {
		const result = await mcpClient.callTool({
			name: 'fluentcart_customer_list',
			arguments: { per_page: 5 },
		})

		expect(result.isError).toBeFalsy()

		const text = (result.content as Array<{ type: string; text: string }>)[0]
		expect(text.type).toBe('text')

		const data = JSON.parse(text.text)
		expect(data).toHaveProperty('customers')
	}, 60_000)

	it('calls order_list and gets orders', async () => {
		const result = await mcpClient.callTool({
			name: 'fluentcart_order_list',
			arguments: { per_page: 5 },
		})

		expect(result.isError).toBeFalsy()

		const text = (result.content as Array<{ type: string; text: string }>)[0]
		expect(text.type).toBe('text')

		const data = JSON.parse(text.text)
		expect(data).toHaveProperty('orders')
	}, 60_000)

	it('calls report_overview and gets report data', async () => {
		const result = await mcpClient.callTool({
			name: 'fluentcart_report_overview',
			arguments: {},
		})

		expect(result.isError).toBeFalsy()

		const text = (result.content as Array<{ type: string; text: string }>)[0]
		expect(text.type).toBe('text')

		const data = JSON.parse(text.text)
		expect(data).toHaveProperty('data')
	}, 60_000)

	it('handles tool call with invalid params gracefully', async () => {
		const result = await mcpClient.callTool({
			name: 'fluentcart_order_get',
			arguments: { order_id: 999999 },
		})

		// Should return an error result, not throw
		expect(result.content).toBeDefined()
		expect(Array.isArray(result.content)).toBe(true)

		const text = (result.content as Array<{ type: string; text: string }>)[0]
		expect(text.type).toBe('text')
	}, 60_000)
})

// ---------------------------------------------------------------------------
// Group 2: Bearer Auth Enforcement
// ---------------------------------------------------------------------------
describe('E2E: Bearer Auth Enforcement', () => {
	let baseUrl: string
	let server: Server
	const TEST_API_KEY = 'e2e-test-secret-key-12345'

	beforeAll(async () => {
		process.env.FLUENTCART_MCP_API_KEY = TEST_API_KEY

		const app = createApp('127.0.0.1')

		await new Promise<void>((resolve) => {
			server = app.listen(0, '127.0.0.1', () => {
				const addr = server.address()
				if (addr && typeof addr === 'object') {
					baseUrl = `http://127.0.0.1:${addr.port}`
				}
				resolve()
			})
		})
	})

	afterAll(() => {
		server?.close()
		process.env.FLUENTCART_MCP_API_KEY = ''
	})

	it('rejects request with no auth header (401)', async () => {
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

		expect(res.status).toBe(401)
		const body = await res.json()
		expect(body).toHaveProperty('error')
	})

	it('rejects request with wrong token (401)', async () => {
		const res = await fetch(`${baseUrl}/mcp`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json, text/event-stream',
				Authorization: 'Bearer wrong-token',
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

		expect(res.status).toBe(401)
		const body = await res.json()
		expect(body).toEqual({ error: 'Unauthorized' })
	})

	it('accepts request with correct token (200)', async () => {
		const res = await fetch(`${baseUrl}/mcp`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json, text/event-stream',
				Authorization: `Bearer ${TEST_API_KEY}`,
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
	})
})

// ---------------------------------------------------------------------------
// Group 3: Health and Edge Cases
// ---------------------------------------------------------------------------
describe('E2E: Health and Edge Cases', () => {
	let baseUrl: string
	let server: Server

	beforeAll(async () => {
		process.env.FLUENTCART_MCP_API_KEY = ''

		const app = createApp('127.0.0.1')

		await new Promise<void>((resolve) => {
			server = app.listen(0, '127.0.0.1', () => {
				const addr = server.address()
				if (addr && typeof addr === 'object') {
					baseUrl = `http://127.0.0.1:${addr.port}`
				}
				resolve()
			})
		})
	})

	afterAll(() => {
		server?.close()
	})

	it('GET /health returns { status: ok }', async () => {
		const res = await fetch(`${baseUrl}/health`)
		expect(res.status).toBe(200)

		const body = await res.json()
		expect(body).toEqual({ status: 'ok' })
	})

	it('DELETE /mcp returns 405', async () => {
		const res = await fetch(`${baseUrl}/mcp`, { method: 'DELETE' })
		expect(res.status).toBe(405)

		const body = await res.json()
		expect(body).toHaveProperty('error')
	})

	it('POST /mcp with garbage JSON returns 400', async () => {
		const res = await fetch(`${baseUrl}/mcp`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json, text/event-stream',
			},
			body: JSON.stringify({ garbage: true }),
		})

		expect(res.status).toBe(400)
	})
})

// ---------------------------------------------------------------------------
// Group 4: Dynamic is the default toolset mode
// ---------------------------------------------------------------------------
describe('E2E: dynamic default mode', () => {
	let baseUrl: string
	let server: Server
	let mcpClient: Client

	beforeAll(async () => {
		process.env.FLUENTCART_MCP_API_KEY = ''
		// No mode argument: this is exactly what an operator gets out of the box.
		const app = createApp('127.0.0.1')

		await new Promise<void>((resolve) => {
			server = app.listen(0, '127.0.0.1', () => {
				const addr = server.address()
				if (addr && typeof addr === 'object') baseUrl = `http://127.0.0.1:${addr.port}`
				resolve()
			})
		})

		const transport = new StreamableHTTPClientTransport(new URL(`${baseUrl}/mcp`))
		mcpClient = new Client({ name: 'e2e-dynamic-client', version: '1.0.0' })
		await mcpClient.connect(transport)
	}, 30_000)

	afterAll(async () => {
		try {
			await mcpClient?.close()
		} catch {
			// Already disconnected.
		}
		server?.close()
	})

	it('exposes exactly the five meta-tools by default', async () => {
		const result = await mcpClient.listTools()
		expect(result.tools.map((t) => t.name).sort()).toEqual([
			'fluentcart_describe_tools',
			'fluentcart_execute_guarded_write',
			'fluentcart_execute_read_tool',
			'fluentcart_execute_reversible_write',
			'fluentcart_search_tools',
		])
	}, 30_000)

	it('finds a real tool through search and reports its risk', async () => {
		const result = await mcpClient.callTool({
			name: 'fluentcart_search_tools',
			arguments: { query: 'orders' },
		})
		const text = (result.content as Array<{ type: string; text: string }>)[0]
		const data = JSON.parse(text.text)

		expect(data.tools.length).toBeGreaterThan(0)
		expect(data.tools.length).toBeLessThanOrEqual(5)
		for (const row of data.tools) {
			expect(row.risk).toBeDefined()
			expect(row.execution).toBeDefined()
		}
	}, 60_000)

	it('executes a read through the read executor against the live store', async () => {
		const result = await mcpClient.callTool({
			name: 'fluentcart_execute_read_tool',
			arguments: { tool_name: 'fluentcart_order_list', input: { per_page: 2 } },
		})

		expect(result.isError).toBeFalsy()
		const text = (result.content as Array<{ type: string; text: string }>)[0]
		expect(() => JSON.parse(text.text)).not.toThrow()
	}, 60_000)

	it('refuses a hidden write by name even through the executor', async () => {
		const result = await mcpClient.callTool({
			name: 'fluentcart_execute_reversible_write',
			arguments: { tool_name: 'fluentcart_order_delete', input: {} },
		})

		expect(result.isError).toBe(true)
		const text = (result.content as Array<{ type: string; text: string }>)[0]
		expect(text.text).toMatch(/not exposed/)
	}, 60_000)
})
