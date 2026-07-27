import { mkdtemp, rm } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import type { FluentCartClient } from '../../src/api/client.js'
import type { GuardRuntime } from '../../src/security/guard-config.js'
import { createFileLedger } from '../../src/security/idempotency-ledger.js'
import type { ToolDefinition } from '../../src/tools/_factory.js'
import { subscriptionCancellationTools } from '../../src/tools/subscriptions-cancellation.js'

const BASE_TIME = 1_700_000_000_000
const VENDOR_ID = 'sub_live_abc123_secret_gateway_handle'

interface RecordedCall {
	method: string
	path: string
	body?: Record<string, unknown>
}

let stateDir: string
let calls: RecordedCall[]
let clock: { value: number }
let identifiers: number
let subscription: Record<string, unknown>
let order: Record<string, unknown>

function subscriptionFixture(overrides: Record<string, unknown> = {}): Record<string, unknown> {
	return {
		id: 31,
		parent_order_id: 7,
		status: 'active',
		canceled_at: null,
		vendor_subscription_id: VENDOR_ID,
		current_payment_method: 'stripe',
		payment_mode: 'test',
		item_name: 'Pro plan',
		billing_interval: 'monthly',
		recurring_amount: 4900,
		next_billing_date: '2026-08-27 00:00:00',
		...overrides,
	}
}

function orderFixture(overrides: Record<string, unknown> = {}): Record<string, unknown> {
	return { id: 7, payment_method: 'stripe', payment_mode: 'test', currency: 'PLN', ...overrides }
}

function makeClient(): FluentCartClient {
	return {
		get: async (path: string) => {
			calls.push({ method: 'GET', path })
			if (path.startsWith('/subscriptions/')) return { data: { subscription }, status: 200 }
			return { data: { order }, status: 200 }
		},
		post: async (path: string, body?: Record<string, unknown>) => {
			calls.push({ method: 'POST', path, body })
			return { data: {}, status: 200 }
		},
		put: async (path: string, body?: Record<string, unknown>) => {
			calls.push({ method: 'PUT', path, body })
			return { data: { message: 'Canceled' }, status: 200 }
		},
		delete: async (path: string) => {
			calls.push({ method: 'DELETE', path })
			return { data: {}, status: 200 }
		},
		request: async () => ({ data: {}, status: 200 }),
	} as unknown as FluentCartClient
}

function nextId(): string {
	identifiers += 1
	return `00000000-0000-4000-8000-${String(identifiers).padStart(12, '0')}`
}

function makeGuard(options: { live?: boolean } = {}): GuardRuntime {
	return {
		secret: new Uint8Array(Buffer.from('c'.repeat(64), 'utf8')),
		ledger: createFileLedger({ stateDir, now: () => clock.value, randomUUID: nextId }),
		allowLiveGatewayActions: options.live === true,
		now: () => clock.value,
		randomUUID: nextId,
	}
}

function cancelTool(guard: GuardRuntime | null): ToolDefinition {
	const [tool] = subscriptionCancellationTools(makeClient(), guard)
	if (!tool) throw new Error('expected the cancellation tool')
	return tool
}

async function call(
	tool: ToolDefinition,
	input: Record<string, unknown>,
): Promise<{ isError: boolean; text: string; body: Record<string, unknown> }> {
	const response = await tool.handler(input)
	const text = response.content[0]?.text ?? ''
	let body: Record<string, unknown> = {}
	try {
		body = JSON.parse(text) as Record<string, unknown>
	} catch {
		// Error responses are prose, not JSON; `text` carries the assertion in that case.
	}
	return { isError: response.isError === true, text, body }
}

const REQUEST = { order_id: 7, subscription_id: 31, cancel_reason: 'Customer request' }

async function preview(
	tool: ToolDefinition,
	overrides: Record<string, unknown> = {},
): Promise<Record<string, unknown>> {
	const result = await call(tool, { dry_run: true, ...REQUEST, ...overrides })
	expect(result.isError).toBe(false)
	return result.body
}

