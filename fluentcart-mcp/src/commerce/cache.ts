import { createHash } from 'node:crypto'
import { storeOrigin } from './context.js'

/**
 * A cache of authorised reads, keyed by who was authorised to read them.
 *
 * This is an authorisation-context store rather than a performance trick, and the difference
 * decides the design. A cache keyed only by URL will happily hand one principal's authorised
 * data to another the moment two credentials point at the same store, and nothing downstream
 * can detect it — the second caller simply receives rows they were never entitled to see. So
 * the principal is part of the key, not metadata beside it.
 */

/** Reference lists change rarely; a minute of staleness is cheaper than a request per call. */
export const REFERENCE_DATA_TTL_MS = 60_000

/** Store context reflects live configuration, so it is held only long enough to deduplicate. */
export const STORE_CONTEXT_TTL_MS = 15_000

const KEY_VERSION = 'v1'
const FIELD_SEPARATOR = '|'

export interface PrincipalIdentity {
	/** Configured store URL. Only its origin becomes part of the key. */
	storeUrl: string
	/** WordPress user the credential belongs to. Hashed, never stored raw. */
	username: string
}

/**
 * Everything that must match before two reads may share a cached answer.
 *
 * `routeProfile` is included because the same principal against the same origin can still be
 * talking to a differently-shaped API after a plugin update, and a row projected from the old
 * shape is not a valid answer for the new one.
 */
export interface CacheScope {
	origin: string
	principal: string
	routeProfile: string
}

function sha256(value: string): string {
	return `sha256:${createHash('sha256').update(value).digest('hex')}`
}

/**
 * Identify the principal without retaining anything that could impersonate them.
 *
 * The application password is deliberately excluded. It is not an authorisation boundary — a
 * rotated password grants the same WordPress user exactly the same capabilities — so including
 * it would add no separation while putting a secret-derived value into a key that gets logged,
 * inspected and compared. The user and the origin are what actually decide what may be read.
 *
 * The consequence is stated rather than hidden: a revoked credential leaves its principal's
 * entries readable until they expire, which is why the TTLs here are seconds and not hours.
 */
export function principalDigest(identity: PrincipalIdentity): string {
	const origin = storeOrigin(identity.storeUrl)
	const username = identity.username.trim().toLowerCase()
	// Domain-separated and length-prefixed so no two different pairs can produce one string.
	return sha256(`fluentcart-principal:${origin.length}:${origin}:${username.length}:${username}`)
}

/** Digest a capability set when the caller has operations but no runtime profile. */
export function routeProfileDigestFromOperations(operations: Iterable<string>): string {
	const sorted = [...operations].sort()
	return sha256(`fluentcart-routes:${JSON.stringify(sorted)}`)
}

export function buildCacheScope(input: {
	storeUrl: string
	username: string
	routeProfile: string
}): CacheScope {
	return {
		origin: storeOrigin(input.storeUrl),
		principal: principalDigest({ storeUrl: input.storeUrl, username: input.username }),
		routeProfile: input.routeProfile,
	}
}

/** Recursively key-sorted JSON, so argument order cannot produce two keys for one request. */
function stableStringify(value: unknown): string {
	if (value === null || typeof value !== 'object') return JSON.stringify(value) ?? 'null'
	if (Array.isArray(value)) return `[${value.map(stableStringify).join(',')}]`

	const entries = Object.entries(value as Record<string, unknown>)
		.filter(([, entry]) => entry !== undefined)
		.sort(([left], [right]) => (left < right ? -1 : 1))
		.map(([key, entry]) => `${JSON.stringify(key)}:${stableStringify(entry)}`)
	return `{${entries.join(',')}}`
}

/** The prefix every key for one scope shares, used for scoped invalidation. */
export function scopePrefix(scope: CacheScope, operation?: string): string {
	const base = [KEY_VERSION, scope.origin, scope.principal, scope.routeProfile].join(
		FIELD_SEPARATOR,
	)
	return operation === undefined
		? `${base}${FIELD_SEPARATOR}`
		: `${base}${FIELD_SEPARATOR}${operation}${FIELD_SEPARATOR}`
}

export function cacheKey(
	scope: CacheScope,
	operation: string,
	args: Record<string, unknown> = {},
): string {
	return `${scopePrefix(scope, operation)}${sha256(stableStringify(args))}`
}

interface CacheEntry {
	value: unknown
	expiresAt: number
}

