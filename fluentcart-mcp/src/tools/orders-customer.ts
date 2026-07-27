import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { FluentCartApiError } from '../api/errors.js'
import { createTool, postTool, type ToolDefinition } from './_factory.js'
import { direct } from './endpoints.js'

/**
 * Reassigning an order's customer, and editing the address attached to it.
 *
 * Split from orders-core.ts: these change who an order belongs to and where it ships, which is
 * a different blast radius from editing the order's own fields.
 */
export function orderCustomerTools(client: FluentCartClient): ToolDefinition[] {
	return [
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
			routes: direct('POST', '/orders/{param}/create-and-change-customer'),
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
			routes: direct('PUT', '/orders/{param}/address/{param}'),
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
	]
}
