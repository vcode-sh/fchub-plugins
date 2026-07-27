import { mkdtemp, rm } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import type { FluentCartClient } from '../../src/api/client.js'
import type { GuardRuntime } from '../../src/security/guard-config.js'
import { createFileLedger } from '../../src/security/idempotency-ledger.js'
import type { ToolDefinition } from '../../src/tools/_factory.js'
import { orderRefundTools } from '../../src/tools/orders-refunds.js'

const BASE_TIME = 1_700_000_000_000

interface RecordedCall {
	method: string
	path: string
	body?: Record<string, unknown>
}

let stateDir: string
let calls: RecordedCall[]
let clock: { value: number }
let identifiers: number
let order: Record<string, unknown>

function orderFixture(overrides: Record<string, unknown> = {}): Record<string, unknown> {
	return {
		id: 42,
		currency: 'PLN',
		payment_status: 'paid',
		total_paid: 10_000,
		total_refund: 0,
		payment_method: 'stripe',
		transactions: [
			{
				id: 907,
				transaction_type: 'charge',
				status: 'succeeded',
				payment_method: 'stripe',
				payment_mode: 'test',
			},
		],
		...overrides,
	}
}

function makeClient(): FluentCartClient {
	return {
		get: async (path: string) => {
			calls.push({ method: 'GET', path })
			return { data: { order }, status: 200 }
		},
		post: async (path: string, body?: Record<string, unknown>) => {
			calls.push({ method: 'POST', path, body })
			return { data: { message: 'Refunded' }, status: 200 }
		},
		put: async (path: string, body?: Record<string, unknown>) => {
			calls.push({ method: 'PUT', path, body })
			return { data: {}, status: 200 }
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
		secret: new Uint8Array(Buffer.from('r'.repeat(64), 'utf8')),
		ledger: createFileLedger({ stateDir, now: () => clock.value, randomUUID: nextId }),
		allowLiveGatewayActions: options.live === true,
		now: () => clock.value,
		randomUUID: nextId,
	}
}

function refundTool(guard: GuardRuntime | null): ToolDefinition {
	const [tool] = orderRefundTools(makeClient(), guard)
	if (!tool) throw new Error('expected the refund tool')
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

async function preview(
	tool: ToolDefinition,
	overrides: Record<string, unknown> = {},
): Promise<Record<string, unknown>> {
	const result = await call(tool, { dry_run: true, order_id: 42, amount: 4000, ...overrides })
	expect(result.isError).toBe(false)
	return result.body
}

beforeEach(async () => {
	stateDir = await mkdtemp(join(tmpdir(), 'fluentcart-refund-'))
	calls = []
	clock = { value: BASE_TIME }
	identifiers = 0
	order = orderFixture()
})

afterEach(async () => {
	await rm(stateDir, { recursive: true, force: true })
})

describe('tool definition', () => {
	it('is classified as real money needing the guard', () => {
		const tool = refundTool(makeGuard())
		expect(tool.name).toBe('fluentcart_order_refund')
		expect(tool.safety.risk).toBe('real-money')
		expect(tool.safety.idempotency).toBe('guard-required')
		expect(tool.annotations.readOnlyHint).toBe(false)
	})

	it('no longer accepts refund_method', () => {
		const tool = refundTool(makeGuard())
		expect(Object.keys(tool.schema.shape)).not.toContain('refund_method')
		expect(Object.keys(tool.schema.shape)).toEqual(
			expect.arrayContaining(['dry_run', 'order_id', 'amount', 'confirm_token', 'idempotency_key']),
		)
	})
})

describe('preview', () => {
	it('reports the refundable balance, the charge and the gateway mode', async () => {
		const body = await preview(refundTool(makeGuard()))

		expect(body).toMatchObject({
			dry_run: true,
			action: 'refund',
			order_id: 42,
			currency: 'PLN',
			payment_status: 'paid',
			total_paid: 10_000,
			total_refunded: 0,
			remaining_refundable: 10_000,
			requested_amount: 4000,
			remaining_after_refund: 6000,
			live_payment_mode: false,
			transaction: { id: 907, status: 'succeeded', gateway_mode: 'test' },
		})
		expect(body.confirm_token).toEqual(expect.any(String))
	})

	it('subtracts an existing refund from the refundable balance', async () => {
		order = orderFixture({ total_refund: 7000, payment_status: 'partially_refunded' })
		const body = await preview(refundTool(makeGuard()), { amount: 3000 })
		expect(body.remaining_refundable).toBe(3000)
		expect(body.remaining_after_refund).toBe(0)
	})

	it('reads only, and never posts', async () => {
		await preview(refundTool(makeGuard()))
		expect(calls).toEqual([{ method: 'GET', path: '/orders/42' }])
	})

	it('treats an unknown payment mode as live', async () => {
		order = orderFixture({
			transactions: [{ id: 907, transaction_type: 'charge', status: 'succeeded' }],
		})
		const body = await preview(refundTool(makeGuard()))
		expect(body.live_payment_mode).toBe(true)
		expect(body.live_execution_allowed).toBe(false)
	})

	it('marks a live-mode charge as live', async () => {
		order = orderFixture({
			transactions: [
				{ id: 907, transaction_type: 'charge', status: 'succeeded', payment_mode: 'live' },
			],
		})
		expect((await preview(refundTool(makeGuard()))).live_payment_mode).toBe(true)
	})
})

describe('refusals', () => {
	async function refuse(input: Record<string, unknown>, fragment: string): Promise<void> {
		const result = await call(refundTool(makeGuard()), { dry_run: true, ...input })
		expect(result.isError).toBe(true)
		expect(result.text).toContain('[INVALID_REQUEST]')
		expect(result.text).toContain(fragment)
	}

	it('rejects a non-positive or fractional amount', async () => {
		await refuse({ order_id: 42, amount: 0 }, 'positive whole number')
		await refuse({ order_id: 42, amount: -100 }, 'positive whole number')
		await refuse({ order_id: 42, amount: 40.5 }, 'positive whole number')
	})

	it('rejects more than the remaining refundable amount', async () => {
		await refuse({ order_id: 42, amount: 10_001 }, 'exceeds the remaining refundable')
	})

	it('rejects an order with nothing left to refund', async () => {
		order = orderFixture({ total_refund: 10_000, payment_status: 'refunded' })
		await refuse({ order_id: 42, amount: 100 }, 'nothing left to refund')
	})

	it('rejects a transaction that is not a succeeded charge on this order', async () => {
		await refuse({ order_id: 42, amount: 4000, transaction_id: 999 }, 'is not a succeeded charge')
	})

	it('ignores transactions that are not succeeded charges', async () => {
		order = orderFixture({
			transactions: [
				{ id: 900, transaction_type: 'charge', status: 'failed' },
				{ id: 901, transaction_type: 'refund', status: 'succeeded' },
			],
		})
		await refuse({ order_id: 42, amount: 100 }, 'no succeeded charge transaction')
	})

	it('refuses to guess between several succeeded charges', async () => {
		order = orderFixture({
			transactions: [
				{ id: 907, transaction_type: 'charge', status: 'succeeded', payment_mode: 'test' },
				{ id: 908, transaction_type: 'charge', status: 'succeeded', payment_mode: 'test' },
			],
		})
		await refuse({ order_id: 42, amount: 100 }, 'Pass transaction_id to choose one')
	})

	it('rejects an order that reports no paid total', async () => {
		order = orderFixture({ total_paid: null })
		await refuse({ order_id: 42, amount: 100 }, 'does not report a paid total')
	})

	it('rejects a missing order', async () => {
		order = {}
		await refuse({ order_id: 42, amount: 100 }, 'was not found')
	})

	it('requires the guard to be configured', async () => {
		const result = await call(refundTool(null), { dry_run: true, order_id: 42, amount: 4000 })
		expect(result.isError).toBe(true)
		expect(result.text).toContain('[GUARD_UNAVAILABLE]')
	})

	it('requires a confirmation token and an idempotency key to execute', async () => {
		const tool = refundTool(makeGuard())
		const missingKey = await call(tool, { dry_run: false, order_id: 42, amount: 4000 })
		expect(missingKey.text).toContain('[INVALID_REQUEST]')
		expect(missingKey.text).toContain('idempotency_key')

		const missingToken = await call(tool, {
			dry_run: false,
			order_id: 42,
			amount: 4000,
			idempotency_key: 'key-1',
		})
		expect(missingToken.text).toContain('[CONFIRMATION_INVALID]')
	})
})

describe('execution', () => {
	it('sends exactly one refund with the verified route and body', async () => {
		const tool = refundTool(makeGuard())
		const token = (await preview(tool, { reason: 'duplicate charge' })).confirm_token as string
		calls.length = 0

		const result = await call(tool, {
			dry_run: false,
			order_id: 42,
			amount: 4000,
			reason: 'duplicate charge',
			confirm_token: token,
			idempotency_key: 'key-1',
		})

		expect(result.isError).toBe(false)
		const posts = calls.filter((entry) => entry.method === 'POST')
		expect(posts).toEqual([
			{
				method: 'POST',
				path: '/orders/42/refund',
				body: { refund_info: { transaction_id: 907, amount: 4000, reason: 'duplicate charge' } },
			},
		])
		expect(result.body).toMatchObject({
			replayed: false,
			status: 'succeeded',
			entity: 'order:42',
			summary: { order_id: 42, refunded_amount: 4000, currency: 'PLN' },
		})
	})

	it('omits reason from the body when none was given', async () => {
		const tool = refundTool(makeGuard())
		const token = (await preview(tool)).confirm_token as string

		await call(tool, {
			dry_run: false,
			order_id: 42,
			amount: 4000,
			confirm_token: token,
			idempotency_key: 'key-1',
		})

		const post = calls.find((entry) => entry.method === 'POST')
		expect(post?.body).toEqual({ refund_info: { transaction_id: 907, amount: 4000 } })
	})

	it('replays a repeated key instead of refunding twice', async () => {
		const tool = refundTool(makeGuard())
		const token = (await preview(tool)).confirm_token as string
		const request = {
			dry_run: false,
			order_id: 42,
			amount: 4000,
			confirm_token: token,
			idempotency_key: 'key-1',
		}

		await call(tool, request)
		calls.length = 0
		const replay = await call(tool, request)

		expect(replay.isError).toBe(false)
		expect(replay.body.replayed).toBe(true)
		expect(calls.filter((entry) => entry.method === 'POST')).toEqual([])
	})

	it('refuses a token issued before the order was refunded by someone else', async () => {
		const tool = refundTool(makeGuard())
		const token = (await preview(tool)).confirm_token as string
		// Another operator refunds part of the order between preview and confirmation.
		order = orderFixture({ total_refund: 5000, payment_status: 'partially_refunded' })

		const result = await call(tool, {
			dry_run: false,
			order_id: 42,
			amount: 4000,
			confirm_token: token,
			idempotency_key: 'key-1',
		})

		expect(result.isError).toBe(true)
		expect(result.text).toContain('[STATE_CHANGED]')
		expect(calls.filter((entry) => entry.method === 'POST')).toEqual([])
	})

	it('refuses a token issued for a different amount', async () => {
		const tool = refundTool(makeGuard())
		const token = (await preview(tool)).confirm_token as string

		const result = await call(tool, {
			dry_run: false,
			order_id: 42,
			amount: 100,
			confirm_token: token,
			idempotency_key: 'key-1',
		})

		expect(result.isError).toBe(true)
		expect(result.text).toContain('[STATE_CHANGED]')
		expect(calls.filter((entry) => entry.method === 'POST')).toEqual([])
	})

	it('blocks a live refund until the opt-in is set', async () => {
		order = orderFixture({
			transactions: [
				{ id: 907, transaction_type: 'charge', status: 'succeeded', payment_mode: 'live' },
			],
		})
		const tool = refundTool(makeGuard())
		const token = (await preview(tool)).confirm_token as string

		const result = await call(tool, {
			dry_run: false,
			order_id: 42,
			amount: 4000,
			confirm_token: token,
			idempotency_key: 'key-1',
		})

		expect(result.isError).toBe(true)
		expect(result.text).toContain('[LIVE_ACTION_BLOCKED]')
		expect(calls.filter((entry) => entry.method === 'POST')).toEqual([])
	})
})
