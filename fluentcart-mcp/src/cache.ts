/**
 * In-memory TTL cache for static/near-static API responses.
 *
 * Module-scoped singleton — no class instantiation needed.
 * Concurrent requests for the same key use "last write wins" semantics.
 */

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
	cache.set(key, { data, expires: Date.now() + ttlMs })
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
	/** 1 hour — for rarely-changing reference data */
	LONG: 3_600_000,
	/** 10 minutes — for semi-static configuration */
	MEDIUM: 600_000,
	/** 2 minutes — for frequently-read data */
	SHORT: 120_000,
} as const
