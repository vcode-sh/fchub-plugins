import { randomUUID as nodeRandomUUID } from 'node:crypto'
import { accessSync, chmodSync, constants, lstatSync, mkdirSync } from 'node:fs'
import { isAbsolute } from 'node:path'
import { MIN_SECRET_BYTES } from './confirmation-token.js'
import {
	createFileLedger,
	type FileLedgerOptions,
	type IdempotencyLedger,
} from './idempotency-ledger.js'
import { DIR_MODE } from './ledger-store.js'
import type { GuardAvailability } from './write-policy.js'

/**
 * Startup construction of the guard.
 *
 * Called once per process, before any tool is registered. Nothing here may run inside a request
 * handler: a guard built per request would hold no in-memory entity locks, so two concurrent
 * refunds on one order would each believe they were alone.
 *
 * Half a guard is worse than none, because it looks like protection. So a partial or invalid
 * configuration throws rather than degrading, and only a completely absent configuration
 * returns `null` — the honest "guarded mode was not asked for" answer.
 */

export const GUARD_SECRET_ENV = 'FLUENTCART_GUARD_SECRET'
export const GUARD_STATE_DIR_ENV = 'FLUENTCART_GUARD_STATE_DIR'
export const GUARD_LIVE_ACTIONS_ENV = 'FLUENTCART_ALLOW_LIVE_GATEWAY_ACTIONS'

/** The one accepted opt-in value. Not `true`, not `1`, not `on`. */
export const GUARD_LIVE_ACTIONS_OPT_IN = 'yes'

type Environment = Record<string, string | undefined>

export interface GuardRuntime {
	secret: Uint8Array
	ledger: IdempotencyLedger
	/** Whether a live-mode gateway action may execute. Separate from guarded mode, default off. */
	allowLiveGatewayActions: boolean
	now(): number
	randomUUID(): string
}

export interface GuardRuntimeInput {
	env?: Environment
	/** Test seam for a deterministic clock. Epoch milliseconds. */
	now?: () => number
	/** Test seam for a deterministic identifier source. */
	randomUUID?: () => string
	/** Test seam for an alternative ledger over a temporary directory. */
	createLedger?: (options: FileLedgerOptions) => IdempotencyLedger
}

export function createGuardRuntime(config: GuardRuntimeInput = {}): GuardRuntime | null {
	const env = config.env ?? process.env
	const rawSecret = env[GUARD_SECRET_ENV]
	const rawStateDir = env[GUARD_STATE_DIR_ENV]

	if (!(rawSecret || rawStateDir)) return null

	const secret = readSecret(rawSecret)
	const stateDir = prepareStateDir(rawStateDir)
	const now = config.now ?? Date.now
	const randomUUID = config.randomUUID ?? (() => nodeRandomUUID())
	const createLedger = config.createLedger ?? createFileLedger

	return {
		secret,
		ledger: createLedger({ stateDir, now, randomUUID }),
		allowLiveGatewayActions: readLiveOptIn(env[GUARD_LIVE_ACTIONS_ENV]),
		now,
		randomUUID,
	}
}

/**
 * Report what the guard could do without building or creating anything.
 *
 * The exposure policy asks this before deciding whether a money tool may appear at all, and a
 * policy question must never have the side effect of creating a state directory.
 */
export function inspectGuardAvailability(env: Environment = process.env): GuardAvailability {
	return {
		persistentState: isUsableStateDir(env[GUARD_STATE_DIR_ENV]),
		signingSecret: isUsableSecret(env[GUARD_SECRET_ENV]),
	}
}

function isUsableSecret(value: string | undefined): boolean {
	return value !== undefined && secretProblem(value) === undefined
}

function secretProblem(value: string): string | undefined {
	if (value.trim() !== value) {
		return `${GUARD_SECRET_ENV} has leading or trailing whitespace; the value would differ between environments`
	}
	if (Buffer.byteLength(value, 'utf8') < MIN_SECRET_BYTES) {
		return `${GUARD_SECRET_ENV} must be at least ${MIN_SECRET_BYTES} bytes; generate one with "openssl rand -hex 32"`
	}
	return undefined
}

function readSecret(value: string | undefined): Uint8Array {
	if (!value) {
		throw new Error(
			`${GUARD_SECRET_ENV} is required for guarded mode and is never generated for you. ` +
				'A generated secret would be lost on restart, invalidating every outstanding preview.',
		)
	}
	const problem = secretProblem(value)
	if (problem) throw new Error(problem)
	return new Uint8Array(Buffer.from(value, 'utf8'))
}

function isUsableStateDir(value: string | undefined): boolean {
	if (!(value && isAbsolute(value))) return false
	try {
		const info = lstatSync(value)
		if (info.isSymbolicLink() || !info.isDirectory()) return false
		accessSync(value, constants.W_OK | constants.X_OK)
		return true
	} catch {
		// Not yet created is still usable; createGuardRuntime will make it and fail loudly if not.
		return !existsQuietly(value)
	}
}

function existsQuietly(path: string): boolean {
	try {
		lstatSync(path)
		return true
	} catch {
		return false
	}
}

function prepareStateDir(value: string | undefined): string {
	if (!value) {
		throw new Error(
			`${GUARD_STATE_DIR_ENV} is required for guarded mode. It must be a persistent POSIX ` +
				'directory: an ephemeral path loses the record of whether a refund already happened.',
		)
	}
	if (!isAbsolute(value)) {
		throw new Error(`${GUARD_STATE_DIR_ENV} must be an absolute path, not "${value}"`)
	}

	assertDirectoryShape(value)
	try {
		mkdirSync(value, { recursive: true, mode: DIR_MODE })
		chmodSync(value, DIR_MODE)
		accessSync(value, constants.W_OK | constants.X_OK)
	} catch (error) {
		throw new Error(
			`${GUARD_STATE_DIR_ENV} is not a usable owner-only directory: ${(error as Error).message}`,
		)
	}
	return value
}

function assertDirectoryShape(value: string): void {
	let info: ReturnType<typeof lstatSync>
	try {
		info = lstatSync(value)
	} catch {
		return
	}
	if (info.isSymbolicLink()) {
		throw new Error(`${GUARD_STATE_DIR_ENV} must not be a symlink: ${value}`)
	}
	if (!info.isDirectory()) {
		throw new Error(`${GUARD_STATE_DIR_ENV} must be a directory: ${value}`)
	}
}

function readLiveOptIn(value: string | undefined): boolean {
	if (value === undefined || value === '' || value === 'no') return false
	if (value === GUARD_LIVE_ACTIONS_OPT_IN) return true
	// A typo in a security switch is a misconfiguration, not a silent "off".
	throw new Error(
		`${GUARD_LIVE_ACTIONS_ENV} accepts only "${GUARD_LIVE_ACTIONS_OPT_IN}" or "no", not "${value}"`,
	)
}
