import type { z } from 'zod'
import { canonicalJson, sha256Hex } from './encoding.js'
import type { GuardRuntime } from './guard-config.js'
import type { GuardedResult, GuardedResultValue, LedgerInspection } from './idempotency-ledger.js'

/**
 * Vocabulary for guarded actions: what they can refuse, what they promise, what they return.
 *
 * Separated from the protocol engine so the failure taxonomy and the caller-facing wording can
 * be read — and reviewed — without wading through the locking sequence.
 */

export type GuardedFailureCode =
	| 'GUARD_UNAVAILABLE'
	| 'INVALID_REQUEST'
	| 'IDEMPOTENCY_AMBIGUOUS'
	| 'IDEMPOTENCY_CONFLICT'
	| 'ENTITY_BUSY'
	| 'CONFIRMATION_INVALID'
	| 'STATE_CHANGED'
	| 'LIVE_ACTION_BLOCKED'

export class GuardedActionError extends Error {
	readonly code: GuardedFailureCode

	constructor(code: GuardedFailureCode, message: string) {
		super(`[${code}] ${message}`)
		this.name = 'GuardedActionError'
		this.code = code
	}
}

/** What a preview read established about the entity, as of that read. */
export interface ActionState {
	/** Digest binding every field whose change should invalidate the preview. */
	stateFingerprint: string
	/** True when executing would move money through a live gateway. */
	live: boolean
	/** Redacted, caller-facing description of what execution would do. */
	preview: Record<string, unknown>
}

export interface GuardedAction<TFields, TState extends ActionState = ActionState> {
	/** Public MCP tool name. */
	tool: string
	/** Entity reference derived from the inputs alone, so `inspect()` can precede every read. */
	entityRef(fields: TFields): string
	/** Reads only. Throws `GuardedActionError('INVALID_REQUEST')` when the action is impossible. */
	loadState(fields: TFields): Promise<TState>
	/** Exactly one REST mutation. No fallback route, no retry. */
	mutate(fields: TFields, state: TState): Promise<void>
	/** Re-read after the mutation. Returns the redacted summary stored in the ledger. */
	reread(fields: TFields): Promise<Record<string, GuardedResultValue>>
}

export const AMBIGUOUS_ENTITY =
	'An earlier action on this entity recorded no durable outcome, so this server cannot tell whether it reached the payment gateway. Reconcile it with the provider, then clear the claim through guard-state maintenance. Nothing is retried automatically.'

export const CONFLICTING_KEY =
	'This idempotency key was already used for a different operation. Use a fresh key, or repeat the original request exactly.'

export const BUSY_ENTITY =
	'Another action on this entity is already in flight. Wait for it to finish rather than retrying.'

export const STALE_PREVIEW =
	'The entity or the request no longer matches the preview this token was issued for. Take a fresh preview and review it before confirming.'

export const LIVE_BLOCKED =
	'This entity is in live payment mode. Set FLUENTCART_ALLOW_LIVE_GATEWAY_ACTIONS=yes and restart the server to permit live gateway actions.'

const GUARD_MISSING =
	'This action needs guarded mode. Set FLUENTCART_GUARD_SECRET and FLUENTCART_GUARD_STATE_DIR, then restart the server.'

const TOKEN_SHAPE = /^[A-Za-z0-9_-]{16,3000}\.[A-Za-z0-9_-]{40,90}$/
const MAX_KEY_LENGTH = 200

/** Stable digest over the exact public mutation fields. Two calls agree only on identical input. */
export function operationDigest(tool: string, fields: unknown): string {
	return sha256Hex('fluentcart-mcp/operation/v1', tool, canonicalJson(fields))
}

export function fingerprint(namespace: string, parts: unknown): string {
	return sha256Hex('fluentcart-mcp/fingerprint/v1', namespace, canonicalJson(parts))
}

export function requireGuard(guard: GuardRuntime | null): GuardRuntime {
	if (!guard) throw new GuardedActionError('GUARD_UNAVAILABLE', GUARD_MISSING)
	return guard
}

/**
 * Validate tool input here rather than trusting the transport.
 *
 * The MCP SDK checks the schema before dispatch, but a guarded handler must not depend on that:
 * a caller reaching the handler by any other route still gets the same refusal.
 */
export function parseGuardedInput<TSchema extends z.ZodType>(
	schema: TSchema,
	input: unknown,
): z.infer<TSchema> {
	const parsed = schema.safeParse(input)
	if (parsed.success) return parsed.data
	const detail = parsed.error.issues
		.map((issue) => `${issue.path.join('.') || 'input'}: ${issue.message}`)
		.join('; ')
	throw new GuardedActionError('INVALID_REQUEST', detail)
}

export function assertTokenShape(token: unknown): void {
	if (typeof token !== 'string' || !TOKEN_SHAPE.test(token)) {
		const detail = 'confirm_token is not a confirmation token issued by this server.'
		throw new GuardedActionError('CONFIRMATION_INVALID', detail)
	}
}

export function assertIdempotencyKey(key: unknown): string {
	if (typeof key !== 'string' || key.trim() === '' || key.length > MAX_KEY_LENGTH) {
		const detail = `idempotency_key must be a non-empty string of at most ${MAX_KEY_LENGTH} characters, unique to this attempt.`
		throw new GuardedActionError('INVALID_REQUEST', detail)
	}
	return key
}

/** Turn a ledger verdict into either a replay payload or the matching refusal. */
export function resolveInspection(inspection: LedgerInspection): Record<string, unknown> {
	if (inspection.kind === 'replay') {
		return { ...describeResult(inspection.result), replayed: true }
	}
	if (inspection.kind === 'conflict') {
		throw new GuardedActionError('IDEMPOTENCY_CONFLICT', CONFLICTING_KEY)
	}
	throw new GuardedActionError('IDEMPOTENCY_AMBIGUOUS', AMBIGUOUS_ENTITY)
}

export function describeResult(result: GuardedResult): Record<string, unknown> {
	return {
		tool: result.tool,
		entity: result.entity,
		status: result.status,
		completed_at: new Date(result.completedAt).toISOString(),
		summary: result.summary,
	}
}

/**
 * Everything after `begin()` is ambiguous by construction.
 *
 * A timeout, a dropped connection or an unreadable response says nothing about whether
 * FluentCart forwarded the request to the gateway. The pending claim and the entity lock stay
 * exactly as they are; only an operator with provider evidence may resolve them. The upstream
 * error code is reported, never its message, which routinely echoes the request.
 */
export function ambiguousAfterStart(error: unknown): GuardedActionError {
	const cause =
		typeof error === 'object' && error !== null && 'code' in error
			? String((error as { code: unknown }).code)
			: 'UNKNOWN'
	return new GuardedActionError(
		'IDEMPOTENCY_AMBIGUOUS',
		`The request was sent but no verified outcome was recorded (${cause}). It may or may not have been applied. Check the payment provider before acting; this server will not retry.`,
	)
}
