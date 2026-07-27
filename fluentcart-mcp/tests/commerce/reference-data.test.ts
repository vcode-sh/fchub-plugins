import { describe, expect, it, vi } from 'vitest'
import { FluentCartApiError } from '../../src/api/errors.js'
import {
	buildCacheScope,
	type CacheScope,
	PrincipalScopedCache,
	REFERENCE_DATA_TTL_MS,
	routeProfileDigestFromOperations,
} from '../../src/commerce/cache.js'
import { PaginationError } from '../../src/commerce/pagination.js'
import {
	extractRows,
	fetchReferenceData,
	invalidateForWrite,
	REFERENCE_DESCRIPTORS,
	REFERENCE_INVALIDATIONS,
	REFERENCE_KINDS,
	type ReferenceKind,
	referenceDescriptor,
	referenceOperation,
	UnknownReferenceKindError,
} from '../../src/commerce/reference-data.js'
import { ResponseTooLargeError } from '../../src/commerce/response-budget.js'
import { registerResources } from '../../src/resources.js'
import { referenceDataTools } from '../../src/tools/reference-data.js'

const ROUTE_PROFILE = routeProfileDigestFromOperations(['GET /labels'])

function scopeFor(username = 'alice'): CacheScope {
	return buildCacheScope({
		storeUrl: 'https://shop.example',
		username,
		routeProfile: ROUTE_PROFILE,
	})
}

/** A client that records the paths it was asked for and answers from a fixture. */
function fakeClient(body: unknown, options: { fail?: Error } = {}) {
	const paths: string[] = []
	const get = vi.fn(async (path: string) => {
		paths.push(path)
		if (options.fail) throw options.fail
		return { data: body }
	})
	return { paths, get, client: { get } as never }
}

function deps(client: ReturnType<typeof fakeClient>, cache = new PrincipalScopedCache()) {
	return { client: client.client, cache, scope: scopeFor() }
}

const LABELS = {
	labels: [
		{ id: 1, title: 'Priority', slug: 'priority', status: 'active' },
		{ id: 2, title: 'Fragile', slug: 'fragile', status: 'active' },
		{ id: 3, title: 'Gift', slug: 'gift', status: 'inactive' },
	],
}

describe('every kind names an exact route contract', () => {
	it('covers exactly the six documented kinds', () => {
		expect(REFERENCE_KINDS).toEqual([
			'payment_methods',
			'tax_classes',
			'shipping_zones',
			'countries',
			'labels',
			'product_categories',
		])
	})

	it.each(REFERENCE_KINDS)('%s names a GET route, permission, projection and page rule', (kind) => {
		const descriptor = REFERENCE_DESCRIPTORS[kind as ReferenceKind]

		expect(descriptor.method).toBe('GET')
		expect(descriptor.route.startsWith('/')).toBe(true)
		expect(descriptor.permission.length).toBeGreaterThan(10)
		expect(descriptor.collectionPaths.length).toBeGreaterThan(0)
		expect(descriptor.candidates.id.length).toBeGreaterThan(0)
		expect(descriptor.candidates.label.length).toBeGreaterThan(0)
		expect(descriptor.maxPerPage).toBeGreaterThan(0)
		expect(descriptor.defaultPerPage).toBeLessThanOrEqual(descriptor.maxPerPage)
		expect(descriptor.evidence).toContain('fluentcart-1.5.5')
	})

	it('binds every route to one the captured runtime actually serves', async () => {
		const fixture = await import('../fixtures/routes/fluentcart-1.5.5-core-pro-1.5.4.json', {
			with: { type: 'json' },
		})
		const served = new Set(
			(fixture.default.operations as { method: string; path: string }[]).map(
				(operation) => `${operation.method} ${operation.path}`,
			),
		)

		for (const kind of REFERENCE_KINDS) {
			const descriptor = REFERENCE_DESCRIPTORS[kind as ReferenceKind]
			expect(served.has(`GET ${descriptor.route}`), `${kind} -> ${descriptor.route}`).toBe(true)
		}
	})

	it('uses a distinct route for every kind', () => {
		const routes = REFERENCE_KINDS.map((kind) => REFERENCE_DESCRIPTORS[kind as ReferenceKind].route)
		expect(new Set(routes).size).toBe(routes.length)
	})
})

