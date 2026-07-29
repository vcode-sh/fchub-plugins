import { createServer, type ServerResponse } from 'node:http'
import { Client, StreamableHTTPClientTransport } from '@modelcontextprotocol/client'
import { McpServer, type McpServerFactory } from '@modelcontextprotocol/server'
import { describe, expect, it, vi } from 'vitest'
import { z } from 'zod'
import type { TransportPrincipal } from '../../src/transport/auth.js'
import { startHttpService } from '../../src/transport/http.js'
import type { HttpExposureConfig } from '../../src/transport/http-config.js'
import {
	cancellationRoute,
	RequestCancellationRegistry,
} from '../../src/transport/request-cancellation.js'

const LOCAL: HttpExposureConfig = {
	profile: 'local',
	host: '127.0.0.1',
	allowedHosts: ['localhost', '127.0.0.1', '[::1]'],
	allowedOrigins: ['localhost', '127.0.0.1', '[::1]'],
}

function deferred(): { promise: Promise<void>; resolve(): void } {
	let resolvePromise: (() => void) | undefined
	const promise = new Promise<void>((resolve) => {
		resolvePromise = resolve
	})
	return {
		promise,
		resolve() {
			resolvePromise?.()
		},
	}
}

async function within(promise: Promise<void>, label: string): Promise<void> {
	let timer: NodeJS.Timeout | undefined
	await Promise.race([
		promise,
		new Promise<never>((_, reject) => {
			timer = setTimeout(() => reject(new Error(`${label} timed out`)), 500)
		}),
	]).finally(() => {
		if (timer) clearTimeout(timer)
	})
}