export interface CacheStats {
	hits: number
	misses: number
	coalesced: number
	evictions: number
}

export interface CacheOptions {
	/** Injectable clock so TTL behaviour is testable without waiting. */
	now?: () => number
	/** Bound on retained entries. Oldest-expiring entries are dropped first. */
	maxEntries?: number
}

/**
 * An in-memory cache scoped to a principal, with request coalescing.
 *
 * Two properties matter more than hit rate. Failures are never stored, so a 401 or a 403 cannot
 * become sticky and keep answering for a minute after the credential was fixed. And concurrent
 * identical reads share one in-flight request, so a burst of parallel tool calls produces one
 * upstream call rather than a stampede against the merchant's store.
 */
export class PrincipalScopedCache {
	readonly #entries = new Map<string, CacheEntry>()
	readonly #inFlight = new Map<string, Promise<unknown>>()
	readonly #now: () => number
	readonly #maxEntries: number
	#stats: CacheStats = { hits: 0, misses: 0, coalesced: 0, evictions: 0 }

	constructor(options: CacheOptions = {}) {
		this.#now = options.now ?? Date.now
		this.#maxEntries = options.maxEntries ?? 500
	}

	get size(): number {
		return this.#entries.size
	}

	get inFlightCount(): number {
		return this.#inFlight.size
	}

	get stats(): CacheStats {
		return { ...this.#stats }
	}

	/**
	 * Return a cached value, join an identical in-flight read, or load one.
	 *
	 * A rejected loader is propagated to every joined caller and stored nowhere, so the next
	 * attempt reaches upstream again. That is the behaviour that keeps an auth failure from
	 * outliving the credential that caused it.
	 */
	async getOrLoad<T>(
		scope: CacheScope,
		operation: string,
		args: Record<string, unknown>,
		ttlMs: number,
		loader: () => Promise<T>,
	): Promise<T> {
		const key = cacheKey(scope, operation, args)

		const entry = this.#entries.get(key)
		if (entry) {
			if (entry.expiresAt > this.#now()) {
				this.#stats.hits += 1
				return entry.value as T
			}
			this.#entries.delete(key)
		}

		const pending = this.#inFlight.get(key)
		if (pending) {
			this.#stats.coalesced += 1
			return pending as Promise<T>
		}

		this.#stats.misses += 1
		const request = loader()
			.then((value) => {
				// Only a resolved read is worth remembering; anything else is a transient fact.
				this.#store(key, value, ttlMs)
				return value
			})
			.finally(() => {
				this.#inFlight.delete(key)
			})

		this.#inFlight.set(key, request)
		return request
	}

	/** Read without loading. Present mainly so tests can assert what was stored. */
	peek<T>(scope: CacheScope, operation: string, args: Record<string, unknown> = {}): T | undefined {
		const key = cacheKey(scope, operation, args)
		const entry = this.#entries.get(key)
		if (!entry) return undefined
		if (entry.expiresAt <= this.#now()) {
			this.#entries.delete(key)
			return undefined
		}
		return entry.value as T
	}

	/**
	 * Drop one scope, or one operation within it, and nothing else.
	 *
	 * A write that changed this store's labels says nothing about another principal's payment
	 * methods, so clearing everything would be both wasteful and misleading about what the
	 * write actually touched.
	 */
	invalidate(scope: CacheScope, operation?: string): number {
		const prefix = scopePrefix(scope, operation)
		let removed = 0
		for (const key of [...this.#entries.keys()]) {
			if (key.startsWith(prefix)) {
				this.#entries.delete(key)
				removed += 1
			}
		}
		return removed
	}

	clear(): void {
		this.#entries.clear()
		this.#inFlight.clear()
		this.#stats = { hits: 0, misses: 0, coalesced: 0, evictions: 0 }
	}

	#store(key: string, value: unknown, ttlMs: number): void {
		if (ttlMs <= 0) return
		this.#entries.set(key, { value, expiresAt: this.#now() + ttlMs })
		this.#evictIfNeeded()
	}

	#evictIfNeeded(): void {
		if (this.#entries.size <= this.#maxEntries) return

		const byExpiry = [...this.#entries.entries()].sort(
			([, left], [, right]) => left.expiresAt - right.expiresAt,
		)
		while (this.#entries.size > this.#maxEntries) {
			const oldest = byExpiry.shift()
			if (!oldest) break
			this.#entries.delete(oldest[0])
			this.#stats.evictions += 1
		}
	}
}
