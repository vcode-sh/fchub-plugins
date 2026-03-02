import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { deleteTool, getTool, postTool, putTool, type ToolDefinition } from './_factory.js'

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

		postTool(client, {
			name: 'fluentcart_variant_create',
			title: 'Create Variation',
			description: 'Create a new product variation. Price in cents.',
			schema: z.object({
				product_id: z.number().optional().describe('Parent product ID'),
				title: z.string().optional().describe('Variation title'),
				price: z.number().optional().describe('Price in cents'),
				sku: z.string().optional().describe('Stock keeping unit'),
				stock_quantity: z.number().optional().describe('Stock quantity'),
			}),
			endpoint: '/products/variants',
		}),

		postTool(client, {
			name: 'fluentcart_variant_update',
			title: 'Update Variation',
			description: 'Update an existing product variation.',
			schema: z.object({
				variant_id: z.number().describe('Variant ID'),
				title: z.string().optional().describe('Variation title'),
				price: z.number().optional().describe('Price in cents'),
				sku: z.string().optional().describe('Stock keeping unit'),
				stock_quantity: z.number().optional().describe('Stock quantity'),
			}),
			endpoint: '/products/variants/:variant_id',
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

		postTool(client, {
			name: 'fluentcart_product_variant_option_update',
			title: 'Update Variant Option',
			description: 'Update a product variant option (attribute configuration).',
			schema: z.object({
				product_id: z.number().describe('Product ID'),
				option_name: z.string().optional().describe('Option/attribute name'),
				option_values: z.array(z.string()).optional().describe('Option values'),
			}),
			endpoint: '/products/:product_id/update-variant-option',
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
