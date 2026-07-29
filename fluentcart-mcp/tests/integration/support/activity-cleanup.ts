import type { ApiResponse } from '../../../src/api/client.js'
import { getLiveClient } from './live-client.js'

const ACTIVITY_PAGE_SIZE = 199

export interface ActivityCleanupClient {
	get(path: string, params?: Record<string, unknown>): Promise<Pick<ApiResponse, 'status' | 'data'>>
	delete(path: string): Promise<Pick<ApiResponse, 'status' | 'data'>>
}

interface ActivityRow {
	id: number
	content: unknown
}

interface ActivityPage {
	currentPage: number
	data: ActivityRow[]
	lastPage: number
	perPage: number
	total: number
}

function integer(value: unknown, label: string, minimum: number): number {
	if (!(typeof value === 'number' && Number.isInteger(value) && value >= minimum)) {
		throw new Error(`activity pagination has invalid ${label}`)
	}
	return value
}

function parsePage(
	response: Pick<ApiResponse, 'status' | 'data'>,
	expectedPage: number,
): ActivityPage {
	if (response.status !== 200) {
		throw new Error(`activity list returned HTTP ${response.status}`)
	}

	const body = response.data as Record<string, unknown> | null
	const paginator = body?.activities as Record<string, unknown> | null
	if (!(paginator && Array.isArray(paginator.data))) {
		throw new Error('activity pagination payload is missing activities.data')
	}

	const currentPage = integer(paginator.current_page, 'current_page', 1)
	const lastPage = integer(paginator.last_page, 'last_page', 1)
	const perPage = integer(paginator.per_page, 'per_page', 1)
	const total = integer(paginator.total, 'total', 0)
	if (currentPage !== expectedPage || currentPage > lastPage) {
		throw new Error('activity pagination returned an unexpected page boundary')
	}
	if (paginator.data.length > perPage) {
		throw new Error('activity pagination returned more rows than per_page')
	}

	const data = paginator.data.map((entry, index) => {
		const row = entry as Record<string, unknown> | null
		const id = integer(row?.id, `row ${index} id`, 1)
		return { id, content: row?.content }
	})
	return { currentPage, data, lastPage, perPage, total }
}

async function collectAllActivities(client: ActivityCleanupClient): Promise<ActivityRow[]> {
	const rows: ActivityRow[] = []
	let expectedLastPage: number | null = null
	let expectedTotal: number | null = null
	let previousId = Number.POSITIVE_INFINITY
	const seen = new Set<number>()

	for (let page = 1; expectedLastPage === null || page <= expectedLastPage; page += 1) {
		const response = await client.get('/activity', {
			page,
			per_page: ACTIVITY_PAGE_SIZE,
			sort_by: 'id',
			sort_type: 'desc',
		})
		const parsed = parsePage(response, page)

		if (expectedLastPage === null) {
			expectedLastPage = parsed.lastPage
			expectedTotal = parsed.total
		} else if (parsed.lastPage !== expectedLastPage || parsed.total !== expectedTotal) {
			throw new Error('activity pagination changed while it was being read')
		}

		for (const row of parsed.data) {
			if (row.id >= previousId) {
				throw new Error('activity pagination is not strictly descending by id')
			}
			if (seen.has(row.id)) {
				throw new Error(`activity pagination repeated id ${row.id}`)
			}
			seen.add(row.id)
			rows.push(row)
			previousId = row.id
		}
	}

	if (expectedTotal !== rows.length) {
		throw new Error(
			`activity pagination was incomplete: expected ${String(expectedTotal)}, read ${rows.length}`,
		)
	}
	return rows
}

function exactPrefixPattern(prefix: string): RegExp {
	if (!prefix) throw new Error('activity cleanup requires a non-empty run prefix')
	const escaped = prefix.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
	return new RegExp(`(^|[^A-Za-z0-9])${escaped}(?=$|[^A-Za-z0-9])`)
}

/** Capture the highest activity ID before the lane creates any fixture. */
export async function captureActivityBoundary(
	client: ActivityCleanupClient = getLiveClient(),
): Promise<number> {
	const rows = await collectAllActivities(client)
	return rows[0]?.id ?? 0
}

/**
 * Remove only collateral activity rows created after this run's boundary.
 *
 * Discovery is read-only and complete before deletion. Every candidate must carry the exact
 * run prefix, every DELETE names one exact ID, and a second complete scan proves every selected
 * ID absent. Rows at or below the boundary and newer concurrent rows with another prefix remain
 * untouched.
 */
export async function cleanupRunActivities(
	client: ActivityCleanupClient,
	boundary: number,
	runPrefix: string,
	expectedIds?: readonly number[],
): Promise<number[]> {
	if (!(Number.isInteger(boundary) && boundary >= 0)) {
		throw new Error('activity cleanup requires a non-negative integer boundary')
	}

	const pattern = exactPrefixPattern(runPrefix)
	const after = await collectAllActivities(client)
	const selected: number[] = []
	for (const row of after) {
		if (row.id <= boundary) continue
		if (typeof row.content !== 'string') {
			throw new Error(`newer activity ${row.id} has no inspectable content`)
		}
		if (pattern.test(row.content)) selected.push(row.id)
	}
	selected.sort((left, right) => left - right)

	if (expectedIds) {
		const expected = [...expectedIds].sort((left, right) => left - right)
		if (
			expected.length !== selected.length ||
			expected.some((id, index) => id !== selected[index])
		) {
			throw new Error(
				`activity cleanup exact-ID expectation mismatch: expected ${expected.join(', ')}, found ${selected.join(', ')}`,
			)
		}
	}

	for (const id of selected) {
		const response = await client.delete(`/activity/${id}`)
		if (response.status !== 200) {
			throw new Error(`activity ${id} delete returned HTTP ${response.status}`)
		}
	}

	const remainingIds = new Set((await collectAllActivities(client)).map((row) => row.id))
	const present = selected.filter((id) => remainingIds.has(id))
	if (present.length > 0) {
		throw new Error(`activity cleanup incomplete — exact IDs still present: ${present.join(', ')}`)
	}
	return selected
}
