/**
 * How a variant is reported, and which variants come back.
 *
 * Split out of products-variants.ts, which passed the 280-line limit in this project's CLAUDE.md
 * once the SKU filter joined the stock one. The tools live there; what a row means lives here.
 */

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

export type StockFilter = 'low' | 'out' | 'tracked' | 'untracked'
export type SkuFilter = 'present' | 'missing'

/**
 * Whether a variant carries a SKU.
 *
 * "Which of my products have no SKU set" had no answer short of reading every variant in the
 * store — 19,739 characters over two pages on a 76-variant catalogue, to establish a fact the
 * store could have counted. Nothing in the registry filtered on it. The rows are already in memory
 * by this point, so the filter is free and the count falls out of `total`.
 */
export function matchesSku(v: Record<string, unknown>, filter: SkuFilter): boolean {
	const sku = v.sku
	const present = typeof sku === 'string' && sku.trim() !== ''
	return filter === 'present' ? present : !present
}

/**
 * Apply a stock filter to a raw variant row.
 *
 * `low` and `out` deliberately exclude untracked variants rather than treating their zero as an
 * empty shelf. A digital subscription that counts nothing is not sold out, and returning it under
 * "what have I run out of" would be the same false reading in a different place.
 */
export function matchesStock(
	v: Record<string, unknown>,
	filter: StockFilter,
	lowBelow: number,
): boolean {
	const tracked = tracksStock(v)
	if (filter === 'tracked') return tracked
	if (filter === 'untracked') return !tracked
	if (!tracked) return false

	const available = Number(v.available ?? 0)
	if (filter === 'out') return available <= 0
	return available > 0 && available < lowBelow
}

export function describeStockFilter(filter: StockFilter, lowBelow: number): string {
	if (filter === 'low') return `tracked variants with fewer than ${lowBelow} available`
	if (filter === 'out') return 'tracked variants with none available'
	if (filter === 'tracked') return 'variants that count units'
	return 'variants that do not count units, and so have no stock level'
}

export function trimVariant(v: Record<string, unknown>) {
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
