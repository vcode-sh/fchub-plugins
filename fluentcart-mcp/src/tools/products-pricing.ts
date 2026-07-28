import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { createTool, getTool, type ToolDefinition } from './_factory.js'
import { direct } from './endpoints.js'

export function productPricingTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_product_pricing_get',
			title: 'Get Product Pricing',
			description:
				'The complete product record: pricing, every variant, downloadable files, media and taxonomy. This is the fullest view of one product and the largest — reach for fluentcart_product_get when you only need the product and its variants. Prices are in cents.',
			schema: z.object({
				product_id: z.number().describe('Product ID'),
			}),
			endpoint: '/products/:product_id/pricing',
		}),

		getTool(client, {
			name: 'fluentcart_product_pricing_widgets',
			title: 'Get Product Pricing Widgets',
			description:
				'Sales totals for one product over three fixed windows — all time, last 30 days and this month — with units and revenue. Despite the name this is a per-product sales report, not UI chrome. It returns PRE-RENDERED HTML, not JSON figures, so read the numbers out of the markup or use fluentcart_report_top_products for machine-readable totals.',
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

		createTool(client, {
			name: 'fluentcart_product_bundle_info',
			routes: direct('GET', '/products/get-bundle-info/{param}'),
			title: 'Get Product Bundle Info',
			description:
				'Get bundle information for a bundled product. ' +
				'If the bundle module/route is unavailable, returns a compact capability response instead of a hard failure.',
			schema: z.object({
				product_id: z.number().describe('Product ID'),
			}),
			handler: async (c, input) => {
				const productId = input.product_id as number
				try {
					// Route is /get-bundle-info/{id}, not /{id}/get-bundle-info — the sibling
					// save-bundle-info route has the same shape. The transposed form 404s.
					const response = await c.get(`/products/get-bundle-info/${productId}`)
					return response.data
				} catch (error) {
					const message = error instanceof Error ? error.message : String(error)
					const routeMissing =
						message.includes('No route was found matching the URL and request method') ||
						message.includes('rest_no_route')
					if (!routeMissing) throw error
					return {
						supported: false,
						reason: 'module_not_enabled',
						message:
							'Bundle info endpoint is not available on this store/runtime. Enable bundle module or compatible addon.',
					}
				}
			},
		}),
	]
}
