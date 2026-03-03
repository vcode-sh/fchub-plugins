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

const DEFAULT_OTHER_INFO = {
	payment_type: 'onetime',
	times: '',
	repeat_interval: '',
	trial_days: '',
	billing_summary: '',
	manage_setup_fee: 'no',
	signup_fee_name: '',
	signup_fee: '',
	setup_fee_per_item: 'no',
} as const

function buildVariantFromExisting(
	existing: Record<string, unknown> | undefined,
	productId: number,
	variantId: number,
): Record<string, unknown> {
	const existingPrice = typeof existing?.item_price === 'number' ? existing.item_price / 100 : 0
	const existingComparePrice =
		typeof existing?.compare_price === 'number' ? existing.compare_price / 100 : 0
	const existingOtherInfo = (existing?.other_info ?? DEFAULT_OTHER_INFO) as Record<string, unknown>

	// Convert signup_fee from cents if stored as number
	if (typeof existingOtherInfo.signup_fee === 'number') {
		existingOtherInfo.signup_fee = existingOtherInfo.signup_fee / 100
	}

	return {
		id: variantId,
		post_id: productId,
		variation_title: existing?.variation_title ?? '',
		item_price: existingPrice,
		compare_price: existingComparePrice,
		sku: existing?.sku ?? '',
		fulfillment_type: existing?.fulfillment_type ?? 'physical',
		stock_status: existing?.stock_status ?? 'in-stock',
		item_status: existing?.item_status ?? 'active',
		total_stock: existing?.total_stock ?? 0,
		available: existing?.available ?? 0,
		committed: existing?.committed ?? 0,
		on_hold: existing?.on_hold ?? 0,
		other_info: existingOtherInfo,
	}
}

const subscriptionSchema = {
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
		.describe('Subscription interval: daily, weekly, monthly, quarterly, half_yearly, yearly'),
	times: z.number().optional().describe('Number of billing cycles (0 or omit for unlimited)'),
	trial_days: z.number().optional().describe('Trial period in days'),
	billing_summary: z.string().optional().describe('Human-readable billing summary'),
	manage_setup_fee: z.enum(['yes', 'no']).optional().describe('Enable setup fee (default: no)'),
	signup_fee_name: z.string().optional().describe('Label for setup fee'),
	signup_fee: z.number().optional().describe('Setup fee in currency units'),
	setup_fee_per_item: z.enum(['yes', 'no']).optional().describe('Charge per item'),
	item_status: z
		.enum(['active', 'inactive'])
		.optional()
		.describe('Variant status (default: active)'),
}

function buildOtherInfo(input: Record<string, unknown>): Record<string, unknown> {
	const paymentType = (input.payment_type as string) || 'onetime'
	if (paymentType === 'subscription') {
		return {
			payment_type: 'subscription',
			times: String((input.times as number) ?? ''),
			repeat_interval: (input.repeat_interval as string) || 'monthly',
			trial_days: String((input.trial_days as number) ?? ''),
			billing_summary: (input.billing_summary as string) || '',
			manage_setup_fee: (input.manage_setup_fee as string) || 'no',
			signup_fee_name: (input.signup_fee_name as string) || '',
			signup_fee: (input.signup_fee as number) ?? '',
			setup_fee_per_item: (input.setup_fee_per_item as string) || 'no',
		}
	}
	return { payment_type: 'onetime' }
}

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

		createTool(client, {
			name: 'fluentcart_variant_list',
			title: 'List Variations',
			description: 'List product variations with optional product filtering.',
			schema: z.object({
				product_id: z.number().optional().describe('Filter by product ID'),
				page: z.number().optional().describe('Page number (default: 1)'),
				per_page: z.number().max(50).optional().describe('Results per page (max: 50)'),
			}),
			annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: true },
			handler: async (client, input) => {
				const params: Record<string, unknown> = {
					page: (input.page as number) ?? 1,
					per_page: (input.per_page as number) ?? 15,
				}
				if (input.product_id) params.product_id = input.product_id
				const response = await client.get('/products/variants', params)
				return response.data
			},
		}),

		createTool(client, {
			name: 'fluentcart_variant_create',
			title: 'Create Variation',
			description:
				'Create a new product variation. Price in whole currency units (e.g. 400 for 400 PLN, not cents). ' +
				'Stock is set directly via total_stock (no separate inventory call needed). ' +
				'Set payment_type to "subscription" for recurring billing variants.',
			schema: z.object({
				product_id: z.number().describe('Parent product ID'),
				title: z.string().optional().describe('Variation title (e.g. "Tiger Pants - White")'),
				price: z.number().optional().describe('Price in currency units (e.g. 10 for 10.00)'),
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
						sku: (input.sku as string) || '',
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
			title: 'Update Variation',
			description:
				'Update an existing product variation. Only provided fields are changed. ' +
				'Price in whole currency units (e.g. 400 for 400 PLN, not cents). ' +
				'Fetches current variant state first, then merges your changes.',
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
				if (input.sku !== undefined) overrides.sku = input.sku
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

		createTool(client, {
			name: 'fluentcart_variant_fetch_by_ids',
			title: 'Fetch Variations by IDs',
			description: 'Retrieve multiple variations by their IDs. Limit to 20 per request.',
			schema: z.object({
				variation_ids: z.array(z.number()).describe('Array of variation IDs (max 20)'),
			}),
			annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: true },
			handler: async (client, input) => {
				const ids = input.variation_ids as number[]
				const response = await client.get('/products/fetchVariationsByIds', {
					productIds: ids,
				})
				return response.data
			},
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
