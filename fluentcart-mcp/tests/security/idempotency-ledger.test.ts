import {
	chmod,
	mkdir,
	mkdtemp,
	readdir,
	readFile,
	rm,
	stat,
	symlink,
	writeFile,
} from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join, relative } from 'node:path'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { createGuardRuntime, inspectGuardAvailability } from '../../src/security/guard-config.js'
import {
	createFileLedger,
	type GuardedResult,
	hashClaimKey,
	hashEntityScope,
	type IdempotencyLedger,
} from '../../src/security/idempotency-ledger.js'
import {
	COMPLETED_RETENTION_MS,
	purgeCompletedRecords,
} from '../../src/security/ledger-maintenance.js'

const TOOL = 'fluentcart_order_refund'
const SCOPE = `${TOOL}:order:42`
const OTHER_SCOPE = `${TOOL}:order:43`
const KEY = 'idempotency-key-must-never-be-stored-8f21'
const DIGEST = 'a'.repeat(64)
const OTHER_DIGEST = 'b'.repeat(64)
const BASE_TIME = 1_700_000_000_000

let stateDir: string
let instances = 0

function makeLedger(clock = { value: BASE_TIME }): {
	ledger: IdempotencyLedger
	clock: { value: number }
} {
	const prefix = `run${++instances}`
	let counter = 0
	const ledger = createFileLedger({
		stateDir,
		now: () => clock.value,
		randomUUID: () => `${prefix}-${++counter}`,
	})
	return { ledger, clock }
}

function guardedResult(
	digest = DIGEST,
	summary: Record<string, string | number> = {},
): GuardedResult {
	return {
		tool: TOOL,
		entity: 'order:42',
		status: 'succeeded',
		operationDigest: digest,
		completedAt: BASE_TIME,
		summary: { refund_transaction_id: 907, amount_minor: 4000, ...summary },
	}
}

async function runToCompletion(
	ledger: IdempotencyLedger,
	key = KEY,
	digest = DIGEST,
): Promise<void> {
	const lock = await ledger.lockEntity(SCOPE)
	if (lock.kind !== 'locked') throw new Error(`expected a lock, got ${lock.kind}`)
	const { claimId } = await ledger.begin(lock.lockId, SCOPE, key, digest)
	await ledger.complete(claimId, guardedResult(digest))
	await ledger.releaseEntity(lock.lockId, 'completed')
}

async function walk(dir: string): Promise<string[]> {
	const found: string[] = []
	for (const entry of await readdir(dir, { withFileTypes: true })) {
		const path = join(dir, entry.name)
		found.push(path)
		if (entry.isDirectory()) found.push(...(await walk(path)))
	}
	return found
}

async function modeOf(path: string): Promise<number> {
	return (await stat(path)).mode & 0o777
}

beforeEach(async () => {
	stateDir = await mkdtemp(join(tmpdir(), 'fluentcart-ledger-'))
})

afterEach(async () => {
	await chmod(stateDir, 0o700).catch(() => undefined)
	await chmod(join(stateDir, 'entities'), 0o700).catch(() => undefined)
	await rm(stateDir, { recursive: true, force: true })
})

describe('inspection before mutation', () => {
	it('reports nothing for an unused key', async () => {
		const { ledger } = makeLedger()
		expect(await ledger.inspect(SCOPE, KEY, DIGEST)).toEqual({ kind: 'none' })
		// Inspection must not create state; a preview is not a claim.
		expect(await readdir(stateDir)).toEqual([])
	})

	it('reports nothing while an entity lock is held but no claim has opened', async () => {
		const { ledger } = makeLedger()
		await ledger.lockEntity(SCOPE)
		expect(await ledger.inspect(SCOPE, KEY, DIGEST)).toEqual({ kind: 'none' })
	})
})

