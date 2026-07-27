import { describe, expect, it, vi } from 'vitest'
import { z } from 'zod'
import type { ToolDefinition } from '../../src/tools/_factory.js'
import {
	DYNAMIC_TOOL_COUNT,
	DYNAMIC_TOOL_NAMES,
	GUARDED_EXECUTOR_TOOL_NAME,
	registerDynamicTools,
} from '../../src/tools/dynamic.js'
import type { ToolRisk } from '../../src/tools/risk.js'

interface Registered {
	name: string
	config: {
		title: string
		description: string
		inputSchema: z.ZodObject<z.ZodRawShape>
		annotations?: Record<string, unknown>
	}
	handler: (input: Record<string, unknown>) => Promise<{
		content: Array<{ type: string; text: string }>
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

const SAFETY: Record<ToolRisk, ToolDefinition['safety']> = {
	read: { risk: 'read', idempotency: 'inherent', execution: 'rest' },
	'reversible-write': { risk: 'reversible-write', idempotency: 'inherent', execution: 'rest' },
	'real-money': { risk: 'real-money', idempotency: 'guard-required', execution: 'guarded-rest' },
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
		description: `Does ${name}. Returns a payload.`,
		schema: z.object({ id: z.number().describe('Record id') }),
		annotations: { readOnlyHint: risk === 'read', openWorldHint: true },
		safety: SAFETY[risk],
		handler: handler as unknown as ToolDefinition['handler'],
	}
}

function setup(tools: ToolDefinition[]) {
	const { registered, server } = fakeServer()
	const reported = registerDynamicTools(server, tools)
	return {
		registered,
		reported,
		byName: new Map(registered.map((entry) => [entry.name, entry])),
	}
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
	// This block used to assert five meta-tools unconditionally. It could not stay: every
	// real-money entry in the risk registry ships `execution: 'none'`, so `canExposeTool` removes
	// all of them and the guarded executor was advertised with `destructiveHint: true` at a 100%
	// failure rate. It is now registered only when a real-money tool actually survived the filter.
	it('registers search, describe and the two executors that can always reach something', () => {
		const { registered, reported } = setup([
			tool('fluentcart_product_list', 'read'),
			tool('fluentcart_coupon_create', 'reversible-write'),
		])

		expect(registered.map((entry) => entry.name)).toEqual([
			'fluentcart_search_tools',
			'fluentcart_describe_tools',
			'fluentcart_execute_read_tool',
			'fluentcart_execute_reversible_write',
		])
		expect(registered).toHaveLength(DYNAMIC_TOOL_COUNT)
		expect(reported).toEqual(registered.map((entry) => entry.name))
	})

	it('withholds the guarded executor when no real-money tool survived the exposure filter', () => {
		const { byName, reported } = setup([
			tool('fluentcart_product_list', 'read'),
			tool('fluentcart_coupon_create', 'reversible-write'),
		])

		expect(byName.has(GUARDED_EXECUTOR_TOOL_NAME)).toBe(false)
		expect(reported).not.toContain(GUARDED_EXECUTOR_TOOL_NAME)
	})

	it('registers the guarded executor as soon as a real-money tool is exposed', () => {
		const { registered, reported } = setup(DEFAULT_TOOLS)

		expect(registered.map((entry) => entry.name)).toEqual([
			...DYNAMIC_TOOL_NAMES,
			GUARDED_EXECUTOR_TOOL_NAME,
		])
		expect(reported).toEqual(registered.map((entry) => entry.name))
	})

	it('reports exactly what it registered, never the constant roster', () => {
		const readOnly = setup([tool('fluentcart_product_list', 'read')])
		const withRefund = setup(DEFAULT_TOOLS)

		for (const result of [readOnly, withRefund]) {
			expect(result.reported).toEqual(result.registered.map((entry) => entry.name))
		}
		expect(withRefund.reported.length).toBe(readOnly.reported.length + 1)
	})

	it('annotates each executor according to what it can actually do', () => {
		const { byName } = setup(DEFAULT_TOOLS)
		expect(byName.get('fluentcart_execute_read_tool')?.config.annotations?.readOnlyHint).toBe(true)
		expect(
			byName.get('fluentcart_execute_reversible_write')?.config.annotations?.destructiveHint,
		).not.toBe(true)
		expect(byName.get(GUARDED_EXECUTOR_TOOL_NAME)?.config.annotations?.destructiveHint).toBe(true)
	})
})

