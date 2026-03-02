import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { deleteTool, getTool, postTool, putTool, type ToolDefinition } from './_factory.js'

const discountSchema = z
	.object({
		discount_type: z
			.enum(['percentage', 'fixed'])
			.optional()
			.describe('Discount type: "percentage" or "fixed"'),
		discount_amount: z
			.number()
			.optional()
			.describe('Discount amount (percentage value or fixed amount in smallest currency unit)'),
	})
	.optional()
	.describe('Discount configuration for the bump offer')

const configSchema = z
	.object({
		discount: discountSchema,
		display_conditions_if: z
			.string()
			.optional()
			.describe('Display condition logic ("all" or "any")'),
		call_to_action: z.string().optional().describe('Call-to-action text shown on the bump'),
		allow_coupon: z
			.enum(['yes', 'no'])
			.optional()
			.describe('Allow coupon discount on top of bump discount'),
		free_shipping: z
			.enum(['yes', 'no'])
			.optional()
			.describe('Enable free shipping for the bump item'),
	})
	.optional()
	.describe('Order bump configuration object')

const conditionsSchema = z
	.array(
		z.array(
			z.object({
				key: z.string().describe('Condition key (e.g. product, cart_total)'),
				operator: z.string().describe('Comparison operator'),
				value: z.union([z.string(), z.number()]).describe('Condition value'),
			}),
		),
	)
	.optional()
	.describe('Display condition groups (outer array = OR, inner array = AND)')

export function orderBumpTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_order_bump_list',
			title: 'List Order Bumps',
			description:
				'Retrieve a paginated list of order bump configurations. ' +
				'Returns bump summaries with title, status, and associated product variant. ' +
				'Statuses: active, draft.',
			schema: z.object({
				page: z.number().optional().describe('Page number (default: 1)'),
				per_page: z.number().optional().describe('Results per page (default: 15)'),
				search: z.string().optional().describe('Search bumps by title or ID'),
				sort_by: z
					.enum(['id', 'title', 'created_at'])
					.optional()
					.describe('Sort field (default: id)'),
				sort_type: z.enum(['ASC', 'DESC']).optional().describe('Sort direction (default: DESC)'),
			}),
			endpoint: '/order_bump',
		}),

		getTool(client, {
			name: 'fluentcart_order_bump_get',
			title: 'Get Order Bump',
			description:
				'Retrieve detailed information about a specific order bump including its config, ' +
				'discount settings, display conditions, and associated product variant.',
			schema: z.object({
				id: z.number().describe('Order bump ID'),
			}),
			endpoint: '/order_bump/:id',
		}),

		postTool(client, {
			name: 'fluentcart_order_bump_create',
			title: 'Create Order Bump',
			description:
				'Create a new order bump configuration. Requires a title and a product variation ID ' +
				'(src_object_id) for the promotional product. Returns the created bump with its new ID.',
			schema: z.object({
				title: z.string().describe('Bump offer title shown to customers at checkout'),
				src_object_id: z.number().describe('Product variation ID to use as the bump offer product'),
				description: z.string().optional().describe('HTML description shown below the bump title'),
				status: z.enum(['active', 'draft']).optional().describe('Bump status (default: draft)'),
				priority: z
					.number()
					.optional()
					.describe('Display priority — lower numbers appear first (default: 1)'),
				config: configSchema,
				conditions: conditionsSchema,
			}),
			endpoint: '/order_bump',
		}),

		putTool(client, {
			name: 'fluentcart_order_bump_update',
			title: 'Update Order Bump',
			description:
				'Update an existing order bump configuration. Only provided fields are changed. ' +
				'Use this to modify title, description, status, discount, conditions, or priority.',
			schema: z.object({
				id: z.number().describe('Order bump ID to update'),
				title: z.string().optional().describe('Bump offer title shown to customers'),
				src_object_id: z.number().optional().describe('Product variation ID for the bump product'),
				description: z.string().optional().describe('HTML description for the bump'),
				status: z.enum(['active', 'draft']).optional().describe('Bump status'),
				priority: z.number().optional().describe('Display priority — lower numbers appear first'),
				config: configSchema,
				conditions: conditionsSchema,
			}),
			endpoint: '/order_bump/:id',
		}),

		deleteTool(client, {
			name: 'fluentcart_order_bump_delete',
			title: 'Delete Order Bump',
			description: 'Permanently delete an order bump configuration. This cannot be undone.',
			schema: z.object({
				id: z.number().describe('Order bump ID to delete'),
			}),
			endpoint: '/order_bump/:id',
		}),
	]
}
