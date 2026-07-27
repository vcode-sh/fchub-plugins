import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import {
	type CustomerRow,
	type OrderRow,
	type ProductRow,
	projectCustomer,
	projectOrder,
	projectProduct,
	projectSubscription,
	type SubscriptionRow,
} from '../commerce/entity-projections.js'
import { buildEnvelope, type PageEnvelope } from '../commerce/envelopes.js'
import { continuationHint, resolvePagination } from '../commerce/pagination.js'
import { assertResponseBudget } from '../commerce/response-budget.js'
import {
	assertSearchEntity,
	buildSearchParams,
	getAllSearchCapabilities,
	getSearchCapability,
	SEARCH_ENTITIES,
	type SearchEntity,
	searchPath,
} from '../commerce/search.js'
import { createTool, type ToolDefinition } from './_factory.js'
import { composite, op } from './endpoints.js'

/**
 * Search reads the same endpoints the per-entity list tools read, so it borrows their verified
 * pagination profiles rather than inventing one. A separate profile would be a second set of
 * numbers describing the same route, free to drift from the evidence that produced it.
 */
const PAGINATION_PROFILE_TOOL: Record<SearchEntity, string> = {
	orders: 'fluentcart_order_list',
	products: 'fluentcart_product_list',
	customers: 'fluentcart_customer_list',
	subscriptions: 'fluentcart_subscription_list',
}

const SEARCH_OPERATIONS = SEARCH_ENTITIES.map((entity) => op('GET', searchPath(entity)))

type SearchRow = OrderRow | ProductRow | CustomerRow | SubscriptionRow

/**
 * Find the row array in whichever envelope the endpoint used.
 *
 * FluentCart wraps list payloads differently per controller — `{orders: {data}}`, `{products:
 * {data}}`, `{data: {data}}`. An unrecognised shape yields an empty page with a warning rather
 * than an invented one: reporting zero results the caller can see is recoverable, silently
 * dropping rows is not.
 */
function extractRows(payload: unknown): { rows: unknown[]; total: number | null; found: boolean } {
	const seen = new Set<unknown>()

	const walk = (node: unknown, depth: number): { rows: unknown[]; total: number | null } | null => {
		if (!node || typeof node !== 'object' || depth > 4 || seen.has(node)) return null
		seen.add(node)

		const record = node as Record<string, unknown>
		if (Array.isArray(record.data)) {
			const total = typeof record.total === 'number' ? record.total : null
			return { rows: record.data, total }
		}

		for (const value of Object.values(record)) {
			const found = walk(value, depth + 1)
			if (found) return found
		}
		return null
	}

	if (Array.isArray(payload)) return { rows: payload, total: null, found: true }
	const found = walk(payload, 0)
	return found ? { ...found, found: true } : { rows: [], total: null, found: false }
}

function projectRows(entity: SearchEntity, rows: unknown[], includeEmail: boolean): SearchRow[] {
	switch (entity) {
		case 'orders':
			return rows.map((row) => projectOrder(row))
		case 'products':
			return rows.map((row) => projectProduct(row))
		case 'customers':
			return rows.map((row) => projectCustomer(row, { includeEmail }))
		case 'subscriptions':
			return rows.map((row) => projectSubscription(row))
	}
}

export function commerceSearchTools(client: FluentCartClient): ToolDefinition[] {
	return [
		createTool(client, {
			name: 'fluentcart_get_search_capabilities',
			title: 'Get Search Capabilities',
			description:
				'List the filters this store proves it accepts for a commerce entity, before you ' +
				'build a search. Returns the entity, its flat filter names, whether advanced ' +
				'(expression) filtering is available, and where each filter was verified. ' +
				'Filters not listed here are rejected locally by fluentcart_search_commerce rather ' +
				'than sent upstream, because FluentCart ignores parameters it does not recognise and ' +
				'would return a full unfiltered page that looks like a successful narrow search. ' +
				'Omit `entity` to get every entity at once.',
			schema: z.object({
				entity: z
					.enum(['orders', 'products', 'customers', 'subscriptions'])
					.optional()
					.describe('Entity to describe. Omit for all four.'),
			}),
			annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: true },
			routes: composite(...SEARCH_OPERATIONS),
			handler: async (_client, input) => {
				if (input.entity === undefined) return { capabilities: getAllSearchCapabilities() }
				return getSearchCapability(assertSearchEntity(String(input.entity)))
			},
		}),

		createTool(client, {
			name: 'fluentcart_search_commerce',
			title: 'Search Commerce Entities',
			description:
				'Search orders, products, customers or subscriptions and get back compact rows in a ' +
				'paginated envelope. `query` is free text: for orders it matches invoice number, ' +
				'customer name/email and line-item titles; for customers, name, email and numeric id. ' +
				'`filters` accepts only the names fluentcart_get_search_capabilities reports for that ' +
				'entity — an unknown filter or enum value fails immediately without calling the store. ' +
				'Monetary values are integer minor units (4000 = 40.00) with their ISO currency; ' +
				'values from different currencies are never summed. `total` and `hasMore` are null ' +
				'when the endpoint does not report a count — treat null as unknown, not zero.',
			schema: z.object({
				entity: z
					.enum(['orders', 'products', 'customers', 'subscriptions'])
					.describe('Entity to search'),
				query: z.string().optional().describe('Free-text search term'),
				filters: z
					.record(z.string(), z.unknown())
					.optional()
					.describe('Structured filters; call fluentcart_get_search_capabilities for the names'),
				page: z.number().optional().describe('Page number (default: 1)'),
				per_page: z.number().optional().describe('Rows per page; the entity maximum applies'),
				include_email: z
					.boolean()
					.optional()
					.describe(
						'Customers only: include email addresses. Requires contact-data authorisation.',
					),
			}),
			annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: true },
			routes: composite(...SEARCH_OPERATIONS),
			handler: async (c, input) => {
				const entity = assertSearchEntity(String(input.entity))
				const pagination = resolvePagination(PAGINATION_PROFILE_TOOL[entity], input)
				const filters = buildSearchParams(entity, {
					query: input.query as string | undefined,
					filters: input.filters as Record<string, unknown> | undefined,
				})

				const response = await c.get(searchPath(entity), { ...filters, ...pagination.params })
				const { rows, total, found } = extractRows(response.data)

				const warnings: string[] = []
				if (!found) {
					warnings.push(
						`Could not locate a row collection in the ${entity} response; returning no rows rather than guessing its shape.`,
					)
				}

				const envelope: PageEnvelope<SearchRow> = buildEnvelope(
					projectRows(entity, rows, input.include_email === true),
					{ page: pagination.page, perPage: pagination.perPage, total, warnings },
				)

				assertResponseBudget(envelope)

				const hint = continuationHint('fluentcart_search_commerce', envelope.nextPage)
				return hint ? { ...envelope, continuation: hint } : envelope
			},
		}),
	]
}
