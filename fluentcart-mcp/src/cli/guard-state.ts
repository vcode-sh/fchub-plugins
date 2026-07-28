import { readdir, rename } from 'node:fs/promises'
import { join } from 'node:path'
import {
	COMPLETED_FILE,
	OWNER_FILE,
	PENDING_FILE,
	parseCompletedRecord,
	parsePendingRecord,
} from '../security/ledger-records.js'
import {
	isHexName,
	LedgerError,
	readJsonNoFollow,
	removeQuietly,
	syncDirectory,
	writeFileExclusive,
} from '../security/ledger-store.js'

/**
 * The operator's way out of an ambiguous claim.
 *
 * The ledger never retries and never expires a pending record, so a process killed between
 * `begin()` and a durable `completed.json` leaves an entity that answers `IDEMPOTENCY_AMBIGUOUS`
 * for ever. That is the correct default — a retry that turns out to be a second refund costs
 * real money — but it is only defensible if a human holding payment-provider evidence can close
 * the case. This command is that escape hatch, and nothing else may use it.
 *
 * It is deliberately not an MCP tool. There is no `ToolDefinition` in this module and no
 * registry references it, so no agent and no HTTP client can reach it; resolving an ambiguous
 * money action requires a shell on the host that owns the state directory.
 *
 * Run it with the server stopped. It edits the same directory a running process holds locks in.
 */

const RESOLUTION_FILE = 'resolution.json'
const ARCHIVED_PENDING_FILE = 'pending.archived.json'
const MAX_EVIDENCE_LENGTH = 200

