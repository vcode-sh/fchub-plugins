import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { createTool, getTool, postTool, type ToolDefinition } from './_factory.js'
import { direct } from './endpoints.js'

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

/**
 * Both storefront view routes answer with rendered HTML rather than data, so what the caller gets
 * back is a page, not a record. Counting the per-item markers the templates emit is the only way
 * to say how many products the visitor is actually shown without shipping the whole page.
 *
 * These two attributes are JavaScript hooks in FluentCart's own templates
 * (`SearchBarRenderer::renderResultItems`, the shop product card), not translated strings, so
 * counting them survives a store running in any locale.
 */
const SEARCH_RESULT_MARKER = 'data-fluent-cart-search-bar-lists-list-item'
const PRODUCT_CARD_MARKER = 'data-fct-product-card'

function countMarkers(markup: string, marker: string): number {
	let count = 0
	let index = markup.indexOf(marker)
	while (index !== -1) {
		count += 1
		index = markup.indexOf(marker, index + marker.length)
	}
	return count
}

/**
 * Undo the `esc_html` the template applied on the way out.
 *
 * Without this a title reads `Basic Men&#039;s T-Shirt`, which matches no product record anywhere
 * — the caller would have to un-escape it before comparing to anything the admin API returns.
 */
function decodeEntities(text: string): string {
	return text
		.replace(/&#0?39;|&apos;/g, "'")
		.replace(/&quot;/g, '"')
		.replace(/&lt;/g, '<')
		.replace(/&gt;/g, '>')
		.replace(/&#0?38;|&amp;/g, '&')
}

/**
 * Best-effort product titles out of the search dropdown.
 *
 * The class is a styling hook rather than translated text, but it is still markup, so a template
 * change degrades this to an empty list instead of an error — the marker count above remains the
 * authoritative answer to "how many results", and `include_markup` remains the escape hatch.
 */
function searchResultTitles(markup: string): string[] {
	const titles: string[] = []
	const pattern = /<span class="fct-search-result-title">([\s\S]*?)<\/span>/g
	for (const match of markup.matchAll(pattern)) {
		const title = decodeEntities((match[1] ?? '').replace(/\s+/g, ' ').trim())
		if (title !== '') titles.push(title)
	}
	return titles
}

export function publicTools(client: FluentCartClient): ToolDefinition[] {
	return [
		createTool(client, {
			name: 'fluentcart_public_product_views',
			routes: direct('GET', '/public/product-views'),
			title: 'Get Public Product Views',
			description:
				'What an unauthenticated visitor is shown on the shop page: how many products the ' +
				'catalogue holds, which slice this page covers, and how many product cards the ' +
				'storefront renders for it. No auth required. The rendered HTML is omitted unless you ' +
				'ask for it with include_markup, because the default page of ten cards is roughly ' +
				'41,000 characters of markup on its own.',
			schema: z.object({
				page: z.number().optional().describe('Page number, starting at 1 (default: 1)'),
				per_page: z.number().optional().describe('Products per page (default: 10)'),
				include_markup: z
					.boolean()
					.optional()
					.describe('Return the rendered storefront HTML as well (very large; default false)'),
			}),
			annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: true },
			// The tool used to take no parameters at all, and the store's default page came to 43,185
			// characters — past the 40,000 emergency cap — so on a 16-product catalogue it could not
			// answer at all, and there was no knob to make it smaller. The backend paginates on
			// `current_page`, not `page`: sending `page` was accepted and silently ignored, which is
			// why every request came back as page one.
			handler: async (apiClient, input) => {
				const query: Record<string, unknown> = {}
				if (input.page !== undefined) query.current_page = input.page
				if (input.per_page !== undefined) query.per_page = input.per_page

				const response = await apiClient.get('/public/product-views', query, true)
				const body = ((response.data ?? {}) as Record<string, unknown>).products
				const page = (body ?? {}) as Record<string, unknown>
				const markup = typeof page.views === 'string' ? page.views : ''

				return {
					total: page.total ?? null,
					page: page.page ?? page.current_page ?? null,
					per_page: page.per_page ?? null,
					from: page.from ?? null,
					to: page.to ?? null,
					last_page: page.last_page ?? null,
					cards_rendered: countMarkers(markup, PRODUCT_CARD_MARKER),
					markup_characters: markup.length,
					...(input.include_markup === true
						? { views: markup }
						: {
								markup_omitted: 'Pass include_markup true to receive the rendered storefront HTML.',
							}),
				}
			},
		}),

		createTool(client, {
			name: 'fluentcart_public_product_search',
			routes: direct('GET', '/public/product-search'),
			title: 'Search Public Products',
			description:
				'Search the published catalogue the way a shop visitor does, by product title. No auth ' +
				'required. Returns the matched titles and how many results the storefront renders. ' +
				'Omitting the search term returns the unfiltered first page rather than nothing. The ' +
				'rendered dropdown HTML is omitted unless you pass include_markup.',
			schema: z.object({
				search: z
					.string()
					.optional()
					.describe('Product title to search for; matched as a wildcard, not an exact title'),
				include_markup: z
					.boolean()
					.optional()
					.describe('Return the rendered search dropdown HTML as well (large; default false)'),
			}),
			annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: true },
			// The search term never reached the store. `ShopController::searchProduct` reads
			// `post_title`; this tool sent `search`, which nothing looks at, so every query — including
			// one for a product that does not exist — returned the same unfiltered first ten products
			// as 12,838 characters of dropdown markup. A search tool that ignores what you searched
			// for is worse than no search tool, because the answer looks plausible.
			handler: async (apiClient, input) => {
				const search = (input.search as string | undefined) ?? ''
				const response = await apiClient.get('/public/product-search', { post_title: search }, true)
				const body = (response.data ?? {}) as Record<string, unknown>
				const markup = typeof body.htmlView === 'string' ? body.htmlView : ''

				return {
					search: search === '' ? null : search,
					results: countMarkers(markup, SEARCH_RESULT_MARKER),
					titles: searchResultTitles(markup),
					markup_characters: markup.length,
					...(input.include_markup === true
						? { htmlView: markup }
						: {
								markup_omitted: 'Pass include_markup true to receive the rendered dropdown HTML.',
							}),
				}
			},
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
