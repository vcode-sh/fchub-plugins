import { describe, expect, it } from 'vitest'
import {
	type ActivityCleanupClient,
	captureActivityBoundary,
	cleanupRunActivities,
} from '../integration/support/activity-cleanup.js'

interface Row {
	id: number
	content: string
}

function clientFor(initial: Row[], options: { perPage?: number; deleteFails?: boolean } = {}) {
	const rows = [...initial]
	const deleted: number[] = []
	const requestedPages: number[] = []
	const perPage = options.perPage ?? 2

	const client: ActivityCleanupClient = {
		async get(_path, params) {
			const page = Number(params?.page ?? 1)
			requestedPages.push(page)
			const ordered = [...rows].sort((left, right) => right.id - left.id)
			const start = (page - 1) * perPage
			const data = ordered.slice(start, start + perPage)
			const total = ordered.length
			return {
				status: 200,
				data: {
					activities: {
						current_page: page,
						data,
						last_page: Math.max(1, Math.ceil(total / perPage)),
						per_page: perPage,
						total,
					},
				},
			}
		},
		async delete(path) {
			if (options.deleteFails) throw new Error('delete refused')
			const id = Number(path.split('/').at(-1))
			deleted.push(id)
			const index = rows.findIndex((row) => row.id === id)
			if (index >= 0) rows.splice(index, 1)
			return { status: 200, data: { message: 'deleted' } }
		},
	}

	return { client, deleted, requestedPages, rows }
}

describe('run-owned activity cleanup', () => {
	it('deletes only newer exact-prefix collateral IDs and independently verifies absence', async () => {
		const harness = clientFor([
			{ id: 9, content: 'mcp-owned historical' },
			{ id: 11, content: 'created mcp-owned fixture' },
			{ id: 12, content: 'updated mcp-owned fixture' },
			{ id: 13, content: 'concurrent mcp-someone-else fixture' },
		])

		await expect(cleanupRunActivities(harness.client, 10, 'mcp-owned')).resolves.toEqual([11, 12])
		expect(harness.deleted).toEqual([11, 12])
		expect(harness.rows.map((row) => row.id).sort((a, b) => a - b)).toEqual([9, 13])
	})

	it('captures the greatest pre-write ID without reading or mutating content', async () => {
		const harness = clientFor([
			{ id: 41, content: 'older' },
			{ id: 42, content: 'newest' },
		])

		await expect(captureActivityBoundary(harness.client)).resolves.toBe(42)
		expect(harness.deleted).toEqual([])
	})

	it('reads every declared page before selecting exact IDs', async () => {
		const harness = clientFor(
			[
				{ id: 1, content: 'old' },
				{ id: 2, content: 'mcp-owned one' },
				{ id: 3, content: 'other' },
				{ id: 4, content: 'mcp-owned two' },
				{ id: 5, content: 'other' },
			],
			{ perPage: 2 },
		)

		await cleanupRunActivities(harness.client, 1, 'mcp-owned')
		expect(harness.requestedPages.slice(0, 3)).toEqual([1, 2, 3])
		expect(harness.deleted).toEqual([2, 4])
	})

	it('rejects ambiguous list and authentication failures without deleting', async () => {
		const deleted: number[] = []
		const malformed: ActivityCleanupClient = {
			async get() {
				return { status: 200, data: { activities: { data: [] } } }
			},
			async delete() {
				deleted.push(1)
				return { status: 200, data: {} }
			},
		}
		await expect(cleanupRunActivities(malformed, 0, 'mcp-owned')).rejects.toThrow(
			'activity pagination',
		)

		const unauthorised: ActivityCleanupClient = {
			async get() {
				throw new Error('HTTP 401')
			},
			async delete() {
				deleted.push(2)
				return { status: 200, data: {} }
			},
		}
		await expect(cleanupRunActivities(unauthorised, 0, 'mcp-owned')).rejects.toThrow('HTTP 401')
		expect(deleted).toEqual([])
	})

	it('rejects delete and independent-verification failures', async () => {
		const deleteFailure = clientFor([{ id: 2, content: 'mcp-owned' }], {
			deleteFails: true,
		})
		await expect(cleanupRunActivities(deleteFailure.client, 1, 'mcp-owned')).rejects.toThrow(
			'delete refused',
		)

		const persistent: ActivityCleanupClient = {
			async get() {
				return {
					status: 200,
					data: {
						activities: {
							current_page: 1,
							data: [{ id: 2, content: 'mcp-owned' }],
							last_page: 1,
							per_page: 100,
							total: 1,
						},
					},
				}
			},
			async delete() {
				return { status: 200, data: {} }
			},
		}
		await expect(cleanupRunActivities(persistent, 1, 'mcp-owned')).rejects.toThrow('still present')
	})

	it('aborts before deletion when an exact-ID expectation does not match discovery', async () => {
		const harness = clientFor([{ id: 2, content: 'mcp-owned' }])

		await expect(cleanupRunActivities(harness.client, 1, 'mcp-owned', [3])).rejects.toThrow(
			'exact-ID expectation mismatch',
		)
		expect(harness.deleted).toEqual([])
		expect(harness.rows).toHaveLength(1)
	})

	it('is idempotent after exact IDs are already absent', async () => {
		const harness = clientFor([{ id: 2, content: 'mcp-owned' }])
		await cleanupRunActivities(harness.client, 1, 'mcp-owned')
		await expect(cleanupRunActivities(harness.client, 1, 'mcp-owned')).resolves.toEqual([])
		expect(harness.deleted).toEqual([2])
	})
})
