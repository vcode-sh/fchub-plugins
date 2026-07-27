import { readdir } from 'node:fs/promises'
import { join } from 'node:path'
import { COMPLETED_FILE, PENDING_FILE, parseCompletedRecord } from './ledger-records.js'
import { isHexName, LedgerError, readJsonNoFollow, removeQuietly } from './ledger-store.js'

/**
 * Operator maintenance for the idempotency ledger.
 *
 * Deliberately a separate module with no importer inside the execution path, so "records are
 * never purged during execution" is a fact about the import graph rather than a promise in a
 * comment.
 */

/** Completed records may be purged only after this long, and only on explicit request. */
export const COMPLETED_RETENTION_MS = 30 * 24 * 60 * 60 * 1000

export interface PurgeOptions {
	/** Never below `COMPLETED_RETENTION_MS`. */
	olderThanMs?: number
	/** Epoch milliseconds. */
	now?: number
}

/**
 * Remove completed claim records older than the retention window.
 *
 * Never touches a pending record or an entity lock: those are the evidence an operator needs to
 * reconcile an interrupted action against the payment provider, and deleting them would destroy
 * the only local account of what happened.
 */
export async function purgeCompletedRecords(
	stateDir: string,
	options: PurgeOptions = {},
): Promise<number> {
	const olderThanMs = options.olderThanMs ?? COMPLETED_RETENTION_MS
	if (olderThanMs < COMPLETED_RETENTION_MS) {
		throw new LedgerError('RETENTION_TOO_SHORT', 'Completed records are retained for 30 days')
	}

	const cutoff = (options.now ?? Date.now()) - olderThanMs
	const claimsDir = join(stateDir, 'claims')
	const names = await readdir(claimsDir).catch(() => [] as string[])

	let purged = 0
	for (const name of names) {
		if (!isHexName(name)) continue
		const dir = join(claimsDir, name)
		if ((await readJsonNoFollow(join(dir, PENDING_FILE))).kind !== 'missing') continue

		const read = await readJsonNoFollow(join(dir, COMPLETED_FILE))
		const record = read.kind === 'ok' ? parseCompletedRecord(read.value) : undefined
		if (!record || record.completedAt > cutoff) continue

		await removeQuietly(dir)
		purged += 1
	}
	return purged
}
