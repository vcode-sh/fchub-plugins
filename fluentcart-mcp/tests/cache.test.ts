import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cached, cacheSize, clearCache, invalidate, TTL } from '../src/cache.js'

describe('cache module', () => {
	beforeEach(() => {
		clearCache()
	})

	afterEach(() => {
		vi.useRealTimers()
		clearCache()
	})

	it('calls fetcher on cache miss and returns fetched data', async () => {
		const fetcher = vi.fn().mockResolvedValue({ countries: ['US', 'GB'] })
		const result = await cached('countries', TTL.LONG, fetcher)

		expect(fetcher).toHaveBeenCalledTimes(1)
		expect(result).toEqual({ countries: ['US', 'GB'] })
	})

	it('returns cached data on cache hit without calling fetcher again', async () => {
		const fetcher = vi.fn().mockResolvedValue({ id: 1 })

		await cached('item', TTL.LONG, fetcher)
		const result = await cached('item', TTL.LONG, fetcher)

		expect(fetcher).toHaveBeenCalledTimes(1)
		expect(result).toEqual({ id: 1 })
	})

	it('calls fetcher again after TTL expires', async () => {
		vi.useFakeTimers()
		const fetcher = vi.fn().mockResolvedValueOnce({ v: 1 }).mockResolvedValueOnce({ v: 2 })

		const result1 = await cached('expiring', 5_000, fetcher)
		expect(result1).toEqual({ v: 1 })

		vi.advanceTimersByTime(6_000)

		const result2 = await cached('expiring', 5_000, fetcher)
		expect(result2).toEqual({ v: 2 })
		expect(fetcher).toHaveBeenCalledTimes(2)
	})

	it('does not call fetcher again before TTL expires', async () => {
		vi.useFakeTimers()
		const fetcher = vi.fn().mockResolvedValue({ v: 1 })

		await cached('fresh', 10_000, fetcher)
		vi.advanceTimersByTime(5_000)
		await cached('fresh', 10_000, fetcher)

		expect(fetcher).toHaveBeenCalledTimes(1)
	})

	describe('clearCache', () => {
		it('removes all entries from the cache', async () => {
			await cached('a', TTL.LONG, async () => 'alpha')
			await cached('b', TTL.LONG, async () => 'beta')
			expect(cacheSize()).toBe(2)

			clearCache()
			expect(cacheSize()).toBe(0)
		})

		it('causes next call to re-fetch', async () => {
			const fetcher = vi.fn().mockResolvedValue('data')
			await cached('key', TTL.LONG, fetcher)
			clearCache()
			await cached('key', TTL.LONG, fetcher)

			expect(fetcher).toHaveBeenCalledTimes(2)
		})
	})

	describe('invalidate', () => {
		it('removes a specific key and returns true', async () => {
			await cached('target', TTL.LONG, async () => 'value')
			expect(cacheSize()).toBe(1)

			const removed = invalidate('target')
			expect(removed).toBe(true)
			expect(cacheSize()).toBe(0)
		})

		it('returns false for non-existent key', () => {
			const removed = invalidate('nonexistent')
			expect(removed).toBe(false)
		})

		it('does not affect other entries', async () => {
			await cached('keep', TTL.LONG, async () => 'kept')
			await cached('remove', TTL.LONG, async () => 'removed')

			invalidate('remove')
			expect(cacheSize()).toBe(1)

			const fetcher = vi.fn().mockResolvedValue('kept')
			const result = await cached('keep', TTL.LONG, fetcher)
			expect(fetcher).not.toHaveBeenCalled()
			expect(result).toBe('kept')
		})
	})

	describe('cacheSize', () => {
		it('returns 0 for empty cache', () => {
			expect(cacheSize()).toBe(0)
		})

		it('returns correct count after adding entries', async () => {
			await cached('one', TTL.LONG, async () => 1)
			await cached('two', TTL.LONG, async () => 2)
			await cached('three', TTL.LONG, async () => 3)
			expect(cacheSize()).toBe(3)
		})

		it('includes expired entries that have not been evicted', async () => {
			vi.useFakeTimers()
			await cached('short', 1_000, async () => 'temp')
			vi.advanceTimersByTime(2_000)

			// Entry is expired but not yet evicted (only evicted on next access)
			expect(cacheSize()).toBe(1)
		})
	})

	describe('TTL constants', () => {
		it('has correct values', () => {
			expect(TTL.LONG).toBe(3_600_000)
			expect(TTL.MEDIUM).toBe(600_000)
			expect(TTL.SHORT).toBe(120_000)
		})
	})
})