describe('single execution and replay', () => {
	it('records one completed claim and replays it verbatim', async () => {
		const { ledger } = makeLedger()
		await runToCompletion(ledger)

		const inspection = await ledger.inspect(SCOPE, KEY, DIGEST)
		expect(inspection).toEqual({ kind: 'replay', result: guardedResult() })
	})

	it('replays through a new ledger instance over the same directory', async () => {
		await runToCompletion(makeLedger().ledger)

		const restarted = makeLedger().ledger
		expect(await restarted.inspect(SCOPE, KEY, DIGEST)).toEqual({
			kind: 'replay',
			result: guardedResult(),
		})
	})

	it('frees the entity once the completed record is durable', async () => {
		const { ledger } = makeLedger()
		await runToCompletion(ledger)

		expect(await readdir(join(stateDir, 'entities'))).toEqual([])
		expect(await ledger.lockEntity(SCOPE)).toMatchObject({ kind: 'locked' })
	})

	it('refuses a second claim on the same key', async () => {
		const { ledger } = makeLedger()
		const lock = await ledger.lockEntity(SCOPE)
		if (lock.kind !== 'locked') throw new Error('expected a lock')

		await ledger.begin(lock.lockId, SCOPE, KEY, DIGEST)
		await expect(ledger.begin(lock.lockId, SCOPE, KEY, DIGEST)).rejects.toMatchObject({
			code: 'CLAIM_EXISTS',
		})
	})

	it('refuses a claim under an unknown or foreign lock', async () => {
		const { ledger } = makeLedger()
		const lock = await ledger.lockEntity(SCOPE)
		if (lock.kind !== 'locked') throw new Error('expected a lock')

		await expect(ledger.begin('not-a-lock', SCOPE, KEY, DIGEST)).rejects.toMatchObject({
			code: 'UNKNOWN_LOCK',
		})
		await expect(ledger.begin(lock.lockId, OTHER_SCOPE, KEY, DIGEST)).rejects.toMatchObject({
			code: 'SCOPE_MISMATCH',
		})
	})
})

describe('conflicting reuse of a key', () => {
	it('reports a conflict when a completed key is reused for a different operation', async () => {
		const { ledger } = makeLedger()
		await runToCompletion(ledger)
		expect(await ledger.inspect(SCOPE, KEY, OTHER_DIGEST)).toEqual({ kind: 'conflict' })
	})

	it('reports a conflict when a pending key is reused for a different operation', async () => {
		const { ledger } = makeLedger()
		const lock = await ledger.lockEntity(SCOPE)
		if (lock.kind !== 'locked') throw new Error('expected a lock')
		await ledger.begin(lock.lockId, SCOPE, KEY, DIGEST)

		expect(await ledger.inspect(SCOPE, KEY, OTHER_DIGEST)).toEqual({ kind: 'conflict' })
	})

	it('rejects a result that does not describe the claimed operation', async () => {
		const { ledger } = makeLedger()
		const lock = await ledger.lockEntity(SCOPE)
		if (lock.kind !== 'locked') throw new Error('expected a lock')
		const { claimId } = await ledger.begin(lock.lockId, SCOPE, KEY, DIGEST)

		await expect(ledger.complete(claimId, guardedResult(OTHER_DIGEST))).rejects.toMatchObject({
			code: 'INVALID_RESULT',
		})
	})

	it('rejects a result summary containing credential-like text', async () => {
		const { ledger } = makeLedger()
		const lock = await ledger.lockEntity(SCOPE)
		if (lock.kind !== 'locked') throw new Error('expected a lock')
		const { claimId } = await ledger.begin(lock.lockId, SCOPE, KEY, DIGEST)

		const leaky = guardedResult(DIGEST, { note: 'Basic YWRtaW46c2VjcmV0' })
		await expect(ledger.complete(claimId, leaky)).rejects.toMatchObject({ code: 'INVALID_RESULT' })
	})
})

describe('concurrency on one entity', () => {
	it('lets exactly one of two racing processes take the entity', async () => {
		const first = makeLedger().ledger
		const second = makeLedger().ledger

		const results = await Promise.all([first.lockEntity(SCOPE), second.lockEntity(SCOPE)])
		expect(results.filter((entry) => entry.kind === 'locked')).toHaveLength(1)
		expect(results.filter((entry) => entry.kind === 'ambiguous')).toHaveLength(1)
	})

	it('lets exactly one of two racing claims on one key open', async () => {
		const { ledger } = makeLedger()
		const lock = await ledger.lockEntity(SCOPE)
		if (lock.kind !== 'locked') throw new Error('expected a lock')

		const outcomes = await Promise.allSettled([
			ledger.begin(lock.lockId, SCOPE, KEY, DIGEST),
			ledger.begin(lock.lockId, SCOPE, KEY, DIGEST),
		])
		expect(outcomes.filter((entry) => entry.status === 'fulfilled')).toHaveLength(1)
		expect(outcomes.filter((entry) => entry.status === 'rejected')).toHaveLength(1)
	})

	it('refuses a different key on an entity another call already holds', async () => {
		const { ledger } = makeLedger()
		await ledger.lockEntity(SCOPE)

		expect(await ledger.lockEntity(SCOPE)).toEqual({ kind: 'in-progress' })
	})

	it('refuses a different key once a mutation on that entity is in flight', async () => {
		const { ledger } = makeLedger()
		const lock = await ledger.lockEntity(SCOPE)
		if (lock.kind !== 'locked') throw new Error('expected a lock')
		await ledger.begin(lock.lockId, SCOPE, KEY, DIGEST)

		expect(await ledger.lockEntity(SCOPE)).toEqual({ kind: 'ambiguous' })
	})

	it('leaves an unrelated entity untouched', async () => {
		const { ledger } = makeLedger()
		await ledger.lockEntity(SCOPE)
		expect(await ledger.lockEntity(OTHER_SCOPE)).toMatchObject({ kind: 'locked' })
	})
})

