import { afterEach, describe, expect, it, vi } from 'vitest'
import {
	CAPABILITY_DISCOVERY_ERROR,
	CapabilityDiscoveryError,
	capabilitiesFromRestIndex,
	discoverApiCapabilities,
} from '../../src/api/capabilities.js'
import { FluentCartApiError } from '../../src/api/errors.js'

const STORE = 'http://store.test'

function jsonResponse(body: unknown, status = 200): Response {
	const text = typeof body === 'string' ? body : JSON.stringify(body)
	return {
		ok: status >= 200 && status < 300,
		status,
		headers: new Headers(),
		text: () => Promise.resolve(text),
	} as unknown as Response
}

function redirectResponse(location: string, status = 302): Response {
	return {
		ok: false,
		status,
		headers: new Headers({ location }),
		text: () => Promise.resolve(''),
	} as unknown as Response
}

/** Install a fetch stub and record every call it receives. */
function stubFetch(responder: (url: string, init: RequestInit) => Response | Promise<Response>) {
	const calls: { url: string; init: RequestInit }[] = []
	const stub = vi.fn((url: string, init: RequestInit) => {
		calls.push({ url, init })
		return Promise.resolve(responder(url, init))
	})
	vi.stubGlobal('fetch', stub)
	return calls
}

const REST_INDEX = {
	namespaces: ['oembed/1.0', 'wp/v2', 'fluent-cart/v2'],
	routes: {
		'/': { namespace: '', endpoints: [{ methods: ['GET'] }] },
		'/wp/v2/posts': { endpoints: [{ methods: ['GET', 'POST'] }] },
		'/fluent-cart/v2': { endpoints: [{ methods: ['GET'] }] },
		'/fluent-cart/v2/orders': { endpoints: [{ methods: ['GET'] }, { methods: ['POST'] }] },
		'/fluent-cart/v2/orders/(?P<id>[^\\s(?!/)]+)': {
			endpoints: [{ methods: ['GET', 'PUT', 'DELETE'] }],
		},
		'/fluent-cart/v2/customers/(?P<customerId>[0-9]+)': { endpoints: [{ methods: ['GET'] }] },
		'/fluent-cart/v2/customers/(?P<customerId>[^\\s(?!/)]+)': {
			endpoints: [{ methods: ['GET'] }],
		},
		'/fluent-cart/v2/reports/sales': {
			endpoints: [{ methods: ['GET', 'HEAD'] }, { methods: ['OPTIONS'] }],
		},
	},
}

afterEach(() => {
	vi.unstubAllGlobals()
	vi.useRealTimers()
	vi.restoreAllMocks()
})

