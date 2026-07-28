// Three customer tools were recorded as broken or lying. Each claim was measured live against
// the seeded Docker store before anything here was written, and this file pins the conclusions so
// the fixes cannot quietly regress.
//
// What was measured, on FluentCart 1.5.5:
//
//  1. `fluentcart_customer_stats` returned `{"widgets":[]}` for customer 1 — sixteen orders, ltv
//     450300 — while describing itself as "customer statistics including order count and total
//     spend (in cents)". CustomerController::getStats is one line, `apply_filters(
//     'fluent_cart/widgets/single_customer', [], $customer)`, and no callback is registered on
//     that filter anywhere in the plugin tree.
//
//  2. `fluentcart_report_customer` described itself as "acquisition, lifetime value, and activity.
//     Values in cents" and returned `{"summary":{"customer_count":97}, …}` — seven buckets holding
//     one integer each, and no money anywhere. CustomerReportService runs a single COUNT(*).
//
//  3. `fluentcart_customer_list` advertised `purchase_value` as a sort key for finding top
//     customers. Live, `sort_by=purchase_value&sort_type=DESC` returned customers 25-32, all of
//     whom have spent nothing, and omitted customer 1 entirely: the column is JSON in longtext, so
//     DESC sorts `"[]"` above the NULL the top spender carries. The field also came back `[]` or
//     `null` on every row of the store.
//
// The advertised-parameter-that-does-nothing defect has been fixed twice before in this project.
// The `sort_by` assertions below exist so it cannot come back a third time through this tool.
import { describe, expect, it, vi } from 'vitest'
import type { FluentCartClient } from '../../src/api/client.js'
import { customerTools } from '../../src/tools/customers.js'
import { reportInsightTools } from '../../src/tools/reports-insights.js'

function clientReturning(payload: unknown): {
	client: FluentCartClient
	get: ReturnType<typeof vi.fn>
} {
	const get = vi.fn().mockResolvedValue({ data: payload, status: 200 })
	return { client: { get } as unknown as FluentCartClient, get }
}

function toolNamed(tools: ReturnType<typeof customerTools>, name: string) {
	const found = tools.find((tool) => tool.name === name)
	if (!found) throw new Error(`${name} is not registered`)
	return found
}

async function textOf(
	tool: { handler: (input: never) => Promise<{ content: { text: string }[]; isError?: boolean }> },
	input: Record<string, unknown>,
) {
	const result = await tool.handler(input as never)
	return { text: result.content[0]?.text ?? '', isError: result.isError === true }
}

describe('fluentcart_customer_stats is honest about being an empty extension point', () => {
	function statsTool(payload: unknown) {
		const { client, get } = clientReturning(payload)
		return { tool: toolNamed(customerTools(client), 'fluentcart_customer_stats'), get }
	}

	it('is demoted by the search ranker, which keys on the first word', () => {
		const { tool } = statsTool({ widgets: [] })
		expect(tool.description.startsWith('DIAGNOSTIC')).toBe(true)
	})

	it('no longer claims to carry order count or spend', () => {
		const { tool } = statsTool({ widgets: [] })
		expect(tool.description).not.toMatch(/total spend/i)
		expect(tool.description).toContain('fluentcart_customer_get')
	})

	it('explains the empty list instead of handing back {"widgets":[]}', async () => {
		const { tool } = statsTool({ widgets: [] })
		const { text, isError } = await textOf(tool, { customer_id: 1 })

		expect(text).not.toBe('{"widgets":[]}')
		expect(text).toContain('fluentcart_customer_get')
		expect(text).toMatch(/extension point/i)
		// An extension point nobody extended is an accurate empty, not a failure.
		expect(isError).toBe(false)
	})

	it('treats an empty object the same as an empty array', async () => {
		const { tool } = statsTool({ widgets: {} })
		const { text } = await textOf(tool, { customer_id: 1 })
		expect(text).toContain('fluentcart_customer_get')
	})

	it('passes real widgets straight through when an add-on registers them', async () => {
		const { tool } = statsTool({ widgets: [{ title: 'Lifetime', value: '4503.00' }] })
		const { text } = await textOf(tool, { customer_id: 1 })

		expect(JSON.parse(text)).toEqual({ widgets: [{ title: 'Lifetime', value: '4503.00' }] })
	})
})

