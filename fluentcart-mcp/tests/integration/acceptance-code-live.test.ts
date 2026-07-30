import { Client, InMemoryTransport } from '@modelcontextprotocol/client'

// Code Mode composition against a live store.
//
// The isolation attack matrix lives in tests/acceptance/code-sandbox.test.mjs and runs offline.
// What can only be proven here is the other half of the claim: that the sandbox genuinely reaches
// the read API, composes several calls into one answer without a round trip per call, and still
// cannot write — not even when the surrounding policy has writes switched on.
import { afterAll, beforeAll, describe, expect, it } from 'vitest'
import { buildApiIndex } from '../../src/code-mode/api-index.js'
import { CODE_MODE_LIMITS } from '../../src/code-mode/limits.js'
import type { ServerContext } from '../../src/server.js'
import { createServerFromContext, createServerFromContextAsync } from '../../src/server.js'
import { acceptanceContext } from './support/acceptance-fixture.js'
import { getLiveRun } from './support/live-run.js'

const run = getLiveRun()
const CODE_NAMES = ['fluentcart_execute_code', 'fluentcart_search_api']

let readOnlyCtx: ServerContext
let reversibleCtx: ServerContext
let client: Client
let close: () => Promise<void>

function textOf(result: unknown): string {
	const content = (result as { content?: { type: string; text?: string }[] }).content ?? []
	return content.map((block) => block.text ?? '').join('')
}

async function connect(
	ctx: ServerContext,
): Promise<{ client: Client; close: () => Promise<void> }> {
	const server = await createServerFromContextAsync(ctx, 'code')
	const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair()
	const connected = new Client({ name: 'acceptance-code', version: '1' }, { capabilities: {} })
	await Promise.all([server.connect(serverTransport), connected.connect(clientTransport)])
	return {
		client: connected,
		close: async () => {
			await connected.close()
			await server.close()
		},
	}
}

/** Run one snippet and return the parsed `{result, api_calls}` envelope. */
async function execute(code: string): Promise<{ raw: unknown; isError: boolean; text: string }> {
	const result = await client.callTool({
		name: 'fluentcart_execute_code',
		arguments: { code },
	})
	const text = textOf(result)
	return {
		raw: JSON.parse(text),
		isError: (result as { isError?: boolean }).isError === true,
		text,
	}
}

beforeAll(async () => {
	readOnlyCtx = await acceptanceContext('disabled')
	reversibleCtx = await acceptanceContext('reversible')
	const session = await connect(readOnlyCtx)
	client = session.client
	close = session.close
	console.error(`code lane: run ${run.id}`)
}, 120_000)

afterAll(async () => {
	await close?.()
})

describe('code mode construction', () => {
	it('needs the asynchronous constructor, because the sandbox starts asynchronously', () => {
		expect(() => createServerFromContext(readOnlyCtx, 'code')).toThrow(
			/createServerFromContextAsync/,
		)
	})

	it('registers exactly two tools', async () => {
		const listed = await client.listTools()
		expect(listed.tools.map((tool) => tool.name).sort()).toEqual(CODE_NAMES)
	})

	it('advertises the sandbox only after it has actually started', async () => {
		// registerCodeModeTools refuses registration when the self-test fails, so two tools being
		// present is itself evidence that a WebAssembly runtime ran and evaluated.
		const listed = await client.listTools()
		expect(listed.tools).toHaveLength(2)
	})
})

describe('read discovery', () => {
	it('returns compact declarations for read operations only', async () => {
		const result = await client.callTool({
			name: 'fluentcart_search_api',
			arguments: { query: 'orders and customers' },
		})
		const payload = JSON.parse(textOf(result)) as {
			matches: number
			operations: { operation: string; summary: string; declaration: string }[]
		}
		expect(payload.matches).toBeGreaterThan(0)
		expect(payload.operations.length).toBeLessThanOrEqual(5)

		const reads = new Set(
			readOnlyCtx.tools.filter((tool) => tool.safety.risk === 'read').map((tool) => tool.name),
		)
		for (const entry of payload.operations) {
			expect(reads.has(entry.operation), `${entry.operation} is not a read`).toBe(true)
			// The declaration is what the model copies into its code, so it must name the operation.
			expect(entry.declaration).toContain(entry.operation)
		}
	})

	it('indexes no write, even when the surrounding policy exposes writes', () => {
		const index = buildApiIndex(reversibleCtx.tools)
		const writes = reversibleCtx.tools.filter((tool) => tool.safety.risk !== 'read')
		expect(writes.length).toBeGreaterThan(0)
		for (const tool of writes) {
			expect(index.has(tool.name), `${tool.name} must not be in the read index`).toBe(false)
			expect(index.isExcludedWrite(tool.name)).toBe(true)
		}
	})
})

