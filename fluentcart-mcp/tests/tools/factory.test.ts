import { describe, expect, it, vi } from 'vitest'
import { z } from 'zod'
import type { FluentCartClient } from '../../src/api/client.js'
import { FluentCartApiError } from '../../src/api/errors.js'
import {
	createTool,
	deleteTool,
	getTool,
	MAX_RESPONSE_CHARS,
	postTool,
	putTool,
	truncateResponse,
} from '../../src/tools/_factory.js'

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

	it('returns compact JSON without structuredContent', async () => {
		const client = mockClient()
		const payload = { id: 1, name: 'Order #1' }
		vi.mocked(client.get).mockResolvedValue({ data: payload, status: 200 })
		const tool = getTool(client, { ...baseConfig, endpoint: '/orders' })

		const result = await tool.handler({})

		expect(result.content).toEqual([{ type: 'text', text: JSON.stringify(payload) }])
		expect(result).not.toHaveProperty('structuredContent')
		expect(result.isError).toBeUndefined()
	})

	it('applies transform before formatting response', async () => {
		const client = mockClient()
		const payload = { id: 1, trans: { big: 'data' }, shop: { name: 'Store' } }
		vi.mocked(client.get).mockResolvedValue({ data: payload, status: 200 })
		const tool = getTool(client, {
			...baseConfig,
			endpoint: '/app/init',
			transform: (data) => {
				const { trans, ...rest } = data as Record<string, unknown>
				return rest
			},
		})

		const result = await tool.handler({})

		const parsed = JSON.parse(result.content[0].text)
		expect(parsed).toEqual({ id: 1, shop: { name: 'Store' } })
		expect(parsed).not.toHaveProperty('trans')
	})

	it('returns formatted error when client throws', async () => {
		const client = mockClient()
		vi.mocked(client.get).mockRejectedValue(new Error('Network failure'))
		const tool = getTool(client, { ...baseConfig, endpoint: '/orders' })

		const result = await tool.handler({})

		expect(result.content).toEqual([{ type: 'text', text: 'Error: Network failure' }])
		expect(result.isError).toBe(true)
	})

	it('formats FluentCartApiError with error code', async () => {
		const client = mockClient()
		vi.mocked(client.get).mockRejectedValue(
			new FluentCartApiError('AUTH_FAILED', 'Authentication failed: Invalid credentials', 401),
		)
		const tool = getTool(client, { ...baseConfig, endpoint: '/orders' })

		const result = await tool.handler({})

		expect(result.content[0].text).toBe(
			'Error [AUTH_FAILED]: Authentication failed: Invalid credentials',
		)
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

	it('applies transform to post response', async () => {
		const client = mockClient()
		vi.mocked(client.post).mockResolvedValue({ data: { id: 1, secret: 'x' }, status: 201 })
		const tool = postTool(client, {
			...baseConfig,
			endpoint: '/orders',
			transform: (data) => {
				const { secret, ...rest } = data as Record<string, unknown>
				return rest
			},
		})

		const result = await tool.handler({})
		expect(JSON.parse(result.content[0].text)).toEqual({ id: 1 })
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

	it('wraps handler result in compact JSON without structuredContent', async () => {
		const client = mockClient()
		const payload = { orders: [1, 2, 3] }
		const tool = createTool(client, {
			...baseConfig,
			handler: vi.fn().mockResolvedValue(payload),
		})

		const result = await tool.handler({ id: 1 })

		expect(result.content).toEqual([{ type: 'text', text: JSON.stringify(payload) }])
		expect(result).not.toHaveProperty('structuredContent')
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

	it('rejects missing path parameter with error', async () => {
		const client = mockClient()
		const tool = getTool(client, {
			...baseConfig,
			endpoint: '/orders/:id',
			schema: z.object({}),
		})

		const result = await tool.handler({})

		expect(result.isError).toBe(true)
		expect(result.content[0].text).toContain('Missing required path parameter')
		expect(client.get).not.toHaveBeenCalled()
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

describe('cache integration in endpoint tools', () => {
	it('uses cached response on second call', async () => {
		const client = mockClient()
		const payload = { id: 1, name: 'Countries' }
		vi.mocked(client.get).mockResolvedValue({ data: payload, status: 200 })
		const tool = getTool(client, {
			...baseConfig,
			endpoint: '/misc/countries',
			cache: { key: 'test_countries', ttlMs: 60_000 },
		})

		const result1 = await tool.handler({})
		const result2 = await tool.handler({})

		expect(client.get).toHaveBeenCalledTimes(1)
		expect(result1.content[0].text).toBe(result2.content[0].text)
	})

	it('calls fetcher again after cache is cleared', async () => {
		const { clearCache } = await import('../../src/cache.js')
		clearCache()

		const client = mockClient()
		vi.mocked(client.get).mockResolvedValue({ data: { id: 2 }, status: 200 })
		const tool = getTool(client, {
			...baseConfig,
			endpoint: '/misc/filters',
			cache: { key: 'test_filters', ttlMs: 60_000 },
		})

		await tool.handler({})
		clearCache()
		await tool.handler({})

		expect(client.get).toHaveBeenCalledTimes(2)
	})

	it('does not cache when cache config is absent', async () => {
		const client = mockClient()
		vi.mocked(client.get).mockResolvedValue({ data: { id: 3 }, status: 200 })
		const tool = getTool(client, {
			...baseConfig,
			endpoint: '/orders',
		})

		await tool.handler({})
		await tool.handler({})

		expect(client.get).toHaveBeenCalledTimes(2)
	})
})

describe('P1.2 response transforms', () => {
	it('order_get transform strips activities and post_content', async () => {
		const client = mockClient()
		const fullOrder = {
			id: 1,
			status: 'completed',
			activities: [{ id: 1, type: 'note' }],
			post_content: '<p>Long HTML content</p>',
			customer: { id: 5, full_name: 'John Doe', email: 'john@test.com', phone: '123', labels: [] },
			transactions: [{ id: 10, amount: 100, meta: { gateway: 'stripe' } }],
		}
		vi.mocked(client.get).mockResolvedValue({ data: fullOrder, status: 200 })

		const { orderCoreTools } = await import('../../src/tools/orders-core.js')
		const tools = orderCoreTools(client)
		const orderGet = tools.find((t) => t.name === 'fluentcart_order_get')!

		const result = await orderGet.handler({ order_id: 1 })
		const parsed = JSON.parse(result.content[0].text)

		expect(parsed).not.toHaveProperty('activities')
		expect(parsed).not.toHaveProperty('post_content')
		expect(parsed.customer).toEqual({ id: 5, name: 'John Doe', email: 'john@test.com' })
		expect(parsed.transactions[0]).not.toHaveProperty('meta')
	})

	it('order_list transform returns only summary fields', async () => {
		const client = mockClient()
		const listResponse = {
			data: [
				{
					id: 1,
					receipt_number: 'ORD-001',
					status: 'completed',
					payment_status: 'paid',
					payment_method: 'stripe',
					total_amount: 5000,
					customer_id: 5,
					created_at: '2025-01-01',
					items: [{ id: 10 }],
					customer: { id: 5, email: 'jane@test.com' },
					extra_field: 'should be stripped',
				},
			],
			total: 1,
		}
		vi.mocked(client.get).mockResolvedValue({ data: listResponse, status: 200 })

		const { orderCoreTools } = await import('../../src/tools/orders-core.js')
		const tools = orderCoreTools(client)
		const orderList = tools.find((t) => t.name === 'fluentcart_order_list')!

		const result = await orderList.handler({})
		const parsed = JSON.parse(result.content[0].text)

		expect(parsed.data[0]).toEqual({
			id: 1,
			receipt_number: 'ORD-001',
			status: 'completed',
			payment_status: 'paid',
			payment_method: 'stripe',
			total_amount: 5000,
			customer_id: 5,
			created_at: '2025-01-01',
		})
		expect(parsed.data[0]).not.toHaveProperty('items')
		expect(parsed.data[0]).not.toHaveProperty('customer')
		expect(parsed.data[0]).not.toHaveProperty('extra_field')
	})

	it('product_get transform strips post_content and variant pricing_table', async () => {
		const client = mockClient()
		const fullProduct = {
			id: 42,
			post_title: 'Widget',
			post_content: '<p>Long description</p>',
			variants: [{ id: 1, title: 'Default', pricing_table: { rows: [] } }],
			integrations: [{ id: 1, provider: 'mailchimp' }],
		}
		vi.mocked(client.get).mockResolvedValue({ data: fullProduct, status: 200 })

		const { productCoreTools } = await import('../../src/tools/products-core.js')
		const tools = productCoreTools(client)
		const productGet = tools.find((t) => t.name === 'fluentcart_product_get')!

		const result = await productGet.handler({ product_id: 42 })
		const parsed = JSON.parse(result.content[0].text)

		expect(parsed).not.toHaveProperty('post_content')
		expect(parsed.variants[0]).not.toHaveProperty('pricing_table')
		expect(parsed.variants[0].title).toBe('Default')
		expect(parsed).not.toHaveProperty('integrations')
	})

	it('product_list transform returns only summary fields', async () => {
		const client = mockClient()
		const listResponse = {
			data: [
				{
					ID: 42,
					post_title: 'Widget',
					post_status: 'publish',
					post_name: 'widget',
					post_date: '2025-01-01',
					post_content: '<p>Should be stripped</p>',
					variants: [{ id: 1 }],
				},
			],
			total: 1,
		}
		vi.mocked(client.get).mockResolvedValue({ data: listResponse, status: 200 })

		const { productCoreTools } = await import('../../src/tools/products-core.js')
		const tools = productCoreTools(client)
		const productList = tools.find((t) => t.name === 'fluentcart_product_list')!

		const result = await productList.handler({})
		const parsed = JSON.parse(result.content[0].text)

		expect(parsed.data[0]).toEqual({
			ID: 42,
			post_title: 'Widget',
			post_status: 'publish',
			post_name: 'widget',
			post_date: '2025-01-01',
		})
		expect(parsed.data[0]).not.toHaveProperty('post_content')
		expect(parsed.data[0]).not.toHaveProperty('variants')
	})

	it('customer_get transform replaces addresses with address_count', async () => {
		const client = mockClient()
		const fullCustomer = {
			id: 5,
			email: 'john@test.com',
			addresses: [
				{ id: 1, city: 'London' },
				{ id: 2, city: 'Paris' },
			],
		}
		vi.mocked(client.get).mockResolvedValue({ data: fullCustomer, status: 200 })

		const { customerTools } = await import('../../src/tools/customers.js')
		const tools = customerTools(client)
		const customerGet = tools.find((t) => t.name === 'fluentcart_customer_get')!

		const result = await customerGet.handler({ customer_id: 5 })
		const parsed = JSON.parse(result.content[0].text)

		expect(parsed.address_count).toBe(2)
	})

	it('customer_list transform returns only summary fields', async () => {
		const client = mockClient()
		const listResponse = {
			data: [
				{
					id: 5,
					first_name: 'John',
					last_name: 'Doe',
					email: 'john@test.com',
					full_name: 'John Doe',
					order_count: 3,
					total_spend: 15000,
					created_at: '2025-01-01',
					addresses: [{ id: 1 }],
					labels: [{ id: 1, name: 'VIP' }],
				},
			],
			total: 1,
		}
		vi.mocked(client.get).mockResolvedValue({ data: listResponse, status: 200 })

		const { customerTools } = await import('../../src/tools/customers.js')
		const tools = customerTools(client)
		const customerList = tools.find((t) => t.name === 'fluentcart_customer_list')!

		const result = await customerList.handler({})
		const parsed = JSON.parse(result.content[0].text)

		expect(parsed.data[0]).toEqual({
			id: 5,
			first_name: 'John',
			last_name: 'Doe',
			email: 'john@test.com',
			full_name: 'John Doe',
			order_count: 3,
			total_spend: 15000,
			created_at: '2025-01-01',
		})
		expect(parsed.data[0]).not.toHaveProperty('addresses')
		expect(parsed.data[0]).not.toHaveProperty('labels')
	})
})

describe('truncateResponse', () => {
	it('returns data unchanged when under size limit', () => {
		const data = { id: 1, name: 'test' }
		expect(truncateResponse(data)).toBe(data)
	})

	it('returns small arrays unchanged', () => {
		const data = [1, 2, 3]
		expect(truncateResponse(data)).toBe(data)
	})

	it('truncates oversized arrays with metadata', () => {
		const bigItem = { data: 'x'.repeat(1000) }
		const items = Array.from({ length: 200 }, (_, i) => ({ ...bigItem, id: i }))
		const result = truncateResponse(items) as Record<string, unknown>

		expect(result._truncated).toBe(true)
		expect(result._total).toBe(200)
		expect(typeof result._showing).toBe('number')
		expect(result._showing as number).toBeLessThan(200)
		expect(Array.isArray(result.items)).toBe(true)
		expect(JSON.stringify(result).length).toBeLessThanOrEqual(MAX_RESPONSE_CHARS)
	})

	it('truncates oversized paginated response with data array', () => {
		const bigItem = { data: 'x'.repeat(1000) }
		const items = Array.from({ length: 200 }, (_, i) => ({ ...bigItem, id: i }))
		const paginated = { data: items, current_page: 1, total: 200, per_page: 200 }
		const result = truncateResponse(paginated) as Record<string, unknown>

		expect(result._truncated).toBe(true)
		expect(result._total).toBe(200)
		expect(result.current_page).toBe(1)
		expect(Array.isArray(result.data)).toBe(true)
		expect((result.data as unknown[]).length).toBeLessThan(200)
	})

	it('returns truncation notice for oversized non-array objects', () => {
		const obj: Record<string, string> = {}
		for (let i = 0; i < 1000; i++) {
			obj[`key_${i}`] = 'x'.repeat(200)
		}
		const result = truncateResponse(obj) as Record<string, unknown>

		expect(result._truncated).toBe(true)
		expect(result._message).toContain('Response too large')
	})

	it('handles empty arrays in paginated response', () => {
		const data = { data: [], total: 0 }
		const json = JSON.stringify(data)
		if (json.length <= MAX_RESPONSE_CHARS) {
			expect(truncateResponse(data)).toBe(data)
		}
	})
})
