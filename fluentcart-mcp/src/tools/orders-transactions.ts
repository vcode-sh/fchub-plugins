import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { FluentCartApiError } from '../api/errors.js'
import { createTool, getTool, postTool, putTool, type ToolDefinition } from './_factory.js'

export function orderTransactionTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_order_transactions',
			title: 'Get Order Transactions',
			description:
				'Get all transactions for an order. Types: charge, refund. ' +
				'Statuses: succeeded, pending, failed, refunded, disputed.',
			schema: z.object({
				order_id: z.number().describe('Order ID'),
			}),
			endpoint: '/orders/:order_id/transactions',
			transform: (data: unknown) => {
				const resp = data as Record<string, unknown>
				const order = (resp?.order ?? resp) as Record<string, unknown>
				if (Array.isArray(order.transactions)) {
					return {
						order_id: order.id,
						transactions: (order.transactions as Record<string, unknown>[]).map((t) => {
							const { meta, ...rest } = t
							return rest
						}),
					}
				}
				return resp
			},
		}),

		getTool(client, {
			name: 'fluentcart_order_transaction_get',
			title: 'Get Transaction Details',
			description: 'Get details of a specific transaction on an order.',
			schema: z.object({
				order_id: z.number().describe('Order ID'),
				transaction_id: z.number().describe('Transaction ID'),
			}),
			endpoint: '/orders/:order_id/transactions/:transaction_id',
			transform: (data: unknown) => {
				const resp = data as Record<string, unknown>
				const order = (resp?.order ?? resp) as Record<string, unknown>
				if (Array.isArray(order.transactions)) {
					const transactions = order.transactions as Record<string, unknown>[]
					// The endpoint should return only the requested transaction, but since
					// the route returns full order, we return all transactions for safety
					return {
						order_id: order.id,
						transactions: transactions.map((t) => {
							const { meta, ...rest } = t
							return rest
						}),
					}
				}
				return resp
			},
		}),

		putTool(client, {
			name: 'fluentcart_order_transaction_update_status',
			title: 'Update Transaction Status',
			description:
				'Update transaction status. Statuses: succeeded, pending, failed, refunded, disputed.',
			schema: z.object({
				order_id: z.number().describe('Order ID'),
				transaction_id: z.number().describe('Transaction ID'),
				status: z.string().describe('New transaction status'),
			}),
			endpoint: '/orders/:order_id/transactions/:transaction_id/status',
		}),

		postTool(client, {
			name: 'fluentcart_order_accept_dispute',
			title: 'Accept Dispute',
			description: 'Accept a dispute for a transaction. Side effect: may trigger refund.',
			schema: z.object({
				order_id: z.number().describe('Order ID'),
				transaction_id: z.number().describe('Transaction ID'),
			}),
			endpoint: '/orders/:order_id/transactions/:transaction_id/accept-dispute',
		}),

		createTool(client, {
			name: 'fluentcart_order_bulk_action',
			title: 'Bulk Order Actions',
			description:
				'Perform bulk actions on multiple orders. ' +
				'Actions: change_order_status, change_shipping_status, change_payment_status, delete_orders. ' +
				'Status change actions require new_status parameter.',
			schema: z.object({
				action: z
					.enum([
						'change_order_status',
						'change_shipping_status',
						'change_payment_status',
						'delete_orders',
					])
					.describe('Bulk action to perform'),
				order_ids: z.array(z.number()).describe('Array of order IDs'),
				new_status: z
					.string()
					.optional()
					.describe('New status value (required for status change actions)'),
			}),
			handler: async (c, input) => {
				const action = input.action as string
				const isStatusAction = action.startsWith('change_')

				if (isStatusAction && !input.new_status) {
					throw new FluentCartApiError(
						'VALIDATION_ERROR',
						`Validation error: new_status is required for action '${action}'`,
						422,
					)
				}

				const body: Record<string, unknown> = {
					action,
					order_ids: input.order_ids,
				}
				if (input.new_status) body.new_status = input.new_status

				const resp = await c.post('/orders/do-bulk-action', body)
				return resp.data
			},
		}),

		createTool(client, {
			name: 'fluentcart_order_calculate_shipping',
			title: 'Calculate Shipping',
			description:
				'Apply a shipping method to an order and calculate shipping costs. ' +
				'Requires shipping_id (from shipping_methods endpoint) and order_id. ' +
				'Optionally provide order_items to override.',
			schema: z.object({
				order_id: z.number().describe('Order ID to calculate shipping for'),
				shipping_id: z.number().describe('Shipping method ID (from fluentcart_order_shipping_methods)'),
				order_items: z
					.array(z.record(z.string(), z.unknown()))
					.optional()
					.describe('Order items (fetched from order if omitted)'),
			}),
			handler: async (c, input) => {
				const orderId = input.order_id as number
				let orderItems = input.order_items as Record<string, unknown>[] | undefined

				// Fetch order items if not provided
				if (!orderItems) {
					const orderResp = await c.get(`/orders/${orderId}`)
					const orderWrapper = orderResp.data as Record<string, unknown>
					const order = (orderWrapper.order ?? orderWrapper) as Record<string, unknown>
					orderItems = (order.items ?? order.order_items ?? []) as Record<string, unknown>[]
				}

				const body: Record<string, unknown> = {
					shipping_id: input.shipping_id,
					order_items: orderItems,
					order_id: orderId,
				}

				const resp = await c.post('/orders/calculate-shipping', body)
				return resp.data
			},
		}),

		getTool(client, {
			name: 'fluentcart_order_shipping_methods',
			title: 'Get Shipping Methods',
			description: 'Get available shipping methods for order creation.',
			schema: z.object({}),
			endpoint: '/orders/shipping_methods',
		}),

		createTool(client, {
			name: 'fluentcart_order_customer_orders',
			title: 'Get Customer Orders (Paginated)',
			description:
				'Get paginated orders for a specific customer. Accepts both customerId and customer_id.',
			schema: z.object({
				customerId: z.number().optional().describe('Customer ID'),
				customer_id: z.number().optional().describe('Customer ID (alias for customerId)'),
				page: z.number().optional().describe('Page number'),
				per_page: z.number().max(50).optional().describe('Results per page (max: 50)'),
				search: z.string().optional().describe('Search keyword'),
				sort_by: z.string().optional().describe('Sort field'),
				sort_type: z.string().optional().describe('Sort direction: ASC, DESC'),
			}),
			annotations: { readOnlyHint: true, idempotentHint: true },
			handler: async (c, input) => {
				const customerId = (input.customerId ?? input.customer_id) as number | undefined
				if (!customerId) {
					throw new FluentCartApiError(
						'VALIDATION_ERROR',
						'Validation error: customerId or customer_id is required',
						422,
					)
				}
				const params: Record<string, unknown> = {}
				if (input.page !== undefined) params.page = input.page
				if (input.per_page !== undefined) params.per_page = input.per_page
				if (input.search !== undefined) params.search = input.search
				if (input.sort_by !== undefined) params.sort_by = input.sort_by
				if (input.sort_type !== undefined) params.sort_type = input.sort_type

				const resp = await c.get(`/customers/${customerId}/orders`, params)
				return resp.data
			},
		}),
	]
}
