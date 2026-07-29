import { afterEach, describe, expect, it, vi } from 'vitest'
import { z } from 'zod'
import { createClient } from '../src/api/client.js'
import type { ResolvedConfig } from '../src/config/types.js'
import { createServerFromContext, resolveServerContext } from '../src/server.js'
import { getTool } from '../src/tools/_factory.js'

const originalFetch = globalThis.fetch

afterEach(() => {
	globalThis.fetch = originalFetch
	vi.restoreAllMocks()
	vi.unstubAllEnvs()
})

describe('direct tool cancellation', () => {
	it('preserves the SDK signal at dispatch and aborts the composed fetch signal', async () => {
		vi.stubEnv('FLUENTCART_URL', 'https://fixture.invalid')
		vi.stubEnv('FLUENTCART_USERNAME', 'fixture')
		vi.stubEnv('FLUENTCART_APP_PASSWORD', 'fixture-password')
		const config: ResolvedConfig = {
			url: 'https://fixture.invalid',
			username: 'fixture',
			appPassword: 'fixture-password',
			adminBase: 'https://fixture.invalid/wp-json/fluent-cart/v2',
			publicBase: 'https://fixture.invalid/wp-json/fluent-cart/v2',
			timeout: 1_000,
		}
		let fetchSignal: AbortSignal | undefined
		let fetchStarted: (() => void) | undefined
		const started = new Promise<void>((resolve) => {
			fetchStarted = resolve
		})
		globalThis.fetch = vi.fn(
			(_input: URL | RequestInfo, init?: RequestInit) =>
				new Promise<Response>((_resolve, reject) => {
					fetchSignal = init?.signal ?? undefined
					fetchSignal?.addEventListener(
						'abort',
						() => reject(new DOMException('The operation was aborted', 'AbortError')),
						{ once: true },
					)
					fetchStarted?.()
				}),
		)
		const endpoint = getTool(createClient(config), {
			name: 'fluentcart_product_list',
			title: 'List products',
			description: 'List products for the cancellation fixture.',
			schema: z.object({}),
			endpoint: '/products',
		})
		const dispatch = endpoint.handler
		let handlerSignal: AbortSignal | undefined
		endpoint.handler = (input, execution) => {
			handlerSignal = execution?.signal
			return dispatch(input, execution)
		}
		const runtime = resolveServerContext()
		runtime.tools = [endpoint]
		const server = createServerFromContext(runtime, 'full')
		const registered = (
			server as unknown as {
				_registeredTools: Record<
					string,
					{
						handler: (
							input: Record<string, unknown>,
							context: { mcpReq: { signal: AbortSignal } },
						) => ReturnType<typeof endpoint.handler>
					}
				>
			}
		)._registeredTools[endpoint.name]
		if (!registered) throw new Error('The direct cancellation fixture was not registered.')
		const request = new AbortController()

		const pending = registered.handler({}, { mcpReq: { signal: request.signal } })
		await started

		expect(handlerSignal).toBe(request.signal)
		expect(fetchSignal).not.toBe(request.signal)
		request.abort(new Error('client cancelled'))

		const result = await pending
		expect(result.isError).toBe(true)
		expect(fetchSignal?.aborted).toBe(true)
	})
})
