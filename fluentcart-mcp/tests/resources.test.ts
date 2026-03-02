import { describe, expect, it, vi } from 'vitest'
import type { FluentCartClient } from '../src/api/client.js'
import { registerResources } from '../src/resources.js'

function mockClient(): FluentCartClient {
	return {
		get: vi.fn().mockResolvedValue({ data: { mock: true }, status: 200 }),
		post: vi.fn(),
		put: vi.fn(),
		delete: vi.fn(),
	} as unknown as FluentCartClient
}

function createMockServer() {
	const resources: Array<{ name: string; uri: string; meta: unknown }> = []
	return {
		registerResource: vi.fn(
			(name: string, uri: string, meta: unknown, _handler: (...args: never[]) => unknown) => {
				resources.push({ name, uri, meta })
			},
		),
		_resources: resources,
	}
}

describe('registerResources', () => {
	it('registers exactly 4 resources', () => {
		const server = createMockServer()
		const client = mockClient()
		registerResources(server as never, client)

		expect(server.registerResource).toHaveBeenCalledTimes(4)
	})

	it('registers store-config resource', () => {
		const server = createMockServer()
		registerResources(server as never, mockClient())

		const resource = server._resources.find((r) => r.name === 'store-config')
		expect(resource).toBeDefined()
		expect(resource!.uri).toBe('fluentcart://store/config')
	})

	it('registers store-countries resource', () => {
		const server = createMockServer()
		registerResources(server as never, mockClient())

		const resource = server._resources.find((r) => r.name === 'store-countries')
		expect(resource).toBeDefined()
		expect(resource!.uri).toBe('fluentcart://store/countries')
	})

	it('registers store-payment-methods resource', () => {
		const server = createMockServer()
		registerResources(server as never, mockClient())

		const resource = server._resources.find((r) => r.name === 'store-payment-methods')
		expect(resource).toBeDefined()
		expect(resource!.uri).toBe('fluentcart://store/payment-methods')
	})

	it('registers store-filter-options resource', () => {
		const server = createMockServer()
		registerResources(server as never, mockClient())

		const resource = server._resources.find((r) => r.name === 'store-filter-options')
		expect(resource).toBeDefined()
		expect(resource!.uri).toBe('fluentcart://store/filter-options')
	})

	it('sets mimeType to application/json for all resources', () => {
		const server = createMockServer()
		registerResources(server as never, mockClient())

		for (const resource of server._resources) {
			expect((resource.meta as { mimeType: string }).mimeType).toBe('application/json')
		}
	})

	it('resource handler calls client.get and returns JSON contents', async () => {
		const client = mockClient()
		vi.mocked(client.get).mockResolvedValue({ data: { currency: 'USD' }, status: 200 })

		const handlers = new Map<string, (...args: never[]) => unknown>()
		const server = {
			registerResource: vi.fn(
				(name: string, _uri: string, _meta: unknown, handler: (...args: never[]) => unknown) => {
					handlers.set(name, handler)
				},
			),
		}

		registerResources(server as never, client)

		const handler = handlers.get('store-config')
		expect(handler).toBeDefined()

		const result = await handler!(new URL('fluentcart://store/config'))

		expect(client.get).toHaveBeenCalledWith('/app/init', undefined, undefined)
		expect(result.contents).toHaveLength(1)
		expect(result.contents[0].mimeType).toBe('application/json')
		expect(JSON.parse(result.contents[0].text)).toEqual({ currency: 'USD' })
	})
})
