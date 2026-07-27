// Code Mode isolation, run against the built package rather than a source mock.
//
// The claim under test is narrow and absolute: sandboxed JavaScript can reach the read operations
// and nothing else. Not the host process, not the filesystem, not the network, not a module
// loader, and not a write operation by any spelling. Every rejected snippet must also cost the
// store nothing, so the REST boundary is counted throughout and must stay at zero.

import assert from 'node:assert/strict'
import { dirname, join, resolve } from 'node:path'
import { after, before, describe, it } from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { loadServerModule } from '../../scripts/measure-tool-context.mjs'

const PACKAGE_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..')

// A note on `eval` below: every occurrence lives inside a string handed to the QuickJS sandbox,
// never to the Node host. Probing `typeof <name>` from inside the VM is the only way to prove an
// identifier is genuinely absent there, and the VM is precisely the thing under test.

/** Host globals a sandbox escape would reach for first. */
const FORBIDDEN_GLOBALS = [
	'process',
	'require',
	'fetch',
	'XMLHttpRequest',
	'WebSocket',
	'Deno',
	'global',
	'globalThis.process',
	'setTimeout',
	'setInterval',
	'setImmediate',
	'queueMicrotask',
	'console',
	'Worker',
	'Atomics',
	'importScripts',
]

let limits
let sandbox
let index
let restCalls = 0
let originalFetch

function distImport(...segments) {
	return import(pathToFileURL(join(PACKAGE_ROOT, 'dist', ...segments)).href)
}

/** Assert the runtime was torn down, whatever the snippet did on its way out. */
function assertNoLiveContext() {
	const { contextsCreated, contextsDestroyed } = sandbox.stats
	assert.ok(contextsCreated > 0, 'no context was ever created, so nothing was proven')
	assert.equal(contextsDestroyed, contextsCreated, 'a QuickJS context outlived its execution')
}

async function failWith(source) {
	const result = await sandbox.execute(source)
	assert.equal(result.ok, false, `expected a refusal, got ${result.json}`)
	return result.error
}

before(async () => {
	const serverModule = await loadServerModule()
	// The widest legitimate registry, so a write is genuinely present to be refused.
	process.env.FLUENTCART_WRITE_MODE = 'reversible'
	const tools = serverModule.resolveServerContext().tools

	const { buildApiIndex } = await distImport('code-mode', 'api-index.js')
	const { CODE_MODE_LIMITS } = await distImport('code-mode', 'limits.js')
	const { CodeSandbox } = await distImport('code-mode', 'sandbox.js')

	limits = CODE_MODE_LIMITS
	index = buildApiIndex(tools)
	sandbox = new CodeSandbox(index)

	originalFetch = globalThis.fetch
	globalThis.fetch = (...args) => {
		restCalls += 1
		return originalFetch(...args)
	}
})

after(() => {
	if (originalFetch) globalThis.fetch = originalFetch
})

describe('sandbox availability', () => {
	it('passes its startup self-test, so code mode is advertised only when it works', async () => {
		const selfTest = await sandbox.selfTest()
		assert.deepEqual(selfTest, { ok: true })
		assertNoLiveContext()
	})

	it('indexes only reads, and knows which names it deliberately excluded', () => {
		assert.ok(index.size > 100, `expected a substantial read index, got ${index.size}`)
		assert.ok(index.has('fluentcart_order_list'))
		assert.equal(index.has('fluentcart_coupon_create'), false)
		assert.equal(index.isExcludedWrite('fluentcart_coupon_create'), true)
	})

	it('pins the documented limits, which are the ones production runs', () => {
		assert.equal(limits.maxSourceCharacters, 12_000)
		assert.equal(limits.maxApiCalls, 10)
		assert.equal(limits.maxWallClockMs, 5_000)
		assert.equal(limits.maxCpuMs, 2_000)
		assert.equal(limits.maxHeapBytes, 32 * 1024 * 1024)
		assert.equal(limits.maxStackBytes, 256 * 1024)
		assert.equal(limits.maxOutputCharacters, 24_000)
	})
})

describe('host isolation', () => {
	it('exposes no host global by any name', async () => {
		const source = `return ${JSON.stringify(FORBIDDEN_GLOBALS)}.filter((name) => {
			try { return eval('typeof ' + name) !== 'undefined' } catch (error) { return false }
		})`
		const result = await sandbox.execute(source)
		assert.equal(result.ok, true, result.error?.message)
		assert.deepEqual(JSON.parse(result.json), [], 'a host global is reachable from the sandbox')
		assertNoLiveContext()
	})

	it('offers exactly one host capability, and it is frozen', async () => {
		const result = await sandbox.execute(`
			const own = Object.getOwnPropertyNames(globalThis).filter((k) => k === 'fluentcart')
			let replaced = false
			try { globalThis.fluentcart = { call: () => 'hijacked' }; replaced = globalThis.fluentcart.call() === 'hijacked' } catch (error) { replaced = false }
			return { own, keys: Object.keys(fluentcart), replaced }
		`)
		assert.equal(result.ok, true, result.error?.message)
		assert.deepEqual(JSON.parse(result.json), {
			own: ['fluentcart'],
			keys: ['call'],
			replaced: false,
		})
	})

	it('cannot reach the host through the function constructor', async () => {
		const result = await sandbox.execute(`
			try {
				const escape = fluentcart.call.constructor('return typeof process')
				return { escaped: escape() }
			} catch (error) { return { blocked: error.name } }
		`)
		assert.equal(result.ok, true, result.error?.message)
		const parsed = JSON.parse(result.json)
		assert.notEqual(parsed.escaped, 'object', 'the constructor leaked the host process object')
	})

	it('starts every execution from a clean global object', async () => {
		await sandbox.execute('globalThis.smuggled = "left behind"; return 1')
		const result = await sandbox.execute('return typeof globalThis.smuggled')
		assert.equal(result.json, '"undefined"', 'state survived between executions')
		assertNoLiveContext()
	})

	it('does not leak prototype tampering between executions', async () => {
		await sandbox.execute('Array.prototype.at = () => "tampered"; return 1')
		const result = await sandbox.execute('return [1, 2].at(-1)')
		assert.equal(result.json, '2')
	})
})

