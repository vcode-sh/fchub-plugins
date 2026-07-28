import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { getTool, type ToolDefinition } from './_factory.js'

function record(value: unknown): Record<string, unknown> {
	return value !== null && typeof value === 'object' && !Array.isArray(value)
		? (value as Record<string, unknown>)
		: {}
}

function projectTerm(value: unknown): Record<string, unknown> {
	const term = record(value)
	return {
		term_id: term.term_id ?? null,
		name: term.name ?? null,
		slug: term.slug ?? null,
		parent: term.parent ?? null,
	}
}

function projectVariant(value: unknown): Record<string, unknown> {
	const variant = record(value)
	return {
		id: variant.id ?? null,
		variation_title: variant.variation_title ?? null,
		sku: variant.sku ?? null,
		item_price: variant.item_price ?? null,
		compare_price: variant.compare_price ?? null,
		payment_type: variant.payment_type ?? null,
		manage_stock: variant.manage_stock ?? null,
		total_stock: variant.total_stock ?? null,
		available: variant.available ?? null,
		stock_status: variant.stock_status ?? null,
		serial_index: variant.serial_index ?? null,
		fulfillment_type: variant.fulfillment_type ?? null,
	}
}

function projectProduct(value: unknown): Record<string, unknown> {
	const product = record(value)
	const detail = record(product.detail)
	return {
		id: product.ID ?? null,
		title: product.post_title ?? null,
		excerpt: product.post_excerpt ?? null,
		status: product.post_status ?? null,
		detail: {
			variation_type: detail.variation_type ?? null,
			fulfillment_type: detail.fulfillment_type ?? null,
			manage_stock: detail.manage_stock ?? null,
		},
		variants: Array.isArray(product.variants) ? product.variants.map(projectVariant) : [],
		category_terms: Array.isArray(product.category_terms)
			? product.category_terms.map(projectTerm)
			: [],
		categories: Array.isArray(product.categories)
			? product.categories.filter((category): category is string => typeof category === 'string')
			: [],
	}
}

export function productBulkEditTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_product_bulk_edit_data',
			title: 'Get Product Bulk Edit Data',
			description:
				'Read the compact product and variant rows FluentCart uses for bulk editing, including ' +
				'decimal prices, stock and category assignments. Editor HTML, media URLs and internal ' +
				'variant metadata are deliberately omitted from the response.',
			schema: z.object({
				page: z.number().int().min(1).optional().describe('Result page, starting at 1'),
				per_page: z
					.number()
					.int()
					.min(1)
					.max(50)
					.optional()
					.describe('Products per page, from 1 to 50'),
				search: z.string().optional().describe('Search product titles and variant SKUs'),
				active_view: z.string().optional().describe('FluentCart product view filter'),
				sort_by: z.string().optional().describe('FluentCart product sort field'),
				sort_type: z.enum(['asc', 'desc']).optional().describe('Sort direction'),
			}),
			endpoint: '/products/bulk-edit-data',
			transform: (data) => {
				const body = record(data)
				return {
					products: Array.isArray(body.products) ? body.products.map(projectProduct) : [],
					total: body.total ?? 0,
					per_page: body.per_page ?? null,
					page: body.page ?? null,
				}
			},
		}),
	]
}
