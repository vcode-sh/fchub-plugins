import { describe, expect, it, vi } from 'vitest'
import { registerPrompts } from '../src/prompts.js'

function createMockServer() {
	const prompts: Array<{ name: string; meta: unknown; handler: (...args: never[]) => unknown }> = []
	return {
		registerPrompt: vi.fn((name: string, meta: unknown, handler: (...args: never[]) => unknown) => {
			prompts.push({ name, meta, handler })
		}),
		_prompts: prompts,
	}
}

/**
 * A registry standing in for full mode.
 *
 * `registerPrompts` now takes the set of tools the server actually registered, and names a tool
 * only when it is in that set — because in `dynamic` mode, the default, none of these exist. The
 * earlier version of this file called `registerPrompts(server)` with no set and then asserted the
 * output contained concrete tool names, which is precisely the bug it should have caught: the
 * assertion passed against text that instructed the model to call tools that were not registered.
 */
const FULL_TOOLS = new Set([
	'fluentcart_report_sales_summary',
	'fluentcart_report_sales_trend',
	'fluentcart_report_top_products',
	'fluentcart_order_get',
	'fluentcart_order_transactions',
	'fluentcart_activity_list',
	'fluentcart_customer_get',
	'fluentcart_customer_addresses',
	'fluentcart_product_list',
	'fluentcart_dashboard_overview',
	'fluentcart_subscription_list',
])

const DYNAMIC_TOOLS = new Set([
	'fluentcart_search_tools',
	'fluentcart_describe_tools',
	'fluentcart_execute_read_tool',
])

function render(name: string, args: Record<string, string>, available: ReadonlySet<string>) {
	const server = createMockServer()
	registerPrompts(server as never, available)
	const prompt = server._prompts.find((p) => p.name === name)
	if (!prompt) throw new Error(`prompt ${name} was not registered`)
	const result = prompt.handler(args as never) as {
		messages: { content: { text: string } }[]
	}
	return result.messages[0]?.content.text ?? ''
}

describe('registerPrompts', () => {
	it('registers exactly 5 prompts', () => {
		const server = createMockServer()
		registerPrompts(server as never, FULL_TOOLS)

		expect(server.registerPrompt).toHaveBeenCalledTimes(5)
	})

	it('registers all expected prompt names', () => {
		const server = createMockServer()
		registerPrompts(server as never, FULL_TOOLS)

		const names = server._prompts.map((p) => p.name)
		expect(names).toContain('analyze-store-performance')
		expect(names).toContain('investigate-order')
		expect(names).toContain('customer-overview')
		expect(names).toContain('catalog-summary')
		expect(names).toContain('subscription-health')
	})

	it('registers the same prompts even when no tool is available', () => {
		// A mode that registers nothing familiar still offers the workflows; it just describes them
		// rather than naming functions.
		const server = createMockServer()
		registerPrompts(server as never, new Set())
		expect(server.registerPrompt).toHaveBeenCalledTimes(5)
	})

	it('each prompt has a title and description', () => {
		const server = createMockServer()
		registerPrompts(server as never, FULL_TOOLS)

		for (const prompt of server._prompts) {
			const meta = prompt.meta as { title: string; description: string }
			expect(meta.title).toBeDefined()
			expect(meta.title.length).toBeGreaterThan(0)
			expect(meta.description).toBeDefined()
			expect(meta.description.length).toBeGreaterThan(0)
		}
	})

	describe('analyze-store-performance', () => {
		const args = { startDate: '2025-01-01', endDate: '2025-01-31' }

		it('names the contract-backed reports when they are registered', () => {
			const text = render('analyze-store-performance', args, FULL_TOOLS)
			expect(text).toContain('fluentcart_report_sales_summary')
			expect(text).toContain('fluentcart_report_sales_trend')
			expect(text).toContain('2025-01-01')
			expect(text).toContain('2025-01-31')
		})

		it('falls back to the raw revenue tool only when the summary is absent', () => {
			const text = render('analyze-store-performance', args, new Set(['fluentcart_report_revenue']))
			expect(text).toContain('fluentcart_report_revenue')
			expect(text).not.toContain('fluentcart_report_sales_summary')
		})

		it('routes through discovery instead of naming absent tools in dynamic mode', () => {
			const text = render('analyze-store-performance', args, DYNAMIC_TOOLS)
			expect(text).toContain('fluentcart_search_tools')
			expect(text).not.toContain('fluentcart_report_sales_summary')
			// The goal survives even with no tool named.
			expect(text).toMatch(/gross sales|net revenue/)
		})
	})

	describe('investigate-order', () => {
		it('names order tools when registered', () => {
			const text = render('investigate-order', { order_id: '123' }, FULL_TOOLS)
			expect(text).toContain('fluentcart_order_get')
			expect(text).toContain('fluentcart_order_transactions')
			expect(text).toContain('#123')
		})

		it('keeps the order id and the task when no tool is named', () => {
			const text = render('investigate-order', { order_id: '123' }, DYNAMIC_TOOLS)
			expect(text).toContain('#123')
			expect(text).toContain('fluentcart_search_tools')
		})
	})

	describe('customer-overview', () => {
		it('names customer tools when registered', () => {
			const text = render('customer-overview', { customer_id: '456' }, FULL_TOOLS)
			expect(text).toContain('fluentcart_customer_get')
			expect(text).toContain('fluentcart_customer_addresses')
			// customer_stats is deliberately absent: it returns add-on widgets and nothing else, so a
			// prompt sending an agent there for spending figures spends a call to be told to go back.
			expect(text).not.toContain('fluentcart_customer_stats')
			expect(text).toContain('#456')
		})
	})

	describe('catalog-summary', () => {
		it('names product and dashboard tools when registered', () => {
			const text = render('catalog-summary', {}, FULL_TOOLS)
			expect(text).toContain('fluentcart_product_list')
			expect(text).toContain('fluentcart_dashboard_overview')
		})

		it('uses the working top-products tool, not the deprecated one', () => {
			const text = render('catalog-summary', {}, FULL_TOOLS)
			expect(text).toContain('fluentcart_report_top_products')
			expect(text).not.toContain('fluentcart_report_top_products_sold')
		})
	})

	describe('subscription-health', () => {
		const args = { startDate: '2025-06-01', endDate: '2025-06-30' }

		it('names subscription tools when registered', () => {
			const text = render('subscription-health', args, FULL_TOOLS)
			expect(text).toContain('fluentcart_subscription_list')
			expect(text).toContain('2025-06-01')
		})

		it('recommends neither rejected subscription report', () => {
			// future_renewals ignores the caller's dates and sums across currencies; the subscription
			// chart has never been verified. Both were recommended by name until now.
			const text = render('subscription-health', args, FULL_TOOLS)
			expect(text).not.toContain('fluentcart_report_future_renewals')
			expect(text).not.toContain('fluentcart_report_subscription_chart')
		})
	})
})
