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

describe('registerPrompts', () => {
	it('registers exactly 5 prompts', () => {
		const server = createMockServer()
		registerPrompts(server as never)

		expect(server.registerPrompt).toHaveBeenCalledTimes(5)
	})

	it('registers all expected prompt names', () => {
		const server = createMockServer()
		registerPrompts(server as never)

		const names = server._prompts.map((p) => p.name)
		expect(names).toContain('analyze-store-performance')
		expect(names).toContain('investigate-order')
		expect(names).toContain('customer-overview')
		expect(names).toContain('catalog-summary')
		expect(names).toContain('subscription-health')
	})

	it('each prompt has a title and description', () => {
		const server = createMockServer()
		registerPrompts(server as never)

		for (const prompt of server._prompts) {
			const meta = prompt.meta as { title: string; description: string }
			expect(meta.title).toBeDefined()
			expect(meta.title.length).toBeGreaterThan(0)
			expect(meta.description).toBeDefined()
			expect(meta.description.length).toBeGreaterThan(0)
		}
	})

	describe('analyze-store-performance', () => {
		it('returns messages referencing report tools', () => {
			const server = createMockServer()
			registerPrompts(server as never)

			const prompt = server._prompts.find((p) => p.name === 'analyze-store-performance')!
			const result = prompt.handler({ startDate: '2025-01-01', endDate: '2025-01-31' })

			expect(result.messages).toHaveLength(1)
			const text = result.messages[0].content.text
			expect(text).toContain('fluentcart_report_overview')
			expect(text).toContain('fluentcart_report_revenue')
			expect(text).toContain('2025-01-01')
			expect(text).toContain('2025-01-31')
		})
	})

	describe('investigate-order', () => {
		it('returns messages referencing order tools', () => {
			const server = createMockServer()
			registerPrompts(server as never)

			const prompt = server._prompts.find((p) => p.name === 'investigate-order')!
			const result = prompt.handler({ order_id: '123' })

			const text = result.messages[0].content.text
			expect(text).toContain('fluentcart_order_get')
			expect(text).toContain('fluentcart_order_transactions')
			expect(text).toContain('#123')
		})
	})

	describe('customer-overview', () => {
		it('returns messages referencing customer tools', () => {
			const server = createMockServer()
			registerPrompts(server as never)

			const prompt = server._prompts.find((p) => p.name === 'customer-overview')!
			const result = prompt.handler({ customer_id: '456' })

			const text = result.messages[0].content.text
			expect(text).toContain('fluentcart_customer_get')
			expect(text).toContain('fluentcart_customer_stats')
			expect(text).toContain('#456')
		})
	})

	describe('catalog-summary', () => {
		it('returns messages referencing product and dashboard tools', () => {
			const server = createMockServer()
			registerPrompts(server as never)

			const prompt = server._prompts.find((p) => p.name === 'catalog-summary')!
			const result = prompt.handler({})

			const text = result.messages[0].content.text
			expect(text).toContain('fluentcart_product_list')
			expect(text).toContain('fluentcart_dashboard_overview')
		})
	})

	describe('subscription-health', () => {
		it('returns messages referencing subscription tools', () => {
			const server = createMockServer()
			registerPrompts(server as never)

			const prompt = server._prompts.find((p) => p.name === 'subscription-health')!
			const result = prompt.handler({ startDate: '2025-06-01', endDate: '2025-06-30' })

			const text = result.messages[0].content.text
			expect(text).toContain('fluentcart_subscription_list')
			expect(text).toContain('fluentcart_report_subscription_chart')
			expect(text).toContain('fluentcart_report_future_renewals')
			expect(text).toContain('2025-06-01')
		})
	})
})
