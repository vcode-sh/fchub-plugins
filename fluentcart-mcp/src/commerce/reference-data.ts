import type { FluentCartClient } from '../api/client.js'
import type { CacheScope, PrincipalScopedCache } from './cache.js'
import { REFERENCE_DATA_TTL_MS } from './cache.js'
import { buildEnvelope, type PageEnvelope } from './envelopes.js'
import { PaginationError } from './pagination.js'
import {
	REFERENCE_INVALIDATIONS,
	type ReferenceDescriptor,
	type ReferenceItem,
	type ReferenceKind,
	referenceDescriptor,
	referenceOperation,
} from './reference-descriptors.js'
import { assertResponseBudget, assertWithinEmergencyCap } from './response-budget.js'

// The contract half is re-exported so callers have one import for the whole concern.
export * from './reference-descriptors.js'

function pick(row: Record<string, unknown>, keys: readonly string[]): unknown {
	for (const key of keys) {
		const value = row[key]
		if (value !== undefined && value !== null && value !== '') return value
	}
	return undefined
}

function asText(value: unknown): string | undefined {
	if (typeof value === 'string' && value.trim() !== '') return value.trim()
	if (typeof value === 'number' || typeof value === 'boolean') return String(value)
	return undefined
}

/**
 * Locate the row collection in a response body.
 *
 * Falls back to a bare array so an endpoint that returns rows at the root still works, and
 * returns nothing rather than guessing when the body is shaped some third way.
 */
export function extractRows(body: unknown, paths: readonly string[]): Record<string, unknown>[] {
	if (Array.isArray(body)) return body.filter(isRow)
	if (typeof body !== 'object' || body === null) return []

	const record = body as Record<string, unknown>
	for (const path of paths) {
		const candidate = record[path]
		if (Array.isArray(candidate)) return candidate.filter(isRow)
		// Several FluentCart settings endpoints return a keyed map rather than a list.
		if (candidate && typeof candidate === 'object') {
			const values = Object.entries(candidate as Record<string, unknown>)
				.filter(([, value]) => isRow(value))
				.map(([key, value]) => ({ key, ...(value as Record<string, unknown>) }))
			if (values.length > 0) return values
		}
	}
	return []
}

function isRow(value: unknown): value is Record<string, unknown> {
	return typeof value === 'object' && value !== null && !Array.isArray(value)
}

/**
 * Project one upstream row onto the reference contract.
 *
 * A row whose label cannot be resolved keeps its identifier and earns a warning rather than
 * being dropped: a missing name is a gap the caller can see, whereas a missing row is one they
 * cannot.
 */
export function projectRow(
	row: Record<string, unknown>,
	descriptor: ReferenceDescriptor,
	warnings: Set<string>,
): ReferenceItem | null {
	const rawId = pick(row, descriptor.candidates.id)
	const id = typeof rawId === 'number' ? rawId : asText(rawId)
	if (id === undefined) {
		warnings.add(
			`Skipped a ${descriptor.kind} row with no identifier in any of: ${descriptor.candidates.id.join(', ')}.`,
		)
		return null
	}

	const label = asText(pick(row, descriptor.candidates.label))
	if (label === undefined) {
		warnings.add(
			`Some ${descriptor.kind} rows carry no label field (${descriptor.candidates.label.join(', ')}); their identifier is shown instead.`,
		)
	}

	const item: ReferenceItem = { id, label: label ?? String(id) }
	const code = asText(pick(row, descriptor.candidates.code))
	if (code !== undefined) item.code = code
	const status = asText(pick(row, descriptor.candidates.status))
	if (status !== undefined) item.status = status
	return item
}

export interface ReferenceQuery {
	kind: unknown
	search?: unknown
	page?: unknown
	per_page?: unknown
}

export interface ReferenceDeps {
	client: FluentCartClient
	cache: PrincipalScopedCache
	scope: CacheScope
	ttlMs?: number
}

function integer(value: unknown, field: string): number | null {
	if (value === undefined || value === null || value === '') return null
	const parsed = typeof value === 'number' ? value : Number(value)
	if (!Number.isFinite(parsed)) throw new PaginationError(`${field} must be a number.`)
	if (!Number.isInteger(parsed)) throw new PaginationError(`${field} must be a whole number.`)
	return parsed
}

/**
 * Fetch, project, filter and page one reference list.
 *
 * These endpoints return a complete collection rather than a page, so filtering and paging
 * happen here over the whole set. That is why `total` is a real number for reference data while
 * it stays null elsewhere: the count is measured, not inferred from how full a page looked.
 */
export async function fetchReferenceData(
	deps: ReferenceDeps,
	query: ReferenceQuery,
): Promise<PageEnvelope<ReferenceItem>> {
	const descriptor = referenceDescriptor(query.kind)

	const page = integer(query.page, 'page') ?? 1
	if (page < 1) throw new PaginationError(`page must be 1 or greater, received ${page}.`)

	const perPage = integer(query.per_page, 'per_page') ?? descriptor.defaultPerPage
	if (perPage < 1) throw new PaginationError(`per_page must be at least 1, received ${perPage}.`)
	if (perPage > descriptor.maxPerPage) {
		throw new PaginationError(
			`per_page must be at most ${descriptor.maxPerPage} for ${descriptor.kind}, received ${perPage}.`,
		)
	}

	// Cached by kind alone: the whole collection is fetched once and paged locally, so two pages
	// of the same list share one upstream read instead of issuing one request each.
	const body = await deps.cache.getOrLoad(
		deps.scope,
		referenceOperation(descriptor.kind),
		{ route: descriptor.route },
		deps.ttlMs ?? REFERENCE_DATA_TTL_MS,
		async () => {
			const response = await deps.client.get(descriptor.route)
			assertWithinEmergencyCap(response.data, `${descriptor.kind} reference list`)
			return response.data
		},
	)

	const warnings = new Set<string>()
	const rows = extractRows(body, descriptor.collectionPaths)
	if (rows.length === 0) {
		warnings.add(
			`No ${descriptor.kind} rows were found at ${descriptor.route} under ${descriptor.collectionPaths.join(', ')}.`,
		)
	}

	const projected = rows
		.map((row) => projectRow(row, descriptor, warnings))
		.filter((item): item is ReferenceItem => item !== null)

	const search = typeof query.search === 'string' ? query.search.trim().toLowerCase() : ''
	const matched =
		search === ''
			? projected
			: projected.filter(
					(item) =>
						item.label.toLowerCase().includes(search) ||
						(item.code ?? '').toLowerCase().includes(search) ||
						String(item.id).toLowerCase().includes(search),
				)

	const start = (page - 1) * perPage
	const envelope = buildEnvelope(matched.slice(start, start + perPage), {
		page,
		perPage,
		total: matched.length,
		warnings: [...warnings],
	})

	assertResponseBudget(envelope)
	return envelope
}

/** Invalidate only what a named write affects. Returns the kinds actually cleared. */
export function invalidateForWrite(
	cache: PrincipalScopedCache,
	scope: CacheScope,
	toolName: string,
): ReferenceKind[] {
	const affected = REFERENCE_INVALIDATIONS[toolName] ?? []
	for (const kind of affected) cache.invalidate(scope, referenceOperation(kind))
	return [...affected]
}
