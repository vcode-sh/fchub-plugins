import { describe, expect, it, vi } from 'vitest'
import { z } from 'zod'
import { buildApiIndex } from '../../src/code-mode/api-index.js'
import { HostBridge } from '../../src/code-mode/bridge.js'
import { CODE_MODE_LIMITS, CpuBudget, findForbiddenSyntax } from '../../src/code-mode/limits.js'
import type { ToolDefinition } from '../../src/tools/_factory.js'
import type { ToolRisk } from '../../src/tools/risk.js'

type Handler = ToolDefinition['handler']

function jsonHandler(value: unknown): Handler {
	return async () => ({ content: [{ type: 'text' as const, text: JSON.stringify(value) }] })
}

function tool(
	name: string,
	risk: ToolRisk,
	handler: Handler = jsonHandler({ ok: true }),
	schema: z.ZodObject<z.ZodRawShape> = z.object({}),
): ToolDefinition {
	return {
		name,
		title: name,
		description: `Operation ${name}.`,
		schema,
		annotations: { readOnlyHint: risk === 'read', openWorldHint: true },
		safety: {
			risk,
			idempotency: risk === 'read' ? 'inherent' : 'unsupported',
			execution: risk === 'read' ? 'rest' : 'none',
		},
		handler,
	}
}

function bridgeFor(tools: ToolDefinition[], options = {}) {
	return new HostBridge(buildApiIndex(tools), options)
}

describe('operation resolution', () => {
	it('dispatches a known read and returns its payload verbatim', async () => {
		const bridge = bridgeFor([tool('fluentcart_order_list', 'read', jsonHandler({ orders: [1] }))])

		const outcome = await bridge.call('fluentcart_order_list', {})

		expect(outcome).toEqual({ ok: true, json: '{"orders":[1]}' })
		expect(bridge.callCount).toBe(1)
	})

	it('refuses an unknown operation', async () => {
		const bridge = bridgeFor([tool('fluentcart_order_list', 'read')])

		const outcome = await bridge.call('fluentcart_invented', {})

		expect(outcome.ok).toBe(false)
		if (outcome.ok) return
		expect(outcome.error.code).toBe('UNKNOWN_OPERATION')
	})

	it.each<ToolRisk>([
		'reversible-write',
		'destructive-write',
		'real-money',
		'control-plane',
		'credential-bearing',
		'infrastructure',
		'external-side-effect',
		'unreviewed-write',
	])('refuses %s operations and never invokes their handler', async (risk) => {
		const handler = vi.fn(jsonHandler({ mutated: true }))
		const bridge = bridgeFor([
			tool('fluentcart_order_list', 'read'),
			tool('fluentcart_order_mutate', risk, handler),
		])

		const outcome = await bridge.call('fluentcart_order_mutate', {})

		expect(outcome.ok).toBe(false)
		if (outcome.ok) return
		expect(outcome.error.code).toBe('WRITE_OPERATION_REFUSED')
		expect(handler).not.toHaveBeenCalled()
	})

	it.each([[42], [null], [undefined], [{}], ['']])(
		'rejects a non-string operation name (%p)',
		async (operation) => {
			const bridge = bridgeFor([tool('fluentcart_order_list', 'read')])

			const outcome = await bridge.call(operation, {})

			expect(outcome.ok).toBe(false)
			if (outcome.ok) return
			expect(outcome.error.code).toBe('INVALID_INPUT')
		},
	)
})