describe('fluentcart_search_tools', () => {
	it('returns at most five matches by default', async () => {
		const many = Array.from({ length: 12 }, (_, i) => tool(`fluentcart_product_${i}`, 'read'))
		const { byName } = setup(many)
		const { json } = await callTool(byName.get('fluentcart_search_tools')!, { query: 'product' })

		expect(json.tools).toHaveLength(5)
	})

	it('accepts a limit up to ten and rejects anything outside that', () => {
		const { byName } = setup(DEFAULT_TOOLS)
		const schema = byName.get('fluentcart_search_tools')!.config.inputSchema

		expect(schema.safeParse({ query: 'x', limit: 10 }).success).toBe(true)
		expect(schema.safeParse({ query: 'x', limit: 11 }).success).toBe(false)
		expect(schema.safeParse({ query: 'x', limit: 0 }).success).toBe(false)
		expect(schema.safeParse({ query: 'x', limit: 2.5 }).success).toBe(false)
	})

	it('reports risk, execution and idempotency on every row', async () => {
		const { byName } = setup(DEFAULT_TOOLS)
		const { json } = await callTool(byName.get('fluentcart_search_tools')!, { query: 'refund' })

		const row = json.tools.find((r: { name: string }) => r.name === 'fluentcart_order_refund')
		expect(row).toMatchObject({
			risk: 'real-money',
			execution: 'guarded-rest',
			idempotency: 'guard-required',
		})
	})

	it('sorts by score then name so ordering is stable', async () => {
		const { byName } = setup([
			tool('fluentcart_order_zebra', 'read'),
			tool('fluentcart_order_alpha', 'read'),
		])
		const { json } = await callTool(byName.get('fluentcart_search_tools')!, { query: 'order' })

		expect(json.tools.map((r: { name: string }) => r.name)).toEqual([
			'fluentcart_order_alpha',
			'fluentcart_order_zebra',
		])
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

	it('names the executor a caller must use for each tool', async () => {
		const { byName } = setup(DEFAULT_TOOLS)
		const { json } = await callTool(byName.get('fluentcart_describe_tools')!, {
			tools: ['fluentcart_order_refund', 'fluentcart_product_list'],
		})

		expect(json[0]).toMatchObject({
			risk: 'real-money',
			executor: 'fluentcart_execute_guarded_write',
		})
		expect(json[1]).toMatchObject({ risk: 'read', executor: 'fluentcart_execute_read_tool' })
	})

	it('reports an unknown name without inventing a schema', async () => {
		const { byName } = setup(DEFAULT_TOOLS)
		const { json } = await callTool(byName.get('fluentcart_describe_tools')!, { tools: ['nope'] })
		expect(json[0].error).toMatch(/not available|not found/i)
	})
})

describe('risk-split execution', () => {
	it('executes a read through the read executor', async () => {
		const handler = vi.fn(async () => ({
			content: [{ type: 'text' as const, text: '{"ok":true}' }],
		}))
		const { byName } = setup([tool('fluentcart_product_list', 'read', handler)])

		const result = await byName.get('fluentcart_execute_read_tool')!.handler({
			tool_name: 'fluentcart_product_list',
			input: { id: 1 },
		})

		expect(result.isError).toBeFalsy()
		expect(handler).toHaveBeenCalledWith({ id: 1 })
	})

	it('refuses a real-money tool on the read executor and names the right one', async () => {
		const handler = vi.fn()
		const { byName } = setup([tool('fluentcart_order_refund', 'real-money', handler)])

		const result = await byName.get('fluentcart_execute_read_tool')!.handler({
			tool_name: 'fluentcart_order_refund',
			input: { id: 1 },
		})

		expect(result.isError).toBe(true)
		expect(result.content[0]?.text).toMatch(/wrong executor/)
		expect(result.content[0]?.text).toMatch(/fluentcart_execute_guarded_write/)
		expect(handler).not.toHaveBeenCalled()
	})

	it('refuses a reversible write on the read executor', async () => {
		const handler = vi.fn()
		const { byName } = setup([tool('fluentcart_coupon_create', 'reversible-write', handler)])

		const result = await byName.get('fluentcart_execute_read_tool')!.handler({
			tool_name: 'fluentcart_coupon_create',
			input: { id: 1 },
		})

		expect(result.isError).toBe(true)
		expect(handler).not.toHaveBeenCalled()
	})

	it('refuses a real-money tool on the reversible executor', async () => {
		const handler = vi.fn()
		const { byName } = setup([tool('fluentcart_order_refund', 'real-money', handler)])

		const result = await byName.get('fluentcart_execute_reversible_write')!.handler({
			tool_name: 'fluentcart_order_refund',
			input: { id: 1 },
		})

		expect(result.isError).toBe(true)
		expect(handler).not.toHaveBeenCalled()
	})

	it('refuses a tool that is not exposed at all', async () => {
		const { byName } = setup(DEFAULT_TOOLS)
		const result = await byName.get('fluentcart_execute_read_tool')!.handler({
			tool_name: 'fluentcart_order_delete',
			input: {},
		})

		expect(result.isError).toBe(true)
		expect(result.content[0]?.text).toMatch(/not exposed/)
	})

	it('refuses a tool whose execution is none even on the matching executor', async () => {
		const handler = vi.fn()
		const unavailable = tool('fluentcart_order_refund', 'real-money', handler)
		unavailable.safety = { risk: 'real-money', idempotency: 'guard-required', execution: 'none' }
		const { byName } = setup([unavailable])

		const result = await byName.get('fluentcart_execute_guarded_write')!.handler({
			tool_name: 'fluentcart_order_refund',
			input: { id: 1 },
		})

		expect(result.isError).toBe(true)
		expect(result.content[0]?.text).toMatch(/not executable/)
		expect(handler).not.toHaveBeenCalled()
	})

	it('revalidates input immediately before dispatch', async () => {
		const handler = vi.fn()
		const { byName } = setup([tool('fluentcart_product_list', 'read', handler)])

		const result = await byName.get('fluentcart_execute_read_tool')!.handler({
			tool_name: 'fluentcart_product_list',
			input: { id: 'not-a-number' },
		})

		expect(result.isError).toBe(true)
		expect(result.content[0]?.text).toMatch(/Validation error/)
		expect(handler).not.toHaveBeenCalled()
	})
})
