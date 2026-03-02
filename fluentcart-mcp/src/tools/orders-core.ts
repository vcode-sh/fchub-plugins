import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { deleteTool, getTool, postTool, putTool, type ToolDefinition } from './_factory.js'

export function orderCoreTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_order_list',
			title: 'List Orders',
			description:
				'Retrieve a paginated list of orders with optional filtering. ' +
				'Returns order summaries with customer info, payment status, and totals. ' +
				'Monetary values in smallest currency unit (cents). ' +
				'Statuses: pending, processing, completed, canceled. ' +
				'Payment statuses: pending, paid, partially_refunded, refunded, failed.',
			schema: z.object({
				page: z.number().optional().describe('Page number (default: 1)'),
				per_page: z.number().optional().describe('Results per page (default: 10)'),
				search: z.string().optional().describe('Search orders by keyword'),
				order_by: z.string().optional().describe('Sort field (default: id)'),
				order_type: z.string().optional().describe('Sort direction: ASC, DESC (default: DESC)'),
			}),
			endpoint: '/orders',
		}),

		postTool(client, {
			name: 'fluentcart_order_create',
			title: 'Create Order',
			description:
				'Create a new order with items and customer information. ' +
				'Monetary values in smallest currency unit (cents).',
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
				'Retrieve detailed order information including items, transactions, addresses, ' +
				'activities, and customer data. Monetary values in smallest currency unit (cents).',
			schema: z.object({
				order_id: z.number().describe('Order ID'),
			}),
			endpoint: '/orders/:order_id',
		}),

		postTool(client, {
			name: 'fluentcart_order_update',
			title: 'Update Order',
			description:
				'Update an existing order. Subscription orders cannot be edited. ' +
				'Completed orders cannot have their status updated.',
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
				'Mark an order as paid manually. ' +
				'Side effect: triggers order paid hooks and integration feeds.',
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
				'Process a refund for an order. Amount in smallest currency unit (cents). ' +
				'Side effect: sends refund to payment gateway if applicable.',
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
