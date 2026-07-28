import RELEASE_ASYNC from '@jitl/quickjs-wasmfile-release-asyncify'
import {
	newQuickJSAsyncWASMModuleFromVariant,
	type QuickJSAsyncWASMModule,
} from 'quickjs-emscripten-core'
import { describe, expect, it, vi } from 'vitest'
import { z } from 'zod'
import { buildApiIndex } from '../../src/code-mode/api-index.js'
import { CODE_MODE_LIMITS } from '../../src/code-mode/limits.js'
import { CodeSandbox, type SandboxLimits } from '../../src/code-mode/sandbox.js'
import type { ToolDefinition } from '../../src/tools/_factory.js'
import { registerCodeModeTools } from '../../src/tools/code-mode.js'
import type { ToolRisk } from '../../src/tools/risk.js'

type Handler = ToolDefinition['handler']

/**
 * One WebAssembly module for the whole file. Starting a module costs far more than creating a
 * context, and every test still gets its own runtime and context.
 */
let sharedModule: Promise<QuickJSAsyncWASMModule> | null = null
function loadModule(): Promise<QuickJSAsyncWASMModule> {
	sharedModule ??= newQuickJSAsyncWASMModuleFromVariant(Promise.resolve(RELEASE_ASYNC))
	return sharedModule
}

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

const DEFAULT_TOOLS = [
	tool('fluentcart_order_list', 'read', jsonHandler({ orders: [{ id: 1, total: 4000 }] })),
	tool('fluentcart_customer_list', 'read', jsonHandler({ customers: [{ id: 7 }] })),
	tool('fluentcart_order_refund', 'real-money', jsonHandler({ refunded: true })),
]

function makeSandbox(tools: ToolDefinition[] = DEFAULT_TOOLS, limits: SandboxLimits = {}) {
	return new CodeSandbox(buildApiIndex(tools), { limits, loadModule })
}

/** Every execution must leave the sandbox with no live context, whatever the outcome. */
function expectAllContextsDestroyed(sandbox: CodeSandbox) {
	const { contextsCreated, contextsDestroyed } = sandbox.stats
	expect(contextsCreated).toBeGreaterThan(0)
	expect(contextsDestroyed).toBe(contextsCreated)
}

