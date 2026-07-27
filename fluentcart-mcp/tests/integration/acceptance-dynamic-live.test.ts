// Dynamic-mode progressive disclosure, measured against a live store.
//
// Dynamic mode's whole argument is that a caller pays for a handful of meta-tools instead of a
// hundred and fifty definitions, and then pays again, in small amounts, only for what it asks about.
// That argument is only true if the disclosure steps stay small, so this lane measures the real
// wire payloads of a real session rather than trusting the design.

import { Client } from '@modelcontextprotocol/sdk/client/index.js'
import { InMemoryTransport } from '@modelcontextprotocol/sdk/inMemory.js'
import { encode as encodeCl100k } from 'gpt-tokenizer/encoding/cl100k_base'
import { afterAll, beforeAll, describe, expect, it } from 'vitest'
import { createServerFromContextAsync } from '../../src/server.js'
import { acceptanceContext } from './support/acceptance-fixture.js'
import { getLiveRun } from './support/live-run.js'

const run = getLiveRun()

/** Plan 08 Task 4 Step 2 budgets. Characters where the limit is about payload, tokens where cost. */
const SEARCH_ROW_CHARACTERS = 3_000
const DESCRIBE_CHARACTERS = 4_000
const COMBINED_CL100K = 4_000
const SEARCH_LIMIT_DEFAULT = 5
const SEARCH_LIMIT_MAX = 10
const DESCRIBE_MAX = 5

/**
 * The guarded executor used to be listed here too. It is registered only when a real-money action
 * survives the exposure filter, and every real-money entry ships `execution: 'none'` in 2.0.0, so
 * listing it would assert a tool that answers nothing but "not exposed" on every call.
 */
const DYNAMIC_NAMES = [
	'fluentcart_describe_tools',
	'fluentcart_execute_read_tool',
	'fluentcart_execute_reversible_write',
	'fluentcart_search_tools',
]

let client: Client
let close: () => Promise<void>
let definitionTokens = 0

function textOf(result: unknown): string {
	const content = (result as { content?: { type: string; text?: string }[] }).content ?? []
	return content.map((block) => block.text ?? '').join('')
}

/** Every tool result must be one complete JSON document, never a truncated fragment. */
function completeJson(result: unknown): unknown {
	const text = textOf(result)
	expect(text.length).toBeGreaterThan(0)
	return JSON.parse(text)
}

const tokens = (text: string) => encodeCl100k(text).length

async function call(name: string, args: Record<string, unknown>) {
	return client.callTool({ name, arguments: args })
}

beforeAll(async () => {
	const ctx = await acceptanceContext('disabled')
	const server = await createServerFromContextAsync(ctx, 'dynamic')
	const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair()
	client = new Client({ name: 'acceptance-dynamic', version: '1' }, { capabilities: {} })
	await Promise.all([server.connect(serverTransport), client.connect(clientTransport)])
	close = async () => {
		await client.close()
		await server.close()
	}
	console.error(`dynamic lane: run ${run.id}, ${ctx.tools.length} tools behind the meta-tools`)
}, 120_000)

afterAll(async () => {
	await close?.()
})

describe('startup surface', () => {
	it('registers exactly the five meta-tools and nothing else', async () => {
		const listed = await client.listTools()
		expect(listed.tools.map((tool) => tool.name).sort()).toEqual(DYNAMIC_NAMES)
	})

	it('costs a small, measured definition payload', async () => {
		const listed = await client.listTools()
		definitionTokens = tokens(JSON.stringify(listed.tools))
		console.error(`dynamic lane: definitions cost ${definitionTokens} cl100k tokens`)
		expect(definitionTokens).toBeLessThanOrEqual(1_500)
	})
})

describe('search disclosure', () => {
	it('defaults to five rows without being asked', async () => {
		const result = await call('fluentcart_search_tools', { query: 'order' })
		const payload = completeJson(result) as { matches: number; tools: unknown[] }
		expect(payload.tools.length).toBe(SEARCH_LIMIT_DEFAULT)
		expect(payload.matches).toBe(SEARCH_LIMIT_DEFAULT)
	})

	it('caps at ten rows and refuses to be pushed past it', async () => {
		const capped = await call('fluentcart_search_tools', {
			query: 'order',
			limit: SEARCH_LIMIT_MAX,
		})
		const payload = completeJson(capped) as { tools: unknown[] }
		expect(payload.tools.length).toBeLessThanOrEqual(SEARCH_LIMIT_MAX)

		const rejected = await call('fluentcart_search_tools', { query: 'order', limit: 50 })
		expect((rejected as { isError?: boolean }).isError).toBe(true)
	})

	it('keeps a full page of rows inside the summary budget', async () => {
		const result = await call('fluentcart_search_tools', {
			query: 'order',
			limit: SEARCH_LIMIT_MAX,
		})
		const text = textOf(result)
		console.error(`dynamic lane: ten search rows are ${text.length} characters`)
		expect(text.length).toBeLessThanOrEqual(SEARCH_ROW_CHARACTERS)
	})

	it('carries risk, execution and idempotency on every row', async () => {
		const result = await call('fluentcart_search_tools', { query: 'customer' })
		const payload = completeJson(result) as {
			total_available: number
			tools: Record<string, unknown>[]
		}
		expect(payload.total_available).toBeGreaterThan(0)
		for (const row of payload.tools) {
			// A caller must never have to guess whether the tool it just found moves money.
			expect(typeof row.risk).toBe('string')
			expect(typeof row.execution).toBe('string')
			expect(typeof row.idempotency).toBe('string')
			expect(row.risk).toBe('read')
		}
	})

	it('answers an unmatchable query completely rather than erroring', async () => {
		const result = await call('fluentcart_search_tools', { query: 'zzzznotathing' })
		const payload = completeJson(result) as { matches: number; tools: unknown[] }
		expect(payload.matches).toBe(0)
		expect(payload.tools).toEqual([])
	})
})

