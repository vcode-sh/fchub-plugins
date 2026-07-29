import { describe, expect, it, vi } from 'vitest'
import { FluentCartApiError } from '../../src/api/errors.js'
import {
	AUTHORIZATION_CACHE_MAX_TTL_MS,
	buildCacheScope,
	type CacheScope,
	cacheKey,
	PrincipalScopedCache,
	principalDigest,
	REFERENCE_DATA_TTL_MS,
	routeProfileDigestFromOperations,
	STORE_CONTEXT_TTL_MS,
	scopePrefix,
} from '../../src/commerce/cache.js'

const ROUTE_PROFILE = routeProfileDigestFromOperations(['GET /orders', 'GET /labels'])

function scopeFor(username: string, storeUrl = 'https://shop.example'): CacheScope {
	return buildCacheScope({ storeUrl, username, routeProfile: ROUTE_PROFILE })
}

/** A controllable clock, so TTL behaviour is asserted rather than waited for. */
function clock(start = 1_000) {
	let value = start
	return { now: () => value, advance: (ms: number) => (value += ms) }
}

describe('principal digests', () => {
	it('never contains the raw username or the password', () => {
		const digest = principalDigest({
			storeUrl: 'https://shop.example',
			username: 'merchant-admin',
		})

		expect(digest.startsWith('sha256:')).toBe(true)
		expect(digest).not.toContain('merchant-admin')
	})

	it('separates two users on the same store', () => {
		const first = principalDigest({ storeUrl: 'https://shop.example', username: 'alice' })
		const second = principalDigest({ storeUrl: 'https://shop.example', username: 'bob' })
		expect(first).not.toBe(second)
	})

	it('separates the same user on two stores', () => {
		const first = principalDigest({ storeUrl: 'https://one.example', username: 'alice' })
		const second = principalDigest({ storeUrl: 'https://two.example', username: 'alice' })
		expect(first).not.toBe(second)
	})

	it('canonicalises the origin, so a path or trailing slash is not a new principal', () => {
		const plain = principalDigest({ storeUrl: 'https://shop.example', username: 'alice' })
		for (const url of [
			'https://shop.example/',
			'https://shop.example/wp-json/',
			'https://shop.example?utm=1',
		]) {
			expect(principalDigest({ storeUrl: url, username: 'alice' })).toBe(plain)
		}
	})

	it('treats usernames case-insensitively, as WordPress does', () => {
		const lower = principalDigest({ storeUrl: 'https://shop.example', username: 'alice' })
		const upper = principalDigest({ storeUrl: 'https://shop.example', username: 'ALICE' })
		expect(upper).toBe(lower)
	})

	it('cannot be collided by moving characters between the origin and the username', () => {
		const first = principalDigest({ storeUrl: 'https://ab.example', username: 'cd' })
		const second = principalDigest({ storeUrl: 'https://ab.example', username: 'c:d' })
		expect(first).not.toBe(second)
	})
})

describe('cache keys', () => {
	it('changes with the principal, the origin and the route profile', () => {
		const base = cacheKey(scopeFor('alice'), 'reference:labels', {})
		expect(cacheKey(scopeFor('bob'), 'reference:labels', {})).not.toBe(base)
		expect(cacheKey(scopeFor('alice', 'https://other.example'), 'reference:labels', {})).not.toBe(
			base,
		)

		const otherProfile = buildCacheScope({
			storeUrl: 'https://shop.example',
			username: 'alice',
			routeProfile: routeProfileDigestFromOperations(['GET /orders']),
		})
		expect(cacheKey(otherProfile, 'reference:labels', {})).not.toBe(base)
	})

	it('is stable regardless of argument order', () => {
		const scope = scopeFor('alice')
		expect(cacheKey(scope, 'op', { a: 1, b: 2 })).toBe(cacheKey(scope, 'op', { b: 2, a: 1 }))
	})

	it('distinguishes different arguments', () => {
		const scope = scopeFor('alice')
		expect(cacheKey(scope, 'op', { page: 1 })).not.toBe(cacheKey(scope, 'op', { page: 2 }))
	})

	it('carries no raw identity', () => {
		const key = cacheKey(scopeFor('merchant-admin'), 'reference:labels', { secret: 'hunter2' })
		expect(key).not.toContain('merchant-admin')
		expect(key).not.toContain('hunter2')
	})

	it('nests under its scope prefix so invalidation can target it', () => {
		const scope = scopeFor('alice')
		expect(cacheKey(scope, 'reference:labels', {}).startsWith(scopePrefix(scope))).toBe(true)
		expect(
			cacheKey(scope, 'reference:labels', {}).startsWith(scopePrefix(scope, 'reference:labels')),
		).toBe(true)
	})
})

