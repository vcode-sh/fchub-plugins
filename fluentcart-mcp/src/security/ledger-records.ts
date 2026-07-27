import { join } from 'node:path'
import { sha256Hex } from './encoding.js'
import { LedgerError, readJsonNoFollow } from './ledger-store.js'

/**
 * On-disk shapes, naming and inspection for the idempotency ledger.
 *
 * Records are read back by a later process — possibly a later release — so every field is
 * validated on the way in and on the way out. A record that does not validate is not treated as
 * absent: the ledger reports ambiguity, because "I cannot read the evidence" and "there is no
 * evidence" are very different answers when the question is whether a refund already happened.
 */

export const LEDGER_RECORD_VERSION = 1

export const PENDING_FILE = 'pending.json'
export const COMPLETED_FILE = 'completed.json'
export const OWNER_FILE = 'owner.json'

const MAX_IDENTIFIER = 256
const MAX_SUMMARY_ENTRIES = 32
const MAX_SUMMARY_TEXT = 512
const CREDENTIAL_LIKE = /\b(Basic|Bearer)\s+\S/i

/** Values a stored summary may contain. Deliberately flat: no nesting, no room to hide a payload. */
export type GuardedResultValue = string | number | boolean | null

/**
 * The redacted outcome of one completed guarded mutation.
 *
 * Replayed verbatim when the same idempotency key returns, so it must carry enough for the
 * caller to understand what already happened and nothing that would be unsafe to keep on disk:
 * no credentials, no payment instrument data, no customer personal data. Stable identifiers and
 * amounts are fine; a card number, an email address or an authorisation header is not.
 */
export interface GuardedResult {
	/** Public MCP tool name that performed the mutation. */
	tool: string
	/** Opaque entity reference such as `order:42`. */
	entity: string
	/** A completed record exists only for a verified success. */
	status: 'succeeded'
	/** Digest of the exact mutation fields, so a reused key with different fields conflicts. */
	operationDigest: string
	/** Epoch milliseconds at which success was verified. */
	completedAt: number
	/** Flat, redacted detail returned to the caller on replay. */
	summary: Record<string, GuardedResultValue>
}

export interface PendingRecord {
	version: typeof LEDGER_RECORD_VERSION
	state: 'pending'
	claimId: string
	entityHash: string
	claimHash: string
	operationDigest: string
	startedAt: number
}

export interface CompletedRecord {
	version: typeof LEDGER_RECORD_VERSION
	state: 'completed'
	claimId: string
	entityHash: string
	claimHash: string
	operationDigest: string
	startedAt: number
	completedAt: number
	result: GuardedResult
}

export type LedgerInspection =
	| { kind: 'none' }
	| { kind: 'replay'; result: GuardedResult }
	| { kind: 'ambiguous' }
	| { kind: 'conflict' }

/** Domain-separated so an entity name can never collide with a claim name. */
export function hashEntityScope(scope: string): string {
	return sha256Hex('fluentcart-mcp/ledger/entity/v1', scope)
}

/** The raw idempotency key is hashed and never written to disk. */
export function hashClaimKey(scope: string, key: string): string {
	return sha256Hex('fluentcart-mcp/ledger/claim/v1', scope, key)
}

/**
 * Decide what a claim directory says about an operation, without taking any lock.
 *
 * Completed evidence wins over pending evidence: a crash that left the entity locked must still
 * let the original caller collect the result it already earned.
 */
export async function inspectClaimDir(
	dir: string,
	operationDigest: string,
): Promise<LedgerInspection> {
	const completed = await inspectCompleted(dir, operationDigest)
	return completed ?? (await inspectPending(dir, operationDigest))
}

async function inspectCompleted(
	dir: string,
	operationDigest: string,
): Promise<LedgerInspection | undefined> {
	const read = await readJsonNoFollow(join(dir, COMPLETED_FILE))
	if (read.kind === 'missing') return undefined
	if (read.kind === 'corrupt') return { kind: 'ambiguous' }

	const record = parseCompletedRecord(read.value)
	if (!record) return { kind: 'ambiguous' }
	if (record.operationDigest !== operationDigest) return { kind: 'conflict' }
	return { kind: 'replay', result: record.result }
}