describe('live composition', () => {
	it('joins two live reads inside one execution and returns one bounded result', async () => {
		const { raw, isError, text } = await execute(`
			const orders = await fluentcart.call('fluentcart_order_list', { page: 1, per_page: 2 })
			const customers = await fluentcart.call('fluentcart_customer_list', { page: 1, per_page: 2 })
			return {
				orderShape: Object.keys(orders).sort(),
				customerShape: Object.keys(customers).sort(),
				joined: Object.keys(orders).length > 0 && Object.keys(customers).length > 0,
			}
		`)

		expect(isError, text).toBe(false)
		const envelope = raw as { result: Record<string, unknown>; api_calls: number }
		// Two calls, one round trip. That is the entire economic argument for code mode.
		expect(envelope.api_calls).toBe(2)
		expect(envelope.result.joined).toBe(true)
		expect(Array.isArray(envelope.result.orderShape)).toBe(true)
		expect(text.length).toBeLessThanOrEqual(CODE_MODE_LIMITS.maxOutputCharacters)
	})

	it('counts calls host-side, so sandboxed code cannot under-report them', async () => {
		const { raw, isError, text } = await execute(`
			await fluentcart.call('fluentcart_product_list', { page: 1, per_page: 1 })
			await fluentcart.call('fluentcart_product_list', { page: 1, per_page: 1 })
			await fluentcart.call('fluentcart_product_list', { page: 1, per_page: 1 })
			return { claimed: 0 }
		`)
		expect(isError, text).toBe(false)
		const envelope = raw as { result: { claimed: number }; api_calls: number }
		expect(envelope.result.claimed).toBe(0)
		expect(envelope.api_calls).toBe(3)
	})

	it('returns one complete JSON document, never a truncated one', async () => {
		const { text } = await execute('return { ok: true, nested: { deep: [1, 2, 3] } }')
		const envelope = JSON.parse(text) as { result: unknown }
		expect(envelope.result).toEqual({ ok: true, nested: { deep: [1, 2, 3] } })
	})
})

describe('no write is reachable by any spelling', () => {
	it('refuses a write the surrounding policy exposes', async () => {
		const session = await connect(reversibleCtx)
		try {
			const result = await session.client.callTool({
				name: 'fluentcart_execute_code',
				arguments: {
					code: `
						try { await fluentcart.call('fluentcart_customer_create', { email: 'x@example.invalid' }) }
						catch (error) { return { code: error.code } }
						return { code: 'NOT_REFUSED' }
					`,
				},
			})
			const envelope = JSON.parse(textOf(result)) as { result: { code: string } }
			expect(envelope.result.code).toBe('WRITE_OPERATION_REFUSED')
		} finally {
			await session.close()
		}
	})

	it('refuses a write assembled from a string at runtime', async () => {
		const { raw } = await execute(`
			const name = ['fluentcart', 'customer', 'create'].join('_')
			try { await fluentcart.call(name, {}) }
			catch (error) { return { code: error.code } }
			return { code: 'NOT_REFUSED' }
		`)
		const envelope = raw as { result: { code: string } }
		// In this policy the write is not exposed at all, so it is unknown rather than refused.
		expect(['UNKNOWN_OPERATION', 'WRITE_OPERATION_REFUSED']).toContain(envelope.result.code)
	})

	it('exposes no write declaration to the model in the first place', async () => {
		for (const query of ['create', 'update', 'delete', 'refund', 'cancel']) {
			const result = await client.callTool({
				name: 'fluentcart_search_api',
				arguments: { query },
			})
			const payload = JSON.parse(textOf(result)) as { operations?: { operation: string }[] }
			for (const entry of payload.operations ?? []) {
				const tool = readOnlyCtx.tools.find((candidate) => candidate.name === entry.operation)
				expect(tool?.safety.risk, `${entry.operation} surfaced for "${query}"`).toBe('read')
			}
		}
	})
})