describe('two principals never share an entry', () => {
	it('serves each principal only what was loaded for them', async () => {
		const cache = new PrincipalScopedCache()
		const alice = scopeFor('alice')
		const bob = scopeFor('bob')

		const first = await cache.getOrLoad(alice, 'reference:labels', {}, 60_000, async () => [
			'alice-only',
		])
		const second = await cache.getOrLoad(bob, 'reference:labels', {}, 60_000, async () => [
			'bob-only',
		])

		expect(first).toEqual(['alice-only'])
		expect(second).toEqual(['bob-only'])
		expect(cache.size).toBe(2)
	})

	it('does not let one principal populate another principal cache', async () => {
		const cache = new PrincipalScopedCache()
		const alice = scopeFor('alice')
		const bob = scopeFor('bob')

		await cache.getOrLoad(alice, 'reference:labels', {}, 60_000, async () => ['alice-only'])

		const bobLoader = vi.fn(async () => ['bob-only'])
		await cache.getOrLoad(bob, 'reference:labels', {}, 60_000, bobLoader)

		// Bob's read must reach upstream: a hit here would be one user reading another's rows.
		expect(bobLoader).toHaveBeenCalledTimes(1)
		expect(cache.peek(bob, 'reference:labels')).toEqual(['bob-only'])
		expect(cache.peek(alice, 'reference:labels')).toEqual(['alice-only'])
	})

	it('keeps principals apart even under concurrent identical reads', async () => {
		const cache = new PrincipalScopedCache()
		const [alice, bob] = [scopeFor('alice'), scopeFor('bob')]

		const [a, b] = await Promise.all([
			cache.getOrLoad(alice, 'reference:labels', {}, 60_000, async () => 'alice'),
			cache.getOrLoad(bob, 'reference:labels', {}, 60_000, async () => 'bob'),
		])

		expect(a).toBe('alice')
		expect(b).toBe('bob')
	})
})

describe('failures are never cached', () => {
	it.each([
		['AUTH_FAILED', 401],
		['FORBIDDEN', 403],
	])('does not store a %s response', async (code, status) => {
		const cache = new PrincipalScopedCache()
		const scope = scopeFor('alice')
		const loader = vi.fn(async () => {
			throw new FluentCartApiError(code as 'AUTH_FAILED', 'denied', status)
		})

		await expect(cache.getOrLoad(scope, 'reference:labels', {}, 60_000, loader)).rejects.toThrow(
			'denied',
		)

		expect(cache.size).toBe(0)
		expect(cache.peek(scope, 'reference:labels')).toBeUndefined()
	})

	it('lets the next attempt reach upstream, so a 401 cannot become sticky', async () => {
		const cache = new PrincipalScopedCache()
		const scope = scopeFor('alice')
		let attempt = 0
		const loader = vi.fn(async () => {
			attempt += 1
			if (attempt === 1) throw new FluentCartApiError('AUTH_FAILED', 'denied', 401)
			return ['recovered']
		})

		await expect(cache.getOrLoad(scope, 'op', {}, 60_000, loader)).rejects.toThrow()
		const second = await cache.getOrLoad(scope, 'op', {}, 60_000, loader)

		expect(second).toEqual(['recovered'])
		expect(loader).toHaveBeenCalledTimes(2)
	})

	it('rejects every joined caller and caches nothing', async () => {
		const cache = new PrincipalScopedCache()
		const scope = scopeFor('alice')
		const loader = vi.fn(async () => {
			await new Promise((resolve) => setTimeout(resolve, 5))
			throw new FluentCartApiError('FORBIDDEN', 'denied', 403)
		})

		const results = await Promise.allSettled([
			cache.getOrLoad(scope, 'op', {}, 60_000, loader),
			cache.getOrLoad(scope, 'op', {}, 60_000, loader),
			cache.getOrLoad(scope, 'op', {}, 60_000, loader),
		])

		expect(results.every((result) => result.status === 'rejected')).toBe(true)
		expect(loader).toHaveBeenCalledTimes(1)
		expect(cache.size).toBe(0)
		expect(cache.inFlightCount).toBe(0)
	})
})

describe('identical in-flight reads coalesce', () => {
	it('issues one upstream request for a burst of identical reads', async () => {
		const cache = new PrincipalScopedCache()
		const scope = scopeFor('alice')
		const loader = vi.fn(async () => {
			await new Promise((resolve) => setTimeout(resolve, 5))
			return ['one-request']
		})

		const results = await Promise.all(
			Array.from({ length: 8 }, () => cache.getOrLoad(scope, 'op', {}, 60_000, loader)),
		)

		expect(loader).toHaveBeenCalledTimes(1)
		expect(results.every((value) => value === results[0])).toBe(true)
		expect(cache.stats.coalesced).toBe(7)
	})

	it('does not coalesce different arguments', async () => {
		const cache = new PrincipalScopedCache()
		const scope = scopeFor('alice')
		const loader = vi.fn(async (page: number) => [page])

		await Promise.all([
			cache.getOrLoad(scope, 'op', { page: 1 }, 60_000, () => loader(1)),
			cache.getOrLoad(scope, 'op', { page: 2 }, 60_000, () => loader(2)),
		])

		expect(loader).toHaveBeenCalledTimes(2)
	})

	it('clears the in-flight slot once settled', async () => {
		const cache = new PrincipalScopedCache()
		const scope = scopeFor('alice')
		await cache.getOrLoad(scope, 'op', {}, 60_000, async () => 'value')
		expect(cache.inFlightCount).toBe(0)
	})
})

