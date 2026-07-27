import { describe, expect, it } from 'vitest'
import {
	canonicaliseRoute,
	compareOperations,
	dedupeOperations,
	HTTP_METHODS,
	type HttpMethod,
	isFluentCartRoute,
	isHttpMethod,
	isNamespaceRoot,
	normaliseOperations,
	operationKey,
	type RouteOperation,
	sortOperations,
} from '../../src/api/route-normalisation.js'

describe('canonicaliseRoute', () => {
	it('replaces a WordPress named group containing nested parentheses and a slash', () => {
		expect(canonicaliseRoute('/fluent-cart/v2/orders/(?P<id>[^\\s(?!/)]+)')).toBe('/orders/{param}')
	})

	it('replaces an OpenAPI-style placeholder', () => {
		expect(canonicaliseRoute('/orders/{order_id}/refund')).toBe('/orders/{param}/refund')
	})

	it('collapses differing character classes for the same parameter', () => {
		const numeric = canonicaliseRoute('/fluent-cart/v2/customers/(?P<customerId>[0-9]+)')
		const permissive = canonicaliseRoute('/fluent-cart/v2/customers/(?P<customerId>[^\\s(?!/)]+)')

		expect(numeric).toBe('/customers/{param}')
		expect(permissive).toBe('/customers/{param}')
		expect(numeric).toBe(permissive)
	})

	it('collapses differing group names for the same position', () => {
		expect(canonicaliseRoute('/fluent-cart/v2/products/(?P<productId>[^\\s(?!/)]+)/pricing')).toBe(
			canonicaliseRoute('/fluent-cart/v2/products/(?P<postId>[^\\s(?!/)]+)/pricing'),
		)
	})

	it('replaces every group in a multi-parameter route', () => {
		expect(
			canonicaliseRoute(
				'/fluent-cart/v2/orders/(?P<order>[^\\s(?!/)]+)/subscriptions/(?P<subscription>[^\\s(?!/)]+)/cancel',
			),
		).toBe('/orders/{param}/subscriptions/{param}/cancel')
	})

	it('handles a group whose body contains a real nested group', () => {
		expect(canonicaliseRoute('/fluent-cart/v2/tax/(?P<code>(?:eu|uk)-[a-z]+)/rates')).toBe(
			'/tax/{param}/rates',
		)
	})

	it('handles an escaped closing parenthesis inside a group body', () => {
		expect(canonicaliseRoute('/fluent-cart/v2/files/(?P<name>[a-z\\)]+)/download')).toBe(
			'/files/{param}/download',
		)
	})

	it('leaves no regular expression syntax in the canonical form', () => {
		const canonical = canonicaliseRoute('/fluent-cart/v2/options/attr/group/(?P<id>[0-9]+)/terms')

		expect(canonical).toBe('/options/attr/group/{param}/terms')
		expect(canonical).not.toMatch(/[()?<>[\]\\+^$]/)
	})

	it('strips the wp-json prefix and the FluentCart namespace', () => {
		expect(canonicaliseRoute('/wp-json/fluent-cart/v2/orders')).toBe('/orders')
		expect(canonicaliseRoute('wp-json/fluent-cart/v2/orders')).toBe('/orders')
	})

	it('does not strip a path that merely starts with the same letters', () => {
		expect(canonicaliseRoute('/wp-jsonish/orders')).toBe('/wp-jsonish/orders')
	})

	it('reduces the namespace root to the root path', () => {
		expect(canonicaliseRoute('/fluent-cart/v2')).toBe('/')
		expect(isNamespaceRoot('/fluent-cart/v2')).toBe(true)
		expect(isNamespaceRoot('/fluent-cart/v2/orders')).toBe(false)
	})

	it('normalises leading slash, trailing slash and repeated slashes', () => {
		expect(canonicaliseRoute('orders')).toBe('/orders')
		expect(canonicaliseRoute('/orders/')).toBe('/orders')
		expect(canonicaliseRoute('/orders//refunds')).toBe('/orders/refunds')
		expect(canonicaliseRoute('   /orders   ')).toBe('/orders')
		expect(canonicaliseRoute('')).toBe('/')
	})

	it('is idempotent', () => {
		const once = canonicaliseRoute('/fluent-cart/v2/orders/(?P<id>[^\\s(?!/)]+)/notes')
		expect(canonicaliseRoute(once)).toBe(once)
	})

	it('does not leak the remainder of an unterminated group', () => {
		expect(canonicaliseRoute('/fluent-cart/v2/broken/(?P<id>[0-9]+')).toBe('/broken/{param}')
	})
})

describe('isFluentCartRoute', () => {
	it('accepts namespace routes and the namespace root', () => {
		expect(isFluentCartRoute('/fluent-cart/v2')).toBe(true)
		expect(isFluentCartRoute('/fluent-cart/v2/orders')).toBe(true)
		expect(isFluentCartRoute('/wp-json/fluent-cart/v2/orders')).toBe(true)
	})

	it('rejects other namespaces, including deceptive prefixes', () => {
		expect(isFluentCartRoute('/wp/v2/posts')).toBe(false)
		expect(isFluentCartRoute('/fluent-cart/v1/orders')).toBe(false)
		expect(isFluentCartRoute('/fluent-cart/v22/orders')).toBe(false)
	})
})

