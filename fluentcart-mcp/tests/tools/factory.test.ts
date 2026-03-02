import { describe, expect, it, vi } from 'vitest'
import { z } from 'zod'
import type { FluentCartClient } from '../../src/api/client.js'
import { createTool, deleteTool, getTool, postTool, putTool } from '../../src/tools/_factory.js'

function mockClient(): FluentCartClient {
	return {
		get: vi.fn(),
		post: vi.fn(),
		put: vi.fn(),
		delete: vi.fn(),
	} as unknown as FluentCartClient
}

const baseConfig = {
	name: 'test-tool',
	title: 'Test Tool',
	description: 'A tool for testing',
	schema: z.object({ id: z.number() }),
}

describe('getTool', () => {
	it('sets readOnlyHint, idempotentHint, and openWorldHint annotations', () => {
		const tool = getTool(mockClient(), { ...baseConfig, endpoint: '/orders' })
		expect(tool.annotations).toEqual({
			readOnlyHint: true,
			idempotentHint: true,
			openWorldHint: true,
		})
	})

	it('passes name, title, description, and schema through', () => {
		const tool = getTool(mockClient(), { ...baseConfig, endpoint: '/orders' })
		expect(tool.name).toBe('test-tool')
		expect(tool.title).toBe('Test Tool')
		expect(tool.description).toBe('A tool for testing')
		expect(tool.schema).toBe(baseConfig.schema)
	})

	it('allows annotation overrides', () => {
		const tool = getTool(mockClient(), {
			...baseConfig,
			endpoint: '/orders',
			annotations: { readOnlyHint: false },
		})
		expect(tool.annotations.readOnlyHint).toBe(false)
		expect(tool.annotations.openWorldHint).toBe(true)
	})

	it('calls client.get with resolved path and remaining params', async () => {
		const client = mockClient()
		vi.mocked(client.get).mockResolvedValue({ data: { id: 1 }, status: 200 })
		const tool = getTool(client, {
			...baseConfig,
			endpoint: '/orders/:order_id/items/:item_id',
			schema: z.object({
				order_id: z.number(),
				item_id: z.number(),
				page: z.number().optional(),
			}),
		})

		await tool.handler({ order_id: 42, item_id: 7, page: 2 })

		expect(client.get).toHaveBeenCalledWith('/orders/42/items/7', { page: 2 }, undefined)
	})

	it('passes isPublic flag to client.get', async () => {
		const client = mockClient()
		vi.mocked(client.get).mockResolvedValue({ data: {}, status: 200 })
		const tool = getTool(client, { ...baseConfig, endpoint: '/products', isPublic: true })

		await tool.handler({})

		expect(client.get).toHaveBeenCalledWith('/products', {}, true)
	})

	it('returns formatted success response', async () => {
		const client = mockClient()
		const payload = { id: 1, name: 'Order #1' }
		vi.mocked(client.get).mockResolvedValue({ data: payload, status: 200 })
		const tool = getTool(client, { ...baseConfig, endpoint: '/orders' })

		const result = await tool.handler({})

		expect(result.content).toEqual([{ type: 'text', text: JSON.stringify(payload, null, 2) }])
		expect(result.structuredContent).toEqual(payload)
		expect(result.isError).toBeUndefined()
	})

	it('returns formatted error when client throws', async () => {
		const client = mockClient()
		vi.mocked(client.get).mockRejectedValue(new Error('Network failure'))
		const tool = getTool(client, { ...baseConfig, endpoint: '/orders' })

		const result = await tool.handler({})

		expect(result.content).toEqual([{ type: 'text', text: 'Error: Network failure' }])
		expect(result.isError).toBe(true)
	})

	it('handles non-Error thrown values', async () => {
		const client = mockClient()
		vi.mocked(client.get).mockRejectedValue('string error')
		const tool = getTool(client, { ...baseConfig, endpoint: '/orders' })

		const result = await tool.handler({})

		expect(result.content[0].text).toBe('Error: string error')
		expect(result.isError).toBe(true)
	})
})