describe('schema validation at the dispatch boundary', () => {
	const schema = z.object({
		id: z.string().describe('Order id'),
		page: z.number().int().optional().describe('Page'),
	})

	it('rejects input the schema refuses and names the offending path', async () => {
		const handler = vi.fn(jsonHandler({ ok: true }))
		const bridge = bridgeFor([tool('fluentcart_order_get', 'read', handler, schema)])

		const outcome = await bridge.call('fluentcart_order_get', { id: 99 })

		expect(outcome.ok).toBe(false)
		if (outcome.ok) return
		expect(outcome.error.code).toBe('INVALID_INPUT')
		expect(outcome.error.details).toEqual([expect.objectContaining({ path: 'id' })])
		expect(handler).not.toHaveBeenCalled()
	})

	it('passes the parsed value, not the raw one, to the handler', async () => {
		const handler = vi.fn(jsonHandler({ ok: true }))
		const bridge = bridgeFor([tool('fluentcart_order_get', 'read', handler, schema)])

		await bridge.call('fluentcart_order_get', { id: 'o-1', extra: 'dropped' })

		expect(handler).toHaveBeenCalledWith({ id: 'o-1' }, { signal: expect.any(AbortSignal) })
	})

	it('treats a missing input as an empty object', async () => {
		const handler = vi.fn(jsonHandler({ ok: true }))
		const bridge = bridgeFor([tool('fluentcart_ping', 'read', handler)])

		const outcome = await bridge.call('fluentcart_ping', undefined)

		expect(outcome.ok).toBe(true)
		expect(handler).toHaveBeenCalledWith({}, { signal: expect.any(AbortSignal) })
	})
})

describe('failure propagation', () => {
	it('maps an MCP error response to OPERATION_FAILED', async () => {
		const failing: Handler = async () => ({
			content: [{ type: 'text' as const, text: 'Error [404]: Order not found' }],
			isError: true,
		})
		const bridge = bridgeFor([tool('fluentcart_order_get', 'read', failing)])

		const outcome = await bridge.call('fluentcart_order_get', {})

		expect(outcome.ok).toBe(false)
		if (outcome.ok) return
		expect(outcome.error.code).toBe('OPERATION_FAILED')
		expect(outcome.error.message).toBe('Error [404]: Order not found')
	})

	it('maps a thrown handler to OPERATION_FAILED', async () => {
		const throwing: Handler = async () => {
			throw new Error('socket hang up')
		}
		const bridge = bridgeFor([tool('fluentcart_order_get', 'read', throwing)])

		const outcome = await bridge.call('fluentcart_order_get', {})

		expect(outcome.ok).toBe(false)
		if (outcome.ok) return
		expect(outcome.error.message).toBe('socket hang up')
	})

	it('substitutes null for an empty payload so the sandbox always parses valid JSON', async () => {
		const empty: Handler = async () => ({ content: [] })
		const bridge = bridgeFor([tool('fluentcart_order_get', 'read', empty)])

		expect(await bridge.call('fluentcart_order_get', {})).toEqual({ ok: true, json: 'null' })
	})
})

describe('call budget', () => {
	it('allows exactly the configured number of calls', async () => {
		const bridge = bridgeFor([tool('fluentcart_ping', 'read')], { maxCalls: 3 })

		for (let i = 0; i < 3; i++) {
			expect((await bridge.call('fluentcart_ping', {})).ok).toBe(true)
		}

		const outcome = await bridge.call('fluentcart_ping', {})
		expect(outcome.ok).toBe(false)
		if (outcome.ok) return
		expect(outcome.error.code).toBe('CALL_BUDGET_EXCEEDED')
	})

	it('defaults to the documented ten-call ceiling', async () => {
		const handler = vi.fn(jsonHandler({ ok: true }))
		const bridge = bridgeFor([tool('fluentcart_ping', 'read', handler)])

		for (let i = 0; i < CODE_MODE_LIMITS.maxApiCalls; i++) {
			await bridge.call('fluentcart_ping', {})
		}
		const outcome = await bridge.call('fluentcart_ping', {})

		expect(outcome.ok).toBe(false)
		expect(handler).toHaveBeenCalledTimes(CODE_MODE_LIMITS.maxApiCalls)
	})

	it('counts refused calls too, so a loop of bad names cannot run for ever', async () => {
		const bridge = bridgeFor([tool('fluentcart_ping', 'read')], { maxCalls: 2 })

		await bridge.call('fluentcart_missing', {})
		await bridge.call('fluentcart_missing', {})
		const outcome = await bridge.call('fluentcart_ping', {})

		expect(outcome.ok).toBe(false)
		if (outcome.ok) return
		expect(outcome.error.code).toBe('CALL_BUDGET_EXCEEDED')
	})
})

