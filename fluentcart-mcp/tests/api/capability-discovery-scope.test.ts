// Discovery reads one namespace, not the whole site, and still fails closed.
//
// Measured on the development store: `/wp-json/` is 531,388 characters over 998 routes, of which
// 396 operations survive the FluentCart filter; `/wp-json/fluent-cart/v2` is 121,333 characters
// and yields the identical 396 operations — same set, nothing only in one, nothing only in the
// other. Every startup was moving 4.5x the bytes it kept.
//
// The narrower URL changes one failure mode, which is what most of this file is about: a store
// without FluentCart now answers 404 `rest_no_route` rather than serving an index whose
// `namespaces` array lacks the entry. That must still be a fatal, actionable startup error.
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

function stubFetch(responder: (url: string) => Response) {
	const calls: string[] = []
	vi.stubGlobal(
		'fetch',
		vi.fn((url: string) => {
			calls.push(url)
			return Promise.resolve(responder(url))
		}),
	)
	return calls
}

/** The shape WordPress serves at a namespace URL: `namespace` and `routes`, no `namespaces`. */
const NAMESPACE_INDEX = {
	namespace: 'fluent-cart/v2',
	routes: {
		'/fluent-cart/v2': { namespace: 'fluent-cart/v2', endpoints: [{ methods: ['GET'] }] },
		'/fluent-cart/v2/orders': { endpoints: [{ methods: ['GET', 'POST'] }] },
		'/fluent-cart/v2/orders/(?P<id>[^\\s(?!/)]+)': { endpoints: [{ methods: ['GET', 'PUT'] }] },
	},
	_links: { up: [{ href: 'http://store.test/wp-json/' }] },
}

/** The same store as seen through the whole-site index, plus everything discovery drops. */
const WHOLE_SITE_INDEX = {
	name: 'Store',
	namespaces: ['oembed/1.0', 'wp/v2', 'fluent-cart/v2'],
	routes: {
		'/': { endpoints: [{ methods: ['GET'] }] },
		'/wp/v2/posts': { endpoints: [{ methods: ['GET', 'POST'] }] },
		'/wp/v2/users': { endpoints: [{ methods: ['GET'] }] },
		'/oembed/1.0/embed': { endpoints: [{ methods: ['GET'] }] },
		...NAMESPACE_INDEX.routes,
	},
}

/** WordPress's answer for a namespace nobody registered. Captured from the live store. */
const REST_NO_ROUTE = {
	code: 'rest_no_route',
	message: 'No route was found matching the URL and request method.',
	data: { status: 404 },
}

afterEach(() => {
	vi.unstubAllGlobals()
	vi.useRealTimers()
	vi.restoreAllMocks()
})

describe('discovery fetches one namespace', () => {
	it('requests the namespace index and nothing else', async () => {
		const calls = stubFetch(() => jsonResponse(NAMESPACE_INDEX))
		await discoverApiCapabilities(STORE)

		expect(calls).toEqual(['http://store.test/wp-json/fluent-cart/v2'])
	})

	it('derives the same operations from the namespace index as from the whole-site index', async () => {
		// The claim the change rests on, checked rather than assumed: the site index carries wp/v2
		// and oembed routes that are filtered out anyway, so the two documents describe the same
		// FluentCart store.
		const narrow = capabilitiesFromRestIndex(NAMESPACE_INDEX)
		const wide = capabilitiesFromRestIndex(WHOLE_SITE_INDEX)

		expect([...narrow.operations].sort()).toEqual([...wide.operations].sort())
		expect(narrow.has('GET', '/orders')).toBe(true)
		expect(narrow.has('PUT', '/orders/{param}')).toBe(true)
		expect([...narrow.operations].some((entry) => entry.includes('/wp/v2'))).toBe(false)
	})

	it('accepts a document with no namespaces array, which a namespace index never has', async () => {
		stubFetch(() => jsonResponse(NAMESPACE_INDEX))
		const capabilities = await discoverApiCapabilities(STORE)

		expect(capabilities.source).toBe('live-rest-index')
		expect(capabilities.operations.size).toBeGreaterThan(0)
	})

	it('sends no credential to the public namespace index', async () => {
		const init: RequestInit[] = []
		vi.stubGlobal(
			'fetch',
			vi.fn((_url: string, options: RequestInit) => {
				init.push(options)
				return Promise.resolve(jsonResponse(NAMESPACE_INDEX))
			}),
		)
		await discoverApiCapabilities(STORE)

		const headers = (init[0]?.headers ?? {}) as Record<string, string>
		expect(Object.keys(headers).map((name) => name.toLowerCase())).not.toContain('authorization')
	})
})

