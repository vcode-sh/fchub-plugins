import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { FluentCartApiError } from '../api/errors.js'
import {
	createTool,
	deleteTool,
	getTool,
	postTool,
	putTool,
	type ToolDefinition,
} from './_factory.js'

export function orderCoreTools(client: FluentCartClient): ToolDefinition[] {
	return [
		createTool(client, {
			name: 'fluentcart_order_list',
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
							variation_id: z.number().optional().describe('Variation/variant ID (mapped to object_id)'),
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
				const { activities, post_content, ...rest } = order
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
						const { meta, ...txRest } = t
						return txRest
					})
				}
				return resp?.order ? { ...resp, order: rest } : rest
			},
		}),

		createTool(client, {
			name: 'fluentcart_order_update',
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
				const currentItems = ((order.items ?? order.order_items ?? []) as Record<string, unknown>[]).map(
					(item) => ({
						...item,
						unit_price: typeof item.unit_price === 'number' ? item.unit_price / 100 : item.unit_price,
					}),
				)

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

		createTool(client, {
			name: 'fluentcart_order_mark_paid',
			title: 'Mark Order as Paid',
			description:
				'Mark an order as paid manually. Maps `note` to backend field `mark_paid_note`. ' +
				'Side effect: triggers order paid hooks and integration feeds.',
			schema: z.object({
				order_id: z.number().describe('Order ID'),
				payment_method: z.string().optional().describe('Payment method used'),
				transaction_id: z.string().optional().describe('External transaction ID'),
				note: z.string().optional().describe('Payment note (mapped to mark_paid_note)'),
			}),
			handler: async (c, input) => {
				const orderId = input.order_id as number
				const body: Record<string, unknown> = {}
				if (input.payment_method) body.payment_method = input.payment_method
				if (input.transaction_id) body.transaction_id = input.transaction_id
				if (input.note) body.mark_paid_note = input.note

				const resp = await c.post(`/orders/${orderId}/mark-as-paid`, body)
				return resp.data
			},
		}),

		createTool(client, {
			name: 'fluentcart_order_refund',
			title: 'Refund Order',
			description:
				'Process a refund for an order. Wraps input into backend-required `refund_info` structure. ' +
				'If transaction_id is omitted, automatically finds the first successful charge transaction.',
			schema: z.object({
				order_id: z.number().describe('Order ID'),
				amount: z.number().describe('Refund amount'),
				transaction_id: z
					.number()
					.optional()
					.describe('Transaction ID to refund (auto-detected if omitted)'),
				reason: z.string().optional().describe('Reason for the refund'),
				refund_method: z.string().optional().describe('Refund method to use'),
			}),
			handler: async (c, input) => {
				const orderId = input.order_id as number
				let transactionId = input.transaction_id as number | undefined

				// Auto-detect transaction if not provided
				if (!transactionId) {
					const orderResp = await c.get(`/orders/${orderId}`)
					const orderWrapper = orderResp.data as Record<string, unknown>
					const orderData = (orderWrapper.order ?? orderWrapper) as Record<string, unknown>
					const transactions = (orderData.transactions ?? []) as Record<string, unknown>[]
					const chargeTx = transactions.find(
						(tx) => tx.transaction_type === 'charge' && tx.status === 'succeeded',
					)
					if (chargeTx) {
						transactionId = chargeTx.id as number
					}
				}

				const refundInfo: Record<string, unknown> = {
					amount: input.amount,
				}
				if (transactionId) refundInfo.transaction_id = transactionId
				if (input.reason) refundInfo.reason = input.reason
				if (input.refund_method) refundInfo.refund_method = input.refund_method

				const resp = await c.post(`/orders/${orderId}/refund`, { refund_info: refundInfo })
				return resp.data
			},
		}),

		createTool(client, {
			name: 'fluentcart_order_update_statuses',
			title: 'Update Order Statuses',
			description:
				'Update payment, shipping, and order statuses independently using backend action+statuses payload mapping. ' +
				'Payment: pending, paid, partially_refunded, refunded, failed. ' +
				'Shipping: pending, shipped, delivered, returned, unshipped.',
			schema: z.object({
				order_id: z.number().describe('Order ID'),
				payment_status: z.string().optional().describe('Payment status'),
				shipping_status: z.string().optional().describe('Shipping status'),
				order_status: z.string().optional().describe('Order status'),
			}),
			handler: async (c, input) => {
				const orderId = input.order_id as number
				const operations: { key: string; body: Record<string, unknown> }[] = []
				const currentResp = await c.get(`/orders/${orderId}`)
				const currentWrapper = currentResp.data as Record<string, unknown>
				const currentOrder = (currentWrapper.order ?? currentWrapper) as Record<string, unknown>
				const currentOrderStatus =
					(currentOrder.status as string | undefined) ??
					(currentOrder.order_status as string | undefined)
				const currentShippingStatus = currentOrder.shipping_status as string | undefined
				const currentPaymentStatus = currentOrder.payment_status as string | undefined

				if (
					input.order_status !== undefined &&
					String(input.order_status) !== String(currentOrderStatus ?? '')
				) {
					operations.push({
						key: 'order_status',
						body: {
							action: 'change_order_status',
							statuses: {
								order_status: input.order_status,
							},
						},
					})
				}
				if (
					input.shipping_status !== undefined &&
					String(input.shipping_status) !== String(currentShippingStatus ?? '')
				) {
					operations.push({
						key: 'shipping_status',
						body: {
							action: 'change_shipping_status',
							statuses: {
								shipping_status: input.shipping_status,
							},
						},
					})
				}
				if (
					input.payment_status !== undefined &&
					String(input.payment_status) !== String(currentPaymentStatus ?? '')
				) {
					operations.push({
						key: 'payment_status',
						body: {
							action: 'change_payment_status',
							statuses: {
								payment_status: input.payment_status,
							},
						},
					})
				}

				if (!operations.length) {
					return {
						message: 'No status changes required',
						order_id: orderId,
						results: [],
					}
				}

				const results: Array<Record<string, unknown>> = []
				for (const op of operations) {
					try {
						const resp = await c.put(`/orders/${orderId}/statuses`, op.body)
						results.push({ field: op.key, ok: true, data: resp.data })
					} catch (error) {
						if (error instanceof FluentCartApiError) {
							results.push({
								field: op.key,
								ok: false,
								error: {
									code: error.code,
									message: error.message,
									detail: error.detail,
								},
							})
							continue
						}
						throw error
					}
				}

				const successCount = results.filter((r) => r.ok === true).length
				if (successCount === 0) {
					throw new FluentCartApiError(
						'SERVER_ERROR',
						'Server error: Failed to update requested status fields',
						500,
						{ results },
					)
				}

				return {
					message:
						successCount === results.length
							? 'Statuses updated successfully'
							: 'Statuses updated partially',
					order_id: orderId,
					results,
				}
			},
		}),

		putTool(client, {
			name: 'fluentcart_order_sync_statuses',
			title: 'Sync Order Statuses',
			description: 'Synchronise order statuses with the payment gateway.',
			schema: z.object({
				order_id: z.number().describe('Order ID'),
			}),
			endpoint: '/orders/:order_id/sync-statuses',
		}),

		postTool(client, {
			name: 'fluentcart_order_change_customer',
			title: 'Change Order Customer',
			description: 'Change the customer associated with an order.',
			schema: z.object({
				order_id: z.number().describe('Order ID'),
				customer_id: z.number().describe('New customer ID'),
			}),
			endpoint: '/orders/:order_id/change-customer',
		}),

		createTool(client, {
			name: 'fluentcart_order_create_and_change_customer',
			title: 'Create and Change Customer',
			description:
				'Create a new customer and associate them with the order. Backend requires `full_name`; ' +
				'this tool auto-composes it from first_name/last_name if needed.',
			schema: z.object({
				order_id: z.number().describe('Order ID'),
				email: z.string().describe('New customer email'),
				full_name: z.string().optional().describe('Full name (required by backend)'),
				first_name: z.string().optional().describe('First name'),
				last_name: z.string().optional().describe('Last name'),
			}),
			handler: async (c, input) => {
				const orderId = input.order_id as number
				const firstName = (input.first_name as string | undefined)?.trim() ?? ''
				const lastName = (input.last_name as string | undefined)?.trim() ?? ''
				const fullName =
					((input.full_name as string | undefined)?.trim() ?? '') ||
					`${firstName} ${lastName}`.trim()

				if (!fullName) {
					throw new FluentCartApiError(
						'VALIDATION_ERROR',
						'Validation error: full_name is required (or provide first_name + last_name)',
						422,
					)
				}

				const body: Record<string, unknown> = {
					email: input.email,
					full_name: fullName,
				}
				if (firstName) body.first_name = firstName
				if (lastName) body.last_name = lastName

				const resp = await c.post(`/orders/${orderId}/create-and-change-customer`, body)
				return resp.data
			},
		}),

		postTool(client, {
			name: 'fluentcart_order_update_address_id',
			title: 'Update Order Address ID',
			description: 'Update the address ID associated with an order.',
			schema: z.object({
				order_id: z.number().describe('Order ID'),
				address_id: z.number().optional().describe('Address ID'),
				address_type: z.string().optional().describe('Address type: billing, shipping'),
			}),
			endpoint: '/orders/:order_id/update-address-id',
		}),

		createTool(client, {
			name: 'fluentcart_order_update_address',
			title: 'Update Order Address',
			description:
				'Update a billing or shipping address on an order. Re-injects IDs into the request body as required by the backend.',
			schema: z.object({
				order_id: z.number().describe('Order ID'),
				address_id: z.number().describe('Address ID'),
				first_name: z.string().optional().describe('First name'),
				last_name: z.string().optional().describe('Last name'),
				address_1: z.string().optional().describe('Address line 1'),
				address_2: z.string().optional().describe('Address line 2'),
				city: z.string().optional().describe('City'),
				state: z.string().optional().describe('State/province'),
				postcode: z.string().optional().describe('Postal code'),
				country: z.string().optional().describe('ISO 3166-1 alpha-2 country code'),
			}),
			handler: async (c, input) => {
				const orderId = input.order_id as number
				const addressId = input.address_id as number

				// Re-inject IDs into body (backend expects them in body, not just path)
				const body: Record<string, unknown> = { ...input }

				const resp = await c.put(`/orders/${orderId}/address/${addressId}`, body)
				return resp.data
			},
		}),

		createTool(client, {
			name: 'fluentcart_order_create_custom',
			title: 'Create Custom Order Line',
			description:
				'Add a custom (non-catalog) line item to an existing order. ' +
				'Requires item name, price, and quantity.',
			schema: z.object({
				order_id: z.number().describe('Order ID'),
				item_name: z.string().describe('Custom item name'),
				item_price: z.number().describe('Item price in currency units'),
				quantity: z.number().optional().describe('Quantity (default: 1)'),
				item_description: z.string().optional().describe('Item description'),
			}),
			handler: async (c, input) => {
				const orderId = input.order_id as number
				const product: Record<string, unknown> = {
					item_name: input.item_name,
					item_price: input.item_price,
					quantity: input.quantity ?? 1,
				}
				if (input.item_description) product.item_description = input.item_description

				const resp = await c.post(`/orders/${orderId}/create-custom`, { product })
				return resp.data
			},
		}),

		postTool(client, {
			name: 'fluentcart_order_generate_licenses',
			title: 'Generate Missing Licenses',
			description: 'Generate any missing licenses for digital products in an order.',
			schema: z.object({
				order_id: z.number().describe('Order ID'),
			}),
			endpoint: '/orders/:order_id/generate-missing-licenses',
		}),
	]
}