describe('unknown kinds fail locally', () => {
	it.each([['orders'], [''], [null], [undefined], [42], [{}]])(
		'rejects %p before any request',
		async (kind) => {
			const client = fakeClient(LABELS)
			await expect(fetchReferenceData(deps(client), { kind })).rejects.toBeInstanceOf(
				UnknownReferenceKindError,
			)
			expect(client.get).not.toHaveBeenCalled()
		},
	)

	it('names the supported kinds in the error', () => {
		expect(() => referenceDescriptor('orders')).toThrow(/payment_methods/)
		expect(() => referenceDescriptor('orders')).toThrow(/UNKNOWN|Unknown/)
	})

	it('resolves a known kind', () => {
		expect(referenceDescriptor('labels').route).toBe('/labels')
	})
})

describe('projection', () => {
	it('projects rows onto the reference contract', async () => {
		const client = fakeClient(LABELS)
		const envelope = await fetchReferenceData(deps(client), { kind: 'labels' })

		expect(client.paths).toEqual(['/labels'])
		expect(envelope.data[0]).toEqual({
			id: 1,
			label: 'Priority',
			code: 'priority',
			status: 'active',
		})
	})

	it('omits code and status when the store does not provide them', async () => {
		const client = fakeClient({ labels: [{ id: 7, title: 'Bare' }] })
		const envelope = await fetchReferenceData(deps(client), { kind: 'labels' })

		expect(envelope.data[0]).toEqual({ id: 7, label: 'Bare' })
		expect(Object.keys(envelope.data[0] ?? {})).not.toContain('code')
	})

	it('keeps an unlabelled row and warns rather than inventing a name', async () => {
		const client = fakeClient({ labels: [{ id: 9 }] })
		const envelope = await fetchReferenceData(deps(client), { kind: 'labels' })

		expect(envelope.data).toEqual([{ id: 9, label: '9' }])
		expect(envelope.warnings.join(' ')).toMatch(/no label field/)
	})

	it('skips a row with no identifier and says so', async () => {
		const client = fakeClient({ labels: [{ title: 'Nameless' }, { id: 2, title: 'Fine' }] })
		const envelope = await fetchReferenceData(deps(client), { kind: 'labels' })

		expect(envelope.data).toEqual([{ id: 2, label: 'Fine' }])
		expect(envelope.warnings.join(' ')).toMatch(/no identifier/)
	})

	it('warns instead of pretending an unrecognised body is empty', async () => {
		const client = fakeClient({ something_else: { nested: true } })
		const envelope = await fetchReferenceData(deps(client), { kind: 'labels' })

		expect(envelope.data).toEqual([])
		expect(envelope.warnings.join(' ')).toMatch(/No labels rows were found at \/labels/)
	})

	it('reads a keyed map as well as a list, since settings endpoints return both', () => {
		const rows = extractRows({ payment_methods: { stripe: { title: 'Stripe' } } }, [
			'payment_methods',
		])
		expect(rows).toEqual([{ key: 'stripe', title: 'Stripe' }])
	})

	it('reads rows at the document root', () => {
		expect(extractRows([{ id: 1 }], ['labels'])).toEqual([{ id: 1 }])
	})
})

describe('pagination', () => {
	it('reports an exact total, because the whole list is held', async () => {
		const client = fakeClient(LABELS)
		const envelope = await fetchReferenceData(deps(client), { kind: 'labels', per_page: 2 })

		expect(envelope.total).toBe(3)
		expect(envelope.data).toHaveLength(2)
		expect(envelope.hasMore).toBe(true)
		expect(envelope.nextPage).toBe(2)
	})

	it('returns the remainder on the last page', async () => {
		const client = fakeClient(LABELS)
		const envelope = await fetchReferenceData(deps(client), {
			kind: 'labels',
			per_page: 2,
			page: 2,
		})

		expect(envelope.data).toHaveLength(1)
		expect(envelope.hasMore).toBe(false)
		expect(envelope.nextPage).toBeNull()
	})

	it('rejects a page size above the verified maximum rather than clamping it', async () => {
		const client = fakeClient(LABELS)
		await expect(
			fetchReferenceData(deps(client), { kind: 'labels', per_page: 500 }),
		).rejects.toBeInstanceOf(PaginationError)
	})

	it.each([[0], [-1], [1.5], ['abc']])('rejects per_page %p', async (perPage) => {
		const client = fakeClient(LABELS)
		await expect(
			fetchReferenceData(deps(client), { kind: 'labels', per_page: perPage }),
		).rejects.toBeInstanceOf(PaginationError)
	})

	it('rejects a page below one', async () => {
		const client = fakeClient(LABELS)
		await expect(
			fetchReferenceData(deps(client), { kind: 'labels', page: 0 }),
		).rejects.toBeInstanceOf(PaginationError)
	})

	it('applies the kind default when per_page is omitted', async () => {
		const client = fakeClient(LABELS)
		const envelope = await fetchReferenceData(deps(client), { kind: 'labels' })
		expect(envelope.perPage).toBe(REFERENCE_DESCRIPTORS.labels.defaultPerPage)
	})

	it('refuses an oversized page instead of trimming it', async () => {
		const wide = 'x'.repeat(400)
		const client = fakeClient({
			labels: Array.from({ length: 100 }, (_, index) => ({ id: index, title: wide })),
		})

		await expect(
			fetchReferenceData(deps(client), { kind: 'labels', per_page: 100 }),
		).rejects.toBeInstanceOf(ResponseTooLargeError)
	})
})

