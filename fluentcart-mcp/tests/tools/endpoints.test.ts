import { describe, expect, it } from 'vitest'
import { type ApiCapabilities, capabilitiesFromRestIndex } from '../../src/api/capabilities.js'
import type { EndpointVariant } from '../../src/tools/endpoint-types.js'
import {
	composite,
	direct,
	isSupported,
	op,
	selectEndpoint,
	toCanonicalPath,
} from '../../src/tools/endpoints.js'

function capabilitiesFor(operations: readonly { method: string; path: string }[]): ApiCapabilities {
	const routes: Record<string, { endpoints: { methods: string[] }[] }> = {}
	for (const { method, path } of operations) {
		const key = `/fluent-cart/v2${path}`
		routes[key] ??= { endpoints: [{ methods: [] }] }
		routes[key].endpoints[0]?.methods.push(method)
	}
	return capabilitiesFromRestIndex({ namespaces: ['fluent-cart/v2'], routes })
}

const store = capabilitiesFor([
	{ method: 'GET', path: '/orders' },
	{ method: 'POST', path: '/orders' },
	{ method: 'GET', path: '/orders/{param}' },
	{ method: 'DELETE', path: '/coupons/{param}' },
])

describe('toCanonicalPath', () => {
	it('flattens every placeholder dialect to one identity', () => {
		expect(toCanonicalPath('/orders/:order_id')).toBe('/orders/{param}')
		expect(toCanonicalPath('/orders/{order_id}')).toBe('/orders/{param}')
		expect(toCanonicalPath('/fluent-cart/v2/orders/(?P<id>[^\\s(?!/)]+)')).toBe('/orders/{param}')
	})

	it('handles several parameters in one path', () => {
		expect(toCanonicalPath('/orders/:order_id/address/:address_id')).toBe(
			'/orders/{param}/address/{param}',
		)
	})
})

describe('selectEndpoint', () => {
	it('returns the first supported variant', () => {
		const variants: EndpointVariant[] = [
			{ method: 'GET', path: '/orders' },
			{ method: 'GET', path: '/orders/{param}' },
		]
		expect(selectEndpoint(store, variants)?.path).toBe('/orders')
	})

	it('falls through to a later variant when the first is unsupported', () => {
		const variants: EndpointVariant[] = [
			{ method: 'GET', path: '/legacy/orders' },
			{ method: 'GET', path: '/orders' },
		]
		expect(selectEndpoint(store, variants)?.path).toBe('/orders')
	})

	it('honours declaration order rather than picking the best match', () => {
		const variants: EndpointVariant[] = [
			{ method: 'GET', path: '/orders/:id' },
			{ method: 'GET', path: '/orders' },
		]
		expect(selectEndpoint(store, variants)?.path).toBe('/orders/:id')
	})

	it('does not match a supported path with the wrong method', () => {
		expect(selectEndpoint(store, [{ method: 'PUT', path: '/orders' }])).toBeNull()
		expect(selectEndpoint(store, [{ method: 'DELETE', path: '/orders/{param}' }])).toBeNull()
	})

	it('accepts the factory `:name` dialect against a canonical registry', () => {
		expect(selectEndpoint(store, [{ method: 'GET', path: '/orders/:order_id' }])?.method).toBe(
			'GET',
		)
	})

	it('returns null when nothing matches', () => {
		expect(selectEndpoint(store, [{ method: 'GET', path: '/nope' }])).toBeNull()
	})

	it('returns null for an empty variant list', () => {
		expect(selectEndpoint(store, [])).toBeNull()
	})

	it('preserves mapInput on the selected variant', () => {
		const mapInput = (input: Record<string, unknown>) => ({ path: '/orders', body: input })
		const selected = selectEndpoint(store, [{ method: 'POST', path: '/orders', mapInput }])

		expect(selected?.mapInput).toBe(mapInput)
	})
})

describe('isSupported', () => {
	it('accepts a direct tool when any variant resolves', () => {
		expect(isSupported(store, direct('GET', '/legacy', op('GET', '/orders')))).toBe(true)
	})

	it('rejects a direct tool when no variant resolves', () => {
		expect(isSupported(store, direct('PATCH', '/orders'))).toBe(false)
	})

	it('rejects an empty declaration outright', () => {
		expect(isSupported(store, { kind: 'direct', variants: [] })).toBe(false)
		expect(isSupported(store, composite())).toBe(false)
	})

	it('requires every operation of a composite tool', () => {
		// A composite runs a sequence; finding out mid-flight that the next call does not exist
		// would leave the store half-changed, so a missing leg withdraws the whole tool.
		expect(isSupported(store, composite(op('GET', '/orders/{param}'), op('POST', '/orders')))).toBe(
			true,
		)
		expect(isSupported(store, composite(op('GET', '/orders'), op('PUT', '/orders')))).toBe(false)
	})
})

describe('metadata builders', () => {
	it('direct records the primary route first, then its fallbacks', () => {
		const routes = direct('POST', '/current', op('POST', '/legacy'))

		expect(routes.kind).toBe('direct')
		expect(routes.variants.map((variant) => variant.path)).toEqual(['/current', '/legacy'])
	})

	it('composite records every operation the tool may call', () => {
		const routes = composite(op('GET', '/a'), op('PUT', '/b'), op('DELETE', '/c'))

		expect(routes.kind).toBe('composite')
		expect(routes.variants).toHaveLength(3)
	})
})
