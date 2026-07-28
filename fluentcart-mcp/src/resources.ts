import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js'
import type { FluentCartClient } from './api/client.js'
import type { CacheScope } from './commerce/cache.js'
import { PrincipalScopedCache, STORE_CONTEXT_TTL_MS } from './commerce/cache.js'
import { fetchReferenceData, type ReferenceKind } from './commerce/reference-data.js'
import { redactSensitive } from './security/redaction.js'

/** A resource backed by a raw endpoint, cached under the store-context TTL. */
interface EndpointResource {
	name: string
	uri: string
	endpoint: string
	description: string
	isPublic?: boolean
}

/** A resource backed by the shared reference-data fetcher. */
interface ReferenceResource {
	name: string
	uri: string
	kind: ReferenceKind
	description: string
}

const ENDPOINT_RESOURCES: EndpointResource[] = [
	{
		name: 'store-config',
		uri: 'fluentcart://store/config',
		endpoint: '/app/init',
		description: 'Store configuration and settings',
	},
	{
		name: 'store-filter-options',
		uri: 'fluentcart://store/filter-options',
		endpoint: '/advance_filter/get-filter-options',
		description: 'Available filter options for orders, products, and customers',
	},
]

/**
 * Resources that serve the same list the reference tool serves.
 *
 * They must not be a second implementation. A resource that projected countries its own way
 * would drift from the tool the moment either changed, and an agent reading both would have no
 * way to tell which one was right.
 */
const REFERENCE_RESOURCES: ReferenceResource[] = [
	{
		name: 'store-countries',
		uri: 'fluentcart://store/countries',
		kind: 'countries',
		description: 'Supported countries and their codes',
	},
	{
		name: 'store-payment-methods',
		uri: 'fluentcart://store/payment-methods',
		kind: 'payment_methods',
		description: 'Configured payment methods',
	},
]

export interface ResourceDeps {
	cache: PrincipalScopedCache
	scope: CacheScope
}

/**
 * What resources do until `server.ts` passes a principal-scoped cache.
 *
 * A throwaway cache per call, never shared. It is deliberately not a module-level default: a
 * process-wide cache with a placeholder scope would be indistinguishable from a working one
 * right up to the moment two credentials pointed at the same store, and then it would serve one
 * principal's data to the other. Correct and slow beats fast and occasionally wrong, and the
 * shape stays identical so wiring the real cache changes one call site and nothing else.
 */
function perCallDeps(): ResourceDeps {
	return {
		cache: new PrincipalScopedCache(),
		scope: { origin: 'unscoped', principal: 'unscoped', routeProfile: 'unscoped' },
	}
}

function jsonContents(uri: URL, value: unknown) {
	return {
		contents: [
			{
				uri: uri.href,
				mimeType: 'application/json',
				text: JSON.stringify(redactSensitive(value)),
			},
		],
	}
}

/**
 * Register the read-only resource surface.
 *
 * Every resource goes through the shared principal-scoped cache, so a resource read and a tool
 * read of the same list share one upstream request and one answer. Failures propagate rather
 * than being swallowed: a resource that returned `{}` on a 403 would tell an agent the store has
 * no payment methods, which is a different and much worse statement than "you may not look".
 */
export function registerResources(
	server: McpServer,
	client: FluentCartClient,
	deps?: ResourceDeps,
): void {
	for (const resource of ENDPOINT_RESOURCES) {
		server.registerResource(
			resource.name,
			resource.uri,
			{ description: resource.description, mimeType: 'application/json' },
			async (uri) => {
				const active = deps ?? perCallDeps()
				const data = await active.cache.getOrLoad(
					active.scope,
					`resource:${resource.name}`,
					{ endpoint: resource.endpoint },
					STORE_CONTEXT_TTL_MS,
					async () => (await client.get(resource.endpoint, undefined, resource.isPublic)).data,
				)
				return jsonContents(uri, data)
			},
		)
	}

	for (const resource of REFERENCE_RESOURCES) {
		server.registerResource(
			resource.name,
			resource.uri,
			{ description: resource.description, mimeType: 'application/json' },
			async (uri) => {
				const active = deps ?? perCallDeps()
				const envelope = await fetchReferenceData(
					{ client, cache: active.cache, scope: active.scope },
					// A resource has no page parameter to pass, so it asks for the largest page the
					// kind allows and reports the exact total alongside it.
					{ kind: resource.kind, per_page: 100 },
				)
				return jsonContents(uri, envelope)
			},
		)
	}
}