describe('search', () => {
	it('filters across the whole list, so the total counts matches not rows on a page', async () => {
		const client = fakeClient(LABELS)
		const envelope = await fetchReferenceData(deps(client), { kind: 'labels', search: 'gift' })

		expect(envelope.data).toEqual([{ id: 3, label: 'Gift', code: 'gift', status: 'inactive' }])
		expect(envelope.total).toBe(1)
	})

	it('is case-insensitive and matches label, code or identifier', async () => {
		const client = fakeClient(LABELS)
		for (const term of ['PRIORITY', 'priority', '1']) {
			const envelope = await fetchReferenceData(deps(client), { kind: 'labels', search: term })
			expect(envelope.data.length).toBeGreaterThan(0)
		}
	})

	it('returns an empty page rather than an error when nothing matches', async () => {
		const client = fakeClient(LABELS)
		const envelope = await fetchReferenceData(deps(client), { kind: 'labels', search: 'nope' })

		expect(envelope.data).toEqual([])
		expect(envelope.total).toBe(0)
		expect(envelope.hasMore).toBe(false)
	})
})

describe('permission failures stay errors', () => {
	it.each([
		['AUTH_FAILED', 401],
		['FORBIDDEN', 403],
	])('propagates %s instead of returning an empty list', async (code, status) => {
		const client = fakeClient(LABELS, {
			fail: new FluentCartApiError(code as 'FORBIDDEN', 'Access denied', status),
		})

		await expect(fetchReferenceData(deps(client), { kind: 'labels' })).rejects.toBeInstanceOf(
			FluentCartApiError,
		)
	})

	it('does not cache the refusal', async () => {
		const cache = new PrincipalScopedCache()
		const client = fakeClient(LABELS, {
			fail: new FluentCartApiError('FORBIDDEN', 'Access denied', 403),
		})

		await expect(fetchReferenceData(deps(client, cache), { kind: 'labels' })).rejects.toThrow()
		expect(cache.size).toBe(0)
	})
})

describe('the cache is shared and scoped', () => {
	it('fetches once for repeated reads of the same list', async () => {
		const cache = new PrincipalScopedCache()
		const client = fakeClient(LABELS)

		await fetchReferenceData(deps(client, cache), { kind: 'labels' })
		await fetchReferenceData(deps(client, cache), { kind: 'labels', page: 2, per_page: 1 })

		expect(client.get).toHaveBeenCalledTimes(1)
	})

	it('never serves one principal from another principal entry', async () => {
		const cache = new PrincipalScopedCache()
		const alice = fakeClient({ labels: [{ id: 1, title: 'Alice only' }] })
		const bob = fakeClient({ labels: [{ id: 2, title: 'Bob only' }] })

		const first = await fetchReferenceData(
			{ client: alice.client, cache, scope: scopeFor('alice') },
			{ kind: 'labels' },
		)
		const second = await fetchReferenceData(
			{ client: bob.client, cache, scope: scopeFor('bob') },
			{ kind: 'labels' },
		)

		expect(first.data[0]?.label).toBe('Alice only')
		expect(second.data[0]?.label).toBe('Bob only')
		expect(bob.get).toHaveBeenCalledTimes(1)
	})

	it('coalesces concurrent identical reads into one request', async () => {
		const cache = new PrincipalScopedCache()
		const client = fakeClient(LABELS)

		await Promise.all([
			fetchReferenceData(deps(client, cache), { kind: 'labels' }),
			fetchReferenceData(deps(client, cache), { kind: 'labels' }),
			fetchReferenceData(deps(client, cache), { kind: 'labels' }),
		])

		expect(client.get).toHaveBeenCalledTimes(1)
	})

	it('keeps each kind in its own cache slot', async () => {
		const cache = new PrincipalScopedCache()
		const client = fakeClient(LABELS)

		await fetchReferenceData(deps(client, cache), { kind: 'labels' })
		await fetchReferenceData(deps(client, cache), { kind: 'tax_classes' })

		expect(client.paths).toEqual(['/labels', '/tax/classes'])
	})

	it('uses the reference time to live', async () => {
		const cache = new PrincipalScopedCache()
		const client = fakeClient(LABELS)
		await fetchReferenceData(deps(client, cache), { kind: 'labels' })

		expect(cache.peek(scopeFor(), referenceOperation('labels'), { route: '/labels' })).toEqual(
			LABELS,
		)
		expect(REFERENCE_DATA_TTL_MS).toBe(60_000)
	})
})