describe('interrupted execution', () => {
	it('keeps returning ambiguous for an abandoned claim, with no expiry', async () => {
		const clock = { value: BASE_TIME }
		const { ledger } = makeLedger(clock)
		const lock = await ledger.lockEntity(SCOPE)
		if (lock.kind !== 'locked') throw new Error('expected a lock')
		await ledger.begin(lock.lockId, SCOPE, KEY, DIGEST)

		const restarted = makeLedger(clock).ledger
		expect(await restarted.inspect(SCOPE, KEY, DIGEST)).toEqual({ kind: 'ambiguous' })
		expect(await restarted.lockEntity(SCOPE)).toEqual({ kind: 'ambiguous' })

		clock.value += 60 * 24 * 60 * 60 * 1000
		const muchLater = makeLedger(clock).ledger
		expect(await muchLater.inspect(SCOPE, KEY, DIGEST)).toEqual({ kind: 'ambiguous' })
		expect(await muchLater.lockEntity(SCOPE)).toEqual({ kind: 'ambiguous' })
	})

	it('replays a completed claim even though the crash left the entity locked', async () => {
		const { ledger } = makeLedger()
		const lock = await ledger.lockEntity(SCOPE)
		if (lock.kind !== 'locked') throw new Error('expected a lock')
		const { claimId } = await ledger.begin(lock.lockId, SCOPE, KEY, DIGEST)
		await ledger.complete(claimId, guardedResult())
		// No releaseEntity: the process died between the durable record and the release.

		const restarted = makeLedger().ledger
		expect(await restarted.inspect(SCOPE, KEY, DIGEST)).toEqual({
			kind: 'replay',
			result: guardedResult(),
		})
		// A different key on that entity still cannot proceed until an operator clears the lock.
		expect(await restarted.lockEntity(SCOPE)).toEqual({ kind: 'ambiguous' })
	})

	it('retains the entity lock when a started mutation has no completed record', async () => {
		const { ledger } = makeLedger()
		const lock = await ledger.lockEntity(SCOPE)
		if (lock.kind !== 'locked') throw new Error('expected a lock')
		await ledger.begin(lock.lockId, SCOPE, KEY, DIGEST)

		await expect(ledger.releaseEntity(lock.lockId, 'completed')).rejects.toMatchObject({
			code: 'MUTATION_UNRESOLVED',
		})
		expect(await readdir(join(stateDir, 'entities'))).toHaveLength(1)
		expect(await ledger.lockEntity(SCOPE)).toEqual({ kind: 'ambiguous' })
	})

	it('refuses to release as not-started once a mutation began', async () => {
		const { ledger } = makeLedger()
		const lock = await ledger.lockEntity(SCOPE)
		if (lock.kind !== 'locked') throw new Error('expected a lock')
		const { claimId } = await ledger.begin(lock.lockId, SCOPE, KEY, DIGEST)
		await ledger.complete(claimId, guardedResult())

		await expect(ledger.releaseEntity(lock.lockId, 'not-started')).rejects.toMatchObject({
			code: 'OUTCOME_MISMATCH',
		})
	})

	it('releases cleanly when nothing was started', async () => {
		const { ledger } = makeLedger()
		const lock = await ledger.lockEntity(SCOPE)
		if (lock.kind !== 'locked') throw new Error('expected a lock')

		await ledger.releaseEntity(lock.lockId, 'not-started')
		expect(await readdir(join(stateDir, 'entities'))).toEqual([])
	})
})

