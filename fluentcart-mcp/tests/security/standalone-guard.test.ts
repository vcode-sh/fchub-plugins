import { mkdtemp, readFile, rm } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import type { z } from 'zod'
import type { FluentCartClient } from '../../src/api/client.js'
import {
	CONFIRMATION_TOKEN_VERSION,
	CONFIRMATION_TTL_SECONDS,
	createConfirmationClaims,
	MIN_SECRET_BYTES,
	signConfirmation,
} from '../../src/security/confirmation-token.js'
import { canonicalJson, sha256Hex } from '../../src/security/encoding.js'
import {
	GUARD_LIVE_ACTIONS_ENV,
	GUARD_LIVE_ACTIONS_OPT_IN,
	GUARD_SECRET_ENV,
	GUARD_STATE_DIR_ENV,
} from '../../src/security/guard-config.js'
import type { GuardedFailureCode } from '../../src/security/guarded-action.js'
import type {
	LedgerInspection,
	LockAcquisition,
	ReleaseOutcome,
} from '../../src/security/idempotency-ledger.js'
import { COMPLETED_RETENTION_MS } from '../../src/security/ledger-maintenance.js'
import { LEDGER_RECORD_VERSION } from '../../src/security/ledger-records.js'
import type { ToolDefinition } from '../../src/tools/_factory.js'
import { orderRefundTools } from '../../src/tools/orders-refunds.js'
import { subscriptionCancellationTools } from '../../src/tools/subscriptions-cancellation.js'

const FIXTURE_PATH = join(
	import.meta.dirname,
	'..',
	'fixtures',
	'security',
	'standalone-guard.json',
)

/**
 * The fixture plan 07 consumes describes what the standalone guard promises: its token lifetime,
 * its ledger vocabulary, the two actions it will execute and the environment it needs. It is
 * generated from the same constants and schemas the code uses, never from a live run, so a
 * change to any of them fails this test rather than quietly shipping a stale contract.
 */

/**
 * Exhaustive by construction: `satisfies Record<Union, …>` fails to compile the moment a union
 * gains a member, which is the only reliable way to keep a hand-written list honest.
 */
function keysOf<T extends Record<string, 0>>(value: T): string[] {
	return Object.keys(value).sort()
}

const INSPECTION_KINDS = keysOf({
	none: 0,
	replay: 0,
	ambiguous: 0,
	conflict: 0,
} satisfies Record<LedgerInspection['kind'], 0>)

const LOCK_KINDS = keysOf({
	locked: 0,
	'in-progress': 0,
	ambiguous: 0,
} satisfies Record<LockAcquisition['kind'], 0>)

const RELEASE_OUTCOMES = keysOf({
	'not-started': 0,
	completed: 0,
} satisfies Record<ReleaseOutcome, 0>)

const FAILURE_CODES = keysOf({
	GUARD_UNAVAILABLE: 0,
	INVALID_REQUEST: 0,
	IDEMPOTENCY_AMBIGUOUS: 0,
	IDEMPOTENCY_CONFLICT: 0,
	ENTITY_BUSY: 0,
	CONFIRMATION_INVALID: 0,
	STATE_CHANGED: 0,
	LIVE_ACTION_BLOCKED: 0,
} satisfies Record<GuardedFailureCode, 0>)

interface ZodInternals {
	def: { type: string; innerType?: ZodInternals }
}

/** Structure only: field names, types and optionality. No values, no descriptions, no defaults. */
function schemaFingerprint(schema: z.ZodObject<z.ZodRawShape>): string {
	const fields = Object.entries(schema.shape)
		.map(([name, field]) => {
			const internals = field as unknown as ZodInternals
			const optional = internals.def.type === 'optional'
			const inner = optional ? internals.def.innerType : internals
			return { name, type: inner?.def.type ?? 'unknown', optional }
		})
		.sort((left, right) => (left.name < right.name ? -1 : 1))

	return `sha256:${sha256Hex('fluentcart-mcp/schema/v1', canonicalJson({ fields }))}`
}

