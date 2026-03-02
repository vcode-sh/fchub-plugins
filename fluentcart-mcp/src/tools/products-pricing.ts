import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { getTool, postTool, putTool, type ToolDefinition } from './_factory.js'

export function productPricingTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_product_pricing_get',
			title: 'Get Product Pricing',
			description:
				'Retrieve pricing information for a product. ' +
				'All prices in smallest currency unit (cents).',
			schema: z.object({
				product_id: z.number().describe('Product ID'),
			}),
			endpoint: '/products/:product_id/pricing',
		}),

		postTool(client, {
			name: 'fluentcart_product_pricing_update',
			title: 'Update Product Pricing',
			description:
				'Update pricing for a product. Prices must be in smallest currency unit (cents).',
			schema: z.object({
				product_id: z.number().describe('Product ID'),
				price: z.number().optional().describe('Price in cents'),
				sale_price: z.number().optional().describe('Sale price in cents'),
				sku: z.string().optional().describe('Stock keeping unit'),
			}),
			endpoint: '/products/:product_id/pricing',
		}),

		getTool(client, {
			name: 'fluentcart_product_pricing_widgets',
			title: 'Get Product Pricing Widgets',
			description: 'Retrieve pricing widgets and display components for a product.',
			schema: z.object({
				product_id: z.number().describe('Product ID'),
			}),
			endpoint: '/products/:product_id/pricing-widgets',
		}),

		getTool(client, {
			name: 'fluentcart_product_related',
			title: 'Get Related Products',
			description: 'Retrieve related products for cross-sell and upsell.',
			schema: z.object({
				product_id: z.number().describe('Product ID'),
			}),
			endpoint: '/products/:product_id/related-products',
		}),

		getTool(client, {
			name: 'fluentcart_product_bundle_info',
			title: 'Get Product Bundle Info',
			description: 'Retrieve bundle information for a bundled product.',
			schema: z.object({
				product_id: z.number().describe('Product ID'),
			}),
			endpoint: '/products/:product_id/get-bundle-info',
		}),

		postTool(client, {
			name: 'fluentcart_product_bundle_save',
			title: 'Save Product Bundle Info',
			description: 'Save bundle configuration for a product variation.',
			schema: z.object({
				variation_id: z.number().describe('Variation ID'),
				bundle_items: z
					.array(z.record(z.string(), z.unknown()))
					.optional()
					.describe('Bundle item configuration'),
			}),
			endpoint: '/products/save-bundle-info/:variation_id',
		}),

		postTool(client, {
			name: 'fluentcart_product_tax_class_update',
			title: 'Update Product Tax Class',
			description: 'Assign a tax class to a product.',
			schema: z.object({
				product_id: z.number().describe('Product ID'),
				tax_class: z.string().optional().describe('Tax class identifier'),
			}),
			endpoint: '/products/:product_id/tax-class',
		}),

		postTool(client, {
			name: 'fluentcart_product_tax_class_remove',
			title: 'Remove Product Tax Class',
			description: 'Remove the tax class assignment from a product.',
			schema: z.object({
				product_id: z.number().describe('Product ID'),
			}),
			endpoint: '/products/:product_id/tax-class/remove',
		}),

		postTool(client, {
			name: 'fluentcart_product_shipping_class_update',
			title: 'Update Product Shipping Class',
			description: 'Assign a shipping class to a product.',
			schema: z.object({
				product_id: z.number().describe('Product ID'),
				shipping_class: z.string().optional().describe('Shipping class identifier'),
			}),
			endpoint: '/products/:product_id/shipping-class',
		}),

		postTool(client, {
			name: 'fluentcart_product_shipping_class_remove',
			title: 'Remove Product Shipping Class',
			description: 'Remove the shipping class assignment from a product.',
			schema: z.object({
				product_id: z.number().describe('Product ID'),
			}),
			endpoint: '/products/:product_id/shipping-class/remove',
		}),

		putTool(client, {
			name: 'fluentcart_product_manage_stock_update',
			title: 'Update Stock Management',
			description: 'Enable or disable stock management for a product.',
			schema: z.object({
				product_id: z.number().describe('Product ID'),
				manage_stock: z.string().describe('Enable stock management: 1 or 0'),
			}),
			endpoint: '/products/:product_id/update-manage-stock',
		}),

		putTool(client, {
			name: 'fluentcart_product_inventory_update',
			title: 'Update Product Inventory',
			description: 'Update inventory quantity for a specific product variant.',
			schema: z.object({
				product_id: z.number().describe('Product ID'),
				variant_id: z.number().describe('Variant ID'),
				total_stock: z.number().optional().describe('Total stock quantity'),
			}),
			endpoint: '/products/:product_id/update-inventory/:variant_id',
		}),
	]
}
