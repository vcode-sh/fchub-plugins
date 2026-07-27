// Report filters must be sent inside the `params` object FluentCart reads.
//
// Every report controller begins `ReportHelper::processParams($request->get('params'), …)`, so a
// flat `?startDate=…` never arrives. The endpoint does not reject it: it falls back to defaults
// and answers HTTP 200 with zeros or an empty array, which is indistinguishable from an empty
// store. 43 of the 48 report tools shipped that way.
//
// Measured live on a 34-order store, same range, flat versus nested:
//
//   /reports/revenue                  399 B, every figure 0   →  2,284 B with real totals
//   /reports/product-report           341 B, units_sold 0     →  1,936 B, units_sold 53
//   /reports/fetch-top-sold-products   22 B, no rows          →  2,273 B with real rows
//   /reports/subscription-retention   389 B, one row          →  4,429 B, full MRR series
//   /reports/top-products-sold        HTTP 500                →    185 B deprecation notice
//
// The nesting is applied by route rather than per tool, so a report added later cannot forget it.
// These tests pin both halves of that rule: report filters move, everything else stays put.
import { describe, expect, it, vi } from 'vitest'
import type { FluentCartClient } from '../../src/api/client.js'
import { createAllTools } from '../../src/tools/index.js'

function stubClient() {
	const get = vi.fn().mockResolvedValue({ data: {}, status: 200 })
	const post = vi.fn().mockResolvedValue({ data: {}, status: 200 })
	return { client: { get, post } as unknown as FluentCartClient, get, post }
}

function toolNamed(client: FluentCartClient, name: string) {
	const tool = createAllTools(client, {}).find((candidate) => candidate.name === name)
	if (!tool) throw new Error(`${name} is not registered`)
	return tool
}

/** The query object a tool actually put on the wire. */
async function queryFor(name: string, input: Record<string, unknown>) {
	const { client, get } = stubClient()
	await toolNamed(client, name).handler(input as never, {} as never)
	return (get.mock.calls[0]?.[1] ?? {}) as Record<string, unknown>
}

describe('report tools nest their filters under params', () => {
	it('nests the date range', async () => {
		const query = await queryFor('fluentcart_report_revenue', {
			startDate: '2026-01-01',
			endDate: '2026-12-31',
		})

		expect(query['params[startDate]']).toBe('2026-01-01')
		expect(query['params[endDate]']).toBe('2026-12-31')
		// The flat form is what the controller ignores; it must not survive.
		expect(query).not.toHaveProperty('startDate')
		expect(query).not.toHaveProperty('endDate')
	})

	it('nests every filter key the report allowlist accepts', async () => {
		// Mirrors ReportHelper::sanitizeParams. A key missing here is a filter the caller sets and
		// the store silently ignores.
		for (const key of [
			'startDate',
			'endDate',
			'compareType',
			'compareDate',
			'groupKey',
			'currency',
			'filterMode',
			'storeMode',
			'subscriptionType',
		]) {
			const query = await queryFor('fluentcart_report_revenue', { [key]: 'x' })
			expect(query[`params[${key}]`], `${key} must be nested`).toBe('x')
			expect(query, `${key} must not also be sent flat`).not.toHaveProperty(key)
		}
	})

	it('leaves paging and other top-level arguments alone', async () => {
		const query = await queryFor('fluentcart_report_top_sold_products', {
			startDate: '2026-01-01',
			per_page: 10,
		})

		expect(query['params[startDate]']).toBe('2026-01-01')
		// per_page is read off the request directly, not out of the params object.
		expect(query.per_page).toBe(10)
		expect(query).not.toHaveProperty('params[per_page]')
	})

	it('does not nest anything for a non-report endpoint', async () => {
		// The rule keys on /reports/, so an order list keeps its flat filters.
		const query = await queryFor('fluentcart_order_list', { page: 2, per_page: 5 })
		expect(query.page).toBe(2)
		expect(query.per_page).toBe(5)
		for (const key of Object.keys(query)) {
			expect(key.startsWith('params['), `${key} must not be nested`).toBe(false)
		}
	})

	it('covers every registered report tool, not just the ones spot-checked', async () => {
		const { client, get } = stubClient()
		const reportTools = createAllTools(client, {}).filter((tool) =>
			(tool.route?.routes ?? []).some(
				(route) => route.method === 'GET' && route.path.startsWith('/reports/'),
			),
		)
		expect(reportTools.length).toBeGreaterThan(20)

		for (const tool of reportTools) {
			get.mockClear()
			// Send a date range at any tool that accepts one; skip those that take no filters.
			const parsed = tool.schema.safeParse({ startDate: '2026-01-01', endDate: '2026-12-31' })
			if (!parsed.success) continue

			await tool.handler({ startDate: '2026-01-01', endDate: '2026-12-31' } as never, {} as never)
			const query = (get.mock.calls[0]?.[1] ?? {}) as Record<string, unknown>
			if (!('params[startDate]' in query || 'startDate' in query)) continue

			expect(query['params[startDate]'], `${tool.name} sends a flat startDate`).toBe('2026-01-01')
			expect(query, `${tool.name} sends a flat startDate`).not.toHaveProperty('startDate')
		}
	})
})