describe('postTool', () => {
	it('sets only openWorldHint annotation', () => {
		const tool = postTool(mockClient(), { ...baseConfig, endpoint: '/orders' })
		expect(tool.annotations).toEqual({ openWorldHint: true })
	})

	it('calls client.post with resolved path and body', async () => {
		const client = mockClient()
		vi.mocked(client.post).mockResolvedValue({ data: { id: 1 }, status: 201 })
		const tool = postTool(client, {
			...baseConfig,
			endpoint: '/orders/:order_id/notes',
			schema: z.object({
				order_id: z.number(),
				content: z.string(),
			}),
		})

		await tool.handler({ order_id: 42, content: 'Hello' })

		expect(client.post).toHaveBeenCalledWith('/orders/42/notes', { content: 'Hello' }, undefined)
	})

	it('passes isPublic flag to client.post', async () => {
		const client = mockClient()
		vi.mocked(client.post).mockResolvedValue({ data: {}, status: 201 })
		const tool = postTool(client, { ...baseConfig, endpoint: '/checkout', isPublic: true })

		await tool.handler({ email: 'a@b.com' })

		expect(client.post).toHaveBeenCalledWith('/checkout', { email: 'a@b.com' }, true)
	})

	it('returns formatted error when client throws', async () => {
		const client = mockClient()
		vi.mocked(client.post).mockRejectedValue(new Error('Validation error'))
		const tool = postTool(client, { ...baseConfig, endpoint: '/orders' })

		const result = await tool.handler({})

		expect(result.isError).toBe(true)
		expect(result.content[0].text).toBe('Error: Validation error')
	})
})

describe('putTool', () => {
	it('sets idempotentHint and openWorldHint annotations', () => {
		const tool = putTool(mockClient(), { ...baseConfig, endpoint: '/orders/:id' })
		expect(tool.annotations).toEqual({
			idempotentHint: true,
			openWorldHint: true,
		})
	})

	it('calls client.put with resolved path and body', async () => {
		const client = mockClient()
		vi.mocked(client.put).mockResolvedValue({ data: { id: 5 }, status: 200 })
		const tool = putTool(client, {
			...baseConfig,
			endpoint: '/orders/:order_id',
			schema: z.object({
				order_id: z.number(),
				status: z.string(),
			}),
		})

		await tool.handler({ order_id: 5, status: 'completed' })

		expect(client.put).toHaveBeenCalledWith('/orders/5', { status: 'completed' })
	})

	it('returns formatted error when client throws', async () => {
		const client = mockClient()
		vi.mocked(client.put).mockRejectedValue(new Error('Not found'))
		const tool = putTool(client, { ...baseConfig, endpoint: '/orders/:id' })

		const result = await tool.handler({ id: 999 })

		expect(result.isError).toBe(true)
		expect(result.content[0].text).toBe('Error: Not found')
	})
})

describe('deleteTool', () => {
	it('sets destructiveHint and openWorldHint annotations', () => {
		const tool = deleteTool(mockClient(), { ...baseConfig, endpoint: '/orders/:id' })
		expect(tool.annotations).toEqual({
			destructiveHint: true,
			openWorldHint: true,
		})
	})

	it('calls client.delete with resolved path and remaining params', async () => {
		const client = mockClient()
		vi.mocked(client.delete).mockResolvedValue({ data: { deleted: true }, status: 200 })
		const tool = deleteTool(client, {
			...baseConfig,
			endpoint: '/orders/:order_id',
			schema: z.object({
				order_id: z.number(),
				force: z.boolean().optional(),
			}),
		})

		await tool.handler({ order_id: 42, force: true })

		expect(client.delete).toHaveBeenCalledWith('/orders/42', { force: true })
	})

	it('returns formatted error when client throws', async () => {
		const client = mockClient()
		vi.mocked(client.delete).mockRejectedValue(new Error('Forbidden'))
		const tool = deleteTool(client, { ...baseConfig, endpoint: '/orders/:id' })

		const result = await tool.handler({ id: 1 })

		expect(result.isError).toBe(true)
		expect(result.content[0].text).toBe('Error: Forbidden')
	})
})

