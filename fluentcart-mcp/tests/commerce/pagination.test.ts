import { describe, expect, it } from 'vitest'
import {
	continuationHint,
	PaginationError,
	paginationProfile,
	paginationProfiles,
	resolvePagination,
} from '../../src/commerce/pagination.js'

const TOOL = 'fluentcart_order_list'

describe('pagination profiles', () => {
	it('declares an evidence source for every profile', () => {
		for (const profile of paginationProfiles()) {
			expect(profile.evidence, `${profile.tool} must record where its limits came from`).not.toBe(
				'',
			)
		}
	})

	it('declares a maximum for every profile', () => {
		for (const profile of paginationProfiles()) {
			expect(profile.maxPerPage).toBeGreaterThan(0)
			expect(profile.minPerPage).toBeGreaterThan(0)
			expect(profile.maxPerPage).toBeGreaterThanOrEqual(profile.minPerPage)
		}
	})

	it('has no duplicate tool entries', () => {
		const names = paginationProfiles().map((profile) => profile.tool)
		expect(new Set(names).size).toBe(names.length)
	})

	it('refuses to paginate a tool with no reviewed profile', () => {
		expect(paginationProfile('fluentcart_unknown_list')).toBeNull()
		expect(() => resolvePagination('fluentcart_unknown_list', {})).toThrow(/No pagination profile/)
	})
})

describe('resolvePagination', () => {
	it('sends no page size when the caller omits it, letting the endpoint default apply', () => {
		const resolved = resolvePagination(TOOL, {})
		expect(resolved.page).toBe(1)
		expect(resolved.params).toEqual({ page: 1 })
		expect(resolved.params.per_page).toBeUndefined()
	})

	it('passes an explicit page size through under its upstream parameter name', () => {
		const resolved = resolvePagination(TOOL, { page: 3, per_page: 25 })
		expect(resolved).toMatchObject({ page: 3, perPage: 25 })
		expect(resolved.params).toEqual({ page: 3, per_page: 25 })
	})

	it('rejects a page below one', () => {
		expect(() => resolvePagination(TOOL, { page: 0 })).toThrow(PaginationError)
		expect(() => resolvePagination(TOOL, { page: -2 })).toThrow(/page must be 1 or greater/)
	})

	it('rejects a zero or negative page size', () => {
		expect(() => resolvePagination(TOOL, { per_page: 0 })).toThrow(/at least/)
		expect(() => resolvePagination(TOOL, { per_page: -5 })).toThrow(/at least/)
	})

	it('rejects a page size above the verified maximum rather than clamping it', () => {
		// Clamping would return fewer rows than asked for while reporting success, which reads
		// exactly like a short final page.
		expect(() => resolvePagination(TOOL, { per_page: 101 })).toThrow(/at most 100/)
	})

	it('rejects a decimal page or page size', () => {
		expect(() => resolvePagination(TOOL, { page: 1.5 })).toThrow(/whole number/)
		expect(() => resolvePagination(TOOL, { per_page: 10.5 })).toThrow(/whole number/)
	})

	it('rejects a non-numeric value', () => {
		expect(() => resolvePagination(TOOL, { per_page: 'ten' })).toThrow(/must be a number/)
		expect(() => resolvePagination(TOOL, { page: {} })).toThrow(/must be a number/)
	})

	it('treats an empty string as omitted', () => {
		expect(resolvePagination(TOOL, { page: '', per_page: '' }).params).toEqual({ page: 1 })
	})

	it('accepts numeric strings, since MCP clients routinely send them', () => {
		const resolved = resolvePagination(TOOL, { page: '2', per_page: '5' })
		expect(resolved.params).toEqual({ page: 2, per_page: 5 })
	})

	it('accepts the exact maximum', () => {
		expect(resolvePagination(TOOL, { per_page: 100 }).perPage).toBe(100)
	})

	it('applies each profile independently', () => {
		for (const profile of paginationProfiles()) {
			const resolved = resolvePagination(profile.tool, { per_page: profile.maxPerPage })
			expect(resolved.params[profile.perPageParam]).toBe(profile.maxPerPage)
			expect(() => resolvePagination(profile.tool, { per_page: profile.maxPerPage + 1 })).toThrow()
		}
	})
})

describe('continuationHint', () => {
	it('uses the public tool input names, not upstream ones', () => {
		expect(continuationHint(TOOL, 4)).toBe(`Call ${TOOL} again with page=4 for the next page.`)
	})

	it('offers no continuation when there is no next page', () => {
		expect(continuationHint(TOOL, null)).toBeNull()
	})
})