beforeEach(async () => {
	stateDir = await mkdtemp(join(tmpdir(), 'fluentcart-cancel-'))
	calls = []
	clock = { value: BASE_TIME }
	identifiers = 0
	subscription = subscriptionFixture()
	order = orderFixture()
})

afterEach(async () => {
	await rm(stateDir, { recursive: true, force: true })
})

describe('tool definition', () => {
	it('is classified as real money needing the guard', () => {
		const tool = cancelTool(makeGuard())
		expect(tool.name).toBe('fluentcart_subscription_cancel')
		expect(tool.safety.risk).toBe('real-money')
		expect(tool.safety.idempotency).toBe('guard-required')
	})

	it('describes cancellation as immediate', () => {
		expect(cancelTool(makeGuard()).description).toMatch(/immediate/i)
	})
})

describe('preview', () => {
	it('reads both the subscription and the owning order', async () => {
		await preview(cancelTool(makeGuard()))
		expect(calls).toEqual([
			{ method: 'GET', path: '/subscriptions/31' },
			{ method: 'GET', path: '/orders/7' },
		])
	})

	it('reports the current state and the immediate effect', async () => {
		const body = await preview(cancelTool(makeGuard()))

		expect(body).toMatchObject({
			dry_run: true,
			action: 'cancel',
			order_id: 7,
			subscription_id: 31,
			status: 'active',
			canceled_at: null,
			payment_method: 'stripe',
			gateway_mode: 'test',
			cancels_immediately: true,
			live_payment_mode: false,
			next_billing_date: '2026-08-27 00:00:00',
		})
		expect(body.confirm_token).toEqual(expect.any(String))
	})

	it('keeps the gateway subscription handle out of the preview and the token', async () => {
		const body = await preview(cancelTool(makeGuard()))
		expect(JSON.stringify(body)).not.toContain(VENDOR_ID)

		const payload = String(body.confirm_token).split('.')[0] ?? ''
		expect(Buffer.from(payload, 'base64url').toString('utf8')).not.toContain(VENDOR_ID)
	})

	it('treats an unknown payment mode as live', async () => {
		subscription = subscriptionFixture({ payment_mode: undefined })
		order = orderFixture({ payment_mode: undefined })
		const body = await preview(cancelTool(makeGuard()))
		expect(body.live_payment_mode).toBe(true)
		expect(body.live_execution_allowed).toBe(false)
	})
})

describe('refusals', () => {
	async function refuse(input: Record<string, unknown>, fragment: string): Promise<void> {
		const result = await call(cancelTool(makeGuard()), { dry_run: true, ...input })
		expect(result.isError).toBe(true)
		expect(result.text).toContain('[INVALID_REQUEST]')
		expect(result.text).toContain(fragment)
	}

	it('rejects an end-of-period cancellation rather than silently cancelling now', async () => {
		await refuse({ ...REQUEST, cancel_immediately: false }, 'not supported')
	})

	it('accepts the redundant cancel_immediately:true', async () => {
		const body = await preview(cancelTool(makeGuard()), { cancel_immediately: true })
		expect(body.cancels_immediately).toBe(true)
	})

	it('requires a non-empty reason', async () => {
		await refuse({ order_id: 7, subscription_id: 31 }, 'cancel_reason')
		await refuse({ ...REQUEST, cancel_reason: '' }, 'cancel_reason')
	})

	it('rejects a subscription that belongs to another order', async () => {
		subscription = subscriptionFixture({ parent_order_id: 99 })
		await refuse(REQUEST, 'does not belong to order 7')
	})

	it('rejects a subscription that has already ended', async () => {
		for (const status of ['canceled', 'expired', 'completed']) {
			subscription = subscriptionFixture({ status })
			await refuse(REQUEST, `already ${status}`)
		}
	})

	it('rejects a missing subscription or order', async () => {
		subscription = {}
		await refuse(REQUEST, 'Subscription 31 was not found')

		subscription = subscriptionFixture()
		order = {}
		await refuse(REQUEST, 'Order 7 was not found')
	})

	it('requires the guard to be configured', async () => {
		const result = await call(cancelTool(null), { dry_run: true, ...REQUEST })
		expect(result.isError).toBe(true)
		expect(result.text).toContain('[GUARD_UNAVAILABLE]')
	})
})

