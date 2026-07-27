import { mkdtemp, rm } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import type { GuardRuntime } from '../../src/security/guard-config.js'
import {
	executeGuardedAction,
	type GuardedAction,
	GuardedActionError,
	operationDigest,
	previewGuardedAction,
} from '../../src/security/guarded-action.js'
import { createFileLedger, type IdempotencyLedger } from '../../src/security/idempotency-ledger.js'

interface Fields {
	order_id: number
	amount: number
}

const FIELDS: Fields = { order_id: 42, amount: 4000 }
const BASE_TIME = 1_700_000_000_000
const SECRET = new Uint8Array(Buffer.from('s'.repeat(64), 'utf8'))

let stateDir: string
let events: string[]
let clock: { value: number }
let identifiers: number

function recording(inner: IdempotencyLedger): IdempotencyLedger {
	return {
		inspect: (scope, key, digest) => {
			events.push('inspect')
			return inner.inspect(scope, key, digest)
		},
		lockEntity: (scope) => {
			events.push('lockEntity')
			return inner.lockEntity(scope)
		},
		begin: (lockId, scope, key, digest) => {
			events.push('begin')
			return inner.begin(lockId, scope, key, digest)
		},
		complete: (claimId, result) => {
			events.push('complete')
			return inner.complete(claimId, result)
		},
		releaseEntity: (lockId, outcome) => {
			events.push(`release:${outcome}`)
			return inner.releaseEntity(lockId, outcome)
		},
	}
}

function makeGuard(options: { live?: boolean } = {}): GuardRuntime {
	const ledger = createFileLedger({
		stateDir,
		now: () => clock.value,
		randomUUID: () => nextId(),
	})
	return {
		secret: SECRET,
		ledger: recording(ledger),
		allowLiveGatewayActions: options.live === true,
		now: () => clock.value,
		randomUUID: () => nextId(),
	}
}

function nextId(): string {
	identifiers += 1
	return `00000000-0000-4000-8000-${String(identifiers).padStart(12, '0')}`
}

interface Harness {
	action: GuardedAction<Fields>
	setFingerprint(value: string): void
	setLive(value: boolean): void
	fail(stage: 'mutate' | 'reread', error: Error): void
}

function makeAction(): Harness {
	let stateFingerprint = 'fingerprint-one'
	let live = false
	const failures = new Map<string, Error>()

	const action: GuardedAction<Fields> = {
		tool: 'fluentcart_order_refund',
		entityRef: (fields) => `order:${fields.order_id}`,
		async loadState(fields) {
			events.push('loadState')
			return { stateFingerprint, live, preview: { order_id: fields.order_id } }
		},
		async mutate() {
			events.push('mutate')
			const failure = failures.get('mutate')
			if (failure) throw failure
		},
		async reread() {
			events.push('reread')
			const failure = failures.get('reread')
			if (failure) throw failure
			return { refunded: true }
		},
	}

	return {
		action,
		setFingerprint: (value) => {
			stateFingerprint = value
		},
		setLive: (value) => {
			live = value
		},
		fail: (stage, error) => failures.set(stage, error),
	}
}

async function issueToken(action: GuardedAction<Fields>, guard: GuardRuntime): Promise<string> {
	const preview = await previewGuardedAction(action, FIELDS, guard)
	events.length = 0
	return preview.confirm_token as string
}

async function expectFailure(run: Promise<unknown>, code: string): Promise<GuardedActionError> {
	const error = await run.then(
		() => undefined,
		(thrown: unknown) => thrown,
	)
	expect(error).toBeInstanceOf(GuardedActionError)
	expect((error as GuardedActionError).code).toBe(code)
	return error as GuardedActionError
}

beforeEach(async () => {
	stateDir = await mkdtemp(join(tmpdir(), 'fluentcart-guarded-'))
	events = []
	clock = { value: BASE_TIME }
	identifiers = 0
})

afterEach(async () => {
	await rm(stateDir, { recursive: true, force: true })
})

describe('operation digest', () => {
	it('ignores key order but not values', () => {
		const tool = 'fluentcart_order_refund'
		expect(operationDigest(tool, { order_id: 42, amount: 4000 })).toBe(
			operationDigest(tool, { amount: 4000, order_id: 42 }),
		)
		expect(operationDigest(tool, { order_id: 42, amount: 4001 })).not.toBe(
			operationDigest(tool, { order_id: 42, amount: 4000 }),
		)
		expect(operationDigest('other_tool', FIELDS)).not.toBe(operationDigest(tool, FIELDS))
	})

	it('treats an absent optional field as absent, not as null', () => {
		const tool = 'fluentcart_order_refund'
		expect(operationDigest(tool, { ...FIELDS, reason: undefined })).toBe(
			operationDigest(tool, FIELDS),
		)
		expect(operationDigest(tool, { ...FIELDS, reason: 'duplicate' })).not.toBe(
			operationDigest(tool, FIELDS),
		)
	})
})

