import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { createTool, deleteTool, postTool, putTool, type ToolDefinition } from './_factory.js'
import { composite, direct, op } from './endpoints.js'
import {
	buildOtherInfo,
	buildVariantFromExisting,
	subscriptionSchema,
} from './products-variant-payload.js'

/** Variant mutations. Split from products-variants.ts, which keeps the read side. */
export function productVariantWriteTools(client: FluentCartClient): ToolDefinition[] {
	return [
		createTool(client, {
			name: 'fluentcart_variant_create',
			routes: direct('POST', '/products/variants'),
			title: 'Create Variation',
			description:
				'Create a new product variation. Price in whole currency units (e.g. 400 for 400 PLN, not cents). ' +
				'Stock is set directly via total_stock (no separate inventory call needed). ' +
				'Set payment_type to "subscription" for recurring billing variants.',
			schema: z.object({
				product_id: z.number().describe('Parent product ID'),
				title: z.string().describe('Variation title (required, e.g. "Tiger Pants - White")'),
				price: z
					.number()
					.optional()
					.describe('Price in currency units (e.g. 10 for 10.00, default: 0)'),
				sku: z.string().optional().describe('Stock keeping unit'),
				stock_quantity: z.number().optional().describe('Stock quantity'),
				fulfillment_type: z
					.string()
					.optional()
					.describe('Fulfilment type: physical or digital (default: physical)'),
				...subscriptionSchema,
			}),
			handler: async (client, input) => {
				const productId = input.product_id as number
				const otherInfo = buildOtherInfo(input)
				const body = {
					product_id: productId,
					variants: {
						post_id: productId,
						variation_title: (input.title as string) || '',
						item_price: (input.price as number) ?? 0,
						compare_price: (input.compare_price as number) ?? 0,
						...(typeof input.sku === 'string' && input.sku.trim() ? { sku: input.sku.trim() } : {}),
						fulfillment_type: (input.fulfillment_type as string) || 'physical',
						total_stock: (input.stock_quantity as number) ?? 0,
						available: (input.stock_quantity as number) ?? 0,
						committed: 0,
						on_hold: 0,
						stock_status: 'in-stock',
						item_status: (input.item_status as string) || 'active',
						other_info: otherInfo,
					},
				}
				const response = await client.post('/products/variants', body)
				return response.data
			},
		}),

		createTool(client, {
			name: 'fluentcart_variant_update',
			routes: composite(
				op('GET', '/products/{param}/pricing'),
				op('POST', '/products/variants/{param}'),
			),
			title: 'Update Variation',
			description:
				'Update an existing product variation. Only provided fields are changed. ' +
				'Price in whole currency units (e.g. 400 for 400 PLN, not cents). ' +
				'Fetches current variant state first, then merges your changes. ' +
				'When updating subscription variants, you must re-specify payment_type to change any subscription field. ' +
				'Partial subscription field updates are silently ignored by the backend.',
			schema: z.object({
				product_id: z.number().describe('Parent product ID'),
				variant_id: z.number().describe('Variant ID'),
				title: z.string().optional().describe('Variation title'),
				price: z.number().optional().describe('Price in currency units (e.g. 400 for 400 PLN)'),
				sku: z.string().optional().describe('Stock keeping unit'),
				stock_quantity: z.number().optional().describe('Stock quantity'),
				...subscriptionSchema,
			}),
			handler: async (client, input) => {
				const productId = input.product_id as number
				const variantId = input.variant_id as number

				// Fetch current product state to get existing variant data
				const current = await client.get(`/products/${productId}/pricing`)
				const wrapper = current.data as Record<string, unknown>
				const product = (wrapper.product ?? wrapper) as Record<string, unknown>
				const existingVariants = (product.variants ?? []) as Record<string, unknown>[]
				const existing = existingVariants.find((v) => v.id === variantId)

				// Build full variant body from existing state + changed fields
				const variants = buildVariantFromExisting(existing, productId, variantId)

				// Apply user's changes
				const overrides: Record<string, unknown> = {}
				if (input.title !== undefined) overrides.variation_title = input.title
				if (input.price !== undefined) overrides.item_price = input.price
				if (input.sku !== undefined && typeof input.sku === 'string' && input.sku.trim()) {
					overrides.sku = input.sku.trim()
				}
				if (input.stock_quantity !== undefined) {
					overrides.total_stock = input.stock_quantity
					overrides.available = input.stock_quantity
				}
				if (input.compare_price !== undefined) overrides.compare_price = input.compare_price
				if (input.item_status !== undefined) overrides.item_status = input.item_status

				// Rebuild other_info if payment_type is explicitly provided
				if (input.payment_type !== undefined) {
					overrides.other_info = buildOtherInfo(input)
				}
				Object.assign(variants, overrides)

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
			title: 'Update Variation Pricing Table Description',
			description:
				'Update the pricing table description for a variation. ' +
				'WARNING: This does NOT update prices — only the description text in other_info. ' +
				'To update prices, use fluentcart_variant_update or fluentcart_product_pricing_update.',
			schema: z.object({
				variant_id: z.number().describe('Variant ID'),
				description: z.string().optional().describe('Pricing table description text'),
			}),
			endpoint: '/products/variants/:variant_id/pricing-table',
		}),
	]
}
