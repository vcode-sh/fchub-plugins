import type { FluentCartClient } from '../api/client.js'
import type { CacheScope } from '../commerce/cache.js'
import { PrincipalScopedCache } from '../commerce/cache.js'

export interface ToolCacheDeps {
	cache: PrincipalScopedCache
	scope: CacheScope
}

/**
 * Bind endpoint caches to the client context that owns their authorisation.
 *
 * Production supplies the store/principal/route scope. Direct factory callers get an isolated
 * cache private to their client object, so responses cannot cross stores or principals.
 */
const CACHE_BY_CLIENT = new WeakMap<FluentCartClient, ToolCacheDeps>()

export function toolCacheDeps(client: FluentCartClient): ToolCacheDeps {
	const existing = CACHE_BY_CLIENT.get(client)
	if (existing) return existing

	const isolated = {
		cache: new PrincipalScopedCache(),
		scope: {
			origin: 'client-local',
			principal: 'client-local',
			routeProfile: 'undiscovered',
		},
	}
	CACHE_BY_CLIENT.set(client, isolated)
	return isolated
}

export function configureToolCache(client: FluentCartClient, deps?: ToolCacheDeps): void {
	if (deps) CACHE_BY_CLIENT.set(client, deps)
	else toolCacheDeps(client)
}

export function invalidateToolCache(client: FluentCartClient, operation?: string): number {
	const deps = toolCacheDeps(client)
	return deps.cache.invalidate(deps.scope, operation)
}