describe('isHttpMethod', () => {
	it('accepts the five discoverable methods', () => {
		expect(HTTP_METHODS).toEqual(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'])
		for (const method of HTTP_METHODS) {
			expect(isHttpMethod(method)).toBe(true)
		}
	})

	it('rejects methods discovery must not retain', () => {
		for (const method of ['HEAD', 'OPTIONS', 'TRACE', 'get', '']) {
			expect(isHttpMethod(method)).toBe(false)
		}
	})
})

describe('operationKey', () => {
	it('qualifies a canonical path by method', () => {
		expect(operationKey('GET', '/fluent-cart/v2/orders/(?P<id>[0-9]+)')).toBe('GET /orders/{param}')
	})

	it('distinguishes methods on one path', () => {
		expect(operationKey('GET', '/orders')).not.toBe(operationKey('POST', '/orders'))
	})
})

const op = (method: HttpMethod, path: string): RouteOperation => ({ method, path })

describe('sortOperations', () => {
	it('orders by path then method', () => {
		const sorted = sortOperations([
			op('POST', '/orders'),
			op('GET', '/customers'),
			op('DELETE', '/orders'),
			op('GET', '/orders'),
		])

		expect(sorted.map((entry) => `${entry.method} ${entry.path}`)).toEqual([
			'GET /customers',
			'DELETE /orders',
			'GET /orders',
			'POST /orders',
		])
	})

	it('produces the same output for any input order', () => {
		const operations = [
			op('GET', '/orders'),
			op('POST', '/orders'),
			op('GET', '/customers'),
			op('PUT', '/products/{param}'),
			op('DELETE', '/coupons/{param}'),
		]
		const reversed = [...operations].reverse()
		const rotated = [...operations.slice(2), ...operations.slice(0, 2)]

		expect(sortOperations(reversed)).toEqual(sortOperations(operations))
		expect(sortOperations(rotated)).toEqual(sortOperations(operations))
	})

	it('is stable for entries that compare equal', () => {
		const first = { method: 'GET', path: '/orders', tag: 'first' }
		const second = { method: 'GET', path: '/orders', tag: 'second' }

		const sorted = sortOperations([first, second] as unknown as RouteOperation[])

		expect(sorted[0]).toBe(first)
		expect(sorted[1]).toBe(second)
	})

	it('does not mutate its input', () => {
		const operations = [op('POST', '/orders'), op('GET', '/customers')]
		sortOperations(operations)

		expect(operations[0]?.method).toBe('POST')
	})

	it('compares consistently in both directions', () => {
		const left = op('GET', '/customers')
		const right = op('GET', '/orders')

		expect(compareOperations(left, right)).toBeLessThan(0)
		expect(compareOperations(right, left)).toBeGreaterThan(0)
		expect(compareOperations(left, { ...left })).toBe(0)
	})
})

describe('dedupeOperations', () => {
	it('removes exact repeats', () => {
		expect(dedupeOperations([op('GET', '/orders'), op('GET', '/orders')])).toEqual([
			op('GET', '/orders'),
		])
	})

	it('merges route variants that canonicalise to one operation', () => {
		const deduped = dedupeOperations([
			op('GET', '/fluent-cart/v2/customers/(?P<customerId>[0-9]+)'),
			op('GET', '/fluent-cart/v2/customers/(?P<customerId>[^\\s(?!/)]+)'),
		])

		expect(deduped).toEqual([op('GET', '/customers/{param}')])
	})

	it('keeps distinct methods on one canonical path', () => {
		const deduped = dedupeOperations([
			op('GET', '/fluent-cart/v2/orders/(?P<id>[0-9]+)'),
			op('DELETE', '/fluent-cart/v2/orders/(?P<order>[^\\s(?!/)]+)'),
		])

		expect(deduped).toHaveLength(2)
	})

	it('preserves first-seen order', () => {
		const deduped = dedupeOperations([
			op('POST', '/orders'),
			op('GET', '/customers'),
			op('POST', '/orders'),
		])

		expect(deduped.map((entry) => entry.method)).toEqual(['POST', 'GET'])
	})
})

describe('normaliseOperations', () => {
	it('canonicalises, deduplicates and sorts in one pass', () => {
		expect(
			normaliseOperations([
				op('POST', '/fluent-cart/v2/orders'),
				op('GET', '/fluent-cart/v2/customers/(?P<customerId>[^\\s(?!/)]+)'),
				op('GET', '/fluent-cart/v2/customers/(?P<customerId>[0-9]+)'),
				op('GET', '/fluent-cart/v2/orders/'),
			]),
		).toEqual([op('GET', '/customers/{param}'), op('GET', '/orders'), op('POST', '/orders')])
	})
})
