// Durable idempotency claims, proved against a real persistent state directory and real
// processes rather than an in-memory double.
//
// The claim under test is what the guard promises operators: an action that reached the payment
// gateway is never executed twice, and an action whose outcome is unknown is never retried
// automatically. Both promises have to survive a process ending mid-flight, so the crash cases
// here use a genuine SIGKILL rather than a thrown error — a thrown error still unwinds, and
// unwinding is exactly what a killed process does not do.
//
// Runs against the built package, so it proves the artefact rather than the source.

import assert from 'node:assert/strict'
import { spawn } from 'node:child_process'
import { mkdtemp, readdir, rm, stat, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { dirname, join, resolve } from 'node:path'
import { after, before, beforeEach, describe, it } from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'

const PACKAGE_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..')
const LEDGER_MODULE = resolve(PACKAGE_ROOT, 'dist/security/idempotency-ledger.js')

const SCOPE = 'fluentcart_order_refund:order:4242'
const DIGEST = 'a'.repeat(64)
const OTHER_DIGEST = 'b'.repeat(64)

let ledgerModule
let workspace
let stateDir
let counter = 0

function nextId() {
	counter += 1
	return `00000000-0000-4000-8000-${String(counter).padStart(12, '0')}`
}

function makeLedger(dir = stateDir) {
	return ledgerModule.createFileLedger({
		stateDir: dir,
		now: () => 1_700_000_000_000,
		randomUUID: nextId,
	})
}

function guardedResult(digest = DIGEST) {
	return {
		tool: 'fluentcart_order_refund',
		entity: 'order:4242',
		status: 'succeeded',
		operationDigest: digest,
		completedAt: 1_700_000_000_000,
		summary: { order_id: 4242, refunded_amount: 500 },
	}
}

/**
 * A child that opens a claim and never finishes it.
 *
 * Written to disk and killed, because the property being proved — pending evidence plus a held
 * entity lock outliving the process — cannot be produced by any in-process simulation.
 */
const ABANDONING_CHILD = `
import { createFileLedger } from ${JSON.stringify(pathToFileURL(LEDGER_MODULE).href)}
const [, , stateDir, scope, key, digest] = process.argv
const ledger = createFileLedger({
	stateDir,
	now: () => 1700000000000,
	randomUUID: () => \`00000000-0000-4000-8000-\${String(Date.now()).slice(-12)}\`,
})
const lock = await ledger.lockEntity(scope)
if (lock.kind !== 'locked') {
	process.stderr.write(\`unexpected lock: \${lock.kind}\`)
	process.exit(2)
}
await ledger.begin(lock.lockId, scope, key, digest)
process.stdout.write('claim-open\\n')
// Hold everything open until the parent kills us.
setInterval(() => {}, 1000)
`

/** A child that completes a claim cleanly and exits, so the parent can reopen the same directory. */
const COMPLETING_CHILD = `
import { createFileLedger } from ${JSON.stringify(pathToFileURL(LEDGER_MODULE).href)}
const [, , stateDir, scope, key, digest] = process.argv
const ledger = createFileLedger({
	stateDir,
	now: () => 1700000000000,
	randomUUID: () => \`00000000-0000-4000-8000-\${String(Date.now()).slice(-12)}\`,
})
const lock = await ledger.lockEntity(scope)
if (lock.kind !== 'locked') process.exit(2)
const { claimId } = await ledger.begin(lock.lockId, scope, key, digest)
await ledger.complete(claimId, {
	tool: 'fluentcart_order_refund',
	entity: 'order:4242',
	status: 'succeeded',
	operationDigest: digest,
	completedAt: 1700000000000,
	summary: { order_id: 4242, refunded_amount: 500 },
})
await ledger.releaseEntity(lock.lockId, 'completed')
process.stdout.write('claim-completed\\n')
`

function runChild(script, args, { waitFor } = {}) {
	return new Promise((resolvePromise, rejectPromise) => {
		const child = spawn(process.execPath, [script, ...args], { stdio: ['ignore', 'pipe', 'pipe'] })
		let out = ''
		let err = ''
		child.stdout.on('data', (chunk) => {
			out += String(chunk)
			if (waitFor && out.includes(waitFor)) resolvePromise({ child, out })
		})
		child.stderr.on('data', (chunk) => {
			err += String(chunk)
		})
		child.on('error', rejectPromise)
		child.on('exit', (code) => {
			if (waitFor) {
				rejectPromise(new Error(`child exited before "${waitFor}" (code ${code}): ${err}`))
				return
			}
			if (code !== 0) {
				rejectPromise(new Error(`child failed with code ${code}: ${err}`))
				return
			}
			resolvePromise({ child, out })
		})
	})
}

before(async () => {
	try {
		await stat(LEDGER_MODULE)
	} catch {
		throw new Error(`${LEDGER_MODULE} is missing. Run "npm run build" before this acceptance lane.`)
	}
	ledgerModule = await import(pathToFileURL(LEDGER_MODULE).href)
	workspace = await mkdtemp(join(tmpdir(), 'fluentcart-guard-acceptance-'))
	await writeFile(join(workspace, 'abandon.mjs'), ABANDONING_CHILD, { mode: 0o600 })
	await writeFile(join(workspace, 'complete.mjs'), COMPLETING_CHILD, { mode: 0o600 })
})

after(async () => {
	if (workspace) await rm(workspace, { recursive: true, force: true })
})

beforeEach(async () => {
	stateDir = await mkdtemp(join(tmpdir(), 'fluentcart-guard-state-'))
})

describe('durable idempotency claims', () => {
	it('records a first claim and replays it for the same key and input', async () => {
		const ledger = makeLedger()
		assert.deepEqual(await ledger.inspect(SCOPE, 'key-1', DIGEST), { kind: 'none' })

		const lock = await ledger.lockEntity(SCOPE)
		assert.equal(lock.kind, 'locked')
		const { claimId } = await ledger.begin(lock.lockId, SCOPE, 'key-1', DIGEST)
		await ledger.complete(claimId, guardedResult())
		await ledger.releaseEntity(lock.lockId, 'completed')

		const replay = await ledger.inspect(SCOPE, 'key-1', DIGEST)
		assert.equal(replay.kind, 'replay')
		assert.equal(replay.result.summary.refunded_amount, 500)
	})

	it('replays a completed claim written by an earlier process', async () => {
		await runChild(join(workspace, 'complete.mjs'), [stateDir, SCOPE, 'key-restart', DIGEST])

		// A brand-new ledger in a brand-new process instance, over the same directory.
		const replay = await makeLedger().inspect(SCOPE, 'key-restart', DIGEST)
		assert.equal(replay.kind, 'replay')
		assert.equal(replay.result.status, 'succeeded')
	})

	it('refuses the same key for a different input', async () => {
		await runChild(join(workspace, 'complete.mjs'), [stateDir, SCOPE, 'key-1', DIGEST])
		assert.deepEqual(await makeLedger().inspect(SCOPE, 'key-1', OTHER_DIGEST), { kind: 'conflict' })
	})

	it('opens exactly one claim when the same key is used concurrently', async () => {
		const ledger = makeLedger()
		const lock = await ledger.lockEntity(SCOPE)
		assert.equal(lock.kind, 'locked')

		const outcomes = await Promise.allSettled([
			ledger.begin(lock.lockId, SCOPE, 'key-race', DIGEST),
			ledger.begin(lock.lockId, SCOPE, 'key-race', DIGEST),
			ledger.begin(lock.lockId, SCOPE, 'key-race', DIGEST),
		])
		assert.equal(outcomes.filter((entry) => entry.status === 'fulfilled').length, 1)
		assert.equal(outcomes.filter((entry) => entry.status === 'rejected').length, 2)
	})

	it('lets only one caller hold an entity when different keys arrive concurrently', async () => {
		const first = makeLedger()
		const second = makeLedger()

		const results = await Promise.all([first.lockEntity(SCOPE), second.lockEntity(SCOPE)])
		assert.equal(results.filter((entry) => entry.kind === 'locked').length, 1)
		assert.equal(results.filter((entry) => entry.kind === 'ambiguous').length, 1)
	})
})

describe('a killed process leaves the entity unusable', () => {
	it('leaves pending evidence and a held lock that stay ambiguous for ever', async () => {
		const { child } = await runChild(
			join(workspace, 'abandon.mjs'),
			[stateDir, SCOPE, 'key-killed', DIGEST],
			{ waitFor: 'claim-open' },
		)

		// SIGKILL: no unwinding, no cleanup handler, exactly what a crash looks like.
		child.kill('SIGKILL')
		await new Promise((resolveExit) => child.on('exit', resolveExit))

		const entities = await readdir(join(stateDir, 'entities'))
		assert.equal(entities.length, 1, 'the entity lock outlives the killed process')

		const ledger = makeLedger()
		assert.deepEqual(await ledger.inspect(SCOPE, 'key-killed', DIGEST), { kind: 'ambiguous' })
		assert.deepEqual(await ledger.lockEntity(SCOPE), { kind: 'ambiguous' })

		// A different key on the same entity is refused too: one unknown outcome poisons the order.
		assert.deepEqual(await ledger.inspect(SCOPE, 'key-after-crash', DIGEST), { kind: 'none' })
		assert.deepEqual(await ledger.lockEntity(SCOPE), { kind: 'ambiguous' })
	})

	it('never expires the ambiguity, however much time passes', async () => {
		const { child } = await runChild(
			join(workspace, 'abandon.mjs'),
			[stateDir, SCOPE, 'key-killed', DIGEST],
			{ waitFor: 'claim-open' },
		)
		child.kill('SIGKILL')
		await new Promise((resolveExit) => child.on('exit', resolveExit))

		// Ten years later, to the second. No TTL, no automatic retry, no quiet recovery.
		const distantFuture = ledgerModule.createFileLedger({
			stateDir,
			now: () => 1_700_000_000_000 + 10 * 365 * 24 * 60 * 60 * 1000,
			randomUUID: nextId,
		})
		assert.deepEqual(await distantFuture.inspect(SCOPE, 'key-killed', DIGEST), {
			kind: 'ambiguous',
		})
		assert.deepEqual(await distantFuture.lockEntity(SCOPE), { kind: 'ambiguous' })
	})
})