describe('a reviewed write invalidates only its own scope', () => {
	it('maps each reviewed write to the kinds it actually affects', () => {
		for (const [tool, kinds] of Object.entries(REFERENCE_INVALIDATIONS)) {
			expect(tool.startsWith('fluentcart_')).toBe(true)
			expect(kinds.length).toBeGreaterThan(0)
			for (const kind of kinds) expect(REFERENCE_KINDS).toContain(kind)
		}
	})

	it('clears the affected list and leaves the others alone', async () => {
		const cache = new PrincipalScopedCache()
		const client = fakeClient(LABELS)
		const scope = scopeFor()

		await fetchReferenceData({ client: client.client, cache, scope }, { kind: 'labels' })
		await fetchReferenceData({ client: client.client, cache, scope }, { kind: 'tax_classes' })

		const affected = invalidateForWrite(cache, scope, 'fluentcart_label_create')

		expect(affected).toEqual(['labels'])
		expect(cache.peek(scope, referenceOperation('labels'), { route: '/labels' })).toBeUndefined()
		expect(cache.peek(scope, referenceOperation('tax_classes'), { route: '/tax/classes' })).toEqual(
			LABELS,
		)
	})

	it('leaves another principal untouched', async () => {
		const cache = new PrincipalScopedCache()
		const client = fakeClient(LABELS)
		const alice = scopeFor('alice')
		const bob = scopeFor('bob')

		await fetchReferenceData({ client: client.client, cache, scope: alice }, { kind: 'labels' })
		await fetchReferenceData({ client: client.client, cache, scope: bob }, { kind: 'labels' })

		invalidateForWrite(cache, alice, 'fluentcart_label_create')

		expect(cache.peek(alice, referenceOperation('labels'), { route: '/labels' })).toBeUndefined()
		expect(cache.peek(bob, referenceOperation('labels'), { route: '/labels' })).toEqual(LABELS)
	})

	it('does nothing for a write that touches no reference list', async () => {
		const cache = new PrincipalScopedCache()
		const client = fakeClient(LABELS)
		const scope = scopeFor()
		await fetchReferenceData({ client: client.client, cache, scope }, { kind: 'labels' })

		expect(invalidateForWrite(cache, scope, 'fluentcart_order_refund')).toEqual([])
		expect(cache.peek(scope, referenceOperation('labels'), { route: '/labels' })).toEqual(LABELS)
	})
})

describe('the public tool', () => {
	function tool() {
		const cache = new PrincipalScopedCache()
		const client = fakeClient(LABELS)
		const [definition] = referenceDataTools(client.client, { cache, scope: scopeFor() })
		if (!definition) throw new Error('referenceDataTools returned nothing')
		return { definition, client, cache }
	}

	it('registers exactly one read-only tool', () => {
		const { definition } = tool()
		expect(definition.name).toBe('fluentcart_list_reference_data')
		expect(definition.safety.risk).toBe('read')
		expect(definition.annotations.readOnlyHint).toBe(true)
	})

	it('declares every route it may reach', () => {
		const { definition } = tool()
		const paths = (definition.routes?.variants ?? []).map((variant) => variant.path).sort()

		expect(definition.routes?.kind).toBe('composite')
		expect(paths).toEqual(
			REFERENCE_KINDS.map((kind) => REFERENCE_DESCRIPTORS[kind as ReferenceKind].route).sort(),
		)
	})

	it('rejects an unknown kind at the schema, before the handler runs', () => {
		const { definition } = tool()
		expect(definition.schema.safeParse({ kind: 'orders' }).success).toBe(false)
		expect(definition.schema.safeParse({ kind: 'labels' }).success).toBe(true)
	})

	it('returns the envelope the shared fetcher produced', async () => {
		const { definition, client } = tool()
		const result = await definition.handler({ kind: 'labels' })

		expect(result.isError).toBeUndefined()
		expect(JSON.parse(result.content[0]?.text ?? '{}').total).toBe(3)
		expect(client.paths).toEqual(['/labels'])
	})

	it('reports a permission failure as an MCP error, not an empty list', async () => {
		const cache = new PrincipalScopedCache()
		const client = fakeClient(LABELS, {
			fail: new FluentCartApiError('FORBIDDEN', 'Access denied', 403),
		})
		const [definition] = referenceDataTools(client.client, { cache, scope: scopeFor() })
		const result = await definition?.handler({ kind: 'labels' })

		expect(result?.isError).toBe(true)
		expect(result?.content[0]?.text).toMatch(/FORBIDDEN|Access denied/)
	})
})

