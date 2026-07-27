import type { PageEnvelope } from './envelopes.js'

export interface ResponseBudget {
	maxCharacters: number
	maxItems: number
}

/** What a normal, well-shaped page is allowed to cost. */
export const DEFAULT_RESPONSE_BUDGET: ResponseBudget = {
	maxCharacters: 24_000,
	maxItems: 100,
}

/**
 * Absolute ceiling for a payload whose shape we do not control.
 *
 * Exceeding this is reported as an error. It is never a successful partial answer, because a
 * caller cannot tell a truncated list from a short one and will happily act on the difference.
 */
export const EMERGENCY_RESPONSE_CAP = 40_000

export class ResponseTooLargeError extends Error {
	readonly code = 'RESPONSE_TOO_LARGE'
	readonly characters: number
	readonly limit: number
	/** Concrete things the caller can do to get a smaller answer. */
	readonly remedies: string[]

	constructor(characters: number, limit: number, remedies: string[]) {
		super(
			`Response is ${characters} characters, over the ${limit} character limit. ` +
				`Retry with: ${remedies.join('; ')}.`,
		)
		this.name = 'ResponseTooLargeError'
		this.characters = characters
		this.limit = limit
		this.remedies = remedies
	}
}

/**
 * Reject an envelope that does not fit its budget.
 *
 * Deliberately a check, not a fixer. Trimming here would mean returning page 1 with some rows
 * silently dropped while `nextPage` points at page 2, so the dropped rows would never be seen
 * by anyone. Bound the request instead, then return the page whole or refuse it.
 */
export function assertResponseBudget<T>(
	envelope: PageEnvelope<T>,
	budget: ResponseBudget = DEFAULT_RESPONSE_BUDGET,
): void {
	if (envelope.data.length > budget.maxItems) {
		throw new ResponseTooLargeError(envelope.data.length, budget.maxItems, [
			`a smaller per_page (at most ${budget.maxItems})`,
		])
	}

	const characters = JSON.stringify(envelope).length
	if (characters <= budget.maxCharacters) return

	const remedies = [`a smaller per_page (current: ${envelope.perPage})`]
	if (envelope.data.length === 1) {
		remedies.push('a narrower include[] or fields[] selection, since one record alone is oversized')
	} else {
		remedies.push('a narrower include[] or fields[] selection')
	}

	throw new ResponseTooLargeError(characters, budget.maxCharacters, remedies)
}

/**
 * Final shield for a payload of unknown shape, applied after every semantic reduction.
 *
 * Returns the value untouched when it fits, and throws otherwise. It never returns partial
 * JSON, and it never marks a response as truncated-but-successful.
 */
export function assertWithinEmergencyCap(value: unknown, context: string): void {
	const characters = JSON.stringify(value)?.length ?? 0
	if (characters <= EMERGENCY_RESPONSE_CAP) return

	throw new ResponseTooLargeError(characters, EMERGENCY_RESPONSE_CAP, [
		`a narrower query for ${context}`,
		'fewer requested fields',
		'a smaller page size',
	])
}
