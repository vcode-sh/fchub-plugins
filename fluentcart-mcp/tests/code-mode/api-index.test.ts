import { describe, expect, it, vi } from 'vitest'
import { z } from 'zod'
import { buildApiIndex, MAX_SEARCH_RESULTS } from '../../src/code-mode/api-index.js'
import type { ToolDefinition } from '../../src/tools/_factory.js'
import type { ToolRisk } from '../../src/tools/risk.js'

const EMPTY = z.object({})

function tool(
	name: string,
	risk: ToolRisk,
	overrides: Partial<ToolDefinition> = {},
): ToolDefinition {
	return {
		name,
		title: overrides.title ?? name.replace(/_/g, ' '),
		description: overrides.description ?? `Operation ${name}.`,
		schema: overrides.schema ?? EMPTY,
		annotations: { readOnlyHint: risk === 'read', openWorldHint: true },
		safety: {
			risk,
			idempotency: risk === 'read' ? 'inherent' : 'unsupported',
			execution: risk === 'read' ? 'rest' : 'none',
		},
		handler: overrides.handler ?? vi.fn(),
	}
}

describe('buildApiIndex admits reads only', () => {
	it('keeps read operations and drops every other risk class', () => {
		const index = buildApiIndex([
			tool('fluentcart_order_list', 'read'),
			tool('fluentcart_order_create', 'reversible-write'),
			tool('fluentcart_order_refund', 'real-money'),
			tool('fluentcart_order_delete', 'destructive-write'),
			tool('fluentcart_settings_update', 'control-plane'),
		])

		expect(index.names()).toEqual(['fluentcart_order_list'])
		expect(index.size).toBe(1)
	})

	it('filters on reviewed risk, not on the readOnlyHint annotation', () => {
		const liar = tool('fluentcart_order_refund', 'real-money')
		liar.annotations = { readOnlyHint: true, openWorldHint: true }

		const index = buildApiIndex([liar])

		expect(index.has('fluentcart_order_refund')).toBe(false)
		expect(index.isExcludedWrite('fluentcart_order_refund')).toBe(true)
	})

	it('never hands back a write definition, so no write executor is reachable', () => {
		const write = tool('fluentcart_order_create', 'reversible-write')
		const index = buildApiIndex([tool('fluentcart_order_list', 'read'), write])

		expect(index.get('fluentcart_order_create')).toBeUndefined()
		expect(index.declare('fluentcart_order_create')).toBeUndefined()
		expect(index.search('order create', 5).map((r) => r.operation)).not.toContain(
			'fluentcart_order_create',
		)
	})

	it('remembers excluded write names for precise refusals without keeping their handlers', () => {
		const index = buildApiIndex([tool('fluentcart_order_delete', 'destructive-write')])

		expect(index.isExcludedWrite('fluentcart_order_delete')).toBe(true)
		expect(index.isExcludedWrite('fluentcart_nonsense')).toBe(false)
		expect(index.get('fluentcart_order_delete')).toBeUndefined()
	})

	it('is frozen', () => {
		expect(Object.isFrozen(buildApiIndex([]))).toBe(true)
	})
})

describe('search', () => {
	const tools = [
		tool('fluentcart_order_list', 'read', { description: 'List orders with filters.' }),
		tool('fluentcart_order_get', 'read', { description: 'Get one order by id.' }),
		tool('fluentcart_customer_list', 'read', { description: 'List customers.' }),
		tool('fluentcart_product_list', 'read', { description: 'List products.' }),
		tool('fluentcart_subscription_list', 'read', { description: 'List subscriptions.' }),
		tool('fluentcart_report_sales', 'read', { description: 'Sales report totals.' }),
		tool('fluentcart_coupon_list', 'read', { description: 'List coupons.' }),
	]

	it('returns at most five declarations even when more match', () => {
		const index = buildApiIndex(tools)
		expect(index.search('list').length).toBe(MAX_SEARCH_RESULTS)
	})

	it('caps an over-large limit at five', () => {
		const index = buildApiIndex(tools)
		expect(index.search('list', 99).length).toBe(MAX_SEARCH_RESULTS)
	})

	it.each([
		[0, 1],
		[-4, 1],
		[1, 1],
		[3, 3],
	])('clamps limit %i to %i result(s)', (limit, expected) => {
		const index = buildApiIndex(tools)
		expect(index.search('list', limit).length).toBe(expected)
	})

	it('truncates a fractional limit rather than rounding up', () => {
		const index = buildApiIndex(tools)
		expect(index.search('list', 3.7).length).toBe(3)
	})

	it('returns nothing when nothing matches', () => {
		expect(buildApiIndex(tools).search('quantum tunnelling')).toEqual([])
	})

	it('ranks name matches above description-only matches', () => {
		const index = buildApiIndex([
			tool('fluentcart_customer_list', 'read', { description: 'List customers.' }),
			tool('fluentcart_report_sales', 'read', { description: 'Totals grouped by customer.' }),
		])

		expect(index.search('customer').map((r) => r.operation)).toEqual([
			'fluentcart_customer_list',
			'fluentcart_report_sales',
		])
	})

	it('breaks score ties by operation name ascending', () => {
		const index = buildApiIndex([
			tool('fluentcart_zeta_list', 'read', { title: 'zeta', description: 'List things.' }),
			tool('fluentcart_alpha_list', 'read', { title: 'alpha', description: 'List things.' }),
		])

		expect(index.search('list things').map((r) => r.operation)).toEqual([
			'fluentcart_alpha_list',
			'fluentcart_zeta_list',
		])
	})
})