/** Digits, letters and light punctuation only: enough for a ticket or provider reference. */
const EVIDENCE_SHAPE = /^[A-Za-z0-9 ._:/#-]+$/

export type ResolutionOutcome = 'confirmed-completed' | 'confirmed-not-executed'

export interface ClaimReport {
	claimHash: string
	state: 'pending' | 'completed' | 'resolved' | 'unreadable'
	startedAt: number | null
	completedAt: number | null
	resolvedAt: number | null
	outcome: ResolutionOutcome | null
}

export interface EntityReport {
	entityHash: string
	acquiredAt: number | null
	state: 'locked' | 'unreadable'
}

export interface GuardStateReport {
	stateDir: string
	entities: EntityReport[]
	claims: ClaimReport[]
}

export interface ResolveRequest {
	stateDir: string
	entityHash: string
	claimHash: string
	outcome: ResolutionOutcome
	evidenceReference: string
	/** Present only when a local shell invoked this. No transport can supply it. */
	invocation: 'local-cli'
	now?: () => number
}

function timestampOf(value: unknown, key: string): number | null {
	if (value === null || typeof value !== 'object') return null
	const entry = (value as Record<string, unknown>)[key]
	return typeof entry === 'number' && Number.isFinite(entry) ? entry : null
}

async function readClaim(claimsDir: string, claimHash: string): Promise<ClaimReport> {
	const dir = join(claimsDir, claimHash)
	const report: ClaimReport = {
		claimHash,
		state: 'unreadable',
		startedAt: null,
		completedAt: null,
		resolvedAt: null,
		outcome: null,
	}

	const resolution = await readJsonNoFollow(join(dir, RESOLUTION_FILE))
	if (resolution.kind === 'ok') {
		const outcome = (resolution.value as Record<string, unknown>).outcome
		report.state = 'resolved'
		report.resolvedAt = timestampOf(resolution.value, 'resolvedAt')
		report.outcome =
			outcome === 'confirmed-completed' || outcome === 'confirmed-not-executed' ? outcome : null
	}

	const completed = await readJsonNoFollow(join(dir, COMPLETED_FILE))
	if (completed.kind === 'ok' && parseCompletedRecord(completed.value)) {
		if (report.state !== 'resolved') report.state = 'completed'
		report.startedAt = timestampOf(completed.value, 'startedAt')
		report.completedAt = timestampOf(completed.value, 'completedAt')
		return report
	}

	const pending = await readJsonNoFollow(join(dir, PENDING_FILE))
	if (pending.kind === 'ok' && parsePendingRecord(pending.value)) {
		if (report.state !== 'resolved') report.state = 'pending'
		report.startedAt = timestampOf(pending.value, 'startedAt')
	}
	return report
}

/**
 * Report every claim and entity lock, as hashes, timestamps and states.
 *
 * Nothing here reads an order id, an idempotency key or a payment figure — the ledger never
 * stored them, and this command must be safe to paste into a ticket.
 */
export async function inspectGuardState(stateDir: string): Promise<GuardStateReport> {
	const entitiesDir = join(stateDir, 'entities')
	const claimsDir = join(stateDir, 'claims')

	const entityNames = (await readdir(entitiesDir).catch(() => [] as string[])).filter(isHexName)
	const claimNames = (await readdir(claimsDir).catch(() => [] as string[])).filter(isHexName)

	const entities: EntityReport[] = []
	for (const entityHash of entityNames.sort()) {
		const owner = await readJsonNoFollow(join(entitiesDir, entityHash, OWNER_FILE))
		entities.push({
			entityHash,
			acquiredAt: owner.kind === 'ok' ? timestampOf(owner.value, 'acquiredAt') : null,
			state: owner.kind === 'ok' ? 'locked' : 'unreadable',
		})
	}

	const claims: ClaimReport[] = []
	for (const claimHash of claimNames.sort()) {
		claims.push(await readClaim(claimsDir, claimHash))
	}

	return { stateDir, entities, claims }
}

function assertHash(value: string, field: string): string {
	if (!isHexName(value)) {
		throw new LedgerError('STATE_UNSAFE', `--${field} must be a SHA-256 hex digest`)
	}
	return value
}

function assertEvidence(reference: string): string {
	const trimmed = reference.trim()
	if (trimmed === '') {
		throw new LedgerError(
			'INVALID_RESULT',
			'--evidence-reference is required: record the provider reference or ticket that proves the outcome.',
		)
	}
	if (trimmed.length > MAX_EVIDENCE_LENGTH || !EVIDENCE_SHAPE.test(trimmed)) {
		throw new LedgerError(
			'INVALID_RESULT',
			'--evidence-reference must be a short non-secret reference; raw ids, keys and payment data are refused.',
		)
	}
	return trimmed
}

/**
 * Close an ambiguous claim with operator evidence.
 *
 * `confirmed-completed` leaves the pending record in place: the action did happen, so that key
 * must never execute again, and the ledger has no verified result to replay. The resolution
 * record is the preserved outcome, and it is deliberately not a `completed.json`.
 *
 * `confirmed-not-executed` archives the pending record so a fresh preview and a new key may
 * proceed. Both outcomes release the entity lock, which is what unsticks the order.
 */
export async function resolveGuardClaim(request: ResolveRequest): Promise<ClaimReport> {
	if (request.invocation !== 'local-cli') {
		throw new LedgerError(
			'STATE_UNSAFE',
			'guard-state resolve runs only from a local shell with filesystem access; it is not reachable over HTTP or MCP.',
		)
	}

	const entityHash = assertHash(request.entityHash, 'entity-hash')
	const claimHash = assertHash(request.claimHash, 'claim-hash')
	const evidenceReference = assertEvidence(request.evidenceReference)
	const now = request.now ?? Date.now

	const claimsDir = join(request.stateDir, 'claims')
	const claimDir = join(claimsDir, claimHash)

	// Checked before the pending record: `confirmed-not-executed` archives that record, so a
	// second attempt would otherwise be reported as an unknown claim rather than a repeat.
	if ((await readJsonNoFollow(join(claimDir, RESOLUTION_FILE))).kind !== 'missing') {
		throw new LedgerError('CLAIM_EXISTS', 'That claim is already resolved; resolutions are final.')
	}

	const pending = await readJsonNoFollow(join(claimDir, PENDING_FILE))
	const pendingRecord = pending.kind === 'ok' ? parsePendingRecord(pending.value) : undefined
	if (!pendingRecord) {
		throw new LedgerError(
			'UNKNOWN_CLAIM',
			'That claim has no pending record to resolve. Only an interrupted action can be resolved.',
		)
	}
	if (pendingRecord.entityHash !== entityHash || pendingRecord.claimHash !== claimHash) {
		throw new LedgerError(
			'CLAIM_MISMATCH',
			'The pending claim does not belong to the supplied entity and claim hashes.',
		)
	}

	const record = {
		version: 1,
		state: 'resolved',
		entityHash,
		claimHash,
		outcome: request.outcome,
		evidenceReference,
		resolvedAt: now(),
	}

	// Exclusive create: a resolution is written once and never revised, so a second attempt is
	// refused rather than silently overwriting the first operator's account of what happened.
	const written = await writeFileExclusive(
		join(claimDir, RESOLUTION_FILE),
		`${JSON.stringify(record, null, 2)}\n`,
	)
	if (!written) {
		throw new LedgerError('CLAIM_EXISTS', 'That claim is already resolved; resolutions are final.')
	}

	if (request.outcome === 'confirmed-not-executed') {
		await rename(join(claimDir, PENDING_FILE), join(claimDir, ARCHIVED_PENDING_FILE))
	}
	await syncDirectory(claimDir)

	// Releasing the entity is the point: the order becomes actionable again.
	await removeQuietly(join(request.stateDir, 'entities', entityHash))
	await syncDirectory(join(request.stateDir, 'entities'))

	return readClaim(claimsDir, claimHash)
}

export function formatGuardStateReport(report: GuardStateReport): string {
	const lines = [`guard state: ${report.stateDir}`, '']

	lines.push(`entity locks (${report.entities.length}):`)
	if (report.entities.length === 0) lines.push('  none')
	for (const entity of report.entities) {
		lines.push(`  ${entity.entityHash}  ${entity.state}  acquired ${iso(entity.acquiredAt)}`)
	}

	lines.push('', `claims (${report.claims.length}):`)
	if (report.claims.length === 0) lines.push('  none')
	for (const claim of report.claims) {
		const outcome = claim.outcome ? `  ${claim.outcome}` : ''
		lines.push(
			`  ${claim.claimHash}  ${claim.state}  started ${iso(claim.startedAt)}  completed ${iso(claim.completedAt)}  resolved ${iso(claim.resolvedAt)}${outcome}`,
		)
	}

	const stuck = report.claims.filter((claim) => claim.state === 'pending')
	if (stuck.length > 0) {
		lines.push(
			'',
			`${stuck.length} claim(s) are pending. Each holds its entity ambiguous until resolved with`,
			'payment-provider evidence: fluentcart-mcp guard-state resolve --entity-hash <sha256>',
			'--claim-hash <sha256> --outcome confirmed-completed|confirmed-not-executed',
			'--evidence-reference <reference>',
		)
	}
	return `${lines.join('\n')}\n`
}

function iso(value: number | null): string {
	return value === null ? '-' : new Date(value).toISOString()
}

export { ARCHIVED_PENDING_FILE, RESOLUTION_FILE }
