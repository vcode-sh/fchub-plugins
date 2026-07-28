/**
 * What `GET /orders/{id}` says three times, said once.
 *
 * The order-detail payload was already stripped of the whole-order copy FluentCart nests inside
 * every address. What was left still measured 11,303 characters for a three-line order, and the
 * remaining weight is almost entirely repetition:
 *
 *  - The two addresses arrive THREE times. `order_addresses` is an array holding billing and
 *    shipping; `billing_address` and `shipping_address` hold the same two records again. Measured:
 *    1,627 + 811 + 813 characters for two addresses.
 *  - Each address then repeats itself internally. `formatted_address` restates every field it
 *    already carries, and `meta.other_data` restates the email and name a third time.
 *  - Each order item embeds the variant's entire record — `manage_stock`, `backorders`,
 *    `total_stock`, timestamps — under `variants`. Three items came to 4,629 characters. The one
 *    field there that the item does not already carry is the SKU.
 *  - `formatted_total` is the line total as the HTML entity string `5.00&euro;`, beside the plain
 *    `line_total` a caller can actually compute with.
 *
 * None of this is a judgement about what an agent might want. It is the same fact, written out
 * repeatedly, and the caller cannot tell which copy to trust.
 */

function asRecord(value: unknown): Record<string, unknown> | null {
	return value !== null && typeof value === 'object' && !Array.isArray(value)
		? (value as Record<string, unknown>)
		: null
}

/** One address, without the copies of itself it carries. */
function trimAddress(value: unknown): unknown {
	const address = asRecord(value)
	if (!address) return value

	const {
		formatted_address: _formatted,
		meta: _meta,
		created_at: _created,
		updated_at: _updated,
		order_id: _orderId,
		...rest
	} = address
	return rest
}

/**
 * One order line, without the variant catalogue record hanging off it.
 *
 * The SKU is lifted out before the rest goes, because it is the one thing a fulfilment question
 * needs that the line does not already state. Everything else about the variant belongs to
 * `fluentcart_variant_list` or `fluentcart_product_get`.
 */
function trimOrderItem(value: unknown): unknown {
	const item = asRecord(value)
	if (!item) return value

	const {
		variants,
		formatted_total: _formatted,
		line_meta: _lineMeta,
		other_info: _otherInfo,
		created_at: _created,
		updated_at: _updated,
		...rest
	} = item

	const sku = asRecord(variants)?.sku
	return sku === undefined || sku === null ? rest : { ...rest, sku }
}

/**
 * Collapse the repeated halves of an order-detail payload.
 *
 * `order_addresses` is dropped only when both named addresses are present, so a payload shaped
 * differently keeps everything it had rather than losing the only copy it carries.
 */
export function collapseOrderDetail(order: Record<string, unknown>): Record<string, unknown> {
	const output = { ...order }

	for (const key of ['billing_address', 'shipping_address']) {
		if (output[key] !== undefined) output[key] = trimAddress(output[key])
	}

	const named = output.billing_address !== undefined && output.shipping_address !== undefined
	if (named && output.order_addresses !== undefined) {
		const { order_addresses: _duplicated, ...withoutArray } = output
		return finishOrder(withoutArray)
	}
	if (Array.isArray(output.order_addresses)) {
		output.order_addresses = output.order_addresses.map(trimAddress)
	}

	return finishOrder(output)
}

/** The part that runs whichever way the addresses went. */
function finishOrder(order: Record<string, unknown>): Record<string, unknown> {
	if (!Array.isArray(order.order_items)) return order
	return { ...order, order_items: order.order_items.map(trimOrderItem) }
}

/**
 * One order as a LIST row: enough to recognise it, price it and decide whether to open it.
 *
 * `order_customer_orders` returned the full order record per row — 761 characters each, 13,050 for
 * one customer's nineteen orders — carrying `manual_discount_total`, `coupon_discount_total`,
 * `shipping_tax`, `fee_total`, `tax_behavior`, `rate`, `parent_id` and the internal `type` and
 * `mode`. Those belong to an order you have opened, not to a list you are scanning. FluentCart's
 * own `/orders` list returns roughly this shape, so the two now agree on what a row is.
 *
 * `total_refund` stays, because "has this customer ever had a refund" is one of the commonest
 * reasons to pull the list at all, and dropping it would send the caller back for every row.
 */
export function orderListRow(value: unknown): unknown {
	const order = asRecord(value)
	if (!order) return value

	return {
		id: order.id,
		receipt_number: order.receipt_number,
		status: order.status,
		payment_status: order.payment_status,
		payment_method_title: order.payment_method_title,
		currency: order.currency,
		total_amount: order.total_amount,
		total_paid: order.total_paid,
		total_refund: order.total_refund,
		created_at: order.created_at,
	}
}

/** Apply {@link orderListRow} to whichever key the paginator put the rows under. */
export function projectOrderListRows(data: unknown, key: string): unknown {
	const body = asRecord(data)
	const page = asRecord(body?.[key])
	if (!(body && page && Array.isArray(page.data))) return data
	return { ...body, [key]: { ...page, data: page.data.map(orderListRow) } }
}
