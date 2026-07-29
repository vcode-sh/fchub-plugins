import type { z } from 'zod'
import { FluentCartApiError } from '../api/errors.js'
import {
	assertToolResponseBudget,
	assertWithinEmergencyCap,
	ResponseTooLargeError,
} from '../commerce/response-budget.js'
import { redactSensitive } from '../security/redaction.js'

/** Retained for callers that imported the former factory constant. */
export const MAX_RESPONSE_CHARS = 80_000

/** Reject over-budget output rather than shortening a page and silently dropping records. */
export function truncateResponse(data: unknown): unknown {
	assertWithinEmergencyCap(data, 'this request')
	return data
}

/** A smaller-response remedy is valid only when the public schema exposes `per_page`. */
export function isPageable(schema: z.ZodObject<z.ZodRawShape>): boolean {
	return Object.hasOwn(schema.shape, 'per_page')
}

const PAGINATOR_LINK_KEYS: readonly string[] = [
	'links',
	'first_page_url',
	'last_page_url',
	'next_page_url',
	'prev_page_url',
	'path',
]

/** Require the characteristic Laravel paginator pair before removing URL-shaped keys. */
function isPaginator(record: Record<string, unknown>): boolean {
	return Array.isArray(record.data) && Object.hasOwn(record, 'current_page')
}

/**
 * Remove only URL navigation from paginator envelopes.
 *
 * Callers page by number, while these links can consume most of a response and expose the
 * store's internal REST origin. The depth limit bounds work on hostile upstream payloads.
 */
function stripPaginationLinks(value: unknown, depth = 0): unknown {
	if (depth > 6 || value === null || typeof value !== 'object') return value

	if (Array.isArray(value)) {
		let changed = false
		const mapped = value.map((entry) => {
			const next = stripPaginationLinks(entry, depth + 1)
			if (next !== entry) changed = true
			return next
		})
		return changed ? mapped : value
	}

	const record = value as Record<string, unknown>
	const dropping = isPaginator(record)
	let changed = false
	const result: Record<string, unknown> = {}

	for (const [key, inner] of Object.entries(record)) {
		if (dropping && PAGINATOR_LINK_KEYS.includes(key)) {
			changed = true
			continue
		}
		const next = stripPaginationLinks(inner, depth + 1)
		if (next !== inner) changed = true
		result[key] = next
	}

	return changed ? result : value
}

/** Reduce, budget, and serialise a successful tool result. */
export function formatSuccess(data: unknown, budget: { context: string; pageable: boolean }) {
	const reduced = stripPaginationLinks(data)
	assertToolResponseBudget(reduced, budget.context, { pageable: budget.pageable })
	return {
		content: [{ type: 'text' as const, text: JSON.stringify(reduced) }],
	}
}

/** Format a failure after redacting both upstream messages and structured details. */
export function formatError(error: unknown) {
	if (error instanceof ResponseTooLargeError) {
		return {
			content: [{ type: 'text' as const, text: `Error [${error.code}]: ${error.message}` }],
			isError: true,
		}
	}

	if (error instanceof FluentCartApiError) {
		let text = `Error [${error.code}]: ${redactSensitive(error.message) as string}`
		if (error.detail !== undefined) {
			const redactedDetail = redactSensitive(error.detail)
			const detail =
				typeof redactedDetail === 'string' ? redactedDetail : JSON.stringify(redactedDetail)
			text += `: ${detail}`
		}
		return {
			content: [{ type: 'text' as const, text }],
			isError: true,
		}
	}

	const message = error instanceof Error ? error.message : String(error)
	return {
		content: [{ type: 'text' as const, text: `Error: ${redactSensitive(message) as string}` }],
		isError: true,
	}
}