describe('a store without FluentCart still fails closed', () => {
	async function capturedError(promise: Promise<unknown>): Promise<Error> {
		try {
			await promise
		} catch (error) {
			if (error instanceof Error) return error
			throw new Error(`Expected Error, received ${String(error)}`)
		}
		throw new Error('Expected promise to reject')
	}

	it('reports the 404 as a discovery error, never as an empty registry', async () => {
		stubFetch(() => jsonResponse(REST_NO_ROUTE, 404))
		const promise = discoverApiCapabilities(STORE)

		await expect(promise).rejects.toBeInstanceOf(CapabilityDiscoveryError)
		await expect(promise).rejects.toMatchObject({ code: CAPABILITY_DISCOVERY_ERROR })
	})

	it('names the URL, both causes and what to check', async () => {
		stubFetch(() => jsonResponse(REST_NO_ROUTE, 404))

		const error = await capturedError(discoverApiCapabilities(STORE))

		expect(error.message).toContain('http://store.test/wp-json/fluent-cart/v2')
		expect(error.message).toContain('FluentCart is not active')
		expect(error.message).toContain('REST API is disabled')
		expect(error.message).toContain('/wp-json/')
	})

	it('still names the URL on a blocked or forbidden index', async () => {
		stubFetch(() => jsonResponse({ code: 'rest_forbidden' }, 403))

		const error = await capturedError(discoverApiCapabilities(STORE))

		expect(error.message).toContain('403')
		expect(error.message).toContain('http://store.test/wp-json/fluent-cart/v2')
	})

	it('refuses a namespace index that carries only its own root', async () => {
		// 200, valid shape, one FluentCart path, zero operations. Passing this through would have
		// pruned every tool and reported nothing wrong.
		stubFetch(() =>
			jsonResponse({
				namespace: 'fluent-cart/v2',
				routes: { '/fluent-cart/v2': { endpoints: [{ methods: ['GET'] }] } },
			}),
		)

		const promise = discoverApiCapabilities(STORE)
		await expect(promise).rejects.toBeInstanceOf(CapabilityDiscoveryError)
		await expect(promise).rejects.toThrow(/exposes no operations/)
	})

	it('refuses a rewritten or hidden index that is not JSON', async () => {
		stubFetch(() => jsonResponse('<html>blocked by firewall</html>'))
		await expect(discoverApiCapabilities(STORE)).rejects.toBeInstanceOf(CapabilityDiscoveryError)
	})
})

describe('transport semantics are unchanged', () => {
	it('keeps CONNECTION_ERROR for an unreachable store', async () => {
		vi.stubGlobal(
			'fetch',
			vi.fn(() => Promise.reject(new TypeError('fetch failed'))),
		)
		const promise = discoverApiCapabilities(STORE)

		await expect(promise).rejects.toBeInstanceOf(FluentCartApiError)
		await expect(promise).rejects.toMatchObject({ code: 'CONNECTION_ERROR' })
	})

	it('keeps the ten-second budget and reports a timeout', async () => {
		vi.useFakeTimers()
		let captured: AbortSignal | undefined
		vi.stubGlobal(
			'fetch',
			vi.fn(
				(_url: string, options: RequestInit) =>
					new Promise<Response>((_resolve, reject) => {
						captured = options.signal ?? undefined
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
