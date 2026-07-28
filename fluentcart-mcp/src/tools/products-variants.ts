import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { createTool, getTool, type ToolDefinition } from './_factory.js'
import { composite, direct, op } from './endpoints.js'

/** Whether this variant counts units. FluentCart stores the flag as the string "0" or "1". */
function tracksStock(v: Record<string, unknown>): boolean {
	return v.manage_stock === 1 || v.manage_stock === '1' || v.manage_stock === true
}

/**
 * A variant's stock, reported so that the numbers cannot be read the wrong way.
 *
 * FluentCart's stock columns only mean anything when `manage_stock` is on. For a variant that does
 * not track units the counters simply sit at their initial zero, and `stock_status` is the whole
 * truth. This projection used to return `total_stock` and drop `manage_stock`, which manufactured a
 * contradiction that does not exist in the store: 27 of this store's 76 variants came back as
 * `stock_status: in-stock` beside `total_stock: 0`, and nothing in the payload said the zero was
 * inert. Both readings an agent can take from that — "in stock" and "none left" — are a coin toss,
 * and one of them is wrong.
 *
 * `manage_stock` is also normalised to a boolean rather than passed through. The string "0" is
 * truthy, so `if (variant.manage_stock)` — the obvious line to write in code mode — reads every
 * untracked variant as tracked.
 *
 * `available` is included because it, not `total_stock`, is what checkout decrements and therefore
 * what "can I still sell this" means; `fluentcart_product_manage_stock_update` has said so on the
 * write side all along. `committed` and `on_hold` appear only when non-zero, since on a healthy
 * catalogue they are zero on every row and would cost tokens to say nothing.
 */
function stockFacts(v: Record<string, unknown>): Record<string, unknown> {
	if (!tracksStock(v)) return { stock_status: v.stock_status, manage_stock: false }

	return {
		stock_status: v.stock_status,
		manage_stock: true,
		total_stock: v.total_stock,
		available: v.available,
		...(Number(v.committed) ? { committed: v.committed } : {}),
		...(Number(v.on_hold) ? { on_hold: v.on_hold } : {}),
	}
}

type StockFilter = 'low' | 'out' | 'tracked' | 'untracked'

/**
 * Apply a stock filter to a raw variant row.
 *
 * `low` and `out` deliberately exclude untracked variants rather than treating their zero as an
 * empty shelf. A digital subscription that counts nothing is not sold out, and returning it under
 * "what have I run out of" would be the same false reading in a different place.
 */
function matchesStock(v: Record<string, unknown>, filter: StockFilter, lowBelow: number): boolean {
	const tracked = tracksStock(v)
	if (filter === 'tracked') return tracked
	if (filter === 'untracked') return !tracked
	if (!tracked) return false

	const available = Number(v.available ?? 0)
	if (filter === 'out') return available <= 0
	return available > 0 && available < lowBelow
}

function describeStockFilter(filter: StockFilter, lowBelow: number): string {
	if (filter === 'low') return `tracked variants with fewer than ${lowBelow} available`
	if (filter === 'out') return 'tracked variants with none available'
	if (filter === 'tracked') return 'variants that count units'
	return 'variants that do not count units, and so have no stock level'
}

function trimVariant(v: Record<string, unknown>) {
	const otherInfo = v.other_info as Record<string, unknown> | undefined
	return {
		id: v.id,
		post_id: v.post_id,
		variation_title: v.variation_title,
		item_price: v.item_price,
		compare_price: v.compare_price,
		sku: v.sku,
		...stockFacts(v),
		item_status: v.item_status,
		fulfillment_type: v.fulfillment_type,
		payment_type: v.payment_type,
		...(otherInfo?.payment_type === 'subscription'
			? {
					repeat_interval: otherInfo.repeat_interval,
					times: otherInfo.times,
					trial_days: otherInfo.trial_days,
				}
			: {}),
	}
}

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
				const lowBelow = Math.max(1, (input.low_below as number) ?? 5)

				const response = await apiClient.get('/variants')
				const rows = Array.isArray(response.data)
					? (response.data as Record<string, unknown>[])
					: []

				// Free, and it saves the caller the only alternative: paging the whole catalogue and
				// filtering by hand. The endpoint has already transferred every row by this point.
				const matching = stock ? rows.filter((row) => matchesStock(row, stock, lowBelow)) : rows

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
					...(stock
						? { filter: describeStockFilter(stock, lowBelow), total_in_store: rows.length }
						: {}),
					// Said plainly, because a caller that believes the store paged would draw the wrong
					// conclusion about cost from a small page.
					paging: 'applied by fluentcart-mcp; the /variants endpoint returns the whole catalogue',
				}
			},
		}),

		createTool(client, {
			name: 'fluentcart_variant_list',
			routes: composite(op('GET', '/products/variants'), op('GET', '/products/{param}')),
			title: 'List Variations',
			description:
				"List one product's variations with their prices, SKUs and stock levels. " +
				'For stock across the whole store — what is low, what is out — use ' +
				'fluentcart_variant_list_all, which filters on stock. ' +
				'`product_id` is required because the backend route is unstable without it. ' +
				'If upstream `/products/variants` fails with a known runtime bug, this tool falls back to `/products/:id` and returns variants from product detail.',
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
				const params: Record<string, unknown> = {
					product_id: productId,
					page,
					per_page: perPage,
				}

				const trimVariants = (arr: unknown[]) =>
					arr.map((v) => trimVariant(v as Record<string, unknown>))

				try {
					const response = await client.get('/products/variants', params)
					const resp = response.data as Record<string, unknown>
					if (resp && Array.isArray(resp.variants)) {
						resp.variants = trimVariants(resp.variants)
					}
					return resp
				} catch (error) {
					const message = error instanceof Error ? error.message : String(error)
					const isKnownUpstreamBug =
						message.includes('ProductVariationResource::get()') && message.includes('null given')
					if (!isKnownUpstreamBug) throw error

					// Relations are opt-in: ProductController::find eager-loads only what `with` names,
					// so the previous fallback — a bare GET /products/{id} — read a payload that never
					// contains variants and returned `total: 0` for a product that has them, reporting
					// success. Verified live against a product with one variant.
					const fallback = await client.get(`/products/${productId}`, { 'with[]': 'variants' })
					const wrapper = fallback.data as Record<string, unknown>
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
						source: 'fallback_product_get',
						note: 'Upstream /products/variants route failed; served from product detail variants.',
					}
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