describe('TypeScript declarations', () => {
	it('omits the input parameter for an operation that takes none', () => {
		const index = buildApiIndex([tool('fluentcart_store_context', 'read')])

		expect(index.declare('fluentcart_store_context')).toContain(
			"fluentcart.call('fluentcart_store_context'): Promise<unknown>",
		)
	})

	it('marks input optional when every field is optional', () => {
		const index = buildApiIndex([
			tool('fluentcart_order_list', 'read', {
				schema: z.object({ page: z.number().int().optional().describe('Page') }),
			}),
		])

		expect(index.declare('fluentcart_order_list')).toContain('input?: { page?: number }')
	})

	it('marks input required and each field by requiredness', () => {
		const index = buildApiIndex([
			tool('fluentcart_order_get', 'read', {
				schema: z.object({
					id: z.string().describe('Order id'),
					include: z.array(z.string()).optional().describe('Relations'),
				}),
			}),
		])

		const declaration = index.declare('fluentcart_order_get') ?? ''
		expect(declaration).toContain('input: {')
		expect(declaration).toContain('id: string')
		expect(declaration).toContain('include?: string[]')
	})

	it('renders a small enum as a literal union and integers as number', () => {
		const index = buildApiIndex([
			tool('fluentcart_order_list', 'read', {
				schema: z.object({
					status: z.enum(['paid', 'refunded']).describe('Status'),
					page: z.number().int().describe('Page'),
				}),
			}),
		])

		const declaration = index.declare('fluentcart_order_list') ?? ''
		expect(declaration).toContain("status: 'paid' | 'refunded'")
		expect(declaration).toContain('page: number')
	})

	it('falls back to the base type for a large enum rather than printing it all', () => {
		const index = buildApiIndex([
			tool('fluentcart_order_list', 'read', {
				schema: z.object({
					status: z.enum(['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h']).describe('Status'),
				}),
			}),
		])

		expect(index.declare('fluentcart_order_list')).toContain('status: string')
	})

	it('collapses deeply nested objects to Record<string, unknown>', () => {
		const index = buildApiIndex([
			tool('fluentcart_order_search', 'read', {
				schema: z.object({
					filter: z.object({ nested: z.object({ deep: z.string() }) }).describe('Filter'),
				}),
			}),
		])

		const declaration = index.declare('fluentcart_order_search') ?? ''
		expect(declaration).toContain('nested: Record<string, unknown>')
	})

	it('survives a schema that cannot be expressed as JSON Schema', () => {
		const index = buildApiIndex([
			tool('fluentcart_order_since', 'read', {
				schema: z.object({ since: z.date().describe('Cutoff') }),
			}),
		])

		expect(index.has('fluentcart_order_since')).toBe(true)
		expect(index.declare('fluentcart_order_since')).toContain('fluentcart_order_since')
	})

	it('keeps the summary to one collapsed line within the length cap', () => {
		const index = buildApiIndex([
			tool('fluentcart_order_list', 'read', {
				description: `${'Extremely detailed prose. '.repeat(40)}`,
			}),
		])

		const declaration = index.declare('fluentcart_order_list') ?? ''
		const comment = declaration.split('\n')[0] ?? ''
		expect(comment.startsWith('/** ')).toBe(true)
		expect(comment.length).toBeLessThanOrEqual(200)
		expect(comment).not.toContain('\n')
	})

	it('exposes the same declaration through search results', () => {
		const index = buildApiIndex([tool('fluentcart_order_list', 'read')])
		const [result] = index.search('order')

		expect(result?.declaration).toBe(index.declare('fluentcart_order_list'))
		expect(result?.summary).toBe('Operation fluentcart_order_list.')
	})
})
