import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { createTool, getTool, postTool, putTool, type ToolDefinition } from './_factory.js'

function buildOtherInfo(v: Record<string, unknown>): Record<string, unknown> {
	if (v.payment_type === 'subscription') {
		return {
			payment_type: 'subscription',
			times: v.times !== undefined ? String(v.times) : '',
			repeat_interval: (v.repeat_interval as string) || '',
			trial_days: v.trial_days !== undefined ? String(v.trial_days) : '',
			billing_summary: (v.billing_summary as string) || '',
			manage_setup_fee: (v.manage_setup_fee as string) || 'no',
			signup_fee_name: (v.signup_fee_name as string) || '',
			signup_fee: v.signup_fee !== undefined ? v.signup_fee : '',
			setup_fee_per_item: (v.setup_fee_per_item as string) || 'no',
		}
	}
	return {
		payment_type: 'onetime',
		times: '',
		repeat_interval: '',
		trial_days: '',
		billing_summary: '',
		manage_setup_fee: 'no',
		signup_fee_name: '',
		signup_fee: '',
		setup_fee_per_item: 'no',
	}
}

export function productPricingTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_product_pricing_get',
			title: 'Get Product Pricing',
			description:
				'Get pricing information for a product. Returned prices are in cents (smallest currency unit).',
			schema: z.object({
				product_id: z.number().describe('Product ID'),
			}),
			endpoint: '/products/:product_id/pricing',
		}),

		createTool(client, {
			name: 'fluentcart_product_pricing_update',
			title: 'Update Product Pricing',
			description:
				'Update product pricing, status, and variants in one call. Use to publish a product ' +
				'or update its variants. Prices in whole currency units (e.g. 400 for 400 PLN, not cents). ' +
				'The API converts to cents internally. Variants require at least title and price. ' +
				'Fetches current product state first, then merges your changes.',
			schema: z.object({
				product_id: z.number().describe('Product ID'),
				post_title: z.string().optional().describe('Product title'),
				post_status: z.string().optional().describe('Product status: draft, publish, future'),
				post_content: z.string().optional().describe('Long description (HTML)'),
				post_excerpt: z.string().optional().describe('Short description'),
				fulfillment_type: z
					.string()
					.optional()
					.describe('Fulfilment: physical or digital (default: physical)'),
				variation_type: z
					.enum(['simple', 'variable', 'subscription'])
					.optional()
					.describe('Product variation type'),
				variants: z
					.array(
						z.object({
							id: z.number().optional().describe('Variant ID (for updating existing variants)'),
							title: z.string().describe('Variant title'),
							price: z.number().describe('Price in currency units (e.g. 400 for 400 PLN)'),
							sku: z.string().optional().describe('SKU'),
							total_stock: z.number().optional().describe('Total stock quantity'),
							payment_type: z
								.enum(['onetime', 'subscription'])
								.optional()
								.describe('Payment type (default: onetime)'),
							compare_price: z
								.number()
								.optional()
								.describe('Compare-at/strike-through price in currency units'),
							repeat_interval: z
								.string()
								.optional()
								.describe(
									'Subscription interval: daily, weekly, monthly, quarterly, half_yearly, yearly',
								),
							times: z
								.number()
								.optional()
								.describe('Number of billing cycles (0 or omit for unlimited)'),
							trial_days: z.number().optional().describe('Trial period in days'),
							billing_summary: z
								.string()
								.optional()
								.describe('Human-readable billing summary text'),
							manage_setup_fee: z
								.enum(['yes', 'no'])
								.optional()
								.describe('Enable setup/signup fee (default: no)'),
							signup_fee_name: z.string().optional().describe('Label for the setup fee'),
							signup_fee: z.number().optional().describe('Setup fee amount in currency units'),
							setup_fee_per_item: z
								.enum(['yes', 'no'])
								.optional()
								.describe('Charge setup fee per item in cart'),
							item_status: z
								.enum(['active', 'inactive'])
								.optional()
								.describe('Variant status (default: active)'),
						}),
					)
					.optional()
					.describe('Product variants to save (creates/updates)'),
			}),
			handler: async (client, input) => {
				const productId = input.product_id as number

				// Fetch current product state — response is { product: {...}, taxonomies: {...} }
				const current = await client.get(`/products/${productId}/pricing`)
				const wrapper = current.data as Record<string, unknown>
				const product = (wrapper.product ?? wrapper) as Record<string, unknown>

				// Merge detail — preserve variation_type and fulfillment_type (both required by API)
				const existingDetail = (product.detail ?? {}) as Record<string, unknown>
				const detail = {
					...existingDetail,
					...(input.fulfillment_type ? { fulfillment_type: input.fulfillment_type } : {}),
					...(input.variation_type ? { variation_type: input.variation_type } : {}),
				}

				// Build variants array
				let variants: Record<string, unknown>[]
				if (input.variants) {
					const inputVariants = input.variants as Array<Record<string, unknown>>
					const ft =
						(input.fulfillment_type as string) ||
						(existingDetail.fulfillment_type as string) ||
						'physical'
					variants = inputVariants.map((v) => ({
						...(v.id ? { id: v.id } : {}),
						post_id: productId,
						variation_title: v.title as string,
						item_price: v.price as number,
						...(v.compare_price !== undefined ? { compare_price: v.compare_price as number } : {}),
						sku: (v.sku as string) || '',
						fulfillment_type: ft,
						stock_status: 'in-stock',
						item_status: (v.item_status as string) || 'active',
						other_info: buildOtherInfo(v),
						...(v.total_stock !== undefined
							? { total_stock: v.total_stock, available: v.total_stock }
							: {}),
					}))
				} else {
					// Preserve existing variants — convert prices from cents (DB storage) back to
					// currency units because the API will multiply by 100 on save
					variants = ((product.variants ?? []) as Record<string, unknown>[]).map((v) => {
						const mapped = {
							...v,
							item_price: typeof v.item_price === 'number' ? v.item_price / 100 : v.item_price,
							compare_price:
								typeof v.compare_price === 'number' ? v.compare_price / 100 : v.compare_price,
						}
						const oi = v.other_info as Record<string, unknown> | undefined
						if (oi && typeof oi.signup_fee === 'number') {
							return { ...mapped, other_info: { ...oi, signup_fee: oi.signup_fee / 100 } }
						}
						return mapped
					})
				}

				const body: Record<string, unknown> = {
					post_title: (input.post_title as string) ?? product.post_title,
					post_status: (input.post_status as string) ?? product.post_status,
					detail,
					variants,
				}
				if (input.post_content !== undefined) body.post_content = input.post_content
				if (input.post_excerpt !== undefined) body.post_excerpt = input.post_excerpt

				const response = await client.post(`/products/${productId}/pricing`, body)
				return response.data
			},
		}),

		getTool(client, {
			name: 'fluentcart_product_pricing_widgets',
			title: 'Get Product Pricing Widgets',
			description: 'Get pricing widgets and display components for a product.',
			schema: z.object({
				product_id: z.number().describe('Product ID'),
			}),
			endpoint: '/products/:product_id/pricing-widgets',
		}),

		getTool(client, {
			name: 'fluentcart_product_related',
			title: 'Get Related Products',
			description: 'Get related products for cross-sell and upsell.',
			schema: z.object({
				product_id: z.number().describe('Product ID'),
			}),
			endpoint: '/products/:product_id/related-products',
		}),

		getTool(client, {
			name: 'fluentcart_product_bundle_info',
			title: 'Get Product Bundle Info',
			description: 'Get bundle information for a bundled product.',
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
