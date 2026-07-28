import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { createTool, deleteTool, getTool, type ToolDefinition } from './_factory.js'
import { composite, direct, op } from './endpoints.js'
import { collapseOrderDetail } from './order-detail-projection.js'

/**
 * Strip the internals every order row carries, wherever that row appears.
 *
 * `config` holds the payment-session id, `ip_address` is the customer's, `uuid` is an internal
 * handle nothing here addresses orders by, and `meta`/`vendor_response` are gateway bookkeeping.
 * On this store one customer's order list was 19,547 characters of which 10,973 was `config` —
 * 6,813 of that pure Redsys payload — and the unpaginated variant was 46,531 characters, over the
 * emergency cap, so it could not answer for that customer at all.
 *
 * Exported because three routes return order rows and only `GET /orders/{id}` had ever been
 * projected. Writing this a third time in a third file is how the other two came to be missed.
 */
export function stripOrderInternals(row: unknown): unknown {
	if (row === null || typeof row !== 'object' || Array.isArray(row)) return row

	const {
		config: _config,
		ip_address: _ip,
		uuid: _uuid,
		meta: _meta,
		vendor_response: _vendorResponse,
		...rest
	} = row as Record<string, unknown>

	return rest
}

/** Apply {@link stripOrderInternals} across a list, whatever wrapper the route used. */
export function stripOrderRows(value: unknown): unknown {
	return Array.isArray(value) ? value.map(stripOrderInternals) : stripOrderInternals(value)
}

/**
 * Project the order rows inside a response envelope, wherever the route put them.
 *
 * The three order-bearing routes disagree about shape: one answers `{orders: {data: [...]}}`,
 * another `{data: {data: [...]}}`, a third a bare array. Guarding on one spelling is how the
 * paginated customer-orders route kept its Redsys payload after the same fix had already been
 * written twice — so this walks the envelope instead of assuming, rewriting only the array it
 * finds and leaving the paginator around it intact.
 */
export function projectOrderEnvelope(data: unknown, depth = 0): unknown {
	if (Array.isArray(data)) return data.map(stripOrderInternals)
	if (data === null || typeof data !== 'object' || depth > 3) return data

	const body = data as Record<string, unknown>
	let changed = false
	const output: Record<string, unknown> = {}

	for (const [key, value] of Object.entries(body)) {
		const projected = projectOrderEnvelope(value, depth + 1)
		if (projected !== value) changed = true
		output[key] = projected
	}

	return changed ? output : data
}

/**
 * Remove the whole order that FluentCart embeds inside every address it returns.
 *
 * `GET /orders/{id}` nests a complete copy of the order under `order_addresses[].order`,
 * `billing_address.order` and `shipping_address.order` — four copies of the same record, each
 * carrying its own `ip_address`, `uuid` and `config`. On the seeded store that made one order
 * 14,476 characters, of which the three address fields alone were 11,220, and put the customer's
 * IP in the response five separate times.
 *
 * The parent is the thing the caller already has, so the back-reference carries no information at
 * any price. Everything else about the address is left exactly as it was.
 */
function stripAddressBackReference(value: unknown): unknown {
	const clean = (entry: unknown): unknown => {
		if (entry === null || typeof entry !== 'object' || Array.isArray(entry)) return entry
		const { order: _parent, ...rest } = entry as Record<string, unknown>
		return rest
	}

	return Array.isArray(value) ? value.map(clean) : clean(value)
}