describe('preview', () => {
	it('returns a signed token bound to a 300 second window and changes nothing', async () => {
		const { action } = makeAction()
		const preview = await previewGuardedAction(action, FIELDS, makeGuard())

		expect(preview.dry_run).toBe(true)
		expect(preview.confirm_token).toMatch(/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/)
		expect(preview.confirm_token_ttl_seconds).toBe(300)
		expect(preview.confirm_token_expires_at).toBe(new Date(BASE_TIME + 300_000).toISOString())
		expect(events).toEqual(['loadState'])
	})

	it('reports that a live action cannot execute without the opt-in', async () => {
		const harness = makeAction()
		harness.setLive(true)

		const blocked = await previewGuardedAction(harness.action, FIELDS, makeGuard())
		expect(blocked.live_payment_mode).toBe(true)
		expect(blocked.live_execution_allowed).toBe(false)

		const allowed = await previewGuardedAction(harness.action, FIELDS, makeGuard({ live: true }))
		expect(allowed.live_execution_allowed).toBe(true)
	})

	it('refuses to run at all without a configured guard', async () => {
		const { action } = makeAction()
		await expectFailure(previewGuardedAction(action, FIELDS, null), 'GUARD_UNAVAILABLE')
		await expectFailure(
			executeGuardedAction(action, FIELDS, null, { confirmToken: 'x', idempotencyKey: 'k' }),
			'GUARD_UNAVAILABLE',
		)
	})
})

describe('execution order', () => {
	it('inspects before locking and before reading any state', async () => {
		const { action } = makeAction()
		const guard = makeGuard()
		const token = await issueToken(action, guard)

		await executeGuardedAction(action, FIELDS, guard, {
			confirmToken: token,
			idempotencyKey: 'key-1',
		})

		expect(events).toEqual([
			'inspect',
			'lockEntity',
			'inspect',
			'loadState',
			'begin',
			'mutate',
			'reread',
			'complete',
			'release:completed',
		])
	})

	it('performs exactly one mutation and stores a redacted result', async () => {
		const { action } = makeAction()
		const guard = makeGuard()
		const token = await issueToken(action, guard)

		const result = await executeGuardedAction(action, FIELDS, guard, {
			confirmToken: token,
			idempotencyKey: 'key-1',
		})

		expect(events.filter((entry) => entry === 'mutate')).toHaveLength(1)
		expect(result).toMatchObject({
			replayed: false,
			status: 'succeeded',
			entity: 'order:42',
			tool: 'fluentcart_order_refund',
			summary: { refunded: true },
		})
	})
})

describe('replaying a completed action', () => {
	it('returns the recorded result without a token and without mutating again', async () => {
		const { action } = makeAction()
		const guard = makeGuard()
		const token = await issueToken(action, guard)
		await executeGuardedAction(action, FIELDS, guard, {
			confirmToken: token,
			idempotencyKey: 'key-1',
		})
		events.length = 0

		const replay = await executeGuardedAction(action, FIELDS, guard, {
			confirmToken: 'not-a-token-at-all',
			idempotencyKey: 'key-1',
		})

		expect(replay).toMatchObject({ replayed: true, status: 'succeeded' })
		expect(events).toEqual(['inspect'])
	})

	it('refuses the same key used for a different operation', async () => {
		const { action } = makeAction()
		const guard = makeGuard()
		const token = await issueToken(action, guard)
		await executeGuardedAction(action, FIELDS, guard, {
			confirmToken: token,
			idempotencyKey: 'key-1',
		})

		const other = { ...FIELDS, amount: 100 }
		await expectFailure(
			executeGuardedAction(action, other, guard, {
				confirmToken: token,
				idempotencyKey: 'key-1',
			}),
			'IDEMPOTENCY_CONFLICT',
		)
	})
})