describe('module and filesystem refusals', () => {
	it('refuses every module-loading construct before a context is created', async () => {
		const before = sandbox.stats.contextsCreated
		for (const source of [
			'const fs = require("node:fs"); return 1',
			'const mod = await import("node:fs"); return 1',
			'return import.meta.url',
			'import fs from "node:fs"; return 1',
			'export const x = 1',
		]) {
			const error = await failWith(source)
			assert.equal(error.code, 'FORBIDDEN_SYNTAX', source)
		}
		assert.equal(sandbox.stats.contextsCreated, before, 'a refused source must not start a runtime')
	})

	it('refuses source above the character limit without starting a runtime', async () => {
		const before = sandbox.stats.contextsCreated
		const error = await failWith(`return "${'p'.repeat(limits.maxSourceCharacters)}"`)
		assert.equal(error.code, 'SOURCE_TOO_LARGE')
		assert.equal(sandbox.stats.contextsCreated, before)
	})

	it('has no filesystem to read even by an unguarded spelling', async () => {
		const result = await sandbox.execute(`
			const names = ['readFileSync', 'openSync', 'readdirSync', 'Buffer']
			return names.filter((n) => { try { return eval('typeof ' + n) !== 'undefined' } catch { return false } })
		`)
		assert.equal(result.ok, true, result.error?.message)
		assert.deepEqual(JSON.parse(result.json), [])
	})
})

describe('resource budgets', () => {
	it('interrupts an infinite loop at the CPU budget', async () => {
		const error = await failWith('while (true) {}')
		assert.equal(error.code, 'CPU_BUDGET_EXCEEDED')
		assertNoLiveContext()
	})

	it('turns unbounded recursion into a catchable error rather than an aborted module', async () => {
		const error = await failWith('function down() { return down() } return down()')
		assert.ok(['STACK_EXCEEDED', 'UNCAUGHT_EXCEPTION'].includes(error.code), error.code)
		assertNoLiveContext()
	})

	it('stops a runaway allocation at the heap ceiling', async () => {
		const error = await failWith(
			'const held = []; while (true) { held.push(new Array(100000).fill(7)) }',
		)
		assert.ok(['MEMORY_EXCEEDED', 'CPU_BUDGET_EXCEEDED'].includes(error.code), error.code)
		assertNoLiveContext()
	})

	it('stops after the tenth call, counting refusals too', async () => {
		// The budget is a catchable in-VM error, so a loop of bad names cannot spin for ever even
		// though every individual failure is swallowed by the caller's own try/catch.
		const result = await sandbox.execute(`
			for (let i = 0; i < 40; i += 1) {
				try { await fluentcart.call('fluentcart_not_a_real_operation') }
				catch (error) { if (error.code === 'CALL_BUDGET_EXCEEDED') return { code: error.code, calls: i } }
			}
			return { code: 'NOT_LIMITED' }
		`)
		assert.equal(result.ok, true, result.error?.message)
		assert.deepEqual(JSON.parse(result.json), {
			code: 'CALL_BUDGET_EXCEEDED',
			calls: limits.maxApiCalls,
		})
	})

	it('refuses an oversized result instead of truncating it into a plausible lie', async () => {
		const error = await failWith(`return "x".repeat(${limits.maxOutputCharacters + 1000})`)
		assert.equal(error.code, 'RESPONSE_TOO_LARGE')
		assert.match(error.message, /\d+ characters/)
	})
})

describe('write refusal', () => {
	it('refuses a write operation by name and never dispatches it', async () => {
		const result = await sandbox.execute(`
			try { await fluentcart.call('fluentcart_coupon_create', { code: 'NOPE' }) }
			catch (error) { return { code: error.code } }
			return { code: 'NOT_REFUSED' }
		`)
		assert.equal(result.ok, true, result.error?.message)
		assert.deepEqual(JSON.parse(result.json), { code: 'WRITE_OPERATION_REFUSED' })
	})

	it('refuses an unknown operation rather than guessing a close match', async () => {
		const result = await sandbox.execute(`
			try { await fluentcart.call('fluentcart_order_refund') }
			catch (error) { return error.code }
			return 'NOT_REFUSED'
		`)
		assert.equal(result.json, '"UNKNOWN_OPERATION"')
	})

	it('exposes no write name through the searchable index', () => {
		for (const name of index.names()) {
			assert.ok(index.has(name))
			assert.equal(index.isExcludedWrite(name), false, `${name} is indexed and excluded at once`)
		}
	})
})

describe('the store pays nothing for a rejected snippet', () => {
	it('made no REST request across the entire attack matrix', () => {
		assert.equal(restCalls, 0, `${restCalls} REST requests escaped the sandbox`)
	})

	it('left no QuickJS context alive', () => {
		assertNoLiveContext()
	})
})