describe('time to live', () => {
	it('pins the documented durations', () => {
		expect(AUTHORIZATION_CACHE_MAX_TTL_MS).toBe(60_000)
		expect(REFERENCE_DATA_TTL_MS).toBe(60_000)
		expect(STORE_CONTEXT_TTL_MS).toBe(15_000)
	})

	it('clamps every authenticated entry to the revocation window', async () => {
		const time = clock()
		const cache = new PrincipalScopedCache({ now: time.now })
		const scope = scopeFor('alice')
		const loader = vi.fn(async () => ['fresh'])

		await cache.getOrLoad(scope, 'op', {}, 3_600_000, loader)
		time.advance(AUTHORIZATION_CACHE_MAX_TTL_MS + 1)
		await cache.getOrLoad(scope, 'op', {}, 3_600_000, loader)

		expect(loader).toHaveBeenCalledTimes(2)
	})

	it('serves within the window and reloads after it', async () => {
		const time = clock()
		const cache = new PrincipalScopedCache({ now: time.now })
		const scope = scopeFor('alice')
		const loader = vi.fn(async () => ['value'])

		await cache.getOrLoad(scope, 'op', {}, REFERENCE_DATA_TTL_MS, loader)
		time.advance(REFERENCE_DATA_TTL_MS - 1)
		await cache.getOrLoad(scope, 'op', {}, REFERENCE_DATA_TTL_MS, loader)
		expect(loader).toHaveBeenCalledTimes(1)

		time.advance(2)
		await cache.getOrLoad(scope, 'op', {}, REFERENCE_DATA_TTL_MS, loader)
		expect(loader).toHaveBeenCalledTimes(2)
	})

	it('treats a zero TTL as do-not-store', async () => {
		const cache = new PrincipalScopedCache()
		const scope = scopeFor('alice')
		await cache.getOrLoad(scope, 'op', {}, 0, async () => 'value')
		expect(cache.size).toBe(0)
	})
})

describe('invalidation is scoped', () => {
	async function populate(cache: PrincipalScopedCache) {
		const alice = scopeFor('alice')
		const bob = scopeFor('bob')
		await cache.getOrLoad(alice, 'reference:labels', {}, 60_000, async () => 'alice-labels')
		await cache.getOrLoad(alice, 'reference:tax_classes', {}, 60_000, async () => 'alice-tax')
		await cache.getOrLoad(bob, 'reference:labels', {}, 60_000, async () => 'bob-labels')
		return { alice, bob }
	}

	it('clears one operation and leaves the rest of the principal intact', async () => {
		const cache = new PrincipalScopedCache()
		const { alice, bob } = await populate(cache)

		expect(cache.invalidate(alice, 'reference:labels')).toBe(1)

		expect(cache.peek(alice, 'reference:labels')).toBeUndefined()
		expect(cache.peek(alice, 'reference:tax_classes')).toBe('alice-tax')
		expect(cache.peek(bob, 'reference:labels')).toBe('bob-labels')
	})

	it('never reaches another principal', async () => {
		const cache = new PrincipalScopedCache()
		const { alice, bob } = await populate(cache)

		cache.invalidate(alice)

		expect(cache.peek(alice, 'reference:labels')).toBeUndefined()
		expect(cache.peek(alice, 'reference:tax_classes')).toBeUndefined()
		expect(cache.peek(bob, 'reference:labels')).toBe('bob-labels')
	})

	it('reports nothing removed when the scope holds nothing', async () => {
		const cache = new PrincipalScopedCache()
		await populate(cache)
		expect(cache.invalidate(scopeFor('carol'))).toBe(0)
	})

	it('does not let an in-flight authorised read repopulate after a purge', async () => {
		const cache = new PrincipalScopedCache()
		const scope = scopeFor('alice')
		let release: ((value: string) => void) | undefined
		const pending = cache.getOrLoad(
			scope,
			'reference:labels',
			{},
			60_000,
			() =>
				new Promise<string>((resolve) => {
					release = resolve
				}),
		)

		cache.clear()
		release?.('stale-authorised-data')
		await pending

		expect(cache.peek(scope, 'reference:labels')).toBeUndefined()
	})
})

describe('bounded retention', () => {
	it('evicts rather than growing without limit', async () => {
		const cache = new PrincipalScopedCache({ maxEntries: 3 })
		const scope = scopeFor('alice')

		for (let index = 0; index < 10; index += 1) {
			await cache.getOrLoad(scope, 'op', { index }, 60_000, async () => index)
		}

		expect(cache.size).toBeLessThanOrEqual(3)
		expect(cache.stats.evictions).toBeGreaterThan(0)
	})
})