describe('legacy HTTP cancellation routing', () => {
	it('aborts the original upstream request when cancellation arrives in a separate POST', async () => {
		const upstreamStarted = deferred()
		const upstreamCancelled = deferred()
		const openResponses = new Set<ServerResponse>()
		const upstream = createServer((request, response) => {
			openResponses.add(response)
			upstreamStarted.resolve()
			let observed = false
			const cancelled = () => {
				if (observed) return
				observed = true
				openResponses.delete(response)
				upstreamCancelled.resolve()
			}
			request.once('aborted', cancelled)
			response.once('close', cancelled)
		})
		await new Promise<void>((resolve) => upstream.listen(0, '127.0.0.1', resolve))
		const address = upstream.address()
		if (!address || typeof address === 'string') throw new Error('Missing upstream address.')
		const upstreamUrl = `http://127.0.0.1:${address.port}`

		const factory: McpServerFactory = async () => {
			const server = new McpServer({ name: 'legacy-cancellation-fixture', version: '1.0.0' })
			server.registerTool('blocking_read', { inputSchema: z.object({}) }, async (_input, ctx) => {
				await fetch(upstreamUrl, { signal: ctx.mcpReq.signal })
				return { content: [{ type: 'text', text: 'unexpected completion' }] }
			})
			return server
		}
		const service = await startHttpService(factory, 0, LOCAL, { drainMs: 20 })
		const client = new Client(
			{ name: 'legacy-cancellation-client', version: '1.0.0' },
			{
				capabilities: {},
				supportedProtocolVersions: ['2025-11-25'],
				versionNegotiation: { mode: 'legacy' },
			},
		)
		const transport = new StreamableHTTPClientTransport(new URL(`${service.url}/mcp`))

		try {
			await client.connect(transport)
			const controller = new AbortController()
			const call = client
				.callTool({ name: 'blocking_read', arguments: {} }, { signal: controller.signal })
				.catch(() => undefined)
			await within(upstreamStarted.promise, 'upstream start')

			controller.abort(new Error('cancelled by fixture client'))

			await within(upstreamCancelled.promise, 'upstream cancellation')
			await call
		} finally {
			await client.close()
			await service.close()
			for (const response of openResponses) response.destroy()
			await new Promise<void>((resolve) => upstream.close(() => resolve()))
		}
	})

	it('keeps matching request IDs isolated by principal while unique requests remain cancellable', () => {
		const registry = new RequestCancellationRegistry({ horizonMs: 1_000, timeoutMs: 1_000 })
		const first: TransportPrincipal = { kind: 'static', id: 'sha256:first' }
		const second: TransportPrincipal = { kind: 'static', id: 'sha256:second' }
		let firstAborts = 0
		let secondAborts = 0
		registry.register(first, 7, () => {
			firstAborts += 1
		})
		registry.register(second, 7, () => {
			secondAborts += 1
		})

		expect(registry.cancel(second, 7)).toBe(true)
		expect({ firstAborts, secondAborts }).toEqual({ firstAborts: 0, secondAborts: 1 })
		expect(registry.cancel(first, 7)).toBe(true)
		expect(firstAborts).toBe(1)
		registry.close()
	})

	it('keeps a collided ID blocked after one request completes', () => {
		const registry = new RequestCancellationRegistry({ horizonMs: 1_000, timeoutMs: 1_000 })
		const principal: TransportPrincipal = { kind: 'static', id: 'sha256:shared' }
		let firstAborts = 0
		let secondAborts = 0
		const releaseFirst = registry.register(principal, 7, () => {
			firstAborts += 1
		})
		const releaseSecond = registry.register(principal, 7, () => {
			secondAborts += 1
		})

		expect(registry.cancel(principal, 7)).toBe(false)
		releaseFirst?.()
		expect(registry.cancel(principal, 7)).toBe(false)
		expect({ firstAborts, secondAborts }).toEqual({ firstAborts: 0, secondAborts: 0 })
		releaseSecond?.()
		registry.close()
	})

	it('blocks delayed cancellation when a completed request ID is reused', () => {
		const registry = new RequestCancellationRegistry({ horizonMs: 1_000, timeoutMs: 1_000 })
		const principal: TransportPrincipal = { kind: 'anonymous-loopback', id: 'loopback' }
		let laterAborts = 0
		const releaseFirst = registry.register(principal, 'reused', () => undefined)
		releaseFirst?.()
		const releaseLater = registry.register(principal, 'reused', () => {
			laterAborts += 1
		})

		expect(registry.cancel(principal, 'reused')).toBe(false)
		expect(laterAborts).toBe(0)
		releaseLater?.()
		registry.close()
	})

	it('blocks cancellation after an unknown ID is later reused', () => {
		const registry = new RequestCancellationRegistry({ horizonMs: 1_000, timeoutMs: 1_000 })
		const principal: TransportPrincipal = { kind: 'anonymous-loopback', id: 'loopback' }
		let aborts = 0

		expect(registry.cancel(principal, 'future')).toBe(false)
		const release = registry.register(principal, 'future', () => {
			aborts += 1
		})
		expect(registry.cancel(principal, 'future')).toBe(false)
		expect(aborts).toBe(0)
		release?.()
		registry.close()
	})

	it('retains tombstones for the cancellation horizon, then permits a fresh owner', async () => {
		vi.useFakeTimers()
		const registry = new RequestCancellationRegistry({ horizonMs: 10, timeoutMs: 1_000 })
		const principal: TransportPrincipal = { kind: 'anonymous-loopback', id: 'loopback' }
		try {
			const releaseFirst = registry.register(principal, 'bounded', () => undefined)
			releaseFirst?.()
			await vi.advanceTimersByTimeAsync(10)

			let aborts = 0
			registry.register(principal, 'bounded', () => {
				aborts += 1
			})
			expect(registry.cancel(principal, 'bounded')).toBe(true)
			expect(aborts).toBe(1)
		} finally {
			registry.close()
			vi.useRealTimers()
		}
	})

	it('removes active listeners on timeout and service shutdown', async () => {
		const registry = new RequestCancellationRegistry({
			horizonMs: 10,
			timeoutMs: 10,
		})
		const principal: TransportPrincipal = { kind: 'anonymous-loopback', id: 'loopback' }
		let aborts = 0
		let releases = 0
		registry.register(
			principal,
			'expired',
			() => {
				aborts += 1
			},
			() => {
				releases += 1
			},
		)
		await new Promise((resolve) => setTimeout(resolve, 20))
		expect(registry.cancel(principal, 'expired')).toBe(false)

		registry.register(
			principal,
			'shutdown',
			() => {
				aborts += 1
			},
			() => {
				releases += 1
			},
		)
		registry.close()
		expect(registry.cancel(principal, 'shutdown')).toBe(false)
		expect(aborts).toBe(0)
		expect(releases).toBe(2)
	})

	it('disables routing on principal or request-id saturation without evicting safety state', () => {
		const first: TransportPrincipal = { kind: 'static', id: 'sha256:first' }
		const second: TransportPrincipal = { kind: 'static', id: 'sha256:second' }
		const principalBound = new RequestCancellationRegistry({
			horizonMs: 1_000,
			maxPrincipals: 1,
			timeoutMs: 1_000,
		})
		let firstAborts = 0
		const releaseFirst = principalBound.register(first, 1, () => {
			firstAborts += 1
		})
		expect(principalBound.register(second, 1, () => undefined)).toBeUndefined()
		expect(principalBound.cancel(first, 1)).toBe(false)
		releaseFirst?.()
		expect(principalBound.register(first, 2, () => undefined)).toBeUndefined()
		expect(firstAborts).toBe(0)
		principalBound.close()

		const idBound = new RequestCancellationRegistry({
			horizonMs: 1_000,
			maxRequestIdsPerPrincipal: 1,
			timeoutMs: 1_000,
		})
		const releaseId = idBound.register(first, 1, () => {
			firstAborts += 1
		})
		expect(idBound.register(first, 2, () => undefined)).toBeUndefined()
		expect(idBound.cancel(first, 1)).toBe(false)
		releaseId?.()
		expect(firstAborts).toBe(0)
		idBound.close()

		const registrationBound = new RequestCancellationRegistry({
			horizonMs: 1_000,
			maxRegistrationsPerId: 1,
			timeoutMs: 1_000,
		})
		const releaseRegistration = registrationBound.register(first, 1, () => {
			firstAborts += 1
		})
		expect(registrationBound.register(first, 1, () => undefined)).toBeUndefined()
		expect(registrationBound.cancel(first, 1)).toBe(false)
		releaseRegistration?.()
		expect(firstAborts).toBe(0)
		registrationBound.close()
	})

	it('answers an unknown cancellation notification without opening retained work', async () => {
		let factoryCalls = 0
		const service = await startHttpService(
			async () => {
				factoryCalls += 1
				return new McpServer({ name: 'unknown-cancellation-fixture', version: '1.0.0' })
			},
			0,
			LOCAL,
			{ drainMs: 20 },
		)

		try {
			const response = await fetch(`${service.url}/mcp`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json, text/event-stream',
				},
				body: JSON.stringify({
					jsonrpc: '2.0',
					method: 'notifications/cancelled',
					params: { requestId: 'unknown', reason: 'fixture' },
				}),
			})

			expect(response.status).toBe(202)
			expect((await response.text()).length).toBe(0)
			expect(factoryCalls).toBe(1)
		} finally {
			await service.close()
		}
	})
})

