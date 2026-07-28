/**
 * What a product search result needs to carry.
 *
 * A search answers "which product do you mean", and the answer is a name, a price and enough to
 * fetch the rest. `/products/searchProductByName` returns 1,064 characters per row instead:
 * `thumbnail` and `featured_media` and `gallery_image` all describing the same picture,
 * `formatted_min_price` as the HTML entity string `8.00&euro;` beside the plain `min_price`,
 * `wp_terms` rows carrying Laravel's internal `laravel_through_key`, and creation timestamps.
 *
 * Measured live on a 20-product store: an unfiltered call was 10,604 characters and `name=shirt`
 * was 2,446 for two rows. Detail belongs to `fluentcart_product_get`, categories to
 * `fluentcart_product_terms`, and prices in a currency the caller can compute with belong here.
 */

function asRecord(value: unknown): Record<string, unknown> | null {
	return value !== null && typeof value === 'object' && !Array.isArray(value)
		? (value as Record<string, unknown>)
		: null
}

/** One row, reduced to what identifies and prices a product. */
function searchRow(value: unknown): unknown {
	const row = asRecord(value)
	if (!row) return value

	const detail = asRecord(row.detail)
	return {
		ID: row.ID ?? row.id,
		post_title: row.post_title,
		...(detail
			? {
					min_price: detail.min_price,
					max_price: detail.max_price,
					stock_availability: detail.stock_availability,
					fulfillment_type: detail.fulfillment_type,
					variation_type: detail.variation_type,
					default_variation_id: detail.default_variation_id,
				}
			: {}),
	}
}

/**
 * Project the rows wherever the paginator put them, leaving anything else untouched.
 *
 * Written to walk rather than to assume: this route wraps its page in `products`, and guessing an
 * envelope shape has already cost this project three separate transforms that silently did
 * nothing. An unrecognised payload is returned as-is rather than emptied.
 */
export function projectProductSearch(data: unknown): unknown {
	const body = asRecord(data)
	if (!body) return data

	if (Array.isArray(body.data)) {
		return { ...body, data: body.data.map(searchRow) }
	}

	const inner = asRecord(body.products)
	if (inner && Array.isArray(inner.data)) {
		return { ...body, products: { ...inner, data: inner.data.map(searchRow) } }
	}
	if (Array.isArray(body.products)) {
		return { ...body, products: body.products.map(searchRow) }
	}

	return data
}