export function orderCoreTools(client: FluentCartClient): ToolDefinition[] {
	return [
		createTool(client, {
			name: 'fluentcart_order_list',
			routes: direct('GET', '/orders'),
			title: 'List Orders',
			description:
				'List orders with customer names. Filter by date range, status tab, or search. ' +
				'Search matches customer name, email, and invoice number. ' +
				'Tabs: paid, completed, processing, refunded, subscription, renewal.',
			schema: z.object({
				page: z.number().optional().describe('Page number (default: 1)'),
				per_page: z.number().max(50).optional().describe('Results per page (default: 10, max: 50)'),
				search: z.string().optional().describe('Search by customer name, email, or invoice number'),
				date_from: z.string().optional().describe('Filter from date (YYYY-MM-DD)'),
				date_to: z.string().optional().describe('Filter to date (YYYY-MM-DD)'),
				active_view: z
					.string()
					.optional()
					.describe(
						'Quick filter tab: paid, completed, processing, on-hold, refunded, partially_refunded, subscription, renewal, onetime',
					),
				sort_by: z
					.string()
					.optional()
					.describe('Sort field: id, total_amount, created_at (default: id)'),
				sort_type: z.string().optional().describe('Sort direction: ASC, DESC (default: DESC)'),
			}),
			annotations: { readOnlyHint: true, idempotentHint: true },
			handler: async (client, input) => {
				const { date_from, date_to, ...params } = input as Record<string, unknown>

				if (date_from || date_to) {
					const dateFilters: unknown[] = []
					if (date_from) {
						dateFilters.push({
							source: ['order', 'created_at'],
							operator: 'after',
							value: date_from,
							filter_type: 'date',
						})
					}
					if (date_to) {
						dateFilters.push({
							source: ['order', 'created_at'],
							operator: 'before',
							value: `${date_to} 23:59:59`,
							filter_type: 'date',
						})
					}
					params.filter_type = 'advanced'
					params.advanced_filters = JSON.stringify([dateFilters])
				}

				const response = await client.get('/orders', {
					...params,
					'with[]': 'customer',
				})
				const resp = response.data as Record<string, unknown>
				const wrapper = (resp?.orders ?? resp) as Record<string, unknown>
				if (wrapper && Array.isArray(wrapper.data)) {
					wrapper.data = (wrapper.data as Record<string, unknown>[]).map((item) => {
						const c = item.customer as Record<string, unknown> | undefined
						return {
							id: item.id,
							receipt_number: item.receipt_number,
							status: item.status,
							payment_status: item.payment_status,
							payment_method_title: item.payment_method_title,
							shipping_status: item.shipping_status,
							currency: item.currency,
							total_amount: item.total_amount,
							customer_name: c
								? (c.full_name as string) ||
									`${(c.first_name as string) || ''} ${(c.last_name as string) || ''}`.trim() ||
									null
								: null,
							customer_email: c ? c.email : null,
							created_at: item.created_at,
						}
					})
				}
				return resp
			},
		}),

		createTool(client, {
			name: 'fluentcart_order_create',
			routes: direct('POST', '/orders'),
			title: 'Create Order',
			description:
				'Create a new order. Backend requires `order_items` array with product/variant details. ' +
				'This tool maps user-friendly `items` input to the required `order_items` format.',
			schema: z.object({
				customer_id: z.number().describe('Customer ID (required)'),
				items: z
					.array(
						z.object({
							product_id: z.number().describe('Product ID (mapped to post_id)'),
							variation_id: z
								.number()
								.optional()
								.describe('Variation/variant ID (mapped to object_id)'),
							quantity: z.number().optional().describe('Quantity (default: 1)'),
							unit_price: z.number().optional().describe('Unit price override'),
						}),
					)
					.describe('Order line items'),
				payment_method: z.string().optional().describe('Payment method identifier'),
				note: z.string().optional().describe('Order note'),
				shipping_total: z.number().optional().describe('Shipping total (default: 0)'),
			}),
			handler: async (c, input) => {
				const items = input.items as Array<Record<string, unknown>>
				const orderItems = items.map((item) => ({
					post_id: item.product_id,
					object_id: item.variation_id ?? item.product_id,
					quantity: item.quantity ?? 1,
					...(item.unit_price !== undefined ? { unit_price: item.unit_price } : {}),
				}))

				const body: Record<string, unknown> = {
					customer_id: input.customer_id,
					order_items: orderItems,
					shipping_total: input.shipping_total ?? 0,
				}
				if (input.payment_method) body.payment_method = input.payment_method
				if (input.note) body.note = input.note

				const resp = await c.post('/orders', body)
				return resp.data
			},
		}),

		getTool(client, {
			name: 'fluentcart_order_get',
			title: 'Get Order',
			description:
				'Get full order details including items, transactions, addresses, and customer data.',
			schema: z.object({
				order_id: z.number().describe('Order ID'),
			}),
			endpoint: '/orders/:order_id',
			transform: (data: unknown) => {
				const resp = data as Record<string, unknown>
				const order = (resp?.order ?? resp) as Record<string, unknown>
				// `ip_address` is the customer's IP and `config` carries the payment-session id
				// (p24_session_id); neither is something an agent can act on, and handing either to a
				// model is a disclosure with no upside. `uuid` is an internal handle — every tool here
				// addresses orders by their numeric id.
				const { activities, post_content, ip_address, config, uuid, ...rest } = order
				if (rest.customer && typeof rest.customer === 'object') {
					const c = rest.customer as Record<string, unknown>
					rest.customer = {
						id: c.id,
						name: c.full_name || c.first_name,
						email: c.email,
					}
				}
				if (Array.isArray(rest.transactions)) {
					rest.transactions = (rest.transactions as Record<string, unknown>[]).map((t) => {
						const { meta, uuid: txUuid, ...txRest } = t
						return txRest
					})
				}
				for (const key of ['order_addresses', 'billing_address', 'shipping_address']) {
					rest[key] = stripAddressBackReference(rest[key])
				}
				// The two addresses arrived three times over, and every line carried the variant's whole
				// catalogue record. See order-detail-projection.ts for the measurements.
				const collapsed = collapseOrderDetail(rest)
				return resp?.order ? { ...resp, order: collapsed } : collapsed
			},
		}),

		createTool(client, {
			name: 'fluentcart_order_update',
			routes: composite(op('GET', '/orders/{param}'), op('POST', '/orders/{param}')),
			title: 'Update Order',
			description:
				'Update an existing order using fetch-merge pattern. Fetches current state, merges your changes, ' +
				'and submits the full payload. Subscription orders cannot be edited.',
			schema: z.object({
				order_id: z.number().describe('Order ID'),
				status: z.string().optional().describe('Order status'),
				note: z.string().optional().describe('Order note'),
				payment_method: z.string().optional().describe('Payment method'),
				customer_id: z.number().optional().describe('Override customer ID'),
			}),
			handler: async (c, input) => {
				const orderId = input.order_id as number

				// Fetch current order state
				const current = await c.get(`/orders/${orderId}`)
				const wrapper = current.data as Record<string, unknown>
				const order = (wrapper.order ?? wrapper) as Record<string, unknown>

				// Extract current order items, converting prices from cents back to currency units
				const currentItems = (
					(order.items ?? order.order_items ?? []) as Record<string, unknown>[]
				).map((item) => ({
					...item,
					unit_price: typeof item.unit_price === 'number' ? item.unit_price / 100 : item.unit_price,
				}))

				const body: Record<string, unknown> = {
					customer_id: (input.customer_id as number) ?? order.customer_id,
					order_items: currentItems,
				}
				if (input.status !== undefined) body.status = input.status
				if (input.note !== undefined) body.note = input.note
				if (input.payment_method !== undefined) body.payment_method = input.payment_method

				const resp = await c.post(`/orders/${orderId}`, body)
				return resp.data
			},
		}),

		deleteTool(client, {
			name: 'fluentcart_order_delete',
			title: 'Delete Order',
			description: 'Delete an order (soft delete). This action cannot be undone.',
			schema: z.object({
				order_id: z.number().describe('Order ID'),
			}),
			endpoint: '/orders/:order_id',
		}),
	]
}
