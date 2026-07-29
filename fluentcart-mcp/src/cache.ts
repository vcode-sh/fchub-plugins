/**
 * In-memory TTL cache for static/near-static API responses.
 *
 * Module-scoped singleton — no class instantiation needed.
 * Concurrent requests for the same key use "last write wins" semantics.
 */
import { AUTHORIZATION_CACHE_MAX_TTL_MS } from './commerce/cache.js'

interface CacheEntry {
	data: unknown
	expires: number
}

const cache = new Map<string, CacheEntry>()

/**
 * Return cached data if still valid, otherwise call `fetcher` and cache the result.
 */
export async function cached<T>(key: string, ttlMs: number, fetcher: () => Promise<T>): Promise<T> {
	const entry = cache.get(key)
	if (entry && entry.expires > Date.now()) {
		return entry.data as T
	}
	const data = await fetcher()
	cache.set(key, {
		data,
		expires: Date.now() + Math.min(ttlMs, AUTHORIZATION_CACHE_MAX_TTL_MS),
	})
	return data
}

/**
 * Remove a single key from the cache. Returns true if the key existed.
 */
export function invalidate(key: string): boolean {
	return cache.delete(key)
}

/**
 * Drop every entry in the cache.
 */
export function clearCache(): void {
	cache.clear()
}

/**
 * Number of entries currently held (including expired ones not yet evicted).
 */
export function cacheSize(): number {
	return cache.size
}

/** Pre-defined TTL constants (milliseconds). */
export const TTL = {
	/** Maximum authorised-response window, for rarely-changing reference data. */
	LONG: AUTHORIZATION_CACHE_MAX_TTL_MS,
	/** Configuration is refreshed sooner than reference catalogues. */
	MEDIUM: 30_000,
	/** Frequently-read state receives only brief request deduplication. */
	SHORT: 15_000,
} as const
