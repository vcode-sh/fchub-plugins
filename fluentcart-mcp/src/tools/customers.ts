import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { getTool, type ToolDefinition } from './_factory.js'

export function customerTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_customer_list',
			title: 'List Customers',
			description:
				'List customers with optional filtering and sorting. Sort by purchase_value or ltv DESC to find top customers.',
			schema: z.object({
				page: z.number().optional().describe('Page number (default: 1)'),
				per_page: z.number().max(50).optional().describe('Results per page (default: 10, max: 50)'),
				search: z.string().optional().describe('Search by name or email'),
				sort_by: z
					.string()
					.optional()
					.describe(
						'Sort field: id, purchase_value, purchase_count, ltv, created_at, first_purchase_date (default: id)',
					),
				sort_type: z.string().optional().describe('Sort direction: ASC, DESC (default: DESC)'),
			}),
			endpoint: '/customers',
			transform: (data: unknown) => {
				const resp = data as Record<string, unknown>
				const wrapper = (resp?.customers ?? resp) as Record<string, unknown>
				if (wrapper && Array.isArray(wrapper.data)) {
					wrapper.data = (wrapper.data as Record<string, unknown>[]).map((item) => ({
						id: item.id,
						first_name: item.first_name,
						last_name: item.last_name,
						email: item.email,
						full_name: item.full_name,
						status: item.status,
						order_count: item.order_count,
						total_spend: item.total_spend,
						purchase_value: item.purchase_value,
						purchase_count: item.purchase_count,
						ltv: item.ltv,
						created_at: item.created_at,
					}))
				}
				return resp
			},
		}),

		getTool(client, {
			name: 'fluentcart_customer_get',
			title: 'Get Customer',
			description: 'Get detailed customer information including labels and stats.',
			schema: z.object({
				customer_id: z.number().describe('Customer ID'),
			}),
			endpoint: '/customers/:customer_id',
			transform: (data: unknown) => {
				const resp = data as Record<string, unknown>
				const customer = (resp?.customer ?? resp) as Record<string, unknown>
				const { addresses, ...rest } = customer
				const shaped = Array.isArray(addresses)
					? { ...rest, address_count: (addresses as unknown[]).length }
					: rest
				return resp?.customer ? { ...resp, customer: shaped } : shaped
			},
		}),

		getTool(client, {
			name: 'fluentcart_customer_stats',
			title: 'Get Customer Stats',
			description: 'Get customer statistics including order count and total spend (in cents).',
			schema: z.object({
				customer_id: z.number().describe('Customer ID'),
			}),
			endpoint: '/customers/get-stats/:customer_id',
		}),

		getTool(client, {
			name: 'fluentcart_customer_addresses',
			title: 'Get Customer Addresses',
			description: 'Retrieve all billing and shipping addresses for a customer.',
			schema: z.object({
				customer_id: z.number().describe('Customer ID'),
			}),
			endpoint: '/customers/:customer_id/address',
		}),

		getTool(client, {
			name: 'fluentcart_customer_attachable_users',
			title: 'Get Attachable Users',
			description: 'Retrieve WordPress users that can be attached to customer records.',
			schema: z.object({
				search: z.string().optional().describe('Search users by name or email'),
			}),
			endpoint: '/customers/attachable-user',
		}),

		getTool(client, {
			name: 'fluentcart_customer_orders_simple',
			title: 'Get Customer Orders (Simple)',
			description:
				'Get a non-paginated list of orders for a customer with basic order details. For paginated results, use order_list with customer_id filter.',
			schema: z.object({
				customer_id: z.number().describe('Customer ID'),
			}),
			endpoint: '/customers/:customer_id/order',
		}),
	]
}
