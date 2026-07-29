import { describe, expect, it, vi } from 'vitest'
import { z } from 'zod'
import type { ToolDefinition } from '../../src/tools/_factory.js'
import {
	DYNAMIC_TOOL_COUNT,
	DYNAMIC_TOOL_NAMES,
	registerDynamicTools,
} from '../../src/tools/dynamic.js'
import type { ToolRisk } from '../../src/tools/risk.js'

interface Registered {
	name: string
	config: { inputSchema: z.ZodObject<z.ZodRawShape>; annotations?: Record<string, unknown> }
	handler: (
		input: Record<string, unknown>,
		requestContext?: { mcpReq: { signal: AbortSignal } },
	) => Promise<{ content: Array<{ type: string; text: string }>; isError?: boolean }>
}

function fakeServer() {
	const registered: Registered[] = []
	return {
		registered,
		server: {
			registerTool: (name: string, config: Registered['config'], handler: Registered['handler']) =>
				registered.push({ name, config, handler }),
		} as never,
	}
}

const SAFETY: Record<ToolRisk, ToolDefinition['safety']> = {
	read: { risk: 'read', idempotency: 'inherent', execution: 'rest' },
	'reversible-write': { risk: 'reversible-write', idempotency: 'inherent', execution: 'rest' },
	'real-money': { risk: 'real-money', idempotency: 'unsupported', execution: 'none' },
	'destructive-write': { risk: 'destructive-write', idempotency: 'unsupported', execution: 'none' },
	'external-side-effect': {
		risk: 'external-side-effect',
		idempotency: 'unsupported',
		execution: 'none',
	},
	'control-plane': { risk: 'control-plane', idempotency: 'unsupported', execution: 'none' },
	'credential-bearing': {
		risk: 'credential-bearing',
		idempotency: 'unsupported',
		execution: 'none',
	},
	infrastructure: { risk: 'infrastructure', idempotency: 'unsupported', execution: 'none' },
	'unreviewed-write': { risk: 'unreviewed-write', idempotency: 'unsupported', execution: 'none' },
}

function tool(name: string, risk: ToolRisk, handler = vi.fn()): ToolDefinition {
	return {
		name,
		title: name.replace(/_/g, ' '),
		description: `Does ${name}.`,
		schema: z.object({ id: z.number() }),
		annotations: { readOnlyHint: risk === 'read', openWorldHint: true },
		safety: SAFETY[risk],
		handler: handler as unknown as ToolDefinition['handler'],
	}
}

function setup(tools: ToolDefinition[]) {
	const { registered, server } = fakeServer()
	const reported = registerDynamicTools(server, tools)
	return { registered, reported, byName: new Map(registered.map((entry) => [entry.name, entry])) }
}

const DEFAULT_TOOLS = [
	tool('fluentcart_product_list', 'read'),
	tool('fluentcart_order_list', 'read'),
	tool('fluentcart_coupon_create', 'reversible-write'),
	tool('fluentcart_order_refund', 'real-money'),
]

async function callTool(entry: Registered, input: Record<string, unknown>) {
	const result = await entry.handler(input)
	return { raw: result, json: JSON.parse(result.content[0]?.text ?? 'null') }
}

describe('dynamic mode registration', () => {
	it('has exactly the supported public executor roster', () => {
		expect(DYNAMIC_TOOL_NAMES).toEqual([
			'fluentcart_search_tools',
			'fluentcart_describe_tools',
			'fluentcart_execute_read_tool',
			'fluentcart_execute_reversible_write',
		])
		expect(DYNAMIC_TOOL_COUNT).toBe(4)
	})

	it('registers only search, describe and read when no reversible operation is exposed', () => {
		const { registered, reported } = setup([tool('fluentcart_product_list', 'read')])
		expect(registered.map((entry) => entry.name)).toEqual(DYNAMIC_TOOL_NAMES.slice(0, 3))
		expect(reported).toEqual(registered.map((entry) => entry.name))
	})

	it('adds the reversible executor, but never a real-money executor', () => {
		const { registered, reported } = setup([
			tool('fluentcart_product_list', 'read'),
			tool('fluentcart_coupon_create', 'reversible-write'),
			tool('fluentcart_order_refund', 'real-money'),
		])
		expect(registered.map((entry) => entry.name)).toEqual(DYNAMIC_TOOL_NAMES)
		expect(reported).toEqual(DYNAMIC_TOOL_NAMES)
	})

	it('annotates each executor according to its actual risk boundary', () => {
		const { byName } = setup(DEFAULT_TOOLS)
		for (const name of ['fluentcart_search_tools', 'fluentcart_describe_tools']) {
			expect(byName.get(name)?.config.annotations).toMatchObject({
				readOnlyHint: true,
				destructiveHint: false,
				openWorldHint: false,
			})
		}
		expect(byName.get('fluentcart_execute_read_tool')?.config.annotations).toMatchObject({
			readOnlyHint: true,
			destructiveHint: false,
		})
		expect(byName.get('fluentcart_execute_reversible_write')?.config.annotations).toMatchObject({
			readOnlyHint: false,
			destructiveHint: false,
			idempotentHint: false,
		})
	})
})