describe('discoverApiCapabilities', () => {
	it('reads only the public REST root index, keeping any subdirectory path', async () => {
		const root = stubFetch(() => jsonResponse(REST_INDEX))
		await discoverApiCapabilities(STORE)

		expect(root).toHaveLength(1)
		expect(root[0]?.url).toBe('http://store.test/wp-json/')

		const subdirectory = stubFetch(() => jsonResponse(REST_INDEX))
		await discoverApiCapabilities('http://store.test/shop/')

		expect(subdirectory[0]?.url).toBe('http://store.test/shop/wp-json/')
	})

	it('never sends application-password credentials to the public root index', async () => {
		const calls = stubFetch(() => jsonResponse(REST_INDEX))
		await discoverApiCapabilities(STORE)

		const headers = (calls[0]?.init.headers ?? {}) as Record<string, string>
		const headerNames = Object.keys(headers).map((name) => name.toLowerCase())

		expect(headerNames).not.toContain('authorization')
		expect(headerNames).not.toContain('cookie')
		expect(JSON.stringify(calls[0]?.init)).not.toMatch(/basic/i)
	})

	it('retains only FluentCart v2 operations', async () => {
		stubFetch(() => jsonResponse(REST_INDEX))
		const capabilities = await discoverApiCapabilities(STORE)

		expect(capabilities.has('GET', '/orders')).toBe(true)
		expect([...capabilities.operations].some((entry) => entry.includes('/wp/v2'))).toBe(false)
		expect([...capabilities.operations].some((entry) => entry.includes('posts'))).toBe(false)
	})

	it('respects the per-endpoint methods arrays', async () => {
		stubFetch(() => jsonResponse(REST_INDEX))
		const capabilities = await discoverApiCapabilities(STORE)

		expect(capabilities.has('GET', '/orders')).toBe(true)
		expect(capabilities.has('POST', '/orders')).toBe(true)
		expect(capabilities.has('DELETE', '/orders')).toBe(false)

		expect(capabilities.has('GET', '/orders/{param}')).toBe(true)
		expect(capabilities.has('PUT', '/orders/{param}')).toBe(true)
		expect(capabilities.has('DELETE', '/orders/{param}')).toBe(true)
		expect(capabilities.has('POST', '/orders/{param}')).toBe(false)
	})

	it('drops methods outside the discoverable set', async () => {
		stubFetch(() => jsonResponse(REST_INDEX))
		const capabilities = await discoverApiCapabilities(STORE)

		expect(capabilities.has('GET', '/reports/sales')).toBe(true)
		expect([...capabilities.operations].some((entry) => entry.startsWith('HEAD '))).toBe(false)
		expect([...capabilities.operations].some((entry) => entry.startsWith('OPTIONS '))).toBe(false)
	})

	it('canonicalises named parameters and merges route variants', async () => {
		stubFetch(() => jsonResponse(REST_INDEX))
		const capabilities = await discoverApiCapabilities(STORE)

		expect(capabilities.has('GET', '/customers/{param}')).toBe(true)
		expect(
			[...capabilities.operations].filter((entry) => entry === 'GET /customers/{param}'),
		).toHaveLength(1)
		expect([...capabilities.operations].every((entry) => !entry.includes('(?P<'))).toBe(true)
	})

	it('accepts raw and namespaced paths in has()', async () => {
		stubFetch(() => jsonResponse(REST_INDEX))
		const capabilities = await discoverApiCapabilities(STORE)

		expect(capabilities.has('GET', '/fluent-cart/v2/orders/(?P<id>[0-9]+)')).toBe(true)
		expect(capabilities.has('GET', '/fluent-cart/v2/orders')).toBe(true)
		expect(capabilities.has('GET', '/orders/{order_id}')).toBe(true)
	})

	it('excludes the namespace root and reports live evidence as its source', async () => {
		stubFetch(() => jsonResponse(REST_INDEX))
		const capabilities = await discoverApiCapabilities(STORE)

		// The namespace index describes the namespace; it is not an application operation.
		expect(capabilities.has('GET', '/')).toBe(false)
		expect(capabilities.operations.has('GET /')).toBe(false)
		expect(capabilities.source).toBe('live-rest-index')
	})
})