describe('execution', () => {
	it('sends exactly one cancellation on the verified route', async () => {
		const tool = cancelTool(makeGuard())
		const token = (await preview(tool)).confirm_token as string
		calls.length = 0

		const result = await call(tool, {
			dry_run: false,
			...REQUEST,
			confirm_token: token,
			idempotency_key: 'key-1',
		})

		expect(result.isError).toBe(false)
		expect(calls.filter((entry) => entry.method === 'PUT')).toEqual([
			{
				method: 'PUT',
				path: '/orders/7/subscriptions/31/cancel',
				body: { cancel_reason: 'Customer request' },
			},
		])
		expect(result.body).toMatchObject({
			replayed: false,
			status: 'succeeded',
			entity: 'subscription:31',
			summary: { subscription_id: 31, order_id: 7, cancels_immediately: true },
		})
	})

	it('replays a repeated key instead of cancelling twice', async () => {
		const tool = cancelTool(makeGuard())
		const token = (await preview(tool)).confirm_token as string
		const request = {
			dry_run: false,
			...REQUEST,
			confirm_token: token,
			idempotency_key: 'key-1',
		}

		await call(tool, request)
		// A second call sees the subscription already cancelled; the ledger answers first.
		subscription = subscriptionFixture({ status: 'canceled', canceled_at: '2026-07-27 10:00:00' })
		calls.length = 0
		const replay = await call(tool, request)

		expect(replay.isError).toBe(false)
		expect(replay.body.replayed).toBe(true)
		expect(calls).toEqual([])
	})

	it('refuses a token issued before the subscription changed', async () => {
		const tool = cancelTool(makeGuard())
		const token = (await preview(tool)).confirm_token as string
		subscription = subscriptionFixture({ status: 'past_due' })

		const result = await call(tool, {
			dry_run: false,
			...REQUEST,
			confirm_token: token,
			idempotency_key: 'key-1',
		})

		expect(result.isError).toBe(true)
		expect(result.text).toContain('[STATE_CHANGED]')
		expect(calls.filter((entry) => entry.method === 'PUT')).toEqual([])
	})

	it('refuses a token issued for a different reason', async () => {
		const tool = cancelTool(makeGuard())
		const token = (await preview(tool)).confirm_token as string

		const result = await call(tool, {
			dry_run: false,
			...REQUEST,
			cancel_reason: 'Something else entirely',
			confirm_token: token,
			idempotency_key: 'key-1',
		})

		expect(result.isError).toBe(true)
		expect(result.text).toContain('[STATE_CHANGED]')
		expect(calls.filter((entry) => entry.method === 'PUT')).toEqual([])
	})

	it('blocks a live cancellation until the opt-in is set', async () => {
		subscription = subscriptionFixture({ payment_mode: 'live' })
		const tool = cancelTool(makeGuard())
		const token = (await preview(tool)).confirm_token as string

		const blocked = await call(tool, {
			dry_run: false,
			...REQUEST,
			confirm_token: token,
			idempotency_key: 'key-1',
		})
		expect(blocked.text).toContain('[LIVE_ACTION_BLOCKED]')
		expect(calls.filter((entry) => entry.method === 'PUT')).toEqual([])

		const allowed = cancelTool(makeGuard({ live: true }))
		const liveToken = (await preview(allowed)).confirm_token as string
		const result = await call(allowed, {
			dry_run: false,
			...REQUEST,
			confirm_token: liveToken,
			idempotency_key: 'key-2',
		})
		expect(result.isError).toBe(false)
	})
})
