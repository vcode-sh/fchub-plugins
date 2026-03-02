import type { Server } from 'node:http'
import { afterAll, beforeAll, describe, expect, it } from 'vitest'
import { createApp } from '../../src/transport/http.js'

describe('HTTP transport', () => {
	let baseUrl: string
	let server: Server

	beforeAll(async () => {
		process.env.FLUENTCART_URL = 'https://example.com'
		process.env.FLUENTCART_USERNAME = 'admin'
		process.env.FLUENTCART_APP_PASSWORD = 'test-pass'

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
		process.env.FLUENTCART_URL = undefined
		process.env.FLUENTCART_USERNAME = undefined
		process.env.FLUENTCART_APP_PASSWORD = undefined
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
