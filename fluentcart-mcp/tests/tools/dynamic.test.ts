import { describe, expect, it, vi } from 'vitest'
import { z } from 'zod'
import type { ToolDefinition } from '../../src/tools/_factory.js'
import { registerDynamicTools } from '../../src/tools/dynamic.js'

function makeTool(overrides: Partial<ToolDefinition> = {}): ToolDefinition {
	return {
		name: overrides.name ?? 'fluentcart_test_tool',
		title: overrides.title ?? 'Test Tool',
		description: overrides.description ?? 'A test tool for testing',
		schema: overrides.schema ?? z.object({ id: z.number() }),
		annotations: overrides.annotations ?? { openWorldHint: true },
		handler:
			overrides.handler ??
			vi.fn().mockResolvedValue({
				content: [{ type: 'text' as const, text: '{"ok":true}' }],
			}),
	}
}

function createMockServer() {
	const registered = new Map<string, { meta: unknown; handler: (...args: never[]) => unknown }>()
	return {
		registerTool: vi.fn((name: string, meta: unknown, handler: (...args: never[]) => unknown) => {
			registered.set(name, { meta, handler })
		}),
		_registered: registered,
	}
}

type MockServer = ReturnType<typeof createMockServer>

async function callTool(server: MockServer, name: string, input: unknown) {
	const entry = server._registered.get(name)
	if (!entry) throw new Error(`Tool ${name} not registered`)
	return entry.handler(input)
}

describe('registerDynamicTools', () => {
	it('registers exactly 3 meta-tools on the server', () => {
		const server = createMockServer()
		registerDynamicTools(server as never, [])

		expect(server.registerTool).toHaveBeenCalledTimes(3)

		const names = server.registerTool.mock.calls.map((c) => c[0])
		expect(names).toContain('fluentcart_search_tools')
		expect(names).toContain('fluentcart_describe_tools')
		expect(names).toContain('fluentcart_execute_tool')
	})
})

describe('fluentcart_search_tools', () => {
	const tools: ToolDefinition[] = [
		makeTool({
			name: 'fluentcart_product_list',
			title: 'List Products',
			description: 'List all products in the store',
		}),
		makeTool({
			name: 'fluentcart_product_get',
			title: 'Get Product',
			description: 'Get a single product by ID',
		}),
		makeTool({
			name: 'fluentcart_order_list',
			title: 'List Orders',
			description: 'List all orders',
		}),
		makeTool({
			name: 'fluentcart_order_get',
			title: 'Get Order',
			description: 'Get a single order by ID',
		}),
		makeTool({
			name: 'fluentcart_coupon_create',
			title: 'Create Coupon',
			description: 'Create a new coupon code',
		}),
		makeTool({
			name: 'fluentcart_customer_list',
			title: 'List Customers',
			description: 'List all customers',
		}),
	]

	function setup() {
		const server = createMockServer()
		registerDynamicTools(server as never, tools)
		return server
	}

	it('returns product tools when searching for "product"', async () => {
		const server = setup()
		const result = await callTool(server, 'fluentcart_search_tools', { query: 'product' })

		const data = JSON.parse(result.content[0].text)
		expect(data.matches).toBeGreaterThanOrEqual(2)

		const names = data.tools.map((t: { name: string }) => t.name)
		expect(names).toContain('fluentcart_product_list')
		expect(names).toContain('fluentcart_product_get')
	})

	it('returns coupon tools when searching for "coupon"', async () => {
		const server = setup()
		const result = await callTool(server, 'fluentcart_search_tools', { query: 'coupon' })

		const data = JSON.parse(result.content[0].text)
		expect(data.matches).toBeGreaterThanOrEqual(1)

		const names = data.tools.map((t: { name: string }) => t.name)
		expect(names).toContain('fluentcart_coupon_create')
	})

	it('includes total_available count in response', async () => {
		const server = setup()
		const result = await callTool(server, 'fluentcart_search_tools', { query: 'list' })

		const data = JSON.parse(result.content[0].text)
		expect(data.total_available).toBe(tools.length)
	})

	it('filters by category when specified', async () => {
		const server = setup()
		const result = await callTool(server, 'fluentcart_search_tools', {
			query: 'list',
			category: 'order',
		})

		const data = JSON.parse(result.content[0].text)
		const names = data.tools.map((t: { name: string }) => t.name)
		expect(names).toContain('fluentcart_order_list')
		expect(names).not.toContain('fluentcart_product_list')
		expect(names).not.toContain('fluentcart_customer_list')
	})

	it('returns empty results for non-matching query', async () => {
		const server = setup()
		const result = await callTool(server, 'fluentcart_search_tools', { query: 'zzzznonexistent' })

		const data = JSON.parse(result.content[0].text)
		expect(data.matches).toBe(0)
		expect(data.tools).toEqual([])
	})

	it('includes category in each result', async () => {
		const server = setup()
		const result = await callTool(server, 'fluentcart_search_tools', { query: 'product' })

		const data = JSON.parse(result.content[0].text)
		for (const tool of data.tools) {
			expect(tool).toHaveProperty('category')
		}
	})

	it('returns at most 20 results', async () => {
		const manyTools = Array.from({ length: 30 }, (_, i) =>
			makeTool({
				name: `fluentcart_item_tool_${i}`,
				title: `Item ${i}`,
				description: `Item tool ${i}`,
			}),
		)
		const server = createMockServer()
		registerDynamicTools(server as never, manyTools)

		const result = await callTool(server, 'fluentcart_search_tools', { query: 'item' })
		const data = JSON.parse(result.content[0].text)
		expect(data.tools.length).toBeLessThanOrEqual(20)
	})
})

