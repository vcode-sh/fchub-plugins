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
 * Whether another page exists, since the store will not say.
 *
 * `ProductController::searchProductByName` calls `ShopResource::get`, which returns both the
 * paginator and a `total` count — and then returns only `$products['products']`, dropping the
 * count on the floor. What survives is a `simplePaginate` envelope: `current_page`, `from`, `to`,
 * `per_page`, and no total, no last page. A caller reading a full page therefore cannot tell
 * whether it has the catalogue or the first tenth of it, which is how "the most expensive thing I
 * sell" comes back as the most expensive thing on page one. A full page means keep going.
 */
function hasMore(rows: unknown[], perPage: unknown): boolean {
	const size = Number(perPage)
	return Number.isFinite(size) && size > 0 && rows.length >= size
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
		return { ...body, data: body.data.map(searchRow), has_more: hasMore(body.data, body.per_page) }
	}

	const inner = asRecord(body.products)
	if (inner && Array.isArray(inner.data)) {
		const rows = inner.data
		return {
			...body,
			products: { ...inner, data: rows.map(searchRow), has_more: hasMore(rows, inner.per_page) },
		}
	}
	if (Array.isArray(body.products)) {
		return { ...body, products: body.products.map(searchRow) }
	}

	return data
}

/**
 * Spell the product search query the way this endpoint reads it.
 *
 * Two parameters the tool had no way to reach. `termId` filters to one `product-categories` term,
 * and it is the ONLY route in the registry from a category to the products in it —
 * `fluentcart_product_terms` returns the term tree and never says what is filed under a term.
 * `current_page` is what `simplePaginate` was given as its page name, so the obvious `page` and
 * `per_page` are both read by nothing: verified live, `page=2` and `per_page=50` each returned
 * page one unchanged while `current_page=2` returned the remaining rows.
 */
export function searchProductQuery(input: Record<string, unknown>): Record<string, unknown> {
	const { category_id: category, page, ...rest } = input
	return {
		...rest,
		...(category === undefined ? {} : { termId: category }),
		...(page === undefined ? {} : { current_page: page }),
	}
}
