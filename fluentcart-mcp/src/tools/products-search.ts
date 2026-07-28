/**
 * Finding a product, rather than reading or changing one.
 *
 * Split out of products-core.ts, which was over the 280-line ceiling before these tools grew the
 * parameters they had been missing. The seam is the question being asked: everything here answers
 * "which product do you mean", and hands back an id for products-core.ts to load.
 */
import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { createTool, getTool, type ToolDefinition } from './_factory.js'
import { direct } from './endpoints.js'
import { projectProductSearch, searchProductQuery } from './product-search-rows.js'

export function productSearchTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_product_search_by_name',
			title: 'Search Product by Name',
			description:
				'Find products by name, returning id, title, price range, stock and fulfilment type — ' +
				'enough to pick one and then fetch it with fluentcart_product_get. Pass category_id to ' +
				'list a category instead; this is the only way to see which products are filed under a ' +
				'term. Matches PRODUCT TITLES ONLY — a colour or size that exists solely as a variant ' +
				'name finds nothing here, so use fluentcart_product_list for those. Published products ' +
				'only, ten to a page, so read has_more before believing a total. Omitting every ' +
				'argument returns the first page of the catalogue rather than nothing.',
			schema: z.object({
				name: z
					.string()
					.optional()
					.describe('Search term matched against the product title, e.g. "shirt"'),
				category_id: z
					.number()
					.optional()
					.describe(
						'Keep only products in this product-categories term. Term IDs come from ' +
							'fluentcart_product_terms',
					),
				page: z
					.number()
					.optional()
					.describe('Page number (default: 1). The store fixes the page size at ten'),
			}),
			endpoint: '/products/searchProductByName',
			query: searchProductQuery,
			// Rows arrived at 1,064 characters each: three fields for the same image, prices repeated
			// as HTML entity strings, Laravel's laravel_through_key, and timestamps. See
			// product-search-rows.ts for the measurements.
			transform: projectProductSearch,
		}),

		getTool(client, {
			name: 'fluentcart_product_search_variant_by_name',
			title: 'Search Variant by Name',
			description:
				'Every product with its variants beneath it, as an id/title tree. Called with no search ' +
				'term it returns the whole catalogue that way, which is by far the cheapest answer to ' +
				'"how many variants does each product have" — a fifth of what fluentcart_variant_list_all ' +
				'costs. It carries no price, SKU or stock, so use variant_list_all when the numbers matter.',
			schema: z.object({
				search: z.string().optional().describe('Match variants whose product title contains this'),
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

		createTool(client, {
			name: 'fluentcart_product_fetch_by_ids',
			routes: direct('GET', '/products/fetchProductsByIds'),
			title: 'Fetch Products by IDs',
			description: 'Retrieve multiple products by their IDs. Limit to 20 IDs per request.',
			schema: z.object({
				product_ids: z.array(z.number()).describe('Array of product IDs (max 20)'),
			}),
			annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: true },
			handler: async (apiClient, input) => {
				const ids = input.product_ids as number[]
				const response = await apiClient.get('/products/fetchProductsByIds', {
					productIds: ids,
				})
				return response.data
			},
		}),

		getTool(client, {
			name: 'fluentcart_product_find_subscription_variants',
			title: 'Find Subscription Variants',
			description:
				'List the variants billed as subscriptions rather than one-off purchases, store-wide. ' +
				'Returns id and title only — no product, price or billing interval; fetch those with ' +
				'fluentcart_variant_list once you know which product a variant belongs to.',
			schema: z.object({
				// `search` was advertised here and read by nothing: the controller takes `name`
				// (ProductController.php:1188) and ignores every other key, so the filter looked
				// applied and was not. Verified live — `search=Yearly` returned all ten subscription
				// variants, `name=Yearly` returned the three that match.
				name: z
					.string()
					.optional()
					.describe('Keep only variants whose title contains this, e.g. "Yearly"'),
			}),
			endpoint: '/products/findSubscriptionVariants',
		}),
	]
}
