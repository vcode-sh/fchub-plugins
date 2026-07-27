import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { REPORT_CONTRACTS, REPORT_NAMES } from '../commerce/report-contracts.js'
import { salesSummary, salesTrend, topProducts } from '../commerce/reporting.js'
import { createTool, type ToolDefinition } from './_factory.js'
import { direct } from './endpoints.js'

/**
 * Only reports whose semantics are proven get a tool. The rejected four keep their raw REST tools
 * in reports-core/reports-insights, where nothing claims they mean anything in particular.
 */

const periodShape = {
	from: z
		.string()
		.regex(/^\d{4}-\d{2}-\d{2}$/)
		.describe('First day of the period, YYYY-MM-DD, inclusive, read in the store timezone'),
	to: z
		.string()
		.regex(/^\d{4}-\d{2}-\d{2}$/)
		.describe('Last day of the period, YYYY-MM-DD, inclusive, read in the store timezone'),
	currency: z
		.string()
		.regex(/^[A-Z]{3}$/)
		.describe(
			'Three-letter ISO currency to report on, e.g. PLN. Required: the store sums amounts across currencies unless a single one is pinned, so a mixed total would be meaningless',
		),
	timezone: z
		.string()
		.describe(
			'Store timezone the dates are read in, e.g. Europe/Warsaw. Obtain it from fluentcart_get_store_context; it is echoed back so the period is unambiguous',
		),
}

const SHARED_CAVEAT =
	'Counts orders with payment status paid, refunded, partially_paid or partially_refunded, test-mode included — FluentCart applies no mode filter. Counts, money totals, date filtering and currency scoping are reconciled against the order list by a live test; day boundaries in the store timezone remain approximate. Every result states its period, scope and currency, and carries its own warnings.'

export function commerceReportingTools(client: FluentCartClient): ToolDefinition[] {
	return [
		createTool(client, {
			name: 'fluentcart_report_sales_summary',
			routes: direct('GET', '/reports/revenue'),
			title: 'FluentCart Sales Summary',
			description: `Total sales, net revenue, tax, shipping, refunds, order count and average order value for one currency over an inclusive date range. ${SHARED_CAVEAT} Refunds are attributed to the order's creation date, so a closed period can still move when an old order is refunded.`,
			schema: z.object(periodShape),
			annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: true },
			handler: (apiClient, input) =>
				salesSummary(apiClient, input as unknown as Parameters<typeof salesSummary>[1]),
		}),
		createTool(client, {
			name: 'fluentcart_report_sales_trend',
			routes: direct('GET', '/reports/revenue'),
			title: 'FluentCart Sales Trend',
			description: `Sales, net revenue and order count bucketed over time for one currency. Every bucket in range is returned, empty ones included. FluentCart widens the bucket on long ranges — daily to 91 days, monthly to a year, yearly beyond — so the result reports the granularity requested and the one actually applied, and warns when they differ. ${SHARED_CAVEAT}`,
			schema: z.object({
				...periodShape,
				granularity: z
					.enum(['daily', 'monthly', 'yearly'])
					.optional()
					.describe('Bucket size (default: daily)'),
			}),
			annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: true },
			handler: (apiClient, input) =>
				salesTrend(apiClient, input as unknown as Parameters<typeof salesTrend>[1]),
		}),
		createTool(client, {
			name: 'fluentcart_report_top_products',
			routes: direct('GET', '/reports/fetch-top-sold-products'),
			title: 'FluentCart Top Products',
			description: `The 20 best-selling products over an inclusive date range, ranked by units sold rather than revenue. ${SHARED_CAVEAT} Product names come from the most recent matching order line, so a renamed product appears under the title it last sold as.`,
			schema: z.object(periodShape),
			annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: true },
			handler: (apiClient, input) =>
				topProducts(apiClient, input as unknown as Parameters<typeof topProducts>[1]),
		}),
	]
}

/** Diagnostic report names and why each was refused, for a caller that asks what is missing. */
export function diagnosticReportReasons(): { name: string; path: string; reason: string }[] {
	return REPORT_NAMES.map((name) => REPORT_CONTRACTS[name])
		.filter((contract) => contract.status === 'diagnostic-only')
		.map((contract) => ({
			name: contract.name,
			path: contract.path,
			reason: 'rejection' in contract ? contract.rejection : '',
		}))
}