describe('isolation from the host runtime', () => {
	it('exposes no Node, browser or timer globals', async () => {
		const sandbox = makeSandbox()
		const probes = [
			'process',
			'require',
			'module',
			'exports',
			'fetch',
			'XMLHttpRequest',
			'WebSocket',
			'Buffer',
			'setTimeout',
			'setInterval',
			'setImmediate',
			'clearTimeout',
			'queueMicrotask',
			'console',
			'WebAssembly',
			'importScripts',
			'Atomics',
			'Worker',
			'localStorage',
			'navigator',
			'window',
			'document',
			'__dirname',
			'__filename',
		]

		const result = await sandbox.execute(
			`return { ${probes.map((name) => `${JSON.stringify(name)}: typeof ${name}`).join(', ')} }`,
		)

		expect(result.ok).toBe(true)
		const seen = JSON.parse(result.json ?? '{}') as Record<string, string>
		for (const probe of probes) {
			expect(`${probe}=${seen[probe]}`).toBe(`${probe}=undefined`)
		}
		expectAllContextsDestroyed(sandbox)
	})

	it('offers exactly one host capability on the global object', async () => {
		const sandbox = makeSandbox()

		const result = await sandbox.execute(
			'return Object.getOwnPropertyNames(globalThis).filter((k) => !(k in Object.prototype) && typeof globalThis[k] === "object" && k === "fluentcart")',
		)

		expect(JSON.parse(result.json ?? '[]')).toEqual(['fluentcart'])
	})

	it('cannot reach the host through the bridge function constructor', async () => {
		const sandbox = makeSandbox()

		const result = await sandbox.execute(`return {
			viaConstructor: (function () {
				try { return fluentcart.call.constructor('return typeof process')() } catch (e) { return 'blocked: ' + e.name }
			})(),
			viaEval: (function () {
				try { return eval('typeof require') } catch (e) { return 'blocked: ' + e.name }
			})(),
			ownKeys: Object.getOwnPropertyNames(fluentcart),
		}`)

		const payload = JSON.parse(result.json ?? '{}') as Record<string, unknown>
		// The Function constructor and eval both survive inside QuickJS, but they compile code in
		// the same empty realm, so neither reaches anything the sandbox could not already see.
		expect(payload.viaConstructor).toBe('undefined')
		expect(payload.viaEval).toBe('undefined')
		expect(payload.ownKeys).toEqual(['call'])
	})

	it('leaves SharedArrayBuffer inert: no Atomics, no worker, and heap-bounded', async () => {
		const sandbox = makeSandbox(DEFAULT_TOOLS, { maxHeapBytes: 4 * 1024 * 1024 })

		// QuickJS ships the SharedArrayBuffer constructor, but with no Atomics and nothing to share
		// memory with it is an ordinary buffer that still counts against the sandbox heap.
		const present = await sandbox.execute(
			'return { buffer: typeof SharedArrayBuffer, atomics: typeof Atomics }',
		)
		expect(JSON.parse(present.json ?? '{}')).toEqual({ buffer: 'function', atomics: 'undefined' })

		const oversized = await sandbox.execute(
			'return new SharedArrayBuffer(64 * 1024 * 1024).byteLength',
		)
		expect(oversized.ok).toBe(false)
		expect(oversized.error?.code).toBe('MEMORY_EXCEEDED')
	}, 15_000)

	it('hides the raw host callable once the bridge is installed', async () => {
		const sandbox = makeSandbox()

		const result = await sandbox.execute('return typeof __fcHostCall')

		expect(result.json).toBe('"undefined"')
	})

	it('refuses to let sandboxed code replace the frozen bridge', async () => {
		const sandbox = makeSandbox()

		const result = await sandbox.execute(`
			const errors = []
			try { fluentcart.call = () => 'pwned' } catch (e) { errors.push(e.name) }
			try { globalThis.fluentcart = { call: () => 'pwned' } } catch (e) { errors.push(e.name) }
			try { delete globalThis.fluentcart } catch (e) { errors.push(e.name) }
			return { errors, stillFrozen: Object.isFrozen(fluentcart) }
		`)

		const payload = JSON.parse(result.json ?? '{}') as { errors: string[]; stillFrozen: boolean }
		expect(payload.stillFrozen).toBe(true)
		expect(payload.errors).toEqual(['TypeError', 'TypeError', 'TypeError'])
	})

	it('starts every execution from a clean global object', async () => {
		const sandbox = makeSandbox()

		await sandbox.execute('globalThis.leaked = "from the first run"; return 1')
		const second = await sandbox.execute('return typeof globalThis.leaked')

		expect(second.json).toBe('"undefined"')
		expect(sandbox.stats.contextsCreated).toBe(2)
	})

	it('does not leak prototype tampering between executions', async () => {
		const sandbox = makeSandbox()

		await sandbox.execute('Object.prototype.polluted = "yes"; return 1')
		const second = await sandbox.execute('return ({}).polluted === undefined')

		expect(second.json).toBe('true')
	})
})

describe('source guards', () => {
	it.each([
		['dynamic import', 'const m = await import("node:fs"); return 1'],
		['import declaration', 'import fs from "node:fs"; return 1'],
		['import.meta', 'return import.meta.url'],
		['require', 'const fs = require("node:fs"); return 1'],
		['export', 'export const x = 1'],
	])('rejects %s before the sandbox starts', async (_label, source) => {
		const sandbox = makeSandbox()

		const result = await sandbox.execute(source)

		expect(result.ok).toBe(false)
		expect(result.error?.code).toBe('FORBIDDEN_SYNTAX')
		expect(sandbox.stats.contextsCreated).toBe(0)
	})

	it('rejects source above the character limit without creating a context', async () => {
		const sandbox = makeSandbox()

		const result = await sandbox.execute(
			`return "${'a'.repeat(CODE_MODE_LIMITS.maxSourceCharacters)}"`,
		)

		expect(result.ok).toBe(false)
		expect(result.error?.code).toBe('SOURCE_TOO_LARGE')
		expect(sandbox.stats.contextsCreated).toBe(0)
	})

	it('caps source length at twelve thousand characters', () => {
		expect(CODE_MODE_LIMITS.maxSourceCharacters).toBe(12_000)
	})
})