function noClient(): FluentCartClient {
	// The contract is built from definitions alone; nothing here may perform I/O.
	return {} as unknown as FluentCartClient
}

function guardedTool(kind: 'refund' | 'cancel'): ToolDefinition {
	const tools =
		kind === 'refund'
			? orderRefundTools(noClient(), null)
			: subscriptionCancellationTools(noClient(), null)
	const tool = tools[0]
	if (!tool) throw new Error(`expected the ${kind} tool`)
	return tool
}

function buildContract(): Record<string, unknown> {
	const refund = guardedTool('refund')
	const cancel = guardedTool('cancel')

	return {
		contract: 'fluentcart-mcp/standalone-guard',
		contract_version: 1,
		description:
			'Standalone guarded execution contract. Generated from tested constants and input schemas; contains no secret, token, key or state-directory path.',
		confirmation_token: {
			version: CONFIRMATION_TOKEN_VERSION,
			ttl_seconds: CONFIRMATION_TTL_SECONDS,
			algorithm: 'HMAC-SHA256',
			encoding: 'base64url-unpadded',
			min_secret_bytes: MIN_SECRET_BYTES,
			claims: [
				'version',
				'tool',
				'entity',
				'stateFingerprint',
				'operationDigest',
				'issuedAt',
				'expiresAt',
				'nonce',
			],
		},
		ledger: {
			record_version: LEDGER_RECORD_VERSION,
			inspection_kinds: INSPECTION_KINDS,
			lock_kinds: LOCK_KINDS,
			claim_states: ['completed', 'pending'],
			release_outcomes: RELEASE_OUTCOMES,
			completed_retention_days: COMPLETED_RETENTION_MS / 86_400_000,
			automatic_retry: false,
			single_replica_only: true,
		},
		failure_codes: FAILURE_CODES,
		environment: {
			required: [GUARD_SECRET_ENV, GUARD_STATE_DIR_ENV],
			live_execution_opt_in: {
				name: GUARD_LIVE_ACTIONS_ENV,
				accepted_value: GUARD_LIVE_ACTIONS_OPT_IN,
				default_enabled: false,
			},
		},
		guarded_tools: [
			{
				name: refund.name,
				entity: 'order',
				route: { method: 'POST', path: '/orders/{order_id}/refund' },
				input_schema_fingerprint: schemaFingerprint(refund.schema),
			},
			{
				name: cancel.name,
				entity: 'subscription',
				route: {
					method: 'PUT',
					path: '/orders/{order_id}/subscriptions/{subscription_id}/cancel',
				},
				input_schema_fingerprint: schemaFingerprint(cancel.schema),
			},
		],
		fingerprint_basis: 'sha256 over canonical JSON of field name, type and optionality',
	}
}

async function readFixture(): Promise<{ raw: string; parsed: Record<string, unknown> }> {
	const raw = await readFile(FIXTURE_PATH, 'utf8')
	return { raw, parsed: JSON.parse(raw) as Record<string, unknown> }
}

function collectValues(value: unknown, found: string[] = []): string[] {
	if (typeof value === 'string') found.push(value)
	else if (Array.isArray(value)) for (const entry of value) collectValues(entry, found)
	else if (value !== null && typeof value === 'object') {
		for (const entry of Object.values(value)) collectValues(entry, found)
	}
	return found
}

function collectKeys(value: unknown, found: string[] = []): string[] {
	if (Array.isArray(value)) for (const entry of value) collectKeys(entry, found)
	else if (value !== null && typeof value === 'object') {
		for (const [key, entry] of Object.entries(value)) {
			found.push(key)
			collectKeys(entry, found)
		}
	}
	return found
}

let stateDir: string

beforeEach(async () => {
	stateDir = await mkdtemp(join(tmpdir(), 'fluentcart-fixture-'))
})

afterEach(async () => {
	await rm(stateDir, { recursive: true, force: true })
})

