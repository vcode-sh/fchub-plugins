// The order detail said everything three times.
//
// `fluentcart_order_get` had already lost the whole-order copy nested inside each address, and
// still measured 11,303 characters for a three-line order. The rest was repetition:
//
//  - The two addresses arrived THREE times — `order_addresses` holding both, plus
//    `billing_address` and `shipping_address` holding the same two again. 1,627 + 811 + 813.
//  - Each address repeated itself: `formatted_address` restates every field, `meta.other_data`
//    restates the email and name a third time.
//  - Each line embedded the variant's whole catalogue record under `variants` — manage_stock,
//    backorders, total_stock, timestamps. Three lines came to 4,629 characters.
//  - `formatted_total` gave the line total as `5.00&euro;` beside the plain `line_total`.
//
// Live after: order 59 11,303 -> 5,510 characters, its items 4,629 -> 1,481.
import { describe, expect, it } from 'vitest'
import { collapseOrderDetail } from '../../src/tools/order-detail-projection.js'

const ADDRESS = {
	id: 46,
	order_id: '59',
	type: 'billing',
	name: 'Vibe Code',
	address_1: 'tttt',
	city: 'Poznan',
	country: 'PL',
	email: 'buyer@example.invalid',
	meta: { other_data: { email: 'buyer@example.invalid', first_name: 'Vibe' } },
	formatted_address: { country: 'Poland', city: 'Poznan', name: 'Vibe Code' },
	created_at: '2026-04-22T17:10:11+00:00',
	updated_at: '2026-04-22T17:10:11+00:00',
}

const ITEM = {
	id: 51,
	order_id: '59',
	post_title: 'Modern Sweatshirt',
	title: 'Orange',
	object_id: '12',
	quantity: '1',
	unit_price: 500,
	line_total: 500,
	formatted_total: '5.00&euro;',
	line_meta: [],
	other_info: { payment_type: 'onetime' },
	created_at: '2026-04-22T17:10:11+00:00',
	updated_at: '2026-04-22T17:10:11+00:00',
	variants: {
		id: 12,
		sku: 'SW-ORA',
		manage_stock: '1',
		backorders: 0,
		total_stock: 100,
		variation_title: 'Orange',
	},
}

const ORDER = {
	id: 59,
	total_paid: 12500,
	order_addresses: [ADDRESS, { ...ADDRESS, id: 47, type: 'shipping' }],
	billing_address: ADDRESS,
	shipping_address: { ...ADDRESS, id: 47, type: 'shipping' },
	order_items: [ITEM],
}

describe('an order says each fact once', () => {
	it('keeps the two named addresses and drops the array holding the same two', () => {
		const order = collapseOrderDetail(ORDER)

		expect(order).not.toHaveProperty('order_addresses')
		expect((order.billing_address as Record<string, unknown>).city).toBe('Poznan')
		expect((order.shipping_address as Record<string, unknown>).type).toBe('shipping')
	})

	it("drops an address's copies of itself but keeps the address", () => {
		const billing = collapseOrderDetail(ORDER).billing_address as Record<string, unknown>

		expect(billing).not.toHaveProperty('formatted_address')
		expect(billing).not.toHaveProperty('meta')
		expect(billing).not.toHaveProperty('created_at')
		// Everything a parcel needs survives.
		expect(billing).toMatchObject({
			name: 'Vibe Code',
			address_1: 'tttt',
			city: 'Poznan',
			country: 'PL',
			email: 'buyer@example.invalid',
		})
	})

	it('replaces the embedded variant record with the one field the line lacks', () => {
		const item = (collapseOrderDetail(ORDER).order_items as Record<string, unknown>[])[0]

		expect(item).not.toHaveProperty('variants')
		expect(item?.sku, 'the SKU is what a fulfilment question needs').toBe('SW-ORA')
		expect(item).not.toHaveProperty('formatted_total')
		expect(item?.line_total, 'the computable total stays').toBe(500)
		expect(item?.title).toBe('Orange')
	})

	it('omits the SKU rather than inventing one when the variant has none', () => {
		// This store's variants carry sku: null, so there is nothing to lift.
		const order = collapseOrderDetail({
			...ORDER,
			order_items: [{ ...ITEM, variants: { id: 12, sku: null } }],
		})
		expect((order.order_items as Record<string, unknown>[])[0]).not.toHaveProperty('sku')
	})

	it('keeps the address array when the named pair is not there to replace it', () => {
		// Losing the only copy would be worse than the duplication ever was.
		const order = collapseOrderDetail({ order_addresses: [ADDRESS] })

		expect(Array.isArray(order.order_addresses)).toBe(true)
		expect((order.order_addresses as Record<string, unknown>[])[0]?.city).toBe('Poznan')
		expect((order.order_addresses as Record<string, unknown>[])[0]).not.toHaveProperty(
			'formatted_address',
		)
	})

	it('leaves an order with none of these fields untouched', () => {
		const bare = { id: 7, total_paid: 100 }
		expect(collapseOrderDetail(bare)).toEqual(bare)
	})

	it('survives values that are not the shape it expects', () => {
		const odd = { billing_address: 'not an object', order_items: 'not an array' }
		expect(collapseOrderDetail(odd)).toEqual(odd)
	})
})
