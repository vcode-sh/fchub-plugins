import { join } from 'node:path'
import { canonicalJson } from './encoding.js'
import {
	assertStorableResult,
	COMPLETED_FILE,
	type CompletedRecord,
	type GuardedResult,
	hashClaimKey,
	hashEntityScope,
	inspectClaimDir,
	LEDGER_RECORD_VERSION,
	type LedgerInspection,
	OWNER_FILE,
	PENDING_FILE,
	type PendingRecord,
} from './ledger-records.js'
import {
	createDirExclusive,
	ensureOwnerOnlyDir,
	hexChild,
	LedgerError,
	readJsonNoFollow,
	removeQuietly,
	syncDirectory,
	writeFileAtomic,
	writeFileExclusive,
} from './ledger-store.js'

export type { GuardedResult, GuardedResultValue, LedgerInspection } from './ledger-records.js'
export { hashClaimKey, hashEntityScope } from './ledger-records.js'
export { LedgerError, type LedgerErrorCode } from './ledger-store.js'

/**
 * Durable single-writer protection for actions that move real money.
 *
 * Two independent facts are recorded. A *claim* answers "has this exact request already been
 * executed?" and is named after a hash of the scope and the caller's idempotency key. An
 * *entity lock* answers "is anybody else acting on this order right now?" and is named after
 * the scope alone, so two different idempotency keys cannot both refund the same order.
 *
 * The protocol is deliberately pessimistic. If a process dies mid-refund it leaves pending
 * evidence and a held entity lock, and every later action on that entity is `ambiguous` until a
 * human resolves it against the payment provider. Nothing expires, nothing retries. An
 * automatic retry that turns out to be a second refund costs real money; an ambiguous answer
 * costs an operator five minutes.
 */

export type LockAcquisition =
	| { kind: 'locked'; lockId: string }
	| { kind: 'in-progress' }
	| { kind: 'ambiguous' }

export type ReleaseOutcome = 'not-started' | 'completed'

export interface IdempotencyLedger {
	inspect(scope: string, key: string, operationDigest: string): Promise<LedgerInspection>
	lockEntity(scope: string): Promise<LockAcquisition>
	begin(
		lockId: string,
		scope: string,
		key: string,
		operationDigest: string,
	): Promise<{ claimId: string }>
	complete(claimId: string, result: GuardedResult): Promise<void>
	releaseEntity(lockId: string, outcome: ReleaseOutcome): Promise<void>
}

export interface FileLedgerOptions {
	/** Persistent, owner-only POSIX directory. Never an ephemeral path in production. */
	stateDir: string
	/** Epoch milliseconds. */
	now(): number
	randomUUID(): string
}

interface HeldLock {
	lockId: string
	entityHash: string
	dir: string
	started: number
	completed: number
	/** Set when a mutation under this lock never reached a durable completed record. */
	poisoned: boolean
}

interface OpenClaim {
	claimId: string
	claimHash: string
	dir: string
	lock: HeldLock
	operationDigest: string
	startedAt: number
	completed: boolean
}

