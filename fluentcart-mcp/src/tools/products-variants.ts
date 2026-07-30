import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { createTool, getTool, type ToolDefinition } from './_factory.js'
import { direct } from './endpoints.js'
import {
	describeStockFilter,
	matchesSku,
	matchesStock,
	type SkuFilter,
	type StockFilter,
	trimVariant,
} from './variant-projection.js'

export function productVariantTools(client: FluentCartClient): ToolDefinition[] {
	return [
		createTool(client, {
			name: 'fluentcart_variant_list_all',
			routes: direct('GET', '/variants'),
			title: 'List All Variants',
			description:
				'Store-wide inventory: every product variant with its SKU, price and stock level. This is ' +
				'the tool for questions about stock — what is running low, what has sold out, what is on ' +
				'the shelf — because FluentCart offers no per-variant stock search. Each row states ' +
				'stock_status and manage_stock; a variant that tracks stock also reports total_stock and ' +
				'available — how many units are left — and available is the number that answers "can I ' +
				'still sell this". ' +
				'Use the stock filter rather than paging through the catalogue to find low or sold-out ' +
				'items. FluentCart serves this route whole — it has no paging of its own — so the filter ' +
				'and paging are applied by this server after fetching, and a large catalogue still costs a ' +
				'full transfer upstream. For one product use fluentcart_variant_list instead.',
			schema: z.object({
				sku: z
					.enum(['present', 'missing'])
					.optional()
					.describe(
						'Keep only variants that have a SKU, or only those that do not. With per_page 1 the ' +
							'total alone answers "how many are missing a SKU" without reading the catalogue',
					),
				stock: z
					.enum(['low', 'out', 'tracked', 'untracked'])
					.optional()
					.describe(
						'Keep only: low = tracking stock with fewer than low_below units available; out = ' +
							'tracking stock with none available; tracked / untracked = whether the variant counts ' +
							'units at all. Untracked variants have no stock level, so low and out cannot match them',
					),
				low_below: z
					.number()
					.min(1)
					.optional()
					.describe('What "low" means, in units available (default: 5). Only used with stock=low'),
				page: z.number().optional().describe('Page number (default: 1)'),
				per_page: z
					.number()
					.max(50)
					.optional()
					.describe('Results per page (default: 50, max: 50). Applied here, not by the store'),
			}),
			annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: true },
			// Paged here because the endpoint ignores paging. Proven against four spellings: `{}`,
			// `{per_page:5}`, `{per_page:5,page:1}` and `{limit:5}` all returned the identical
			// 63,018-character body. The tool advertised `page`/`per_page` and neither did anything, so
			// past roughly 173 variants the response crossed the 40,000-character cap and the error told
			// the caller to "retry with a smaller page size" — advice that could not work for this tool.
			// This store has 76, so it was one moderate catalogue away from being permanently unusable.
			handler: async (apiClient, input) => {
				const page = Math.max(1, (input.page as number) ?? 1)
				const perPage = Math.min(50, Math.max(1, (input.per_page as number) ?? 50))
				const stock = input.stock as StockFilter | undefined
				const sku = input.sku as SkuFilter | undefined
				const lowBelow = Math.max(1, (input.low_below as number) ?? 5)

				const response = await apiClient.get('/variants')
				const rows = Array.isArray(response.data)
					? (response.data as Record<string, unknown>[])
					: []

				// Free, and it saves the caller the only alternative: paging the whole catalogue and
				// filtering by hand. The endpoint has already transferred every row by this point.
				const matching = rows
					.filter((row) => (stock ? matchesStock(row, stock, lowBelow) : true))
					.filter((row) => (sku ? matchesSku(row, sku) : true))

				const from = (page - 1) * perPage
				const variants = matching.slice(from, from + perPage).map(trimVariant)

				return {
					variants,
					page,
					per_page: perPage,
					// The count paging runs over. When a filter is set that is the number of matches, so
					// the catalogue size is reported beside it rather than silently replaced.
					total: matching.length,
					has_more: from + variants.length < matching.length,
					...(stock || sku
						? {
								filter: [
									stock ? describeStockFilter(stock, lowBelow) : null,
									sku ? `variants with ${sku === 'present' ? 'a' : 'no'} SKU` : null,
								]
									.filter(Boolean)
									.join(', '),
								total_in_store: rows.length,
							}
						: {}),
					// Said plainly, because a caller that believes the store paged would draw the wrong
					// conclusion about cost from a small page.
					paging: 'applied by fluentcart-mcp; the /variants endpoint returns the whole catalogue',
				}
			},
		}),

		createTool(client, {
			name: 'fluentcart_variant_list',
			routes: direct('GET', '/products/{param}'),
			title: 'List Variations',
			description:
				"List one product's variations with their prices, SKUs and stock levels. " +
				'For stock across the whole store — what is low, what is out — use ' +
				'fluentcart_variant_list_all, which filters on stock. ' +
				'Reads the product detail relation because FluentCart `/products/variants` does not ' +
				'filter by product_id.',
			schema: z.object({
				product_id: z.number().describe('Parent product ID'),
				page: z.number().optional().describe('Page number (default: 1)'),
				per_page: z.number().max(50).optional().describe('Results per page (max: 50)'),
			}),
			annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: true },
			handler: async (client, input) => {
				const productId = input.product_id as number
				const page = (input.page as number) ?? 1
				const perPage = (input.per_page as number) ?? 15
				const trimVariants = (arr: unknown[]) =>
					arr.map((v) => trimVariant(v as Record<string, unknown>))

				// Relations are opt-in: ProductController::find eager-loads only what `with` names.
				// FluentCart 1.5 threw when /products/variants received no nested params; 1.6 fixed
				// the throw but still ignores product_id and returns an empty list. Product detail is
				// the stable route that actually binds the variants to this product in both versions.
				const response = await client.get(`/products/${productId}`, { 'with[]': 'variants' })
				const wrapper = response.data as Record<string, unknown>
				const product = (wrapper.product ?? wrapper) as Record<string, unknown>
				const variantsRaw = Array.isArray(product.variants) ? (product.variants as unknown[]) : []
				const from = Math.max(0, (page - 1) * perPage)
				const to = from + perPage
				const variants = trimVariants(variantsRaw.slice(from, to))

				return {
					variants,
					page,
					per_page: perPage,
					total: variantsRaw.length,
				}
			},
		}),

		createTool(client, {
			name: 'fluentcart_variant_fetch_by_ids',
			routes: direct('GET', '/products/fetchVariationsByIds'),
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