describe('createTool', () => {
	it('sets openWorldHint annotation by default', () => {
		const tool = createTool(mockClient(), {
			...baseConfig,
			handler: vi.fn(),
		})
		expect(tool.annotations).toEqual({ openWorldHint: true })
	})

	it('allows annotation overrides', () => {
		const tool = createTool(mockClient(), {
			...baseConfig,
			handler: vi.fn(),
			annotations: { destructiveHint: true },
		})
		expect(tool.annotations).toEqual({
			openWorldHint: true,
			destructiveHint: true,
		})
	})

	it('wraps handler result in formatSuccess', async () => {
		const client = mockClient()
		const payload = { orders: [1, 2, 3] }
		const tool = createTool(client, {
			...baseConfig,
			handler: vi.fn().mockResolvedValue(payload),
		})

		const result = await tool.handler({ id: 1 })

		expect(result.content).toEqual([{ type: 'text', text: JSON.stringify(payload, null, 2) }])
		expect(result.structuredContent).toEqual(payload)
		expect(result.isError).toBeUndefined()
	})

	it('passes client and input to custom handler', async () => {
		const client = mockClient()
		const customHandler = vi.fn().mockResolvedValue({})
		const tool = createTool(client, {
			...baseConfig,
			handler: customHandler,
		})

		await tool.handler({ id: 42, name: 'test' })

		expect(customHandler).toHaveBeenCalledWith(client, { id: 42, name: 'test' })
	})

	it('returns formatted error when custom handler throws', async () => {
		const tool = createTool(mockClient(), {
			...baseConfig,
			handler: vi.fn().mockRejectedValue(new Error('Custom error')),
		})

		const result = await tool.handler({})

		expect(result.isError).toBe(true)
		expect(result.content[0].text).toBe('Error: Custom error')
	})
})

describe('resolveEndpoint (tested indirectly)', () => {
	it('resolves multiple path parameters in order', async () => {
		const client = mockClient()
		vi.mocked(client.get).mockResolvedValue({ data: {}, status: 200 })
		const tool = getTool(client, {
			...baseConfig,
			endpoint: '/stores/:store_id/orders/:order_id/items/:item_id',
			schema: z.object({
				store_id: z.number(),
				order_id: z.number(),
				item_id: z.number(),
			}),
		})

		await tool.handler({ store_id: 1, order_id: 2, item_id: 3 })

		expect(client.get).toHaveBeenCalledWith('/stores/1/orders/2/items/3', {}, undefined)
	})

	it('converts parameter values to strings', async () => {
		const client = mockClient()
		vi.mocked(client.get).mockResolvedValue({ data: {}, status: 200 })
		const tool = getTool(client, {
			...baseConfig,
			endpoint: '/orders/:id',
			schema: z.object({ id: z.number() }),
		})

		await tool.handler({ id: 123 })

		expect(client.get).toHaveBeenCalledWith('/orders/123', {}, undefined)
	})

	it('handles missing path parameter as empty string', async () => {
		const client = mockClient()
		vi.mocked(client.get).mockResolvedValue({ data: {}, status: 200 })
		const tool = getTool(client, {
			...baseConfig,
			endpoint: '/orders/:id',
			schema: z.object({}),
		})

		await tool.handler({})

		expect(client.get).toHaveBeenCalledWith('/orders/', {}, undefined)
	})

	it('separates path params from remaining input', async () => {
		const client = mockClient()
		vi.mocked(client.delete).mockResolvedValue({ data: {}, status: 200 })
		const tool = deleteTool(client, {
			...baseConfig,
			endpoint: '/orders/:order_id',
			schema: z.object({
				order_id: z.number(),
				force: z.boolean(),
				reason: z.string(),
			}),
		})

		await tool.handler({ order_id: 10, force: true, reason: 'duplicate' })

		expect(client.delete).toHaveBeenCalledWith('/orders/10', {
			force: true,
			reason: 'duplicate',
		})
	})

	it('works with endpoint that has no parameters', async () => {
		const client = mockClient()
		vi.mocked(client.get).mockResolvedValue({ data: {}, status: 200 })
		const tool = getTool(client, {
			...baseConfig,
			endpoint: '/orders',
			schema: z.object({ page: z.number().optional() }),
		})

		await tool.handler({ page: 3 })

		expect(client.get).toHaveBeenCalledWith('/orders', { page: 3 }, undefined)
	})
})