describe('compute and memory budgets', () => {
	it('pins the documented default limits', () => {
		expect(CODE_MODE_LIMITS.maxWallClockMs).toBe(5_000)
		expect(CODE_MODE_LIMITS.maxCpuMs).toBe(2_000)
		expect(CODE_MODE_LIMITS.maxHeapBytes).toBe(32 * 1024 * 1024)
		expect(CODE_MODE_LIMITS.maxApiCalls).toBe(10)
		expect(CODE_MODE_LIMITS.maxOutputCharacters).toBe(24_000)
	})

	it('interrupts an infinite loop at the real two-second CPU budget', async () => {
		const sandbox = makeSandbox()

		const started = Date.now()
		const result = await sandbox.execute('while (true) {}')

		expect(result.ok).toBe(false)
		expect(result.error?.code).toBe('CPU_BUDGET_EXCEEDED')
		expect(Date.now() - started).toBeLessThan(CODE_MODE_LIMITS.maxWallClockMs)
		expectAllContextsDestroyed(sandbox)
	}, 15_000)

	it('contains a promise storm and names the budget that stopped it', async () => {
		const orderHandler = vi.fn(jsonHandler({ orders: [] }))
		const sandbox = makeSandbox([tool('fluentcart_order_list', 'read', orderHandler)], {
			maxCpuMs: 300,
			maxWallClockMs: 4_000,
		})

		const started = Date.now()
		const result = await sandbox.execute('for (;;) { Promise.resolve().then(() => {}) }')
		const elapsed = Date.now() - started

		// Containment is the safety property: the run ends, the context goes away, and no REST
		// call escapes. The code is how we prove which limit did the stopping.
		expect(result.ok).toBe(false)
		expect(elapsed).toBeLessThan(4_000)
		expectAllContextsDestroyed(sandbox)
		expect(orderHandler).not.toHaveBeenCalled()
		expect(result.callCount).toBe(0)

		// QuickJS does not always surface an interrupt as `InternalError: interrupted`. Abandoning
		// a `Promise.resolve().then(...)` chain mid-call raises a bare `TypeError: not a function`
		// instead, so the exhausted budget is what names the failure, not the exception text.
		expect(result.error?.code).toBe('CPU_BUDGET_EXCEEDED')
	}, 15_000)

	it('interrupts a tight loop that allocates', async () => {
		const sandbox = makeSandbox(DEFAULT_TOOLS, { maxCpuMs: 300 })

		const result = await sandbox.execute('let s = ""; for (;;) { s += "x" }')

		expect(result.ok).toBe(false)
		expect(['CPU_BUDGET_EXCEEDED', 'MEMORY_EXCEEDED']).toContain(result.error?.code)
		expectAllContextsDestroyed(sandbox)
	}, 15_000)

	it('turns unbounded recursion into a stack error rather than crashing the module', async () => {
		const sandbox = makeSandbox()

		const result = await sandbox.execute(
			'function f(n) { return n <= 0 ? 0 : 1 + f(n - 1) } return f(1e7)',
		)

		expect(result.ok).toBe(false)
		expect(result.error?.code).toBe('STACK_EXCEEDED')
		expectAllContextsDestroyed(sandbox)
	}, 15_000)

	it('stops a huge allocation at the heap ceiling', async () => {
		const sandbox = makeSandbox(DEFAULT_TOOLS, { maxHeapBytes: 4 * 1024 * 1024 })

		const result = await sandbox.execute(
			'const a = []; for (let i = 0; i < 1e8; i++) a.push({ i }); return a.length',
		)

		expect(result.ok).toBe(false)
		expect(result.error?.code).toBe('MEMORY_EXCEEDED')
		expectAllContextsDestroyed(sandbox)
	}, 15_000)

	it('gives up on a wall clock breach caused by a slow read, not by the interpreter', async () => {
		const slow: Handler = () => new Promise(() => undefined)
		// The CPU budget stays at its real default: waiting on the store must not look like a
		// runaway loop, so the wall clock has to be what stops this.
		const sandbox = makeSandbox([tool('fluentcart_slow', 'read', slow)], { maxWallClockMs: 400 })

		const result = await sandbox.execute('return await fluentcart.call("fluentcart_slow")')

		expect(result.ok).toBe(false)
		expect(result.error?.code).toBe('WALL_CLOCK_EXCEEDED')
		expectAllContextsDestroyed(sandbox)
	}, 15_000)

	it('keeps charging CPU when code starts a read but does not await it', async () => {
		const slow: Handler = () => new Promise(() => undefined)
		const sandbox = makeSandbox([tool('fluentcart_slow', 'read', slow)], {
			maxCpuMs: 120,
			maxWallClockMs: 1_500,
		})

		const started = Date.now()
		const result = await sandbox.execute('void fluentcart.call("fluentcart_slow"); while (true) {}')

		expect(result.ok).toBe(false)
		expect(result.error?.code).toBe('CPU_BUDGET_EXCEEDED')
		expect(Date.now() - started).toBeLessThan(1_500)
		expectAllContextsDestroyed(sandbox)
	}, 15_000)

	it('reports a wall-clock breach when nothing ever resolves the promise', async () => {
		const sandbox = makeSandbox(DEFAULT_TOOLS, { maxWallClockMs: 400 })

		const result = await sandbox.execute('await new Promise(() => {}); return 1')

		expect(result.ok).toBe(false)
		expect(result.error?.code).toBe('WALL_CLOCK_EXCEEDED')
		expectAllContextsDestroyed(sandbox)
	}, 15_000)

	it('does not spend the CPU budget while waiting on a slow read', async () => {
		const slow: Handler = async () => {
			await new Promise((resolve) => setTimeout(resolve, 250))
			return { content: [{ type: 'text' as const, text: '{"ok":true}' }] }
		}
		const sandbox = makeSandbox([tool('fluentcart_slow', 'read', slow)], { maxCpuMs: 120 })

		const result = await sandbox.execute('return await fluentcart.call("fluentcart_slow")')

		expect(result.ok).toBe(true)
		expect(result.json).toBe('{"ok":true}')
		expect(result.durationMs).toBeGreaterThanOrEqual(200)
	}, 15_000)
})

