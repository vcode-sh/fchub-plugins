import { Client, InMemoryTransport } from '@modelcontextprotocol/client'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { ApiCapabilities } from '../src/api/capabilities.js'
import { createMcpServerFactory, resolveServerContext } from '../src/server.js'

const REFERENCE_ROUTES = [
	'GET /settings/payment-methods/all',
	'GET /tax/classes',
	'GET /shipping/zones',
	'GET /address-info/countries',
	'GET /labels',
	'GET /products/fetch-term',
]

function capabilities(extra: string): ApiCapabilities {
	const operations = new Set([...REFERENCE_ROUTES, 'GET /app/init', extra])
	return {
		operations,
		source: 'live-rest-index',
		has: (method, path) => operations.has(`${method} ${path}`),
	}
}

function response(data: unknown): Response {
	return {
		ok: true,
		status: 200,
		statusText: 'OK',
		text: async () => JSON.stringify(data),
	} as Response
}

beforeEach(() => {
	vi.stubEnv('FLUENTCART_URL', 'https://cache.example')
	vi.stubEnv('FLUENTCART_USERNAME', 'merchant')
	vi.stubEnv('FLUENTCART_APP_PASSWORD', 'app-password')
})

afterEach(() => {
	vi.unstubAllGlobals()
	vi.unstubAllEnvs()
})

describe('server cache wiring', () => {
	it('uses the operation digest rather than route count as its cache profile', async () => {
		const fetch = vi
			.fn()
			.mockResolvedValueOnce(response({ countries: [{ code: 'PL', name: 'Poland' }] }))
			.mockResolvedValueOnce(response({ countries: [{ code: 'DE', name: 'Germany' }] }))
		vi.stubGlobal('fetch', fetch)

		const firstCapabilities = capabilities('GET /alpha')
		const secondCapabilities = capabilities('GET /beta')
		const first = resolveServerContext(firstCapabilities)
		const second = resolveServerContext(secondCapabilities)

		const firstTool = first.tools.find((tool) => tool.name === 'fluentcart_list_reference_data')
		const secondTool = second.tools.find((tool) => tool.name === 'fluentcart_list_reference_data')
		const firstResult = await firstTool?.handler({ kind: 'countries' })
		const secondResult = await secondTool?.handler({ kind: 'countries' })

		expect(firstResult?.content[0]?.text).toContain('Poland')
		expect(secondResult?.content[0]?.text).toContain('Germany')
		expect(fetch).toHaveBeenCalledTimes(2)
	})

	it('shares one cache entry between a registered resource and its reference tool', async () => {
		const fetch = vi
			.fn()
			.mockResolvedValue(response({ countries: [{ code: 'PL', name: 'Poland' }] }))
		vi.stubGlobal('fetch', fetch)
		const context = resolveServerContext(capabilities('GET /resource-cache-profile'))
		const server = await createMcpServerFactory(context, 'full')({ era: 'modern' })
		const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair()
		const client = new Client({ name: 'cache-test', version: '1' }, { capabilities: {} })

		await Promise.all([server.connect(serverTransport), client.connect(clientTransport)])
		try {
			const resource = await client.readResource({ uri: 'fluentcart://store/countries' })
			expect(JSON.stringify(resource.contents)).toContain('Poland')

			const tool = context.tools.find(
				(candidate) => candidate.name === 'fluentcart_list_reference_data',
			)
			const result = await tool?.handler({ kind: 'countries' })
			expect(result?.content[0]?.text).toContain('Poland')
			expect(fetch).toHaveBeenCalledTimes(1)
		} finally {
			await client.close()
			await server.close()
		}
	})
})
