import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { getTool, postTool, type ToolDefinition } from './_factory.js'

/** WordPress post columns that say nothing about a product. */
const WP_PLUMBING = [
	'post_date_gmt',
	'post_modified_gmt',
	'post_name',
	'guid',
	'comment_status',
	'ping_status',
	'post_password',
	'to_ping',
	'pinged',
	'post_content_filtered',
	'post_parent',
	'menu_order',
	'post_mime_type',
	'comment_count',
	'filter',
] as const

/**
 * Drop the WordPress plumbing from storefront product rows, wherever the envelope put them.
 *
 * `GET /public/products` answers `{products: {products: {current_page, data: [...]}}}` — three
 * levels deep — so this walks rather than assuming, the same lesson the order routes taught.
 */
function stripWordPressPlumbing(data: unknown, depth = 0): unknown {
	if (Array.isArray(data)) return data.map((row) => stripWordPressPlumbing(row, depth + 1))
	if (data === null || typeof data !== 'object' || depth > 4) return data

	const row = data as Record<string, unknown>
	const output: Record<string, unknown> = {}
	let changed = false

	for (const [key, value] of Object.entries(row)) {
		if ((WP_PLUMBING as readonly string[]).includes(key)) {
			changed = true
			continue
		}

		// Variants were 77% of every catalogue row — 3,714 of 4,848 characters — because the listing
		// embeds each variant's whole record. Browsing a catalogue needs to know what the options are
		// and what they cost; the rest of the variant belongs to fluentcart_product_get.
		if (key === 'variants' && Array.isArray(value)) {
			changed = true
			output[key] = value.map((variant) => {
				if (variant === null || typeof variant !== 'object') return variant
				const entry = variant as Record<string, unknown>
				return {
					id: entry.id,
					variation_title: entry.variation_title,
					item_price: entry.item_price,
					stock_status: entry.stock_status,
				}
			})
			continue
		}
		const projected = stripWordPressPlumbing(value, depth + 1)
		if (projected !== value) changed = true
		output[key] = projected
	}

	return changed ? output : data
}

export function publicTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_public_product_views',
			title: 'Get Public Product Views',
			description: 'Get product view data for the storefront. No auth required.',
			schema: z.object({}),
			endpoint: '/public/product-views',
			isPublic: true,
		}),

		getTool(client, {
			name: 'fluentcart_public_product_search',
			title: 'Search Public Products',
			description: 'Search published products by name. No auth required.',
			schema: z.object({
				search: z.string().optional().describe('Search query to filter products by name'),
			}),
			endpoint: '/public/product-search',
			isPublic: true,
		}),

		getTool(client, {
			name: 'fluentcart_public_products',
			title: 'List Public Products',
			description:
				'The storefront product catalogue as an unauthenticated visitor sees it. No auth required. ' +
				'For catalogue management use fluentcart_product_list, which is the admin view.',
			schema: z.object({
				page: z.number().optional().describe('Page number (default: 1)'),
				per_page: z.number().max(50).optional().describe('Results per page (max: 50)'),
			}),
			endpoint: '/public/products',
			isPublic: true,
			// Rows arrive as raw WordPress post columns, so one product cost roughly 4,900 characters
			// and the default page came to 53,762 — past the emergency cap, meaning the tool could not
			// answer at all on a 16-product store. None of what follows is about a product: `guid` and
			// `post_name` are WordPress routing, `comment_status` and `ping_status` are blog features
			// FluentCart does not use, and the four `post_date`/`post_modified` GMT twins restate the
			// two local timestamps beside them.
			transform: stripWordPressPlumbing,
		}),

		postTool(client, {
			name: 'fluentcart_public_user_login',
			title: 'User Login',
			description:
				'Frontend login: authenticate by email/password, returns customer data with token. ' +
				'WARNING: Sends credentials in plaintext. Only use over HTTPS.',
			schema: z.object({
				email: z.string().describe('User email address'),
				password: z.string().describe('User password'),
			}),
			endpoint: '/user/login',
			isPublic: true,
		}),
	]
}