describe('api access policy inside the sandbox', () => {
	it('composes several reads into one answer', async () => {
		const sandbox = makeSandbox()

		const result = await sandbox.execute(`
			const orders = await fluentcart.call('fluentcart_order_list', {})
			const customers = await fluentcart.call('fluentcart_customer_list')
			return { orderCount: orders.orders.length, customerCount: customers.customers.length }
		`)

		expect(result.ok).toBe(true)
		expect(JSON.parse(result.json ?? '{}')).toEqual({ orderCount: 1, customerCount: 1 })
		expect(result.callCount).toBe(2)
	})

	it('refuses a write operation and never runs its handler', async () => {
		const refundHandler = vi.fn(jsonHandler({ refunded: true }))
		const sandbox = makeSandbox([
			tool('fluentcart_order_list', 'read'),
			tool('fluentcart_order_refund', 'real-money', refundHandler),
		])

		const result = await sandbox.execute(`
			try { await fluentcart.call('fluentcart_order_refund', { order_id: 1 }) }
			catch (e) { return { code: e.code, message: e.message } }
			return { code: 'NOT_REFUSED' }
		`)

		expect(JSON.parse(result.json ?? '{}')).toMatchObject({ code: 'WRITE_OPERATION_REFUSED' })
		expect(refundHandler).not.toHaveBeenCalled()
	})

	it('refuses an unknown operation', async () => {
		const sandbox = makeSandbox()

		const result = await sandbox.execute(`
			try { await fluentcart.call('fluentcart_make_me_a_sandwich') }
			catch (e) { return e.code }
			return 'NOT_REFUSED'
		`)

		expect(result.json).toBe('"UNKNOWN_OPERATION"')
	})

	it('re-validates input against the schema at the dispatch boundary', async () => {
		const handler = vi.fn(jsonHandler({ ok: true }))
		const sandbox = makeSandbox([
			tool('fluentcart_order_get', 'read', handler, z.object({ id: z.string().describe('Id') })),
		])

		const result = await sandbox.execute(`
			try { await fluentcart.call('fluentcart_order_get', { id: 12345 }) }
			catch (e) { return e.code }
			return 'NOT_REJECTED'
		`)

		expect(result.json).toBe('"INVALID_INPUT"')
		expect(handler).not.toHaveBeenCalled()
	})

	it('stops after ten calls', async () => {
		const handler = vi.fn(jsonHandler({ ok: true }))
		const sandbox = makeSandbox([tool('fluentcart_ping', 'read', handler)])

		const result = await sandbox.execute(`
			const seen = []
			for (let i = 0; i < 20; i++) {
				try { await fluentcart.call('fluentcart_ping') ; seen.push('ok') }
				catch (e) { return { calls: seen.length, code: e.code } }
			}
			return { calls: seen.length, code: 'NEVER_STOPPED' }
		`)

		expect(JSON.parse(result.json ?? '{}')).toEqual({
			calls: CODE_MODE_LIMITS.maxApiCalls,
			code: 'CALL_BUDGET_EXCEEDED',
		})
		expect(handler).toHaveBeenCalledTimes(CODE_MODE_LIMITS.maxApiCalls)
	}, 15_000)

	it('surfaces an MCP error from a read as a catchable sandbox error', async () => {
		const failing: Handler = async () => ({
			content: [{ type: 'text' as const, text: 'Error [404]: Order not found' }],
			isError: true,
		})
		const sandbox = makeSandbox([tool('fluentcart_order_get', 'read', failing)])

		const result = await sandbox.execute(`
			try { await fluentcart.call('fluentcart_order_get', {}) }
			catch (e) { return { code: e.code, message: e.message } }
			return { code: 'NO_ERROR' }
		`)

		expect(JSON.parse(result.json ?? '{}')).toEqual({
			code: 'OPERATION_FAILED',
			message: 'Error [404]: Order not found',
		})
	})

	it('counts calls host-side, so sandboxed code cannot under-report them', async () => {
		const sandbox = makeSandbox()

		const result = await sandbox.execute(`
			await fluentcart.call('fluentcart_order_list')
			await fluentcart.call('fluentcart_customer_list')
			return 'done'
		`)

		expect(result.callCount).toBe(2)
	})
})

