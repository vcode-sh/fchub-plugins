import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { deleteTool, getTool, postTool, putTool, type ToolDefinition } from './_factory.js'

export function customerTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_customer_list',
			title: 'List Customers',
			description:
				'Retrieve a paginated list of customers with optional filtering. ' +
				'Returns customer summary with name, email, order count, and total spend. ' +
				'Monetary values in smallest currency unit (cents).',
			schema: z.object({
				page: z.number().optional().describe('Page number (default: 1)'),
				per_page: z.number().optional().describe('Results per page (default: 10)'),
				search: z.string().optional().describe('Search by name or email'),
				order_by: z.string().optional().describe('Sort field (default: id)'),
				order_type: z.string().optional().describe('Sort direction: ASC, DESC (default: DESC)'),
			}),
			endpoint: '/customers',
		}),

		postTool(client, {
			name: 'fluentcart_customer_create',
			title: 'Create Customer',
			description: 'Create a new customer record. Email is required and must be unique.',
			schema: z.object({
				email: z.string().describe('Customer email address (required, must be unique)'),
				first_name: z.string().optional().describe('First name'),
				last_name: z.string().optional().describe('Last name'),
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
			description:
				'Retrieve detailed customer information including addresses, labels, and stats. ' +
				'LTV and monetary values in smallest currency unit (cents).',
			schema: z.object({
				customer_id: z.number().describe('Customer ID'),
			}),
			endpoint: '/customers/:customer_id',
		}),

		putTool(client, {
			name: 'fluentcart_customer_update',
			title: 'Update Customer',
			description: 'Update an existing customer profile.',
			schema: z.object({
				customer_id: z.number().describe('Customer ID'),
				first_name: z.string().optional().describe('First name'),
				last_name: z.string().optional().describe('Last name'),
				phone: z.string().optional().describe('Phone number'),
				status: z.string().optional().describe('Customer status: active, inactive'),
			}),
			endpoint: '/customers/:customer_id',
		}),

		putTool(client, {
			name: 'fluentcart_customer_update_additional_info',
			title: 'Update Customer Additional Info',
			description: 'Update custom metadata fields on a customer record.',
			schema: z.object({
				customer_id: z.number().describe('Customer ID'),
				info: z.record(z.string(), z.unknown()).optional().describe('Key-value metadata to update'),
			}),
			endpoint: '/customers/:customer_id/additional-info',
		}),

		getTool(client, {
			name: 'fluentcart_customer_orders_simple',
			title: 'Get Customer Orders (Simple)',
			description: 'Retrieve customer orders in a simplified format.',
			schema: z.object({
				customer_id: z.number().describe('Customer ID'),
			}),
			endpoint: '/customers/:customer_id/order',
		}),

		getTool(client, {
			name: 'fluentcart_customer_stats',
			title: 'Get Customer Stats',
			description:
				'Retrieve statistics for a customer including order count and total spend. ' +
				'Monetary values in smallest currency unit (cents).',
			schema: z.object({
				customer_id: z.number().describe('Customer ID'),
			}),
			endpoint: '/customers/get-stats/:customer_id',
		}),

		postTool(client, {
			name: 'fluentcart_customer_recalculate_ltv',
			title: 'Recalculate Customer LTV',
			description:
				'Recalculate the lifetime value for a customer. ' +
				'LTV is stored in smallest currency unit (cents).',
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

		getTool(client, {
			name: 'fluentcart_customer_address_select',
			title: 'Get Address Select Options',
			description: 'Retrieve address options for the customer address selector dropdown.',
			schema: z.object({
				customer_id: z.number().describe('Customer ID'),
			}),
			endpoint: '/customers/:customer_id/update-address-select',
		}),

		postTool(client, {
			name: 'fluentcart_customer_address_create',
			title: 'Create Customer Address',
			description: 'Create a new billing or shipping address for a customer.',
			schema: z.object({
				customer_id: z.number().describe('Customer ID'),
				type: z.string().optional().describe('Address type: billing, shipping'),
				first_name: z.string().optional().describe('First name'),
				last_name: z.string().optional().describe('Last name'),
				address_1: z.string().optional().describe('Address line 1'),
				address_2: z.string().optional().describe('Address line 2'),
				city: z.string().optional().describe('City'),
				state: z.string().optional().describe('State/province'),
				postcode: z.string().optional().describe('Postal code'),
				country: z.string().optional().describe('ISO 3166-1 alpha-2 country code'),
			}),
			endpoint: '/customers/:customer_id/address',
		}),

		postTool(client, {
			name: 'fluentcart_customer_address_add',
			title: 'Add Customer Address (Alternative)',
			description: 'Alternative endpoint to add a new address to a customer.',
			schema: z.object({
				customer_id: z.number().optional().describe('Customer ID'),
				type: z.string().optional().describe('Address type: billing, shipping'),
				first_name: z.string().optional().describe('First name'),
				last_name: z.string().optional().describe('Last name'),
				address_1: z.string().optional().describe('Address line 1'),
				address_2: z.string().optional().describe('Address line 2'),
				city: z.string().optional().describe('City'),
				state: z.string().optional().describe('State/province'),
				postcode: z.string().optional().describe('Postal code'),
				country: z.string().optional().describe('ISO 3166-1 alpha-2 country code'),
			}),
			endpoint: '/customers/add-address',
		}),

		putTool(client, {
			name: 'fluentcart_customer_address_update',
			title: 'Update Customer Address',
			description: 'Update an existing customer address.',
			schema: z.object({
				customer_id: z.number().describe('Customer ID'),
				address_id: z.number().optional().describe('Address ID to update'),
				first_name: z.string().optional().describe('First name'),
				last_name: z.string().optional().describe('Last name'),
				address_1: z.string().optional().describe('Address line 1'),
				address_2: z.string().optional().describe('Address line 2'),
				city: z.string().optional().describe('City'),
				state: z.string().optional().describe('State/province'),
				postcode: z.string().optional().describe('Postal code'),
				country: z.string().optional().describe('ISO 3166-1 alpha-2 country code'),
			}),
			endpoint: '/customers/:customer_id/address',
		}),

		deleteTool(client, {
			name: 'fluentcart_customer_address_delete',
			title: 'Delete Customer Address',
			description: 'Delete a customer address. This action cannot be undone.',
			schema: z.object({
				customer_id: z.number().describe('Customer ID'),
				address_id: z.number().describe('Address ID to delete'),
			}),
			endpoint: '/customers/:customer_id/address',
		}),

		postTool(client, {
			name: 'fluentcart_customer_address_make_primary',
			title: 'Set Primary Address',
			description: 'Set an address as the primary address for a customer.',
			schema: z.object({
				customer_id: z.number().describe('Customer ID'),
				address_id: z.number().describe('Address ID to set as primary'),
			}),
			endpoint: '/customers/:customer_id/address/make-primary',
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
				'Perform bulk actions on multiple customers. ' + 'Actions: update_status, delete, export.',
			schema: z.object({
				action: z.string().describe('Bulk action: update_status, delete, export'),
				customer_ids: z.array(z.number()).describe('Array of customer IDs'),
				data: z
					.record(z.string(), z.unknown())
					.optional()
					.describe('Additional action data (e.g. status for update_status)'),
			}),
			endpoint: '/customers/do-bulk-action',
		}),
	]
}