describe('fluentcart_customer_list advertises only sort keys the store honours', () => {
	function listTool(payload: unknown) {
		const { client, get } = clientReturning(payload)
		return { tool: toolNamed(customerTools(client), 'fluentcart_customer_list'), get }
	}

	const sortValues = () => {
		const { tool } = listTool({ customers: { data: [] } })
		const shape = tool.schema.shape as Record<string, { options?: readonly string[] }>
		// z.enum().optional() — unwrap to reach the option list.
		const field = shape.sort_by as unknown as { def: { innerType: { options: string[] } } }
		return field.def.innerType.options
	}

	it('no longer offers purchase_value, which sorts the top spender to the back', () => {
		expect(sortValues()).not.toContain('purchase_value')
	})

	it('no longer offers created_at, which the store rewrites to id', () => {
		// BaseFilter::parseSortBy only accepts columns on the model's $fillable list, and
		// created_at is not one. Live, created_at and a deliberately invalid column returned the
		// identical page.
		expect(sortValues()).not.toContain('created_at')
	})

	it('offers ltv, the column that actually answers "top customers"', () => {
		expect(sortValues()).toEqual(
			expect.arrayContaining([
				'id',
				'ltv',
				'aov',
				'purchase_count',
				'first_purchase_date',
				'last_purchase_date',
			]),
		)
	})

	it('every advertised key is one FluentCart lists as fillable on the Customer model', () => {
		// The upstream allowlist, copied from app/Models/Customer.php. Anything outside it is
		// silently replaced with id, so an entry here that is not there is an advertisement for
		// nothing.
		const FILLABLE = new Set([
			'user_id',
			'contact_id',
			'email',
			'first_name',
			'last_name',
			'status',
			'purchase_value',
			'purchase_count',
			'ltv',
			'first_purchase_date',
			'last_purchase_date',
			'aov',
			'notes',
			'uuid',
			'country',
			'city',
			'state',
			'postcode',
		])
		for (const key of sortValues()) {
			// `id` is the exception: it is not fillable, but it is defaultSortBy, so asking for it
			// and being given the fallback are the same answer.
			if (key === 'id') continue
			expect(FILLABLE.has(key), `${key} is not fillable, so the store would ignore it`).toBe(true)
		}
	})

	it('rejects a sort key the store would silently swallow', async () => {
		const { tool } = listTool({ customers: { data: [] } })
		expect(tool.schema.safeParse({ sort_by: 'purchase_value' }).success).toBe(false)
		expect(tool.schema.safeParse({ sort_by: 'created_at' }).success).toBe(false)
		expect(tool.schema.safeParse({ sort_by: 'ltv' }).success).toBe(true)
	})

	it('states that ltv is cents and summed across currencies', () => {
		const { tool } = listTool({ customers: { data: [] } })
		expect(tool.description).toMatch(/CENTS/)
		expect(tool.description).toMatch(/currenc/i)
	})

	it('returns the money fields the sort keys refer to', async () => {
		const { tool } = listTool({
			customers: {
				data: [
					{
						id: 1,
						email: 'top@example.invalid',
						ltv: '450300',
						aov: '28143.75',
						purchase_count: '16',
						first_purchase_date: '2026-02-28 23:01:15',
						last_purchase_date: '2026-05-05 13:56:18',
						purchase_value: null,
					},
				],
			},
		})
		const { text } = await textOf(tool, {})
		const row = JSON.parse(text).customers.data[0]

		expect(row.ltv).toBe('450300')
		expect(row.aov).toBe('28143.75')
		expect(row.first_purchase_date).toBe('2026-02-28 23:01:15')
		expect(row.last_purchase_date).toBe('2026-05-05 13:56:18')
	})

	it('keeps a populated purchase_value, since a migrated store really does carry one', async () => {
		const { tool } = listTool({
			customers: { data: [{ id: 1, purchase_value: { EUR: 12000, PLN: 4500 } }] },
		})
		const { text } = await textOf(tool, {})

		expect(JSON.parse(text).customers.data[0].purchase_value).toEqual({ EUR: 12000, PLN: 4500 })
	})
})