async function inspectPending(dir: string, operationDigest: string): Promise<LedgerInspection> {
	const read = await readJsonNoFollow(join(dir, PENDING_FILE))
	if (read.kind === 'missing') return { kind: 'none' }
	if (read.kind === 'corrupt') return { kind: 'ambiguous' }

	const record = parsePendingRecord(read.value)
	if (!record) return { kind: 'ambiguous' }
	// A reused key describing a different operation is a caller mistake, not a crash.
	if (record.operationDigest !== operationDigest) return { kind: 'conflict' }
	return { kind: 'ambiguous' }
}

function asObject(value: unknown): Record<string, unknown> | undefined {
	if (value === null || typeof value !== 'object' || Array.isArray(value)) return undefined
	return value as Record<string, unknown>
}

function isIdentifier(value: unknown): value is string {
	return typeof value === 'string' && value.length > 0 && value.length <= MAX_IDENTIFIER
}

function isTimestamp(value: unknown): value is number {
	return typeof value === 'number' && Number.isSafeInteger(value) && value >= 0
}

function hasCommonFields(record: Record<string, unknown>): boolean {
	return (
		record.version === LEDGER_RECORD_VERSION &&
		isIdentifier(record.claimId) &&
		isIdentifier(record.entityHash) &&
		isIdentifier(record.claimHash) &&
		isIdentifier(record.operationDigest) &&
		isTimestamp(record.startedAt)
	)
}

export function parsePendingRecord(value: unknown): PendingRecord | undefined {
	const record = asObject(value)
	if (record?.state !== 'pending' || !hasCommonFields(record)) return undefined
	return record as unknown as PendingRecord
}

export function parseCompletedRecord(value: unknown): CompletedRecord | undefined {
	const record = asObject(value)
	if (record?.state !== 'completed' || !hasCommonFields(record)) return undefined
	if (!isTimestamp(record.completedAt)) return undefined
	if (!isGuardedResult(record.result)) return undefined
	return record as unknown as CompletedRecord
}

function isGuardedResult(value: unknown): value is GuardedResult {
	const result = asObject(value)
	if (!result) return false
	return (
		isIdentifier(result.tool) &&
		isIdentifier(result.entity) &&
		result.status === 'succeeded' &&
		isIdentifier(result.operationDigest) &&
		isTimestamp(result.completedAt) &&
		isSummary(result.summary)
	)
}

function isSummary(value: unknown): value is Record<string, GuardedResultValue> {
	const summary = asObject(value)
	if (!summary) return false
	const entries = Object.entries(summary)
	if (entries.length > MAX_SUMMARY_ENTRIES) return false
	return entries.every(([, entry]) => isSummaryValue(entry))
}

function isSummaryValue(value: unknown): value is GuardedResultValue {
	if (value === null || typeof value === 'boolean') return true
	if (typeof value === 'number') return Number.isFinite(value)
	return typeof value === 'string' && value.length <= MAX_SUMMARY_TEXT
}

/**
 * Reject a result the ledger must not persist.
 *
 * Callers are required to redact before storing; this is the second line of defence, and it
 * throws rather than scrubbing so a mistake surfaces in tests instead of being papered over.
 */
export function assertStorableResult(value: unknown, expectedDigest: string): GuardedResult {
	if (!isGuardedResult(value)) {
		throw new LedgerError('INVALID_RESULT', 'Guarded result is not a storable redacted record')
	}
	if (value.operationDigest !== expectedDigest) {
		throw new LedgerError(
			'INVALID_RESULT',
			'Guarded result does not describe the claimed operation',
		)
	}
	for (const entry of Object.values(value.summary)) {
		if (typeof entry === 'string' && CREDENTIAL_LIKE.test(entry)) {
			throw new LedgerError(
				'INVALID_RESULT',
				'Guarded result summary contains credential-like text',
			)
		}
	}
	return value
}