describe('fluentcart_search_tools', () => {
	it('returns at most five matches by default', async () => {
		const many = Array.from({ length: 12 }, (_, index) =>
			tool(`fluentcart_product_${index}`, 'read'),
		)
		const { byName } = setup(many)
		const { json } = await callTool(byName.get('fluentcart_search_tools')!, {
			query: 'product',
		})
		expect(json.tools).toHaveLength(5)
	})

	it('accepts a limit up to ten and rejects values outside the contract', () => {
		const { byName } = setup(DEFAULT_TOOLS)
		const schema = byName.get('fluentcart_search_tools')!.config.inputSchema
		expect(schema.safeParse({ query: 'x', limit: 10 }).success).toBe(true)
		expect(schema.safeParse({ query: 'x', limit: 11 }).success).toBe(false)
		expect(schema.safeParse({ query: 'x', limit: 0 }).success).toBe(false)
		expect(schema.safeParse({ query: 'x', limit: 2.5 }).success).toBe(false)
	})

	it('reports the reviewed risk and unavailable execution state on every row', async () => {
		const { byName } = setup(DEFAULT_TOOLS)
		const { json } = await callTool(byName.get('fluentcart_search_tools')!, {
			query: 'refund',
		})
		expect(json.tools[0]).toMatchObject({
			name: 'fluentcart_order_refund',
			risk: 'real-money',
			execution: 'none',
			idempotency: 'unsupported',
		})
	})

	it('keeps equal-score results stable by name', async () => {
		const { byName } = setup([
			tool('fluentcart_order_zebra', 'read'),
			tool('fluentcart_order_alpha', 'read'),
		])
		const { json } = await callTool(byName.get('fluentcart_search_tools')!, { query: 'order' })
		expect(json.tools.map((row: { name: string }) => row.name)).toEqual([
			'fluentcart_order_alpha',
			'fluentcart_order_zebra',
		])
	})

	it('offers callable entry points when no description matches', async () => {
		const productList = tool('fluentcart_product_list', 'read')
		productList.schema = z.object({})
		const activityList = tool('fluentcart_activity_list', 'read')
		activityList.schema = z.object({})
		const { byName } = setup([activityList, productList])
		const { json } = await callTool(byName.get('fluentcart_search_tools')!, {
			query: 'green shirt',
		})
		expect(json).toMatchObject({
			matches: 0,
			tools: [],
			starting_points: ['fluentcart_product_list', 'fluentcart_activity_list'],
		})
	})
})

describe('fluentcart_describe_tools', () => {
	it('accepts at most five names per call', () => {
		const { byName } = setup(DEFAULT_TOOLS)
		const schema = byName.get('fluentcart_describe_tools')!.config.inputSchema
		expect(schema.safeParse({ tools: ['a', 'b', 'c', 'd', 'e'] }).success).toBe(true)
		expect(schema.safeParse({ tools: ['a', 'b', 'c', 'd', 'e', 'f'] }).success).toBe(false)
		expect(schema.safeParse({ tools: [] }).success).toBe(false)
	})

	it('names supported executors and leaves unavailable risk classes without one', async () => {
		const { byName } = setup(DEFAULT_TOOLS)
		const { json } = await callTool(byName.get('fluentcart_describe_tools')!, {
			tools: ['fluentcart_product_list', 'fluentcart_coupon_create', 'fluentcart_order_refund'],
		})
		expect(json[0]).toMatchObject({ executor: 'fluentcart_execute_read_tool' })
		expect(json[1]).toMatchObject({ executor: 'fluentcart_execute_reversible_write' })
		expect(json[2]).toMatchObject({ risk: 'real-money', execution: 'none', executor: null })
	})

	it('reports an unknown name without inventing a schema', async () => {
		const { byName } = setup(DEFAULT_TOOLS)
		const { json } = await callTool(byName.get('fluentcart_describe_tools')!, {
			tools: ['fluentcart_missing'],
		})
		expect(json[0]).toMatchObject({ name: 'fluentcart_missing' })
		expect(json[0].error).toMatch(/not available|not found/i)
	})
})

