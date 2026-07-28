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
 * Remedies for a payload of unknown shape. Only ever advice the caller can actually follow.
 *
 * "Retry with a smaller page size" on a tool with no page size is the failure this split
 * exists to prevent: it reads as an instruction, costs a round trip to disprove, and leaves
 * the caller with the same oversized answer. A tool without paging is told to narrow the
 * question instead, which is the only lever it has.
 */
function remediesFor(context: string, pageable: boolean): string[] {
	if (pageable) {
		return [`a smaller per_page for ${context}`, 'a narrower filter or fewer requested fields']
	}
	return [`a narrower query for ${context}`, 'fewer requested fields']
}

/**
 * Bound a tool payload against the budget whose remedy the caller can act on.
 *
 * A pageable tool is held to the default budget, because "ask for fewer rows" always works and
 * a 24,000-character page is already a lot of context to hand an agent. A tool with no page
 * size keeps the emergency cap, because the stricter limit would convert working reads into
 * permanent errors: measured on the development store, `fluentcart_settings_print_templates_get`
 * with `include_content` returns 38,669 characters that no argument can shrink, and four
 * further reads already exceed 40,000 with nothing to page. A budget that turns a working tool
 * into an error it cannot escape is worse than no budget.
 */
export function assertToolResponseBudget(
	value: unknown,
	context: string,
	options: { pageable: boolean },
): void {
	const limit = options.pageable ? DEFAULT_RESPONSE_BUDGET.maxCharacters : EMERGENCY_RESPONSE_CAP
	const characters = JSON.stringify(value)?.length ?? 0
	if (characters <= limit) return

	throw new ResponseTooLargeError(characters, limit, remediesFor(context, options.pageable))
}

/**
 * Final shield for a payload of unknown shape, applied after every semantic reduction.
 *
 * Returns the value untouched when it fits, and throws otherwise. It never returns partial
 * JSON, and it never marks a response as truncated-but-successful.
 *
 * Every caller reaching this entry point fetches its route whole — the reference-data loader,
 * the PDF template reads, the saved-view list — so the advice it carries never mentions paging.
 */
export function assertWithinEmergencyCap(value: unknown, context: string): void {
	assertToolResponseBudget(value, context, { pageable: false })
}