describe('cancellation message extraction', () => {
	it('preserves zero, string, and number request identities without coercion', () => {
		expect(cancellationRoute({ jsonrpc: '2.0', id: 0, method: 'tools/call' })).toEqual({
			kind: 'request',
			requestId: 0,
		})
		expect(cancellationRoute({ jsonrpc: '2.0', id: '0', method: 'tools/call' })).toEqual({
			kind: 'request',
			requestId: '0',
		})
		expect(
			cancellationRoute({
				jsonrpc: '2.0',
				method: 'notifications/cancelled',
				params: { requestId: 0 },
			}),
		).toEqual({ kind: 'cancellation', requestId: 0 })

		const registry = new RequestCancellationRegistry({ horizonMs: 1_000, timeoutMs: 1_000 })
		const principal: TransportPrincipal = { kind: 'anonymous-loopback', id: 'loopback' }
		let numberAborts = 0
		let stringAborts = 0
		registry.register(principal, 0, () => {
			numberAborts += 1
		})
		registry.register(principal, '0', () => {
			stringAborts += 1
		})
		expect(registry.cancel(principal, 0)).toBe(true)
		expect({ numberAborts, stringAborts }).toEqual({ numberAborts: 1, stringAborts: 0 })
		registry.close()
	})

	it.each([
		null,
		[],
		{ jsonrpc: '1.0', id: 1, method: 'tools/call' },
		{ jsonrpc: '2.0', id: null, method: 'tools/call' },
		{ jsonrpc: '2.0', id: 1.5, method: 'tools/call' },
		{ jsonrpc: '2.0', id: Number.MAX_SAFE_INTEGER + 1, method: 'tools/call' },
		{ jsonrpc: '2.0', method: 'notifications/cancelled', params: null },
		{ jsonrpc: '2.0', method: 'notifications/cancelled', params: { requestId: null } },
		{ jsonrpc: '2.0', id: 1, method: 'notifications/cancelled', params: { requestId: 1 } },
	])('rejects malformed or unsafe identity shape %#', (body) => {
		expect(cancellationRoute(body)).toBeUndefined()
	})
})