/** Captures what registerResources registered, so a resource can be invoked without a server. */
function fakeServer() {
	const handlers = new Map<string, (uri: URL) => Promise<{ contents: { text: string }[] }>>()
	return {
		handlers,
		server: {
			registerResource: (
				name: string,
				_uri: string,
				_meta: unknown,
				handler: (uri: URL) => Promise<{ contents: { text: string }[] }>,
			) => {
				handlers.set(name, handler)
			},
		} as never,
	}
}

describe('registered resources use the shared cache', () => {
	it('serves a resource and the tool from one upstream read', async () => {
		const cache = new PrincipalScopedCache()
		const client = fakeClient(LABELS)
		const scope = scopeFor()
		const { server, handlers } = fakeServer()

		registerResources(server, client.client, { cache, scope })
		const resource = handlers.get('store-countries')
		if (!resource) throw new Error('store-countries was not registered')

		// A resource read populates the shared entry...
		await resource(new URL('fluentcart://store/countries'))
		// ...and the tool reading the same list does not reach upstream again.
		const [definition] = referenceDataTools(client.client, { cache, scope })
		await definition?.handler({ kind: 'countries' })

		expect(client.get).toHaveBeenCalledTimes(1)
		expect(client.paths).toEqual(['/address-info/countries'])
	})

	it('keeps two principals apart across the resource surface', async () => {
		const cache = new PrincipalScopedCache()
		const alice = fakeClient({ countries: [{ code: 'PL', name: 'Poland' }] })
		const bob = fakeClient({ countries: [{ code: 'DE', name: 'Germany' }] })

		const first = fakeServer()
		registerResources(first.server, alice.client, { cache, scope: scopeFor('alice') })
		const second = fakeServer()
		registerResources(second.server, bob.client, { cache, scope: scopeFor('bob') })

		const aliceBody = await first.handlers.get('store-countries')?.(
			new URL('fluentcart://store/countries'),
		)
		const bobBody = await second.handlers.get('store-countries')?.(
			new URL('fluentcart://store/countries'),
		)

		expect(aliceBody?.contents[0]?.text).toContain('Poland')
		expect(bobBody?.contents[0]?.text).toContain('Germany')
		expect(bob.get).toHaveBeenCalledTimes(1)
	})

	it('propagates a permission failure instead of returning an empty resource', async () => {
		const cache = new PrincipalScopedCache()
		const client = fakeClient(LABELS, {
			fail: new FluentCartApiError('FORBIDDEN', 'Access denied', 403),
		})
		const { server, handlers } = fakeServer()
		registerResources(server, client.client, { cache, scope: scopeFor() })

		await expect(
			handlers.get('store-payment-methods')?.(new URL('fluentcart://store/payment-methods')),
		).rejects.toBeInstanceOf(FluentCartApiError)
	})

	it('does not share a cache between registrations when no deps are supplied', async () => {
		const client = fakeClient(LABELS)
		const { server, handlers } = fakeServer()
		registerResources(server, client.client)

		const resource = handlers.get('store-countries')
		await resource?.(new URL('fluentcart://store/countries'))
		await resource?.(new URL('fluentcart://store/countries'))

		// Unwired, every call is independent: correct answers, no cross-principal risk, no cache.
		expect(client.get).toHaveBeenCalledTimes(2)
	})
})

describe('resources and the tool share one fetcher', () => {
	it('serves a resource read from the same cache entry the tool populated', async () => {
		const cache = new PrincipalScopedCache()
		const client = fakeClient(LABELS)
		const scope = scopeFor()

		// The tool populates the entry.
		const [definition] = referenceDataTools(client.client, { cache, scope })
		await definition?.handler({ kind: 'labels' })

		// A resource read of the same list is served without a second request.
		const envelope = await fetchReferenceData(
			{ client: client.client, cache, scope },
			{ kind: 'labels', per_page: 100 },
		)

		expect(client.get).toHaveBeenCalledTimes(1)
		expect(envelope.total).toBe(3)
	})
})