describe('fluentcart_describe_tools', () => {
	const tools: ToolDefinition[] = [
		makeTool({
			name: 'fluentcart_product_get',
			title: 'Get Product',
			description: 'Get a single product by ID',
			schema: z.object({ product_id: z.number().describe('Product ID') }),
			annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: true },
		}),
		makeTool({
			name: 'fluentcart_order_delete',
			title: 'Delete Order',
			description: 'Delete an order by ID',
			schema: z.object({ order_id: z.number() }),
			annotations: { destructiveHint: true, openWorldHint: true },
		}),
	]

	function setup() {
		const server = createMockServer()
		registerDynamicTools(server as never, tools)
		return server
	}

	it('returns JSON schema for known tool names', async () => {
		const server = setup()
		const result = await callTool(server, 'fluentcart_describe_tools', {
			tools: ['fluentcart_product_get'],
		})

		const data = JSON.parse(result.content[0].text)
		expect(data).toHaveLength(1)
		expect(data[0].name).toBe('fluentcart_product_get')
		expect(data[0].inputSchema).toBeDefined()
		expect(data[0].inputSchema.type).toBe('object')
		expect(data[0].inputSchema.properties).toHaveProperty('product_id')
	})

	it('returns annotations for described tools', async () => {
		const server = setup()
		const result = await callTool(server, 'fluentcart_describe_tools', {
			tools: ['fluentcart_product_get'],
		})

		const data = JSON.parse(result.content[0].text)
		expect(data[0].annotations).toEqual({
			readOnlyHint: true,
			idempotentHint: true,
			openWorldHint: true,
		})
	})

	it('returns error object for unknown tool names', async () => {
		const server = setup()
		const result = await callTool(server, 'fluentcart_describe_tools', {
			tools: ['fluentcart_nonexistent_tool'],
		})

		const data = JSON.parse(result.content[0].text)
		expect(data).toHaveLength(1)
		expect(data[0].error).toBe('Tool not found')
	})

	it('handles mix of known and unknown tool names', async () => {
		const server = setup()
		const result = await callTool(server, 'fluentcart_describe_tools', {
			tools: ['fluentcart_product_get', 'fluentcart_fake_tool', 'fluentcart_order_delete'],
		})

		const data = JSON.parse(result.content[0].text)
		expect(data).toHaveLength(3)
		expect(data[0].name).toBe('fluentcart_product_get')
		expect(data[0].inputSchema).toBeDefined()
		expect(data[1].error).toBe('Tool not found')
		expect(data[2].name).toBe('fluentcart_order_delete')
		expect(data[2].inputSchema).toBeDefined()
	})

	it('returns title and description for each tool', async () => {
		const server = setup()
		const result = await callTool(server, 'fluentcart_describe_tools', {
			tools: ['fluentcart_product_get'],
		})

		const data = JSON.parse(result.content[0].text)
		expect(data[0].title).toBe('Get Product')
		expect(data[0].description).toBe('Get a single product by ID')
	})
})

describe('fluentcart_execute_tool', () => {
	it('dispatches to the correct tool handler', async () => {
		const handler = vi.fn().mockResolvedValue({
			content: [{ type: 'text' as const, text: '{"product_id":42}' }],
		})
		const tools = [
			makeTool({ name: 'fluentcart_product_get', schema: z.object({ id: z.number() }), handler }),
		]
		const server = createMockServer()
		registerDynamicTools(server as never, tools)

		const result = await callTool(server, 'fluentcart_execute_tool', {
			tool_name: 'fluentcart_product_get',
			input: { id: 42 },
		})

		expect(handler).toHaveBeenCalledWith({ id: 42 })
		expect(result.content[0].text).toBe('{"product_id":42}')
	})

	it('returns error for unknown tool names', async () => {
		const server = createMockServer()
		registerDynamicTools(server as never, [])

		const result = await callTool(server, 'fluentcart_execute_tool', {
			tool_name: 'fluentcart_nonexistent',
			input: {},
		})

		expect(result.isError).toBe(true)
		expect(result.content[0].text).toContain('not found')
	})

	it('returns validation error for invalid input', async () => {
		const tools = [
			makeTool({
				name: 'fluentcart_product_get',
				schema: z.object({ id: z.number() }),
			}),
		]
		const server = createMockServer()
		registerDynamicTools(server as never, tools)

		const result = await callTool(server, 'fluentcart_execute_tool', {
			tool_name: 'fluentcart_product_get',
			input: { id: 'not-a-number' },
		})

		expect(result.isError).toBe(true)
		expect(result.content[0].text).toContain('Validation error')
	})

	it('accepts empty input object for tools with no required params', async () => {
		const handler = vi.fn().mockResolvedValue({
			content: [{ type: 'text' as const, text: '{}' }],
		})
		const tools = [
			makeTool({ name: 'fluentcart_dashboard_overview', schema: z.object({}), handler }),
		]
		const server = createMockServer()
		registerDynamicTools(server as never, tools)

		const result = await callTool(server, 'fluentcart_execute_tool', {
			tool_name: 'fluentcart_dashboard_overview',
			input: {},
		})

		expect(handler).toHaveBeenCalledWith({})
		expect(result.isError).toBeUndefined()
	})
})