describe('discoverApiCapabilities failures', () => {
	const expectDiscoveryError = async (promise: Promise<unknown>) => {
		await expect(promise).rejects.toBeInstanceOf(CapabilityDiscoveryError)
		await expect(promise).rejects.toMatchObject({ code: CAPABILITY_DISCOVERY_ERROR })
	}

	it('fails on malformed JSON', async () => {
		stubFetch(() => jsonResponse('<html>blocked by firewall</html>'))
		await expectDiscoveryError(discoverApiCapabilities(STORE))
	})

	it.each([401, 403, 404])('fails on HTTP %i', async (status) => {
		stubFetch(() => jsonResponse({ code: 'rest_forbidden' }, status))
		await expectDiscoveryError(discoverApiCapabilities(STORE))
	})

	it('fails when the FluentCart namespace is absent', async () => {
		stubFetch(() =>
			jsonResponse({ namespaces: ['wp/v2'], routes: { '/wp/v2/posts': { endpoints: [] } } }),
		)
		await expectDiscoveryError(discoverApiCapabilities(STORE))
	})

	it('fails when the namespace is listed but exposes no routes', async () => {
		stubFetch(() => jsonResponse({ namespaces: ['fluent-cart/v2'], routes: {} }))
		await expectDiscoveryError(discoverApiCapabilities(STORE))
	})

	it('validates the document shape before reading routes', async () => {
		stubFetch(() => jsonResponse({ namespaces: ['fluent-cart/v2'], routes: 'not-an-object' }))
		await expectDiscoveryError(discoverApiCapabilities(STORE))

		stubFetch(() => jsonResponse({ namespaces: ['fluent-cart/v2'] }))
		await expectDiscoveryError(discoverApiCapabilities(STORE))
	})

	it('rejects a redirect to another origin', async () => {
		stubFetch(() => redirectResponse('https://evil.test/wp-json/'))
		await expectDiscoveryError(discoverApiCapabilities(STORE))
	})

	it('follows a same-origin redirect', async () => {
		let hop = 0
		const calls = stubFetch(() => {
			hop += 1
			return hop === 1 ? redirectResponse('/wp-json/') : jsonResponse(REST_INDEX)
		})

		const capabilities = await discoverApiCapabilities('http://store.test/old')

		expect(calls).toHaveLength(2)
		expect(calls[1]?.url).toBe('http://store.test/wp-json/')
		expect(capabilities.has('GET', '/orders')).toBe(true)
	})

	it('stops after too many same-origin redirects', async () => {
		stubFetch(() => redirectResponse('/wp-json/'))
		await expectDiscoveryError(discoverApiCapabilities(STORE))
	})

	it('rejects a store URL that is not absolute http(s)', async () => {
		await expectDiscoveryError(discoverApiCapabilities('not-a-url'))
		await expectDiscoveryError(discoverApiCapabilities('ftp://store.test'))
	})

	it('reports an unreachable store as a connection error, not a discovery error', async () => {
		vi.stubGlobal(
			'fetch',
			vi.fn(() => Promise.reject(new TypeError('fetch failed'))),
		)

		const promise = discoverApiCapabilities(STORE)

		await expect(promise).rejects.toBeInstanceOf(FluentCartApiError)
		await expect(promise).rejects.toMatchObject({ code: 'CONNECTION_ERROR' })
		await expect(promise).rejects.not.toBeInstanceOf(CapabilityDiscoveryError)
	})

	it('aborts the request after ten seconds and reports a timeout', async () => {
		vi.useFakeTimers()

		let captured: AbortSignal | undefined
		vi.stubGlobal(
			'fetch',
			vi.fn(
				(_url: string, init: RequestInit) =>
					new Promise<Response>((_resolve, reject) => {
						captured = init.signal ?? undefined
						captured?.addEventListener('abort', () => {
							const error = new Error('The operation was aborted')
							error.name = 'AbortError'
							reject(error)
						})
					}),
			),
		)

		const promise = discoverApiCapabilities(STORE)
		const assertion = expect(promise).rejects.toMatchObject({ code: 'TIMEOUT' })

		await vi.advanceTimersByTimeAsync(9_999)
		expect(captured?.aborted).toBe(false)

		await vi.advanceTimersByTimeAsync(1)
		expect(captured?.aborted).toBe(true)

		await assertion
	})
})

describe('capabilitiesFromRestIndex', () => {
	it('derives the same capabilities as a live discovery', async () => {
		stubFetch(() => jsonResponse(REST_INDEX))
		const live = await discoverApiCapabilities(STORE)
		const offline = capabilitiesFromRestIndex(REST_INDEX)

		expect([...offline.operations].sort()).toEqual([...live.operations].sort())
	})

	it('rejects a document that is not a REST index', () => {
		expect(() => capabilitiesFromRestIndex({ routes: null })).toThrow(CapabilityDiscoveryError)
		expect(() => capabilitiesFromRestIndex(null)).toThrow(CapabilityDiscoveryError)
	})
})