describe('termination', () => {
	it('refuses new calls once aborted', async () => {
		const handler = vi.fn(jsonHandler({ ok: true }))
		const bridge = bridgeFor([tool('fluentcart_ping', 'read', handler)])

		bridge.abort()
		const outcome = await bridge.call('fluentcart_ping', {})

		expect(outcome.ok).toBe(false)
		if (outcome.ok) return
		expect(outcome.error.code).toBe('SANDBOX_TERMINATED')
		expect(handler).not.toHaveBeenCalled()
		expect(bridge.aborted).toBe(true)
		expect(bridge.signal.aborted).toBe(true)
	})

	it('settles an in-flight call as terminated instead of waiting for the response', async () => {
		const never: Handler = () => new Promise(() => undefined)
		const bridge = bridgeFor([tool('fluentcart_slow', 'read', never)])

		const pending = bridge.call('fluentcart_slow', {})
		expect(bridge.inFlight).toBe(1)
		bridge.abort()

		const outcome = await pending
		expect(outcome.ok).toBe(false)
		if (outcome.ok) return
		expect(outcome.error.code).toBe('SANDBOX_TERMINATED')
		expect(bridge.inFlight).toBe(0)
	})

	it('propagates termination to the dispatched operation signal', async () => {
		let operationSignal: AbortSignal | undefined
		let cancelled = false
		const handler = ((_input: Record<string, unknown>, execution?: { signal?: AbortSignal }) =>
			new Promise((resolve) => {
				operationSignal = execution?.signal
				execution?.signal?.addEventListener(
					'abort',
					() => {
						cancelled = true
						resolve({
							content: [{ type: 'text' as const, text: '{"cancelled":true}' }],
						})
					},
					{ once: true },
				)
			})) as Handler
		const bridge = bridgeFor([tool('fluentcart_slow', 'read', handler)])

		const pending = bridge.call('fluentcart_slow', {})
		bridge.abort('wall-clock deadline')
		const outcome = await pending

		expect(outcome.ok).toBe(false)
		expect(operationSignal?.aborted).toBe(true)
		expect(cancelled).toBe(true)
	})

	it('propagates an external cancellation and removes its abort listener', async () => {
		let operationSignal: AbortSignal | undefined
		const handler = ((_input: Record<string, unknown>, execution?: { signal?: AbortSignal }) =>
			new Promise((resolve) => {
				operationSignal = execution?.signal
				setTimeout(
					() =>
						resolve({
							content: [{ type: 'text' as const, text: '{"late":true}' }],
						}),
					30,
				)
			})) as Handler
		const caller = new AbortController()
		const removeListener = vi.spyOn(caller.signal, 'removeEventListener')
		const bridge = bridgeFor([tool('fluentcart_slow', 'read', handler)], {
			signal: caller.signal,
		})

		const pending = bridge.call('fluentcart_slow', {})
		caller.abort(new Error('client cancelled'))
		const outcome = await pending

		expect(outcome.ok).toBe(false)
		expect(operationSignal?.aborted).toBe(true)
		expect(removeListener).toHaveBeenCalledWith('abort', expect.any(Function))
	})

	it('classifies an already-cancelled external signal without dispatching', async () => {
		const handler = vi.fn(jsonHandler({ late: true }))
		const caller = new AbortController()
		caller.abort(new Error('cancelled before dispatch'))
		const bridge = bridgeFor([tool('fluentcart_slow', 'read', handler)], {
			signal: caller.signal,
		})

		const outcome = await bridge.call('fluentcart_slow', {})

		expect(outcome.ok).toBe(false)
		if (outcome.ok) return
		expect(outcome.error.code).toBe('EXECUTION_CANCELLED')
		expect(handler).not.toHaveBeenCalled()
	})

	it('is idempotent', () => {
		const bridge = bridgeFor([])
		bridge.abort('first')
		bridge.abort('second')
		expect(bridge.aborted).toBe(true)
	})
})