describe('risk-split execution', () => {
	it('executes reads and forwards the request cancellation signal', async () => {
		const handler = vi.fn(async () => ({
			content: [{ type: 'text' as const, text: '{"ok":true}' }],
		}))
		const { byName } = setup([tool('fluentcart_product_list', 'read', handler)])
		const controller = new AbortController()
		const result = await byName
			.get('fluentcart_execute_read_tool')!
			.handler(
				{ tool_name: 'fluentcart_product_list', input: { id: 1 } },
				{ mcpReq: { signal: controller.signal } },
			)
		expect(result.isError).toBeFalsy()
		expect(handler).toHaveBeenCalledWith({ id: 1 }, { signal: controller.signal })
	})

	it('executes a reversible write only through the reversible executor', async () => {
		const handler = vi.fn(async () => ({
			content: [{ type: 'text' as const, text: '{"ok":true}' }],
		}))
		const { byName } = setup([tool('fluentcart_coupon_create', 'reversible-write', handler)])
		const result = await byName
			.get('fluentcart_execute_reversible_write')!
			.handler({ tool_name: 'fluentcart_coupon_create', input: { id: 1 } })
		expect(result.isError).toBeFalsy()
		expect(handler).toHaveBeenCalledOnce()
	})

	it('refuses unavailable real-money operations without naming a removed executor', async () => {
		const handler = vi.fn()
		const { byName } = setup([
			tool('fluentcart_product_list', 'read'),
			tool('fluentcart_order_refund', 'real-money', handler),
		])
		const result = await byName
			.get('fluentcart_execute_read_tool')!
			.handler({ tool_name: 'fluentcart_order_refund', input: { id: 1 } })
		expect(result.isError).toBe(true)
		expect(result.content[0]?.text).toMatch(/not exposed|not executable/)
		expect(handler).not.toHaveBeenCalled()
	})

	it('directs reversible writes away from the read executor', async () => {
		const handler = vi.fn()
		const { byName } = setup([tool('fluentcart_coupon_create', 'reversible-write', handler)])
		const result = await byName
			.get('fluentcart_execute_read_tool')!
			.handler({ tool_name: 'fluentcart_coupon_create', input: { id: 1 } })
		expect(result.isError).toBe(true)
		expect(result.content[0]?.text).toContain('fluentcart_execute_reversible_write')
		expect(handler).not.toHaveBeenCalled()
	})

	it('refuses a name that is not exposed', async () => {
		const { byName } = setup(DEFAULT_TOOLS)
		const result = await byName
			.get('fluentcart_execute_read_tool')!
			.handler({ tool_name: 'fluentcart_order_delete', input: {} })
		expect(result.isError).toBe(true)
		expect(result.content[0]?.text).toMatch(/not exposed/)
	})

	it('refuses a matching risk whose execution policy is none', async () => {
		const handler = vi.fn()
		const unavailable = tool('fluentcart_product_list', 'read', handler)
		unavailable.safety = { risk: 'read', idempotency: 'unsupported', execution: 'none' }
		const { byName } = setup([unavailable])
		const result = await byName
			.get('fluentcart_execute_read_tool')!
			.handler({ tool_name: unavailable.name, input: { id: 1 } })
		expect(result.isError).toBe(true)
		expect(result.content[0]?.text).toMatch(/not executable/)
		expect(handler).not.toHaveBeenCalled()
	})

	it('revalidates input immediately before dispatch', async () => {
		const handler = vi.fn()
		const { byName } = setup([tool('fluentcart_product_list', 'read', handler)])
		const result = await byName
			.get('fluentcart_execute_read_tool')!
			.handler({ tool_name: 'fluentcart_product_list', input: { id: 'not-a-number' } })
		expect(result.isError).toBe(true)
		expect(result.content[0]?.text).toMatch(/Validation error/)
		expect(handler).not.toHaveBeenCalled()
	})
})