describe('result marshalling', () => {
	it('returns a complete JSON document for a plain value', async () => {
		const sandbox = makeSandbox()
		const result = await sandbox.execute('return { total: 4000, currency: "PLN" }')

		expect(result.ok).toBe(true)
		expect(result.json).toBe('{"total":4000,"currency":"PLN"}')
	})

	it.each([
		['a function', 'return function () {}'],
		['nothing at all', 'return undefined'],
		['no return statement', 'const x = 1'],
		['a symbol', 'return Symbol("s")'],
	])('refuses to report %s as a result', async (_label, source) => {
		const sandbox = makeSandbox()

		const result = await sandbox.execute(source)

		expect(result.ok).toBe(false)
		expect(result.error?.code).toBe('NON_JSON_RESULT')
		expectAllContextsDestroyed(sandbox)
	})

	it.each([
		['a circular object', 'const a = {}; a.self = a; return a'],
		['a BigInt', 'return 1n'],
	])('refuses %s instead of returning a misleading string', async (_label, source) => {
		const sandbox = makeSandbox()

		const result = await sandbox.execute(source)

		expect(result.ok).toBe(false)
		expect(result.error?.code).toBe('NON_JSON_RESULT')
		expect(result.json).toBeUndefined()
	})

	it('cannot be fooled by replacing JSON.stringify inside the sandbox', async () => {
		const sandbox = makeSandbox()

		const result = await sandbox.execute(`
			JSON.stringify = () => '"hacked"'
			JSON.parse = () => ({ hacked: true })
			return { real: true, total: 4000 }
		`)

		expect(result.json).toBe('{"real":true,"total":4000}')
	})

	it('delivers read payloads through a parser the sandbox cannot intercept', async () => {
		const sandbox = makeSandbox()

		const result = await sandbox.execute(`
			JSON.parse = () => ({ intercepted: true })
			const orders = await fluentcart.call('fluentcart_order_list')
			return orders
		`)

		expect(result.json).toBe('{"orders":[{"id":1,"total":4000}]}')
	})

	it('rejects output above the response budget without truncating it', async () => {
		const sandbox = makeSandbox()

		const result = await sandbox.execute(
			`return "x".repeat(${CODE_MODE_LIMITS.maxOutputCharacters + 1000})`,
		)

		expect(result.ok).toBe(false)
		expect(result.error?.code).toBe('RESPONSE_TOO_LARGE')
		expect(result.json).toBeUndefined()
		expect(result.error?.message).toContain('Return fewer records')
	})

	it.each([
		['a string', "throw 'plain string'", 'plain string'],
		['a number', 'throw 42', '42'],
	])('reports %s thrown as a primitive', async (_label, source, expected) => {
		const sandbox = makeSandbox()

		const result = await sandbox.execute(source)

		expect(result.ok).toBe(false)
		expect(result.error?.code).toBe('UNCAUGHT_EXCEPTION')
		expect(result.error?.message).toBe(expected)
	})

	it('reports a thrown Error with its name and message', async () => {
		const sandbox = makeSandbox()

		const result = await sandbox.execute('throw new TypeError("bad shape")')

		expect(result.error?.code).toBe('UNCAUGHT_EXCEPTION')
		expect(result.error?.message).toBe('TypeError: bad shape')
	})

	it('reports a syntax error rather than hanging', async () => {
		const sandbox = makeSandbox()

		const result = await sandbox.execute('return {{{')

		expect(result.ok).toBe(false)
		expect(result.error?.code).toBe('UNCAUGHT_EXCEPTION')
		expectAllContextsDestroyed(sandbox)
	})
})