describe('budget accounting hooks', () => {
	it('brackets every dispatch with start and end callbacks', async () => {
		const events: string[] = []
		const bridge = bridgeFor([tool('fluentcart_ping', 'read')], {
			onCallStart: () => events.push('start'),
			onCallEnd: () => events.push('end'),
		})

		await bridge.call('fluentcart_ping', {})

		expect(events).toEqual(['start', 'end'])
	})

	it('still closes the bracket when the handler throws', async () => {
		const events: string[] = []
		const throwing: Handler = async () => {
			throw new Error('nope')
		}
		const bridge = bridgeFor([tool('fluentcart_ping', 'read', throwing)], {
			onCallStart: () => events.push('start'),
			onCallEnd: () => events.push('end'),
		})

		await bridge.call('fluentcart_ping', {})

		expect(events).toEqual(['start', 'end'])
	})

	it('does not bracket a call refused before dispatch', async () => {
		const events: string[] = []
		const bridge = bridgeFor([tool('fluentcart_ping', 'read')], {
			onCallStart: () => events.push('start'),
			onCallEnd: () => events.push('end'),
		})

		await bridge.call('fluentcart_missing', {})

		expect(events).toEqual([])
	})
})

describe('CpuBudget', () => {
	function fakeClock(): { now: () => number; advance: (ms: number) => void } {
		let value = 0
		return {
			now: () => value,
			advance: (ms) => {
				value += ms
			},
		}
	}

	it('counts interpreter time towards the budget', () => {
		const clock = fakeClock()
		const budget = new CpuBudget(100, clock.now)

		clock.advance(101)

		expect(budget.consumedMs).toBe(101)
		expect(budget.exceeded).toBe(true)
	})

	it('stops counting while paused', () => {
		const clock = fakeClock()
		const budget = new CpuBudget(100, clock.now)

		budget.pause()
		clock.advance(5_000)
		budget.resume()

		expect(budget.consumedMs).toBe(0)
		expect(budget.exceeded).toBe(false)
	})

	it('only restarts once every overlapping pause has been released', () => {
		const clock = fakeClock()
		const budget = new CpuBudget(100, clock.now)

		budget.pause()
		budget.pause()
		clock.advance(500)
		budget.resume()
		clock.advance(500)

		expect(budget.consumedMs).toBe(0)

		budget.resume()
		clock.advance(30)

		expect(budget.consumedMs).toBe(30)
	})

	it('ignores a resume that has no matching pause', () => {
		const clock = fakeClock()
		const budget = new CpuBudget(100, clock.now)

		budget.resume()
		clock.advance(40)
		budget.pause()
		clock.advance(1_000)
		budget.resume()

		expect(budget.consumedMs).toBe(40)
	})

	it('accumulates across several run and wait cycles', () => {
		const clock = fakeClock()
		const budget = new CpuBudget(100, clock.now)

		for (let i = 0; i < 3; i++) {
			clock.advance(20)
			budget.pause()
			clock.advance(1_000)
			budget.resume()
		}

		expect(budget.consumedMs).toBe(60)
		expect(budget.exceeded).toBe(false)
	})
})

describe('forbidden syntax detection', () => {
	it.each([
		['import("node:fs")', 'dynamic import()'],
		['await import ( "x" )', 'dynamic import()'],
		['import fs from "node:fs"', 'import declaration'],
		['import * as fs from "node:fs"', 'import declaration'],
		['import { readFile } from "node:fs"', 'import declaration'],
		['import.meta.url', 'import.meta'],
		['require("node:fs")', 'require()'],
		['export default 1', 'export declaration'],
	])('flags %s', (source, label) => {
		expect(findForbiddenSyntax(source)).toBe(label)
	})

	it.each([
		'return fluentcart.call("fluentcart_order_list")',
		'const important = 1; return important',
		'return { requirements: [] }',
		'return rows.map((r) => r.exported)',
	])('allows ordinary code: %s', (source) => {
		expect(findForbiddenSyntax(source)).toBeNull()
	})
})