describe('fluentcart_report_customer describes what it can actually count', () => {
	function reportTool(payload: unknown) {
		const { client, get } = clientReturning(payload)
		const tools = reportInsightTools(client)
		const found = tools.find((tool) => tool.name === 'fluentcart_report_customer')
		if (!found) throw new Error('fluentcart_report_customer is not registered')
		return { tool: found, get }
	}

	const emptyReport = { summary: { customer_count: 0 }, currentMetrics: [] }

	it('denies carrying money rather than promising cents', () => {
		const { tool } = reportTool(emptyReport)
		// The old text read "acquisition, lifetime value, and activity. Values in cents." Every
		// figure this route can produce is an integer count of rows, so the claim has to be
		// withdrawn in words, not merely dropped — a silent omission still leaves an agent free to
		// read customer_count as an amount.
		expect(tool.description).not.toMatch(/values in cents/i)
		expect(tool.description).toMatch(/no money in this report/i)
	})

	it('says the only figure is a count, and where lifetime value lives instead', () => {
		const { tool } = reportTool(emptyReport)
		expect(tool.description).toMatch(/customer_count/)
		expect(tool.description).toContain('fluentcart_customer_list')
	})

	it('offers only the two bucket widths the endpoint honours', () => {
		const { tool } = reportTool(emptyReport)
		expect(tool.schema.safeParse({ groupKey: 'monthly' }).success).toBe(true)
		expect(tool.schema.safeParse({ groupKey: 'yearly' }).success).toBe(true)
		// daily and weekly are rewritten upstream and return a sparse series with a junk bucket.
		expect(tool.schema.safeParse({ groupKey: 'daily' }).success).toBe(false)
		expect(tool.schema.safeParse({ groupKey: 'weekly' }).success).toBe(false)
	})

	it('nests its report filters, which is the only shape the controller reads', async () => {
		const { tool, get } = reportTool(emptyReport)
		await tool.handler({ startDate: '2026-04-01', endDate: '2026-04-30' } as never)

		expect(get).toHaveBeenCalledWith(
			'/reports/customer-report',
			expect.objectContaining({
				'params[startDate]': '2026-04-01',
				'params[endDate]': '2026-04-30',
			}),
			undefined,
		)
	})

	it('opens the comparison gate a bare compareType would leave shut', async () => {
		// processParams needs `$compareType && $compareDate` before it will compare anything at
		// all, even for the four types that never read compareDate. Without the placeholder the
		// store answers 200 with previousSummary [] — a comparison that silently did not happen.
		const { tool, get } = reportTool(emptyReport)
		await tool.handler({
			startDate: '2026-04-01',
			endDate: '2026-04-30',
			compareType: 'previous_period',
		} as never)

		const sent = get.mock.calls[0]?.[1] as Record<string, unknown>
		expect(sent['params[compareType]']).toBe('previous_period')
		expect(sent['params[compareDate]']).toBeTypeOf('string')
		expect(sent['params[compareDate]']).not.toBe('')
	})

	it('does not invent a comparison date for custom, the one type that reads it', async () => {
		const { tool, get } = reportTool(emptyReport)
		await tool.handler({
			startDate: '2026-04-01',
			endDate: '2026-04-30',
			compareType: 'custom',
		} as never)

		const sent = get.mock.calls[0]?.[1] as Record<string, unknown>
		expect(Object.hasOwn(sent, 'params[compareDate]')).toBe(false)
	})

	it('leaves an explicit compareDate alone, because custom is the type that reads it', async () => {
		const { tool, get } = reportTool(emptyReport)
		await tool.handler({
			startDate: '2026-04-01',
			endDate: '2026-04-30',
			compareType: 'custom',
			compareDate: '2026-01-15',
		} as never)

		expect(get).toHaveBeenCalledWith(
			'/reports/customer-report',
			expect.objectContaining({ 'params[compareDate]': '2026-01-15' }),
			undefined,
		)
	})

	it('sends no compareDate when no comparison was asked for', async () => {
		const { tool, get } = reportTool(emptyReport)
		await tool.handler({ startDate: '2026-04-01', endDate: '2026-04-30' } as never)

		const sent = get.mock.calls[0]?.[1] as Record<string, unknown>
		expect(Object.hasOwn(sent, 'params[compareDate]')).toBe(false)
	})
})
