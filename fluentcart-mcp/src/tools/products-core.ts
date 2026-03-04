import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { createTool, deleteTool, getTool, postTool, type ToolDefinition } from './_factory.js'

export function productCoreTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_product_list',
			title: 'List Products',
			description:
				'List products with optional filtering by type, status, fulfilment, and search. ' +
				'Use active_view to filter by product type or status.',
			schema: z.object({
				page: z.number().optional().describe('Page number (default: 1)'),
				per_page: z.number().max(50).optional().describe('Results per page (default: 10, max: 50)'),
				sort_by: z.string().optional().describe('Sort field (default: ID)'),
				sort_type: z.string().optional().describe('Sort direction: ASC, DESC (default: DESC)'),
				search: z.string().optional().describe('Search products by name'),
				active_view: z
					.string()
					.optional()
					.describe(
						'Filter view: publish, draft, subscribable, not_subscribable, ' +
							'physical, digital, bundle, non_bundle',
					),
			}),
			endpoint: '/products',
			transform: (data: unknown) => {
				const resp = data as Record<string, unknown>
				const wrapper = (resp?.products ?? resp) as Record<string, unknown>
				if (wrapper && Array.isArray(wrapper.data)) {
					wrapper.data = (wrapper.data as Record<string, unknown>[]).map((item) => ({
						ID: item.ID,
						post_title: item.post_title,
						post_status: item.post_status,
						post_name: item.post_name,
						post_date: item.post_date,
					}))
				}
				return resp
			},
		}),

		createTool(client, {
			name: 'fluentcart_product_create',
			title: 'Create Product',
			description: 'Create a product (defaults to draft). Fulfillment type: digital or physical.',
			schema: z.object({
				post_title: z.string().describe('Product title (required)'),
				post_status: z.string().optional().describe('Status: publish, draft (default: draft)'),
				post_content: z.string().optional().describe('Long description (HTML)'),
				post_excerpt: z.string().optional().describe('Short description'),
				fulfillment_type: z
					.string()
					.optional()
					.describe('Fulfillment type: digital, physical (default: physical)'),
			}),
			handler: async (client, input) => {
				const body: Record<string, unknown> = {
					post_title: input.post_title,
					detail: {
						fulfillment_type: (input.fulfillment_type as string) || 'physical',
					},
				}
				if (input.post_status !== undefined) body.post_status = input.post_status
				if (input.post_content !== undefined) body.post_content = input.post_content
				if (input.post_excerpt !== undefined) body.post_excerpt = input.post_excerpt
				const response = await client.post('/products', body)
				return response.data
			},
		}),

		getTool(client, {
			name: 'fluentcart_product_get',
			title: 'Get Product',
			description: 'Get full product details including pricing, media, and metadata.',
			schema: z.object({
				product_id: z.number().describe('Product ID (WordPress post ID)'),
			}),
			endpoint: '/products/:product_id',
			transform: (data: unknown) => {
				const resp = data as Record<string, unknown>
				const product = (resp?.product ?? resp) as Record<string, unknown>
				const { integrations, ...rest } = product
				if (Array.isArray(rest.variants)) {
					rest.variants = (rest.variants as Record<string, unknown>[]).map((v) => {
						const { pricing_table, ...vRest } = v
						return vRest
					})
				}
				return resp?.product ? { ...resp, product: rest } : rest
			},
		}),

		deleteTool(client, {
			name: 'fluentcart_product_delete',
			title: 'Delete Product',
			description: 'Delete a product. This action cannot be undone.',
			schema: z.object({
				product_id: z.number().describe('Product ID'),
			}),
			endpoint: '/products/:product_id',
		}),

		postTool(client, {
			name: 'fluentcart_product_duplicate',
			title: 'Duplicate Product',
			description: 'Create a duplicate copy of an existing product.',
			schema: z.object({
				product_id: z.number().describe('Product ID to duplicate'),
			}),
			endpoint: '/products/:product_id/duplicate',
		}),

		postTool(client, {
			name: 'fluentcart_product_bulk_action',
			title: 'Bulk Product Actions',
			description: 'Perform bulk actions on multiple products.',
			schema: z.object({
				action: z.string().describe('Bulk action to perform'),
				product_ids: z.array(z.number()).describe('Array of product IDs'),
			}),
			endpoint: '/products/do-bulk-action',
		}),

		postTool(client, {
			name: 'fluentcart_product_update_detail',
			title: 'Update Product Detail',
			description: 'Update a product detail record (fulfillment type, stock settings, etc.).',
			schema: z.object({
				detail_id: z.number().describe('Product detail ID'),
				fulfillment_type: z.string().optional().describe('Fulfillment: digital, physical'),
				manage_stock: z.string().optional().describe('Enable stock management: 0 or 1'),
				sold_individually: z.string().optional().describe('Sell individually: 0 or 1'),
			}),
			endpoint: '/products/detail/:detail_id',
		}),

		getTool(client, {
			name: 'fluentcart_product_search_by_name',
			title: 'Search Product by Name',
			description: 'Search for products by name.',
			schema: z.object({
				name: z.string().optional().describe('Search term'),
			}),
			endpoint: '/products/searchProductByName',
		}),

		getTool(client, {
			name: 'fluentcart_product_search_variant_by_name',
			title: 'Search Variant by Name',
			description: 'Search for product variants by name.',
			schema: z.object({
				search: z.string().optional().describe('Search term'),
			}),
			endpoint: '/products/searchVariantByName',
		}),

		getTool(client, {
			name: 'fluentcart_product_search_variant_options',
			title: 'Search Product Variant Options',
			description: 'Search for product variant options (attribute combinations).',
			schema: z.object({
				search: z.string().optional().describe('Search term'),
			}),
			endpoint: '/products/search-product-variant-options',
		}),

		getTool(client, {
			name: 'fluentcart_product_suggest_sku',
			title: 'Suggest SKU',
			description: 'Generate a suggested SKU based on a product title.',
			schema: z.object({
				title: z.string().describe('Product title to generate SKU from'),
			}),
			endpoint: '/products/suggest-sku',
		}),

		getTool(client, {
			name: 'fluentcart_product_fetch_by_ids',
			title: 'Fetch Products by IDs',
			description: 'Retrieve multiple products by their IDs. Limit to 20 IDs per request.',
			schema: z.object({
				product_ids: z.string().describe('Comma-separated product IDs (max 20)'),
			}),
			endpoint: '/products/fetchProductsByIds',
		}),

		getTool(client, {
			name: 'fluentcart_product_find_subscription_variants',
			title: 'Find Subscription Variants',
			description: 'Find product variants that support subscription billing.',
			schema: z.object({
				search: z.string().optional().describe('Search term'),
			}),
			endpoint: '/products/findSubscriptionVariants',
		}),

		postTool(client, {
			name: 'fluentcart_product_variant_option_update',
			title: 'Update Variant Option Configuration',
			description:
				'Update variant attribute options for an advanced_variations product. ' +
				'Generates a cartesian product of variant combinations from attribute term IDs. ' +
				'NOTE: Only works with variation_type "advanced_variations" — simple and ' +
				'simple_variations products will return "Illegal data provided".',
			schema: z.object({
				product_id: z.number().describe('Product ID'),
				variation_type: z
					.string()
					.describe('Must be "advanced_variations" for this endpoint to work'),
				options: z
					.array(
						z.object({
							id: z.number().describe('Attribute group ID'),
							variants: z.array(z.number()).describe('Attribute term IDs'),
						}),
					)
					.describe('Attribute groups and their term IDs to generate variant combinations'),
			}),
			endpoint: '/products/:product_id/update-variant-option',
		}),

		postTool(client, {
			name: 'fluentcart_product_editor_mode_update',
			title: 'Update Product Editor Mode',
			description: 'Switch the long description editor mode for a product.',
			schema: z.object({
				product_id: z.number().describe('Product ID'),
				editor_mode: z.string().optional().describe('Editor mode: wp-editor or block-editor'),
			}),
			endpoint: '/products/:product_id/update-long-desc-editor-mode',
		}),

		postTool(client, {
			name: 'fluentcart_product_create_dummy',
			title: 'Create Dummy Products',
			description: 'Create dummy/test products for development and testing. Category is required by the backend.',
			schema: z.object({
				count: z.number().optional().describe('Number of dummy products to create'),
				category: z.string().describe('Product category name (required by backend)'),
			}),
			endpoint: '/products/create-dummy',
		}),
	]
}