describe('untrustworthy state', () => {
	it('treats a corrupt pending record as ambiguous', async () => {
		const dir = join(stateDir, 'claims', hashClaimKey(SCOPE, KEY))
		await mkdir(dir, { recursive: true, mode: 0o700 })
		await writeFile(join(dir, 'pending.json'), '{ this is not json', { mode: 0o600 })

		expect(await makeLedger().ledger.inspect(SCOPE, KEY, DIGEST)).toEqual({ kind: 'ambiguous' })
	})

	it('treats a completed record with the wrong shape as ambiguous', async () => {
		const dir = join(stateDir, 'claims', hashClaimKey(SCOPE, KEY))
		await mkdir(dir, { recursive: true, mode: 0o700 })
		await writeFile(join(dir, 'completed.json'), JSON.stringify({ state: 'completed' }), {
			mode: 0o600,
		})

		expect(await makeLedger().ledger.inspect(SCOPE, KEY, DIGEST)).toEqual({ kind: 'ambiguous' })
	})

	it('refuses to follow a symlink planted where a record belongs', async () => {
		const dir = join(stateDir, 'claims', hashClaimKey(SCOPE, KEY))
		await mkdir(dir, { recursive: true, mode: 0o700 })
		const decoy = join(stateDir, 'decoy.json')
		await writeFile(decoy, JSON.stringify({ version: 1, state: 'pending' }), { mode: 0o600 })
		await symlink(decoy, join(dir, 'pending.json'))

		expect(await makeLedger().ledger.inspect(SCOPE, KEY, DIGEST)).toEqual({ kind: 'ambiguous' })
	})

	it('fails loudly when the state directory cannot be written', async () => {
		const { ledger } = makeLedger()
		await ledger.lockEntity(OTHER_SCOPE)
		await chmod(join(stateDir, 'entities'), 0o500)

		await expect(makeLedger().ledger.lockEntity(SCOPE)).rejects.toMatchObject({
			code: 'STATE_UNWRITABLE',
		})
	})
})

describe('state directory hygiene', () => {
	it('keeps directories at 0700 and records at 0600', async () => {
		const { ledger } = makeLedger()
		const lock = await ledger.lockEntity(SCOPE)
		if (lock.kind !== 'locked') throw new Error('expected a lock')
		const { claimId } = await ledger.begin(lock.lockId, SCOPE, KEY, DIGEST)
		await ledger.complete(claimId, guardedResult())

		const entityDir = join(stateDir, 'entities', hashEntityScope(SCOPE))
		const claimDir = join(stateDir, 'claims', hashClaimKey(SCOPE, KEY))
		expect(await modeOf(join(stateDir, 'entities'))).toBe(0o700)
		expect(await modeOf(join(stateDir, 'claims'))).toBe(0o700)
		expect(await modeOf(entityDir)).toBe(0o700)
		expect(await modeOf(claimDir)).toBe(0o700)
		expect(await modeOf(join(entityDir, 'owner.json'))).toBe(0o600)
		expect(await modeOf(join(claimDir, 'completed.json'))).toBe(0o600)
	})

	it('repairs a state directory that others could read', async () => {
		await chmod(stateDir, 0o777)
		await makeLedger().ledger.lockEntity(SCOPE)
		expect(await modeOf(stateDir)).toBe(0o700)
	})

	it('never writes the raw idempotency key or scope to disk', async () => {
		const { ledger } = makeLedger()
		await runToCompletion(ledger)

		const paths = await walk(stateDir)
		expect(paths.length).toBeGreaterThan(0)
		for (const path of paths) {
			expect(path).not.toContain(KEY)
			const info = await stat(path)
			if (!info.isFile()) continue
			const contents = await readFile(path, 'utf8')
			expect(contents).not.toContain(KEY)
			expect(contents).not.toContain(SCOPE)
		}
	})

	it('confines traversal-shaped scopes and keys to hashed names inside the root', async () => {
		const { ledger } = makeLedger()
		const hostileScope = '../../etc:order:../..'
		const hostileKey = '../../../etc/passwd'

		expect(await ledger.inspect(hostileScope, hostileKey, DIGEST)).toEqual({ kind: 'none' })
		const lock = await ledger.lockEntity(hostileScope)
		if (lock.kind !== 'locked') throw new Error('expected a lock')
		await ledger.begin(lock.lockId, hostileScope, hostileKey, DIGEST)

		const paths = await walk(stateDir)
		expect(paths.length).toBeGreaterThan(0)
		for (const path of paths) {
			const inside = relative(stateDir, path)
			expect(inside.startsWith('..')).toBe(false)
			expect(inside).not.toContain('etc')
		}
	})
})