describe('refusals before any mutation', () => {
	it('rejects an unusable idempotency key before touching the ledger', async () => {
		const { action } = makeAction()
		const guard = makeGuard()
		const token = await issueToken(action, guard)

		await expectFailure(
			executeGuardedAction(action, FIELDS, guard, { confirmToken: token, idempotencyKey: '  ' }),
			'INVALID_REQUEST',
		)
		expect(events).toEqual([])
	})

	it('rejects a malformed token and releases the entity', async () => {
		const { action } = makeAction()
		const guard = makeGuard()

		await expectFailure(
			executeGuardedAction(action, FIELDS, guard, {
				confirmToken: 'nonsense',
				idempotencyKey: 'key-1',
			}),
			'CONFIRMATION_INVALID',
		)
		// Refused before the lock, so the next attempt is free to proceed.
		expect(events).toEqual(['inspect'])
		expect(await guard.ledger.lockEntity('fluentcart_order_refund:order:42')).toMatchObject({
			kind: 'locked',
		})
	})

	it('rejects a token signed with another secret', async () => {
		const { action } = makeAction()
		const guard = makeGuard()
		const token = await issueToken(action, guard)

		const foreign = { ...makeGuard(), secret: new Uint8Array(Buffer.from('z'.repeat(64), 'utf8')) }
		await expectFailure(
			executeGuardedAction(action, FIELDS, foreign, {
				confirmToken: token,
				idempotencyKey: 'key-1',
			}),
			'CONFIRMATION_INVALID',
		)
		expect(events).not.toContain('mutate')
	})

	it('rejects an expired token', async () => {
		const { action } = makeAction()
		const guard = makeGuard()
		const token = await issueToken(action, guard)
		clock.value += 300_000

		await expectFailure(
			executeGuardedAction(action, FIELDS, guard, {
				confirmToken: token,
				idempotencyKey: 'key-1',
			}),
			'CONFIRMATION_INVALID',
		)
		expect(events).not.toContain('begin')
	})

	it('rejects a preview whose state moved, and frees the entity', async () => {
		const harness = makeAction()
		const guard = makeGuard()
		const token = await issueToken(harness.action, guard)
		harness.setFingerprint('fingerprint-two')

		await expectFailure(
			executeGuardedAction(harness.action, FIELDS, guard, {
				confirmToken: token,
				idempotencyKey: 'key-1',
			}),
			'STATE_CHANGED',
		)
		expect(events).toContain('release:not-started')
		expect(events).not.toContain('begin')
	})

	it('blocks a live action without the explicit opt-in, before beginning a claim', async () => {
		const harness = makeAction()
		harness.setLive(true)
		const guard = makeGuard()
		const token = await issueToken(harness.action, guard)

		await expectFailure(
			executeGuardedAction(harness.action, FIELDS, guard, {
				confirmToken: token,
				idempotencyKey: 'key-1',
			}),
			'LIVE_ACTION_BLOCKED',
		)
		expect(events).not.toContain('begin')
		expect(events).not.toContain('mutate')
	})

	it('allows the same live action once the opt-in is set', async () => {
		const harness = makeAction()
		harness.setLive(true)
		const guard = makeGuard({ live: true })
		const token = await issueToken(harness.action, guard)

		const result = await executeGuardedAction(harness.action, FIELDS, guard, {
			confirmToken: token,
			idempotencyKey: 'key-1',
		})
		expect(result).toMatchObject({ replayed: false, status: 'succeeded' })
	})

	it('refuses a second action while another is holding the entity', async () => {
		const { action } = makeAction()
		const guard = makeGuard()
		const token = await issueToken(action, guard)
		await guard.ledger.lockEntity('fluentcart_order_refund:order:42')

		await expectFailure(
			executeGuardedAction(action, FIELDS, guard, {
				confirmToken: token,
				idempotencyKey: 'key-2',
			}),
			'ENTITY_BUSY',
		)
	})
})

describe('interrupted execution', () => {
	const timeout = Object.assign(new Error('socket hang up'), { code: 'TIMEOUT' })

	it('reports ambiguity when the mutation call fails, and never retries', async () => {
		const harness = makeAction()
		harness.fail('mutate', timeout)
		const guard = makeGuard()
		const token = await issueToken(harness.action, guard)

		const error = await expectFailure(
			executeGuardedAction(harness.action, FIELDS, guard, {
				confirmToken: token,
				idempotencyKey: 'key-1',
			}),
			'IDEMPOTENCY_AMBIGUOUS',
		)
		expect(error.message).toContain('TIMEOUT')
		expect(events).toContain('begin')
		expect(events).not.toContain('complete')
		expect(events.filter((entry) => entry.startsWith('release'))).toEqual([])
	})

	it('reports ambiguity when the verification read fails after a successful mutation', async () => {
		const harness = makeAction()
		harness.fail('reread', timeout)
		const guard = makeGuard()
		const token = await issueToken(harness.action, guard)

		await expectFailure(
			executeGuardedAction(harness.action, FIELDS, guard, {
				confirmToken: token,
				idempotencyKey: 'key-1',
			}),
			'IDEMPOTENCY_AMBIGUOUS',
		)
		expect(events).toContain('mutate')
		expect(events).not.toContain('complete')
	})

	it('leaves the entity unusable until an operator resolves it', async () => {
		const harness = makeAction()
		harness.fail('mutate', timeout)
		const guard = makeGuard()
		const token = await issueToken(harness.action, guard)
		await executeGuardedAction(harness.action, FIELDS, guard, {
			confirmToken: token,
			idempotencyKey: 'key-1',
		}).catch(() => undefined)

		// Same key: the claim is pending, so the outcome is unknown.
		await expectFailure(
			executeGuardedAction(harness.action, FIELDS, guard, {
				confirmToken: token,
				idempotencyKey: 'key-1',
			}),
			'IDEMPOTENCY_AMBIGUOUS',
		)

		// A different key on the same entity is refused too: the lock was never released.
		const fresh = makeGuard()
		await expectFailure(
			executeGuardedAction(harness.action, FIELDS, fresh, {
				confirmToken: token,
				idempotencyKey: 'key-2',
			}),
			'IDEMPOTENCY_AMBIGUOUS',
		)
	})
})
