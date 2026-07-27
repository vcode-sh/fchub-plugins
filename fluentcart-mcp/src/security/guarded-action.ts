import {
	CONFIRMATION_TTL_SECONDS,
	ConfirmationError,
	createConfirmationClaims,
	signConfirmation,
	verifyConfirmation,
} from './confirmation-token.js'
import type { GuardRuntime } from './guard-config.js'
import {
	type ActionState,
	AMBIGUOUS_ENTITY,
	ambiguousAfterStart,
	assertIdempotencyKey,
	assertTokenShape,
	BUSY_ENTITY,
	describeResult,
	type GuardedAction,
	GuardedActionError,
	LIVE_BLOCKED,
	operationDigest,
	requireGuard,
	resolveInspection,
	STALE_PREVIEW,
} from './guarded-contract.js'
import type { GuardedResult } from './idempotency-ledger.js'

export {
	type ActionState,
	fingerprint,
	type GuardedAction,
	GuardedActionError,
	type GuardedFailureCode,
	operationDigest,
	parseGuardedInput,
} from './guarded-contract.js'

/**
 * The two-call protocol shared by every action that moves real money.
 *
 * Call one previews: it reads current state and returns a signed token pinned to that state and
 * those inputs. Call two spends the token. Between the two the store may have changed, so the
 * second call repeats every read while holding the entity lock and refuses the token if the
 * fingerprint moved. A preview is a photograph, not a promise.
 *
 * The ordering below is not an implementation detail. `inspect()` runs before any lock and any
 * read, because a completed claim must replay even when a crash left the entity locked.
 */

interface ExecutionContext {
	scope: string
	entity: string
	key: string
	digest: string
	lockId: string
	token: string
}

/**
 * Preview an action without changing anything.
 *
 * The token is issued even when live execution is blocked: reading what a refund *would* do is
 * useful on its own, and the gate is enforced again at execution where it cannot be bypassed.
 */
export async function previewGuardedAction<TFields, TState extends ActionState>(
	action: GuardedAction<TFields, TState>,
	fields: TFields,
	guard: GuardRuntime | null,
): Promise<Record<string, unknown>> {
	const runtime = requireGuard(guard)
	const entity = action.entityRef(fields)
	const state = await action.loadState(fields)

	const claims = createConfirmationClaims(
		{
			version: 1,
			tool: action.tool,
			entity,
			stateFingerprint: state.stateFingerprint,
			operationDigest: operationDigest(action.tool, fields),
		},
		runtime.now(),
		runtime.randomUUID(),
	)

	return {
		dry_run: true,
		...state.preview,
		live_payment_mode: state.live,
		live_execution_allowed: !state.live || runtime.allowLiveGatewayActions,
		confirm_token: signConfirmation(claims, runtime.secret),
		confirm_token_expires_at: new Date(claims.expiresAt).toISOString(),
		confirm_token_ttl_seconds: CONFIRMATION_TTL_SECONDS,
		next_step:
			'Call again with dry_run:false, the identical fields, this confirm_token and a fresh idempotency_key.',
	}
}

export async function executeGuardedAction<TFields, TState extends ActionState>(
	action: GuardedAction<TFields, TState>,
	fields: TFields,
	guard: GuardRuntime | null,
	confirmation: { confirmToken: string; idempotencyKey: string },
): Promise<Record<string, unknown>> {
	const runtime = requireGuard(guard)
	const entity = action.entityRef(fields)
	const context: Omit<ExecutionContext, 'lockId'> = {
		scope: `${action.tool}:${entity}`,
		entity,
		key: assertIdempotencyKey(confirmation.idempotencyKey),
		digest: operationDigest(action.tool, fields),
		token: confirmation.confirmToken,
	}

	// Before any lock and before any read: a completed claim replays, a crashed one refuses.
	const known = await runtime.ledger.inspect(context.scope, context.key, context.digest)
	if (known.kind !== 'none') return resolveInspection(known)

	assertTokenShape(confirmation.confirmToken)

	const lock = await runtime.ledger.lockEntity(context.scope)
	if (lock.kind === 'in-progress') throw new GuardedActionError('ENTITY_BUSY', BUSY_ENTITY)
	if (lock.kind === 'ambiguous') {
		throw new GuardedActionError('IDEMPOTENCY_AMBIGUOUS', AMBIGUOUS_ENTITY)
	}

	return runUnderLock(action, fields, runtime, { ...context, lockId: lock.lockId })
}

async function runUnderLock<TFields, TState extends ActionState>(
	action: GuardedAction<TFields, TState>,
	fields: TFields,
	runtime: GuardRuntime,
	context: ExecutionContext,
): Promise<Record<string, unknown>> {
	let phase: 'not-started' | 'in-flight' | 'completed' = 'not-started'

	try {
		const known = await runtime.ledger.inspect(context.scope, context.key, context.digest)
		if (known.kind !== 'none') {
			const replay = resolveInspection(known)
			await release(runtime, context.lockId, 'not-started')
			return replay
		}

		// Read again under the lock: the preview may be up to five minutes old.
		const state = await action.loadState(fields)
		assertConfirmation(action.tool, context, state, runtime)
		assertLiveAllowed(runtime, state)

		const { claimId } = await runtime.ledger.begin(
			context.lockId,
			context.scope,
			context.key,
			context.digest,
		)
		phase = 'in-flight'

		await action.mutate(fields, state)
		const summary = await action.reread(fields)
		const result: GuardedResult = {
			tool: action.tool,
			entity: context.entity,
			status: 'succeeded',
			operationDigest: context.digest,
			completedAt: runtime.now(),
			summary,
		}

		await runtime.ledger.complete(claimId, result)
		phase = 'completed'
		// The record is durable, so a stuck lock is an operator problem, not a reason to tell the
		// caller their refund might not have happened.
		await release(runtime, context.lockId, 'completed')
		return { ...describeResult(result), replayed: false }
	} catch (error) {
		if (phase === 'in-flight') throw ambiguousAfterStart(error)
		if (phase === 'not-started') await release(runtime, context.lockId, 'not-started')
		throw error
	}
}

/** Release failures never change the caller's answer; the ledger keeps the authoritative record. */
async function release(
	runtime: GuardRuntime,
	lockId: string,
	outcome: 'not-started' | 'completed',
): Promise<void> {
	await runtime.ledger.releaseEntity(lockId, outcome).catch(() => undefined)
}

function assertConfirmation(
	tool: string,
	context: ExecutionContext,
	state: ActionState,
	runtime: GuardRuntime,
): void {
	const expected = {
		version: 1 as const,
		tool,
		entity: context.entity,
		stateFingerprint: state.stateFingerprint,
		operationDigest: context.digest,
	}
	try {
		verifyConfirmation(context.token, expected, runtime.secret, runtime.now())
	} catch (error) {
		if (!(error instanceof ConfirmationError)) throw error
		// A mismatch means the world moved, not that the token was forged.
		if (error.code === 'CLAIM_MISMATCH') {
			throw new GuardedActionError('STATE_CHANGED', STALE_PREVIEW)
		}
		throw new GuardedActionError('CONFIRMATION_INVALID', error.message)
	}
}

function assertLiveAllowed(runtime: GuardRuntime, state: ActionState): void {
	if (state.live && !runtime.allowLiveGatewayActions) {
		throw new GuardedActionError('LIVE_ACTION_BLOCKED', LIVE_BLOCKED)
	}
}
