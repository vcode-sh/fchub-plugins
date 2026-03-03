import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
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

		postTool(client, {
			name: 'fluentcart_order_create',
			title: 'Create Order',
			description: 'Create a new order with items and customer information.',
			schema: z.object({
				customer_id: z.number().describe('Customer ID (required)'),
				items: z
					.array(
						z.object({
							product_id: z.number().optional().describe('Product ID'),
							variation_id: z.number().optional().describe('Variation ID'),
							quantity: z.number().optional().describe('Quantity'),
						}),
					)
					.optional()
					.describe('Order line items'),
				payment_method: z.string().optional().describe('Payment method identifier'),
				note: z.string().optional().describe('Order note'),
			}),
			endpoint: '/orders',
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

		postTool(client, {
			name: 'fluentcart_order_update',
			title: 'Update Order',
			description:
				'Update an existing order. Subscription orders cannot be edited. Completed orders cannot change status.',
			schema: z.object({
				order_id: z.number().describe('Order ID'),
				status: z.string().optional().describe('Order status'),
				note: z.string().optional().describe('Order note'),
			}),
			endpoint: '/orders/:order_id',
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

		postTool(client, {
			name: 'fluentcart_order_mark_paid',
			title: 'Mark Order as Paid',
			description:
				'Mark an order as paid manually. Side effect: triggers order paid hooks and integration feeds.',
			schema: z.object({
				order_id: z.number().describe('Order ID'),
				payment_method: z.string().optional().describe('Payment method used'),
				transaction_id: z.string().optional().describe('External transaction ID'),
				note: z.string().optional().describe('Payment note'),
			}),
			endpoint: '/orders/:order_id/mark-as-paid',
		}),

		postTool(client, {
			name: 'fluentcart_order_refund',
			title: 'Refund Order',
			description:
				'Process a refund for an order. Side effect: sends refund to payment gateway if applicable.',
			schema: z.object({
				order_id: z.number().describe('Order ID'),
				amount: z.number().optional().describe('Refund amount in cents'),
				reason: z.string().optional().describe('Reason for the refund'),
				refund_method: z.string().optional().describe('Refund method to use'),
			}),
			endpoint: '/orders/:order_id/refund',
		}),

		putTool(client, {
			name: 'fluentcart_order_update_statuses',
			title: 'Update Order Statuses',
			description:
				'Update payment, shipping, and order statuses independently. ' +
				'Payment: pending, paid, partially_refunded, refunded, failed. ' +
				'Shipping: pending, shipped, delivered, returned, unshipped.',
			schema: z.object({
				order_id: z.number().describe('Order ID'),
				payment_status: z.string().optional().describe('Payment status'),
				shipping_status: z.string().optional().describe('Shipping status'),
				order_status: z.string().optional().describe('Order status'),
			}),
			endpoint: '/orders/:order_id/statuses',
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

		postTool(client, {
			name: 'fluentcart_order_create_and_change_customer',
			title: 'Create and Change Customer',
			description: 'Create a new customer and associate them with the order.',
			schema: z.object({
				order_id: z.number().describe('Order ID'),
				email: z.string().describe('New customer email'),
				first_name: z.string().optional().describe('First name'),
				last_name: z.string().optional().describe('Last name'),
			}),
			endpoint: '/orders/:order_id/create-and-change-customer',
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

		putTool(client, {
			name: 'fluentcart_order_update_address',
			title: 'Update Order Address',
			description: 'Update a billing or shipping address on an order.',
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
			endpoint: '/orders/:order_id/address/:address_id',
		}),

		postTool(client, {
			name: 'fluentcart_order_create_custom',
			title: 'Create Custom Order Line',
			description: 'Create a custom order line item on an existing order.',
			schema: z.object({
				order_id: z.number().describe('Order ID'),
			}),
			endpoint: '/orders/:order_id/create-custom',
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
