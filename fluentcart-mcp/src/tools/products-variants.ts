import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { createTool, getTool, type ToolDefinition } from './_factory.js'
import { composite, direct, op } from './endpoints.js'

function trimVariant(v: Record<string, unknown>) {
	const otherInfo = v.other_info as Record<string, unknown> | undefined
	return {
		id: v.id,
		post_id: v.post_id,
		variation_title: v.variation_title,
		item_price: v.item_price,
		compare_price: v.compare_price,
		sku: v.sku,
		stock_status: v.stock_status,
		item_status: v.item_status,
		total_stock: v.total_stock,
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
		getTool(client, {
			name: 'fluentcart_variant_list_all',
			title: 'List All Variants',
			description: 'List all product variants across all products with pagination.',
			schema: z.object({
				page: z.number().optional().describe('Page number (default: 1)'),
				per_page: z.number().max(50).optional().describe('Results per page (max: 50)'),
			}),
			endpoint: '/variants',
			transform: (data: unknown) => {
				if (!Array.isArray(data)) return data
				return data.map((v: Record<string, unknown>) => trimVariant(v))
			},
		}),

		createTool(client, {
			name: 'fluentcart_variant_list',
			routes: composite(op('GET', '/products/variants'), op('GET', '/products/{param}')),
			title: 'List Variations',
			description:
				'List product variations for a specific product. ' +
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

					const fallback = await client.get(`/products/${productId}`)
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