describe('describe disclosure', () => {
	it('keeps one tool inside the describe budget', async () => {
		const result = await call('fluentcart_describe_tools', { tools: ['fluentcart_order_list'] })
		const text = textOf(result)
		console.error(`dynamic lane: one describe is ${text.length} characters`)
		expect(text.length).toBeLessThanOrEqual(DESCRIBE_CHARACTERS)

		const payload = completeJson(result) as Record<string, unknown>[]
		expect(payload[0]?.name).toBe('fluentcart_order_list')
		expect(payload[0]?.inputSchema).toBeDefined()
		expect(payload[0]?.risk).toBe('read')
		expect(payload[0]?.executor).toBe('fluentcart_execute_read_tool')
	})

	it('refuses more than five names in one call', async () => {
		const rejected = await call('fluentcart_describe_tools', {
			tools: Array.from({ length: DESCRIBE_MAX + 1 }, () => 'fluentcart_order_list'),
		})
		expect((rejected as { isError?: boolean }).isError).toBe(true)
	})

	it('names an unknown tool as unavailable instead of inventing a schema', async () => {
		const result = await call('fluentcart_describe_tools', { tools: ['fluentcart_not_a_tool'] })
		const payload = completeJson(result) as Record<string, unknown>[]
		expect(payload[0]?.error).toMatch(/not found|not available/i)
	})
})

describe('execution disclosure', () => {
	it('reads five orders through the read executor', async () => {
		const result = await call('fluentcart_execute_read_tool', {
			tool_name: 'fluentcart_order_list',
			input: { page: 1, per_page: 5 },
		})
		expect((result as { isError?: boolean }).isError).toBeFalsy()
		expect(completeJson(result)).toBeDefined()
	})

	it('refuses a write through the read executor and names the right one', async () => {
		const result = await call('fluentcart_execute_read_tool', {
			tool_name: 'fluentcart_customer_create',
			input: {},
		})
		expect((result as { isError?: boolean }).isError).toBe(true)
		// In disabled mode the tool is not exposed at all, so the refusal is about exposure.
		expect(textOf(result)).toMatch(/not exposed|wrong executor/i)
	})

	it('refuses an unknown tool name', async () => {
		const result = await call('fluentcart_execute_read_tool', {
			tool_name: 'fluentcart_make_me_a_sandwich',
			input: {},
		})
		expect((result as { isError?: boolean }).isError).toBe(true)
	})

	it('revalidates input at dispatch, so discovery never implies a valid call', async () => {
		const result = await call('fluentcart_execute_read_tool', {
			tool_name: 'fluentcart_order_list',
			input: { per_page: 5000 },
		})
		expect((result as { isError?: boolean }).isError).toBe(true)
		expect(textOf(result)).toMatch(/validation/i)
	})
})

describe('the representative orders journey', () => {
	it('stays under 4,000 cl100k tokens for definitions, search, describe and a five-row read', async () => {
		const listed = await client.listTools()
		const definitions = tokens(JSON.stringify(listed.tools))

		const search = await call('fluentcart_search_tools', { query: 'orders' })
		const describe = await call('fluentcart_describe_tools', { tools: ['fluentcart_order_list'] })
		const read = await call('fluentcart_execute_read_tool', {
			tool_name: 'fluentcart_order_list',
			input: { page: 1, per_page: 5 },
		})

		for (const step of [search, describe, read]) completeJson(step)

		const parts = {
			definitions,
			search: tokens(textOf(search)),
			describe: tokens(textOf(describe)),
			read: tokens(textOf(read)),
		}
		const total = Object.values(parts).reduce((sum, value) => sum + value, 0)
		console.error(`dynamic lane: journey ${JSON.stringify(parts)} = ${total} cl100k tokens`)
		expect(total).toBeLessThanOrEqual(COMBINED_CL100K)
	})
})