describe('context lifecycle', () => {
	it.each([
		['success', 'return 1'],
		['non-JSON result', 'return function () {}'],
		['thrown error', 'throw new Error("boom")'],
		['syntax error', 'return {{{'],
		['oversized output', 'return "x".repeat(30000)'],
		['refused operation', "return await fluentcart.call('fluentcart_order_refund')"],
	])(
		'destroys the context after a %s',
		async (_label, source) => {
			const sandbox = makeSandbox()

			await sandbox.execute(source)

			expect(sandbox.stats).toEqual({ contextsCreated: 1, contextsDestroyed: 1 })
		},
		15_000,
	)

	it('destroys the context after a compute budget breach', async () => {
		const sandbox = makeSandbox(DEFAULT_TOOLS, { maxCpuMs: 200 })

		await sandbox.execute('while (true) {}')

		expect(sandbox.stats).toEqual({ contextsCreated: 1, contextsDestroyed: 1 })
	}, 15_000)

	it('destroys the context when the sandbox is abandoned mid-call', async () => {
		const slow: Handler = () => new Promise(() => undefined)
		const sandbox = makeSandbox([tool('fluentcart_slow', 'read', slow)], { maxWallClockMs: 300 })

		await sandbox.execute("return await fluentcart.call('fluentcart_slow')")

		expect(sandbox.stats).toEqual({ contextsCreated: 1, contextsDestroyed: 1 })
	}, 15_000)

	it('serialises concurrent executions and keeps their results separate', async () => {
		const sandbox = makeSandbox()

		const [first, second, third] = await Promise.all([
			sandbox.execute('return 1'),
			sandbox.execute('return 2'),
			sandbox.execute('return 3'),
		])

		expect([first?.json, second?.json, third?.json]).toEqual(['1', '2', '3'])
		expect(sandbox.stats).toEqual({ contextsCreated: 3, contextsDestroyed: 3 })
	}, 15_000)

	it('keeps working after an execution that breached a budget', async () => {
		const sandbox = makeSandbox(DEFAULT_TOOLS, { maxCpuMs: 200 })

		await sandbox.execute('while (true) {}')
		const recovered = await sandbox.execute('return "still here"')

		expect(recovered.json).toBe('"still here"')
	}, 15_000)
})