describe('standalone guard fixture', () => {
	it('matches the contract the code currently implements', async () => {
		const { parsed } = await readFixture()
		expect(parsed).toEqual(buildContract())
	})

	it('is generated deterministically, not captured from a run', () => {
		expect(JSON.stringify(buildContract())).toBe(JSON.stringify(buildContract()))
	})

	it('names the two guarded actions and their verified routes', async () => {
		const { parsed } = await readFixture()
		const tools = parsed.guarded_tools as Array<Record<string, unknown>>
		expect(tools.map((tool) => tool.name)).toEqual([
			'fluentcart_order_refund',
			'fluentcart_subscription_cancel',
		])
		expect(tools.map((tool) => (tool.route as Record<string, unknown>).method)).toEqual([
			'POST',
			'PUT',
		])
	})

	it('states the 300 second token lifetime and the ledger vocabulary', async () => {
		const { parsed } = await readFixture()
		const token = parsed.confirmation_token as Record<string, unknown>
		const ledger = parsed.ledger as Record<string, unknown>

		expect(token.ttl_seconds).toBe(300)
		expect(ledger.inspection_kinds).toEqual(['ambiguous', 'conflict', 'none', 'replay'])
		expect(ledger.automatic_retry).toBe(false)
		expect(ledger.completed_retention_days).toBe(30)
	})

	it('names the environment variables without carrying any value', async () => {
		const { raw, parsed } = await readFixture()
		const environment = parsed.environment as Record<string, unknown>

		expect(environment.required).toEqual(['FLUENTCART_GUARD_SECRET', 'FLUENTCART_GUARD_STATE_DIR'])
		expect(raw).not.toMatch(/FLUENTCART_GUARD_SECRET\s*[=:]\s*["'][^"']/)
		expect(raw).not.toContain('FLUENTCART_GUARD_STATE_DIR=')
	})
})

describe('fixture carries no secret material', () => {
	it('contains no live secret, token, idempotency key or state directory', async () => {
		const { raw } = await readFixture()
		const secret = 'SENTINEL-SECRET-VALUE-abcdefghijklmnopqrstuvwxyz0123456789'
		const claims = createConfirmationClaims(
			{
				version: 1,
				tool: 'fluentcart_order_refund',
				entity: 'order:42',
				stateFingerprint: 'a'.repeat(64),
				operationDigest: 'b'.repeat(64),
			},
			1_700_000_000_000,
			'00000000-0000-4000-8000-000000000001',
		)
		const token = signConfirmation(claims, new Uint8Array(Buffer.from(secret, 'utf8')))

		expect(raw).not.toContain(secret)
		expect(raw).not.toContain(token)
		expect(raw).not.toContain(stateDir)
		expect(raw).not.toContain(tmpdir())
	})

	it('holds no filesystem path and no opaque blob beyond the schema fingerprints', async () => {
		const { parsed } = await readFixture()

		for (const value of collectValues(parsed)) {
			// REST route templates start with a slash too, so exclude the roots a state directory
			// would actually live under rather than every leading slash.
			expect(value).not.toMatch(/^(\/(Users|home|var|tmp|private|root|opt|etc)\/|[A-Za-z]:\\)/)
			expect(value).not.toMatch(/\b(Basic|Bearer)\s/i)

			// A long run with no separator is either an environment-variable name or a declared
			// fingerprint. A token, a raw secret or a key would land here and fail.
			if (value.length >= 32 && !/[\s/{}]/.test(value)) {
				expect(value).toMatch(/^(sha256:[0-9a-f]{64}|[A-Z][A-Z0-9_]+)$/)
			}
		}
	})

	it('uses no key that would suggest stored credentials or state', async () => {
		const { parsed } = await readFixture()
		const forbidden = [
			'secret',
			'state_dir',
			'state_directory',
			'idempotency_key',
			'signature',
			'confirm_token',
		]

		// Exact names: `min_secret_bytes` is a policy number and `confirmation_token` is a section
		// heading, so substring matching would reject the contract for describing itself.
		for (const key of collectKeys(parsed)) expect(forbidden).not.toContain(key)
	})
})