describe('guard runtime construction', () => {
	const SECRET = 'f'.repeat(64)

	function guardDir(): string {
		return join(stateDir, 'guard')
	}

	it('returns null only when guarded mode was never configured', () => {
		expect(createGuardRuntime({ env: {} })).toBeNull()
	})

	it('refuses a half-configured guard rather than silently disabling it', () => {
		expect(() => createGuardRuntime({ env: { FLUENTCART_GUARD_SECRET: SECRET } })).toThrow(
			/FLUENTCART_GUARD_STATE_DIR is required/,
		)
		expect(() => createGuardRuntime({ env: { FLUENTCART_GUARD_STATE_DIR: guardDir() } })).toThrow(
			/never generated for you/,
		)
	})

	it('refuses a weak or whitespace-padded secret', () => {
		const env = { FLUENTCART_GUARD_STATE_DIR: guardDir() }
		expect(() => createGuardRuntime({ env: { ...env, FLUENTCART_GUARD_SECRET: 'short' } })).toThrow(
			/at least 32 bytes/,
		)
		expect(() =>
			createGuardRuntime({ env: { ...env, FLUENTCART_GUARD_SECRET: ` ${SECRET} ` } }),
		).toThrow(/whitespace/)
	})

	it('refuses a relative state directory', () => {
		expect(() =>
			createGuardRuntime({
				env: { FLUENTCART_GUARD_SECRET: SECRET, FLUENTCART_GUARD_STATE_DIR: './guard' },
			}),
		).toThrow(/absolute path/)
	})

	it('creates an owner-only state directory and a working ledger', async () => {
		const runtime = createGuardRuntime({
			env: { FLUENTCART_GUARD_SECRET: SECRET, FLUENTCART_GUARD_STATE_DIR: guardDir() },
		})
		if (!runtime) throw new Error('expected a guard runtime')

		expect(await modeOf(guardDir())).toBe(0o700)
		expect(runtime.secret).toHaveLength(64)
		expect(await runtime.ledger.inspect(SCOPE, KEY, DIGEST)).toEqual({ kind: 'none' })
	})

	it('keeps live gateway actions off unless opted in exactly', () => {
		const env = { FLUENTCART_GUARD_SECRET: SECRET, FLUENTCART_GUARD_STATE_DIR: guardDir() }
		expect(createGuardRuntime({ env })?.allowLiveGatewayActions).toBe(false)
		expect(
			createGuardRuntime({ env: { ...env, FLUENTCART_ALLOW_LIVE_GATEWAY_ACTIONS: 'no' } })
				?.allowLiveGatewayActions,
		).toBe(false)
		expect(
			createGuardRuntime({ env: { ...env, FLUENTCART_ALLOW_LIVE_GATEWAY_ACTIONS: 'yes' } })
				?.allowLiveGatewayActions,
		).toBe(true)

		for (const typo of ['true', '1', 'YES', 'on']) {
			expect(() =>
				createGuardRuntime({ env: { ...env, FLUENTCART_ALLOW_LIVE_GATEWAY_ACTIONS: typo } }),
			).toThrow(/accepts only/)
		}
	})

	it('reports availability without creating any state', async () => {
		expect(inspectGuardAvailability({})).toEqual({ persistentState: false, signingSecret: false })
		expect(
			inspectGuardAvailability({
				FLUENTCART_GUARD_SECRET: SECRET,
				FLUENTCART_GUARD_STATE_DIR: guardDir(),
			}),
		).toEqual({ persistentState: true, signingSecret: true })
		expect(await readdir(stateDir)).toEqual([])
	})
})

describe('maintenance purge', () => {
	it('removes completed records only after the retention window', async () => {
		const clock = { value: BASE_TIME }
		await runToCompletion(makeLedger(clock).ledger)

		const tooSoon = await purgeCompletedRecords(stateDir, {
			now: BASE_TIME + COMPLETED_RETENTION_MS - 1,
		})
		expect(tooSoon).toBe(0)

		const purged = await purgeCompletedRecords(stateDir, {
			now: BASE_TIME + COMPLETED_RETENTION_MS + 1,
		})
		expect(purged).toBe(1)
		expect(await makeLedger(clock).ledger.inspect(SCOPE, KEY, DIGEST)).toEqual({ kind: 'none' })
	})

	it('never removes pending evidence, however old', async () => {
		const { ledger } = makeLedger()
		const lock = await ledger.lockEntity(SCOPE)
		if (lock.kind !== 'locked') throw new Error('expected a lock')
		await ledger.begin(lock.lockId, SCOPE, KEY, DIGEST)

		const purged = await purgeCompletedRecords(stateDir, { now: BASE_TIME + 10 * 365 * 86_400_000 })
		expect(purged).toBe(0)
		expect(await ledger.inspect(SCOPE, KEY, DIGEST)).toEqual({ kind: 'ambiguous' })
	})

	it('refuses a retention window shorter than 30 days', async () => {
		await expect(
			purgeCompletedRecords(stateDir, { olderThanMs: COMPLETED_RETENTION_MS - 1 }),
		).rejects.toMatchObject({ code: 'RETENTION_TOO_SHORT' })
	})
})