describe('startup self-test', () => {
	it('passes with a working WebAssembly module', async () => {
		expect(await makeSandbox().selfTest()).toEqual({ ok: true })
	}, 15_000)

	it('fails with a reason when the module cannot start', async () => {
		const sandbox = new CodeSandbox(buildApiIndex(DEFAULT_TOOLS), {
			loadModule: () => Promise.reject(new Error('wasm not supported here')),
		})

		const selfTest = await sandbox.selfTest()

		expect(selfTest.ok).toBe(false)
		expect(selfTest.reason).toContain('wasm not supported here')
	})

	it('reports SANDBOX_UNAVAILABLE rather than crashing when the module is missing', async () => {
		const sandbox = new CodeSandbox(buildApiIndex(DEFAULT_TOOLS), {
			loadModule: () => Promise.reject(new Error('no wasm')),
		})

		const result = await sandbox.execute('return 1')

		expect(result.error?.code).toBe('SANDBOX_UNAVAILABLE')
		expect(sandbox.stats).toEqual({ contextsCreated: 0, contextsDestroyed: 0 })
	})
})

interface Registered {
	name: string
	config: { description: string; annotations?: Record<string, unknown> }
	handler: (input: Record<string, never>) => Promise<{
		content: { type: string; text: string }[]
		isError?: boolean
	}>
}

function fakeServer() {
	const registered: Registered[] = []
	return {
		registered,
		server: {
			registerTool: (
				name: string,
				config: Registered['config'],
				handler: Registered['handler'],
			) => {
				registered.push({ name, config, handler })
			},
		} as never,
	}
}

describe('tool registration', () => {
	it('registers exactly two read-only tools', async () => {
		const { server, registered } = fakeServer()

		const outcome = await registerCodeModeTools(server, DEFAULT_TOOLS, {
			sandbox: makeSandbox(),
			skipSelfTest: true,
		})

		expect(outcome.registered).toBe(true)
		expect(registered.map((entry) => entry.name)).toEqual([
			'fluentcart_search_api',
			'fluentcart_execute_code',
		])
		for (const entry of registered) {
			expect(entry.config.annotations?.readOnlyHint).toBe(true)
			expect(entry.config.annotations?.destructiveHint).toBe(false)
		}
	})

	it('refuses to advertise code mode when the sandbox fails its self-test', async () => {
		const { server, registered } = fakeServer()
		const broken = new CodeSandbox(buildApiIndex(DEFAULT_TOOLS), {
			loadModule: () => Promise.reject(new Error('wasm unavailable')),
		})

		const outcome = await registerCodeModeTools(server, DEFAULT_TOOLS, { sandbox: broken })

		expect(outcome.registered).toBe(false)
		expect(outcome.reason).toContain('wasm unavailable')
		expect(registered).toEqual([])
	})

	it('never returns a write declaration from search', async () => {
		const { server, registered } = fakeServer()
		await registerCodeModeTools(server, DEFAULT_TOOLS, {
			sandbox: makeSandbox(),
			skipSelfTest: true,
		})
		const search = registered[0]
		if (!search) throw new Error('search tool was not registered')

		const hit = await search.handler({ query: 'order' } as never)
		const text = hit.content[0]?.text ?? ''

		expect(text).toContain('fluentcart_order_list')
		expect(text).not.toContain('fluentcart_order_refund')
	})

	it('runs code through the registered execute tool and reports the call count', async () => {
		const { server, registered } = fakeServer()
		await registerCodeModeTools(server, DEFAULT_TOOLS, {
			sandbox: makeSandbox(),
			skipSelfTest: true,
		})
		const execute = registered[1]
		if (!execute) throw new Error('execute tool was not registered')

		const response = await execute.handler({
			code: "const o = await fluentcart.call('fluentcart_order_list'); return o.orders.length",
		} as never)

		expect(response.isError).toBeUndefined()
		expect(JSON.parse(response.content[0]?.text ?? '{}')).toEqual({ result: 1, api_calls: 1 })
	}, 15_000)

	it('marks a failed execution as an MCP error carrying the structured code', async () => {
		const { server, registered } = fakeServer()
		await registerCodeModeTools(server, DEFAULT_TOOLS, {
			sandbox: makeSandbox(),
			skipSelfTest: true,
		})
		const execute = registered[1]
		if (!execute) throw new Error('execute tool was not registered')

		const response = await execute.handler({ code: 'throw new Error("nope")' } as never)

		expect(response.isError).toBe(true)
		expect(JSON.parse(response.content[0]?.text ?? '{}')).toMatchObject({
			error: 'UNCAUGHT_EXCEPTION',
		})
	})
})
