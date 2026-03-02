import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { deleteTool, getTool, postTool, type ToolDefinition } from './_factory.js'

export function productCoreTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_product_list',
			title: 'List Products',
			description:
				'Retrieve a paginated list of products with optional filtering. ' +
				'Returns product summaries with title, status, type, and price. ' +
				'Monetary values in smallest currency unit (cents). ' +
				'Types: simple, variable, subscription. ' +
				'Views: all, published, draft, trashed.',
			schema: z.object({
				page: z.number().optional().describe('Page number (default: 1)'),
				per_page: z.number().optional().describe('Results per page (default: 10)'),
				filter_type: z
					.string()
					.optional()
					.describe('Filter by type: simple, variable, subscription'),
				sort_by: z.string().optional().describe('Sort field (default: ID)'),
				sort_type: z.string().optional().describe('Sort direction: ASC, DESC (default: DESC)'),
				search: z.string().optional().describe('Search products by name'),
				active_view: z
					.string()
					.optional()
					.describe('Filter by status: all, published, draft, trashed'),
			}),
			endpoint: '/products',
		}),

		postTool(client, {
			name: 'fluentcart_product_create',
			title: 'Create Product',
			description:
				'Create a new product. Defaults to draft status. ' +
				'Set fulfillment_type in detail object: digital or physical.',
			schema: z.object({
				post_title: z.string().describe('Product title (required)'),
				post_status: z.string().optional().describe('Status: publish, draft (default: draft)'),
				post_content: z.string().optional().describe('Long description (HTML)'),
				post_excerpt: z.string().optional().describe('Short description'),
				fulfillment_type: z.string().optional().describe('Fulfillment type: digital, physical'),
			}),
			endpoint: '/products',
		}),

		getTool(client, {
			name: 'fluentcart_product_get',
			title: 'Get Product',
			description:
				'Retrieve detailed product information including pricing, media, and detail metadata. ' +
				'Monetary values in smallest currency unit (cents).',
			schema: z.object({
				product_id: z.number().describe('Product ID (WordPress post ID)'),
			}),
			endpoint: '/products/:product_id',
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
			description: 'Search for products by name. Returns matching products.',
			schema: z.object({
				search: z.string().optional().describe('Search term'),
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
			description: 'Generate a suggested SKU for a new product.',
			schema: z.object({}),
			endpoint: '/products/suggest-sku',
		}),

		getTool(client, {
			name: 'fluentcart_product_fetch_by_ids',
			title: 'Fetch Products by IDs',
			description: 'Retrieve multiple products by their IDs in a single request.',
			schema: z.object({
				product_ids: z.string().describe('Comma-separated product IDs'),
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
	]
}
