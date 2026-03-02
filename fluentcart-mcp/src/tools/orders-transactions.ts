import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { getTool, postTool, putTool, type ToolDefinition } from './_factory.js'

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

		postTool(client, {
			name: 'fluentcart_order_bulk_action',
			title: 'Bulk Order Actions',
			description:
				'Perform bulk actions on multiple orders. Actions: update_status, delete, export.',
			schema: z.object({
				action: z.string().describe('Bulk action: update_status, delete, export'),
				order_ids: z.array(z.number()).describe('Array of order IDs'),
				data: z
					.record(z.string(), z.unknown())
					.optional()
					.describe('Additional action data (e.g. status for update_status)'),
			}),
			endpoint: '/orders/do-bulk-action',
		}),

		postTool(client, {
			name: 'fluentcart_order_calculate_shipping',
			title: 'Calculate Shipping',
			description: 'Calculate shipping costs for an order based on items and address.',
			schema: z.object({
				items: z.array(z.record(z.string(), z.unknown())).optional().describe('Cart items'),
				shipping_address: z
					.record(z.string(), z.unknown())
					.optional()
					.describe('Shipping address for calculation'),
			}),
			endpoint: '/orders/calculate-shipping',
		}),

		getTool(client, {
			name: 'fluentcart_order_shipping_methods',
			title: 'Get Shipping Methods',
			description: 'Get available shipping methods for order creation.',
			schema: z.object({}),
			endpoint: '/orders/shipping_methods',
		}),

		getTool(client, {
			name: 'fluentcart_order_customer_orders',
			title: 'Get Customer Orders (Paginated)',
			description: 'Get paginated orders for a specific customer with filtering.',
			schema: z.object({
				customerId: z.number().describe('Customer ID'),
				page: z.number().optional().describe('Page number'),
				per_page: z.number().max(50).optional().describe('Results per page (max: 50)'),
				search: z.string().optional().describe('Search keyword'),
				order_by: z.string().optional().describe('Sort field'),
				order_type: z.string().optional().describe('Sort direction: ASC, DESC'),
			}),
			endpoint: '/customers/:customerId/orders',
		}),
	]
}
