import { constants } from 'node:fs'
import { chmod, lstat, mkdir, open, rename, rm } from 'node:fs/promises'
import { join } from 'node:path'

/**
 * Filesystem primitives for the idempotency ledger.
 *
 * Every write here is either exclusive-create or same-directory rename, and every read refuses
 * to follow a symlink. The ledger is the only record of whether real money moved, so a record
 * must never be silently replaced, partially observed, or redirected outside the state
 * directory by something planted in it.
 */

export const DIR_MODE = 0o700
export const FILE_MODE = 0o600

const HEX_NAME = /^[0-9a-f]{64}$/

export type LedgerErrorCode =
	| 'STATE_UNSAFE'
	| 'STATE_UNWRITABLE'
	| 'UNKNOWN_LOCK'
	| 'UNKNOWN_CLAIM'
	| 'SCOPE_MISMATCH'
	| 'CLAIM_EXISTS'
	| 'MUTATION_UNRESOLVED'
	| 'OUTCOME_MISMATCH'
	| 'INVALID_RESULT'
	| 'RETENTION_TOO_SHORT'

export class LedgerError extends Error {
	readonly code: LedgerErrorCode

	constructor(code: LedgerErrorCode, message: string) {
		super(message)
		this.name = 'LedgerError'
		this.code = code
	}
}

export type JsonRead = { kind: 'missing' } | { kind: 'corrupt' } | { kind: 'ok'; value: unknown }

function errnoOf(error: unknown): string | undefined {
	return typeof error === 'object' && error !== null && 'code' in error
		? String((error as { code: unknown }).code)
		: undefined
}

/** Anything that is not "the record simply is not there" is an unwritable-state failure. */
function asUnwritable(error: unknown, path: string): LedgerError {
	return new LedgerError(
		'STATE_UNWRITABLE',
		`Guard state directory is not writable (${errnoOf(error) ?? 'unknown'}): ${path}`,
	)
}

export function isHexName(name: string): boolean {
	return HEX_NAME.test(name)
}

/** Ledger path components are always SHA-256 hex, so traversal cannot survive this check. */
export function assertHexName(name: string): void {
	if (!isHexName(name)) {
		throw new LedgerError('STATE_UNSAFE', 'Ledger path component is not a SHA-256 digest')
	}
}

export function hexChild(parent: string, name: string): string {
	assertHexName(name)
	return join(parent, name)
}

/**
 * Create `path` as an owner-only directory, repairing the mode of an existing one.
 *
 * A state directory readable by other local users would expose which entities have pending
 * money actions, so a loose mode is corrected rather than tolerated.
 */
export async function ensureOwnerOnlyDir(path: string): Promise<void> {
	const info = await lstat(path).catch(() => undefined)

	if (info) {
		if (info.isSymbolicLink()) {
			throw new LedgerError('STATE_UNSAFE', `Guard state path is a symlink: ${path}`)
		}
		if (!info.isDirectory()) {
			throw new LedgerError('STATE_UNSAFE', `Guard state path is not a directory: ${path}`)
		}
		if ((info.mode & 0o077) !== 0) {
			await chmod(path, DIR_MODE).catch((error: unknown) => {
				throw asUnwritable(error, path)
			})
		}
		return
	}

	try {
		await mkdir(path, { recursive: true, mode: DIR_MODE })
		await chmod(path, DIR_MODE)
	} catch (error) {
		throw asUnwritable(error, path)
	}
}

/**
 * Atomic exclusive directory creation — the entity lock.
 *
 * `mkdir` without `recursive` is the one filesystem operation that both creates and tests for
 * existence in a single indivisible step, which is what makes two concurrent callers unable to
 * believe they each hold the same entity.
 */
export async function createDirExclusive(path: string): Promise<boolean> {
	try {
		await mkdir(path, { mode: DIR_MODE })
		await chmod(path, DIR_MODE)
		return true
	} catch (error) {
		if (errnoOf(error) === 'EEXIST') return false
		throw asUnwritable(error, path)
	}
}

/**
 * Write a file that must not already exist.
 *
 * `O_EXCL` fails on an existing file *and* on an existing symlink, so a planted link cannot
 * redirect the write. Records are a few hundred bytes and written in one call, so a crash
 * leaves either nothing or a short file that later reads as corrupt — never a plausible lie.
 */
export async function writeFileExclusive(path: string, text: string): Promise<boolean> {
	const flags = constants.O_WRONLY | constants.O_CREAT | constants.O_EXCL | constants.O_NOFOLLOW
	let handle: Awaited<ReturnType<typeof open>>
	try {
		handle = await open(path, flags, FILE_MODE)
	} catch (error) {
		if (errnoOf(error) === 'EEXIST') return false
		throw asUnwritable(error, path)
	}

	try {
		await handle.writeFile(text, 'utf8')
		await handle.chmod(FILE_MODE)
		await handle.sync()
	} finally {
		await handle.close()
	}
	return true
}

/**
 * Publish a record by renaming a temporary file created in the same directory.
 *
 * The temporary file is fsynced before the rename, so the name never appears until the bytes
 * behind it are durable. A reader therefore sees the record complete or not at all.
 */
export async function writeFileAtomic(
	dir: string,
	name: string,
	unique: string,
	text: string,
): Promise<void> {
	const temp = join(dir, `.${name}.${unique}.tmp`)
	const created = await writeFileExclusive(temp, text)
	if (!created) {
		throw new LedgerError('STATE_UNSAFE', `Ledger temporary file already exists: ${temp}`)
	}

	try {
		await rename(temp, join(dir, name))
	} catch (error) {
		throw asUnwritable(error, dir)
	}
	await syncDirectory(dir)
}

/**
 * Flush a directory entry so a rename or exclusive create survives power loss.
 *
 * Not every platform permits fsync on a directory descriptor; where it is refused the record
 * itself is already fsynced, so the failure is logged nowhere and ignored deliberately.
 */
export async function syncDirectory(path: string): Promise<void> {
	try {
		const handle = await open(path, constants.O_RDONLY)
		try {
			await handle.sync()
		} finally {
			await handle.close()
		}
	} catch {
		// Best effort: durability of the file contents does not depend on this.
	}
}

/** Remove a path that may not exist. Used for entity locks and superseded pending records. */
export async function removeQuietly(path: string): Promise<void> {
	await rm(path, { force: true, recursive: true })
}

/**
 * Read a JSON record without following symlinks.
 *
 * Corrupt and symlinked records are reported as `corrupt` rather than thrown, because the
 * ledger's answer to "I cannot tell what happened here" is ambiguity, not failure.
 */
export async function readJsonNoFollow(path: string): Promise<JsonRead> {
	let handle: Awaited<ReturnType<typeof open>>
	try {
		handle = await open(path, constants.O_RDONLY | constants.O_NOFOLLOW)
	} catch (error) {
		const code = errnoOf(error)
		if (code === 'ENOENT') return { kind: 'missing' }
		if (code === 'ELOOP' || code === 'EMLINK') return { kind: 'corrupt' }
		throw asUnwritable(error, path)
	}

	try {
		const info = await handle.stat()
		if (!info.isFile()) return { kind: 'corrupt' }
		const raw = await handle.readFile('utf8')
		return { kind: 'ok', value: JSON.parse(raw) as unknown }
	} catch {
		return { kind: 'corrupt' }
	} finally {
		await handle.close()
	}
}
