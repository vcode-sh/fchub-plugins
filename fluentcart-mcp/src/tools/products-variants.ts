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

export function productVariantTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_variant_list_all',
			title: 'List All Variants',
			description: 'List all product variants across all products with pagination.',
			schema: z.object({
				page: z.number().optional().describe('Page number (default: 1)'),
				per_page: z.number().max(50).optional().describe('Results per page (max: 50)'),
			}),
			endpoint: '/variants',
		}),

		getTool(client, {
			name: 'fluentcart_variant_list',
			title: 'List Variations',
			description: 'List product variations with optional product filtering.',
			schema: z.object({
				product_id: z.number().optional().describe('Filter by product ID'),
				page: z.number().optional().describe('Page number (default: 1)'),
				per_page: z.number().max(50).optional().describe('Results per page (max: 50)'),
			}),
			endpoint: '/products/variants',
		}),

		createTool(client, {
			name: 'fluentcart_variant_create',
			title: 'Create Variation',
			description:
				'Create a new product variation. Price in cents. ' +
				'Stock is set directly via total_stock (no separate inventory call needed).',
			schema: z.object({
				product_id: z.number().describe('Parent product ID'),
				title: z.string().optional().describe('Variation title (e.g. "Tiger Pants - White")'),
				price: z.number().optional().describe('Price in cents (e.g. 1000 = 10.00)'),
				sku: z.string().optional().describe('Stock keeping unit'),
				stock_quantity: z.number().optional().describe('Stock quantity'),
				fulfillment_type: z
					.string()
					.optional()
					.describe('Fulfilment type: physical or digital (default: physical)'),
			}),
			handler: async (client, input) => {
				const productId = input.product_id as number
				const body = {
					product_id: productId,
					variants: {
						post_id: productId,
						variation_title: (input.title as string) || '',
						item_price: (input.price as number) ?? 0,
						sku: (input.sku as string) || '',
						fulfillment_type: (input.fulfillment_type as string) || 'physical',
						total_stock: (input.stock_quantity as number) ?? 0,
						available: (input.stock_quantity as number) ?? 0,
						committed: 0,
						on_hold: 0,
						stock_status: 'in-stock',
						other_info: {
							payment_type: 'onetime',
							times: '',
							repeat_interval: '',
							trial_days: '',
							billing_summary: '',
							manage_setup_fee: 'no',
							signup_fee_name: '',
							signup_fee: '',
							setup_fee_per_item: 'no',
						},
					},
				}
				const response = await client.post('/products/variants', body)
				return response.data
			},
		}),

		createTool(client, {
			name: 'fluentcart_variant_update',
			title: 'Update Variation',
			description: 'Update an existing product variation. Only provided fields are changed.',
			schema: z.object({
				variant_id: z.number().describe('Variant ID'),
				title: z.string().optional().describe('Variation title'),
				price: z.number().optional().describe('Price in cents'),
				sku: z.string().optional().describe('Stock keeping unit'),
				stock_quantity: z.number().optional().describe('Stock quantity'),
			}),
			handler: async (client, input) => {
				const variantId = input.variant_id as number
				const variants: Record<string, unknown> = {}
				if (input.title !== undefined) variants.variation_title = input.title
				if (input.price !== undefined) variants.item_price = input.price
				if (input.sku !== undefined) variants.sku = input.sku
				if (input.stock_quantity !== undefined) {
					variants.total_stock = input.stock_quantity
					variants.available = input.stock_quantity
				}
				const body = { variants }
				const response = await client.post(`/products/variants/${variantId}`, body)
				return response.data
			},
		}),

		deleteTool(client, {
			name: 'fluentcart_variant_delete',
			title: 'Delete Variation',
			description: 'Delete a product variation. Cannot be undone.',
			schema: z.object({
				variant_id: z.number().describe('Variant ID'),
			}),
			endpoint: '/products/variants/:variant_id',
		}),

		postTool(client, {
			name: 'fluentcart_variant_set_media',
			title: 'Set Variation Media',
			description: 'Set the media (image) for a product variation.',
			schema: z.object({
				variant_id: z.number().describe('Variant ID'),
				media_id: z.number().optional().describe('WordPress media attachment ID'),
			}),
			endpoint: '/products/variants/:variant_id/setMedia',
		}),

		putTool(client, {
			name: 'fluentcart_variant_pricing_table_update',
			title: 'Update Variation Pricing Table',
			description: 'Update the pricing table for a product variation. Prices in cents.',
			schema: z.object({
				variant_id: z.number().describe('Variant ID'),
				item_price: z.number().optional().describe('Price in cents'),
				compare_price: z.number().optional().describe('Compare-at price in cents'),
			}),
			endpoint: '/products/variants/:variant_id/pricing-table',
		}),

		getTool(client, {
			name: 'fluentcart_variant_fetch_by_ids',
			title: 'Fetch Variations by IDs',
			description: 'Retrieve multiple variations by IDs. Limit to 20 per request.',
			schema: z.object({
				variation_ids: z.string().describe('Comma-separated variation IDs (max 20)'),
			}),
			endpoint: '/products/fetchVariationsByIds',
		}),

		getTool(client, {
			name: 'fluentcart_variant_upgrade_paths',
			title: 'Get Variation Upgrade Paths',
			description: 'Get upgrade paths for a specific variation.',
			schema: z.object({
				variant_id: z.number().describe('Variant ID'),
			}),
			endpoint: '/products/variation/:variant_id/upgrade-paths',
		}),
	]
}