export function createFileLedger(options: FileLedgerOptions): IdempotencyLedger {
	const { stateDir, now, randomUUID } = options
	const entitiesDir = join(stateDir, 'entities')
	const claimsDir = join(stateDir, 'claims')
	const locksByEntity = new Map<string, HeldLock>()
	const locksById = new Map<string, HeldLock>()
	const claims = new Map<string, OpenClaim>()

	async function ensureLayout(): Promise<void> {
		await ensureOwnerOnlyDir(stateDir)
		await ensureOwnerOnlyDir(entitiesDir)
		await ensureOwnerOnlyDir(claimsDir)
	}

	function requireLock(lockId: string): HeldLock {
		const lock = locksById.get(lockId)
		if (!lock) throw new LedgerError('UNKNOWN_LOCK', 'No entity lock is held for this identifier')
		return lock
	}

	async function acquire(entityHash: string): Promise<LockAcquisition> {
		await ensureLayout()
		const dir = hexChild(entitiesDir, entityHash)
		// A lock we did not create belongs to a crashed run or another process; either way we
		// cannot know whether its mutation reached the gateway.
		if (!(await createDirExclusive(dir))) return { kind: 'ambiguous' }

		const lockId = randomUUID()
		const owner = { version: LEDGER_RECORD_VERSION, lockId, acquiredAt: now() }
		if (!(await writeFileExclusive(join(dir, OWNER_FILE), canonicalJson(owner)))) {
			throw new LedgerError('STATE_UNSAFE', 'Entity lock already contains an owner record')
		}
		await syncDirectory(dir)

		const lock: HeldLock = { lockId, entityHash, dir, started: 0, completed: 0, poisoned: false }
		locksByEntity.set(entityHash, lock)
		locksById.set(lockId, lock)
		return { kind: 'locked', lockId }
	}

	async function openClaim(lock: HeldLock, scope: string, key: string, digest: string) {
		if (lock.poisoned) {
			throw new LedgerError('MUTATION_UNRESOLVED', 'This entity lock is awaiting resolution')
		}
		if (lock.entityHash !== hashEntityScope(scope)) {
			throw new LedgerError('SCOPE_MISMATCH', 'The held entity lock does not cover this scope')
		}

		const claimHash = hashClaimKey(scope, key)
		const dir = hexChild(claimsDir, claimHash)
		await ensureOwnerOnlyDir(dir)
		if ((await readJsonNoFollow(join(dir, COMPLETED_FILE))).kind !== 'missing') {
			throw new LedgerError('CLAIM_EXISTS', 'This idempotency key already has a completed record')
		}

		const claim: OpenClaim = {
			claimId: randomUUID(),
			claimHash,
			dir,
			lock,
			operationDigest: digest,
			startedAt: now(),
			completed: false,
		}
		const record: PendingRecord = {
			version: LEDGER_RECORD_VERSION,
			state: 'pending',
			claimId: claim.claimId,
			entityHash: lock.entityHash,
			claimHash,
			operationDigest: digest,
			startedAt: claim.startedAt,
		}
		// Exclusive create is the second half of single-writer: even a caller that skipped the
		// entity lock cannot open a second claim on the same key.
		if (!(await writeFileExclusive(join(dir, PENDING_FILE), canonicalJson(record)))) {
			throw new LedgerError('CLAIM_EXISTS', 'This idempotency key already has a pending record')
		}
		await syncDirectory(dir)
		return claim
	}

	return {
		async inspect(scope, key, operationDigest) {
			return inspectClaimDir(hexChild(claimsDir, hashClaimKey(scope, key)), operationDigest)
		},

		async lockEntity(scope) {
			const entityHash = hashEntityScope(scope)
			const held = locksByEntity.get(entityHash)
			if (!held) return acquire(entityHash)
			// An in-flight mutation may still land, so a second caller gets no promise either way.
			if (held.poisoned || held.started > held.completed) return { kind: 'ambiguous' }
			return { kind: 'in-progress' }
		},

		async begin(lockId, scope, key, operationDigest) {
			const lock = requireLock(lockId)
			const claim = await openClaim(lock, scope, key, operationDigest)
			lock.started += 1
			claims.set(claim.claimId, claim)
			return { claimId: claim.claimId }
		},

		async complete(claimId, result) {
			const claim = claims.get(claimId)
			if (!claim) throw new LedgerError('UNKNOWN_CLAIM', 'No open claim for this identifier')
			if (claim.completed) {
				throw new LedgerError('CLAIM_EXISTS', 'This claim already has a completed record')
			}

			const record: CompletedRecord = {
				version: LEDGER_RECORD_VERSION,
				state: 'completed',
				claimId: claim.claimId,
				entityHash: claim.lock.entityHash,
				claimHash: claim.claimHash,
				operationDigest: claim.operationDigest,
				startedAt: claim.startedAt,
				completedAt: now(),
				result: assertStorableResult(result, claim.operationDigest),
			}
			await writeFileAtomic(claim.dir, COMPLETED_FILE, claim.claimId, canonicalJson(record))
			await removeQuietly(join(claim.dir, PENDING_FILE))
			await syncDirectory(claim.dir)

			claim.completed = true
			claim.lock.completed += 1
		},

		async releaseEntity(lockId, outcome) {
			const lock = requireLock(lockId)
			if (lock.poisoned || lock.started > lock.completed) {
				lock.poisoned = true
				throw new LedgerError(
					'MUTATION_UNRESOLVED',
					'A mutation began under this entity lock with no durable completed record; the lock is retained for operator resolution',
				)
			}
			if (outcome === 'not-started' && lock.started > 0) {
				throw new LedgerError(
					'OUTCOME_MISMATCH',
					'Cannot release as not-started: a mutation began under this lock',
				)
			}

			await removeQuietly(lock.dir)
			await syncDirectory(entitiesDir)
			locksByEntity.delete(lock.entityHash)
			locksById.delete(lock.lockId)
		},
	}
}
