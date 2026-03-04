import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { createTool, getTool, postTool, putTool, type ToolDefinition } from './_factory.js'

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
						created_at: item.created_at,
					}))
				}
				return resp
			},
		}),

		postTool(client, {
			name: 'fluentcart_customer_create',
			title: 'Create Customer',
			description: 'Create a new customer record. Email is required and must be unique.',
			schema: z.object({
				email: z.string().describe('Customer email address (required, must be unique)'),
				first_name: z.string().optional().describe('First name'),
				last_name: z.string().optional().describe('Last name'),
				full_name: z
					.string()
					.optional()
					.describe('Full name (required if store uses full-name checkout mode)'),
				phone: z.string().optional().describe('Phone number'),
				status: z.string().optional().describe('Customer status: active, inactive'),
				additional_info: z
					.record(z.string(), z.unknown())
					.optional()
					.describe('Additional customer metadata'),
			}),
			endpoint: '/customers',
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

		createTool(client, {
			name: 'fluentcart_customer_update',
			title: 'Update Customer',
			description:
				'Update a customer profile using fetch-merge. Fetches current state, merges your changes, ensures required fields (full_name, email) are always sent.',
			annotations: { idempotentHint: true },
			schema: z.object({
				customer_id: z.number().describe('Customer ID'),
				first_name: z.string().optional().describe('First name'),
				last_name: z.string().optional().describe('Last name'),
				full_name: z.string().optional().describe('Full name'),
				email: z.string().optional().describe('Email address'),
				status: z.string().optional().describe('Customer status: active, inactive'),
				notes: z.string().optional().describe('Customer notes'),
			}),
			handler: async (c, input) => {
				const customerId = input.customer_id as number
				// Fetch current customer
				const current = await c.get(`/customers/${customerId}`)
				const wrapper = current.data as Record<string, unknown>
				const customer = (wrapper.customer ?? wrapper) as Record<string, unknown>
				// Merge changes over current state
				const { customer_id, ...changes } = input
				const merged: Record<string, unknown> = {
					// id is required in body so the validator can exclude this customer from email uniqueness check
					id: customerId,
					first_name: customer.first_name,
					last_name: customer.last_name,
					full_name: customer.full_name,
					email: customer.email,
					status: customer.status,
					...changes,
				}
				const resp = await c.put(`/customers/${customerId}`, merged)
				return resp.data
			},
		}),

		createTool(client, {
			name: 'fluentcart_customer_update_additional_info',
			title: 'Update Customer Labels',
			description:
				'Update label assignments on a customer record. Pass an array of label IDs to sync.',
			annotations: { idempotentHint: true },
			schema: z.object({
				customer_id: z.number().describe('Customer ID'),
				labels: z.array(z.number()).describe('Array of label IDs to assign to the customer'),
			}),
			handler: async (c, input) => {
				const customerId = input.customer_id as number
				const labels = input.labels as number[]
				const resp = await c.put(`/customers/${customerId}/additional-info`, { labels })
				return resp.data
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

		postTool(client, {
			name: 'fluentcart_customer_recalculate_ltv',
			title: 'Recalculate Customer LTV',
			description: 'Recalculate lifetime value for a customer. LTV stored in cents.',
			schema: z.object({
				customer_id: z.number().describe('Customer ID'),
			}),
			endpoint: '/customers/:customer_id/recalculate-ltv',
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

		postTool(client, {
			name: 'fluentcart_customer_address_create',
			title: 'Create Customer Address',
			description: 'Create a new billing or shipping address for a customer.',
			schema: z.object({
				customer_id: z.number().describe('Customer ID'),
				type: z.string().optional().describe('Address type: billing, shipping'),
				name: z.string().describe('Full name (required)'),
				email: z.string().describe('Email address (required)'),
				label: z.string().max(15).describe('Address label, required (e.g. Home, Office — max 15 chars)'),
				company_name: z.string().optional().describe('Company name'),
				phone: z.string().optional().describe('Phone number'),
				address_1: z.string().optional().describe('Address line 1'),
				address_2: z.string().optional().describe('Address line 2'),
				city: z.string().optional().describe('City'),
				state: z.string().optional().describe('State/province'),
				postcode: z.string().optional().describe('Postal code'),
				country: z.string().optional().describe('ISO 3166-1 alpha-2 country code'),
			}),
			endpoint: '/customers/:customer_id/address',
		}),

		createTool(client, {
			name: 'fluentcart_customer_address_update',
			title: 'Update Customer Address',
			description:
				'Update an existing customer address. Backend validates all required fields on every update, so always include: name, email, address_1, city, state, country.',
			annotations: { idempotentHint: true },
			schema: z.object({
				customer_id: z.number().describe('Customer ID'),
				address_id: z.number().describe('Address ID to update'),
				type: z.string().optional().describe('Address type: billing, shipping'),
				name: z.string().optional().describe('Full name'),
				email: z.string().optional().describe('Email address'),
				label: z.string().max(15).optional().describe('Address label (e.g. Home, Office — max 15 chars)'),
				company_name: z.string().optional().describe('Company name'),
				phone: z.string().optional().describe('Phone number'),
				address_1: z.string().optional().describe('Address line 1'),
				address_2: z.string().optional().describe('Address line 2'),
				city: z.string().optional().describe('City'),
				state: z.string().optional().describe('State/province'),
				postcode: z.string().optional().describe('Postal code'),
				country: z.string().optional().describe('ISO 3166-1 alpha-2 country code'),
			}),
			handler: async (client, input) => {
				const { customer_id, address_id, ...fields } = input
				const body = { ...fields, id: address_id }
				const response = await client.put(`/customers/${customer_id}/address`, body)
				return response.data
			},
		}),

		createTool(client, {
			name: 'fluentcart_customer_address_delete',
			title: 'Delete Customer Address',
			description: 'Delete a customer address. This action cannot be undone.',
			annotations: { destructiveHint: true },
			schema: z.object({
				customer_id: z.number().describe('Customer ID'),
				address_id: z.number().describe('Address ID to delete'),
			}),
			handler: async (client, input) => {
				const { customer_id, address_id } = input
				const response = await client.request('DELETE', `/customers/${customer_id}/address`, {
					body: { address: { id: address_id } },
				})
				return response.data
			},
		}),

		createTool(client, {
			name: 'fluentcart_customer_address_make_primary',
			title: 'Set Primary Address',
			description: 'Set an address as the primary billing or shipping address for a customer.',
			schema: z.object({
				customer_id: z.number().describe('Customer ID'),
				address_id: z.number().describe('Address ID to set as primary'),
				type: z.enum(['billing', 'shipping']).describe('Address type: billing or shipping'),
			}),
			handler: async (client, input) => {
				const { customer_id, address_id, type } = input
				const body = { addressId: address_id, type }
				const response = await client.post(`/customers/${customer_id}/address/make-primary`, body)
				return response.data
			},
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

		postTool(client, {
			name: 'fluentcart_customer_attach_user',
			title: 'Attach User to Customer',
			description: 'Attach a WordPress user account to a customer record.',
			schema: z.object({
				customer_id: z.number().describe('Customer ID'),
				user_id: z.number().describe('WordPress user ID to attach'),
			}),
			endpoint: '/customers/:customer_id/attachable-user',
		}),

		postTool(client, {
			name: 'fluentcart_customer_detach_user',
			title: 'Detach User from Customer',
			description: 'Detach the linked WordPress user from a customer record.',
			schema: z.object({
				customer_id: z.number().describe('Customer ID'),
			}),
			endpoint: '/customers/:customer_id/detach-user',
		}),

		postTool(client, {
			name: 'fluentcart_customer_bulk_action',
			title: 'Bulk Customer Actions',
			description:
				'Perform bulk actions on multiple customers. Only supported action: delete_customers.',
			schema: z.object({
				action: z.enum(['delete_customers']).describe('Bulk action: delete_customers'),
				customer_ids: z.array(z.number()).describe('Array of customer IDs'),
			}),
			endpoint: '/customers/do-bulk-action',
		}),

		getTool(client, {
			name: 'fluentcart_customer_orders_simple',
			title: 'Get Customer Orders (Simple)',
			description: 'Get a simplified list of orders for a customer with basic order details.',
			schema: z.object({
				customer_id: z.number().describe('Customer ID'),
			}),
			endpoint: '/customers/:customer_id/order',
		}),

	]
}
