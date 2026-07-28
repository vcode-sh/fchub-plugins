// Two lists that returned records instead of rows.
//
// A list is scanned; a record is opened. Serving the whole record per row makes the scan cost what
// the reading would have, and both of these did:
//
//  - `label_list` returned 96 labels for 12,581 characters, three fifths of which was `created_at`
//    and `updated_at` on every row — 74 bytes of each 130-byte row. A label is a name you attach to
//    something; when it was first typed is not part of that. It also took no search, no page and no
//    limit, so the only answer available was all of them, however many there came to be.
//  - `order_customer_orders` returned the full order record per row — 761 characters each, 13,050
//    for one customer's nineteen orders — carrying manual_discount_total, coupon_discount_total,
//    shipping_tax, fee_total, tax_behavior and rate.
//
// Live after: 12,581 -> 4,761 for all 96 labels and 544 for ten; 13,050 -> 4,432 for the orders.
import { describe, expect, it, vi } from 'vitest'
import type { FluentCartClient } from '../../src/api/client.js'
import { createAllTools } from '../../src/tools/index.js'

function label(id: number, value: string) {
	return {
		id,
		value,
		created_at: '2026-03-02T21:46:10+00:00',
		updated_at: '2026-03-02T21:46:10+00:00',
	}
}

async function call(payload: unknown, name: string, input: Record<string, unknown> = {}) {
	const get = vi.fn().mockResolvedValue({ data: payload, status: 200 })
	const tool = createAllTools({ get } as unknown as FluentCartClient, {}).find(
		(candidate) => candidate.name === name,
	)
	if (!tool) throw new Error(`${name} is not registered`)
	const result = (await tool.handler(input as never, {} as never)) as {
		content: { text: string }[]
	}
	const text = result.content[0]?.text ?? ''
	return { text, body: JSON.parse(text || '{}') }
}

const LABELS = { labels: [label(1, 'Urgent'), label(2, 'Gift wrap'), label(3, 'Fragile')] }

describe('a label is a name, not a record', () => {
	it('drops the timestamps that were three fifths of every row', async () => {
		const { text, body } = await call(LABELS, 'fluentcart_label_list')

		expect(body.labels[0]).toEqual({ id: 1, value: 'Urgent' })
		expect(text).not.toContain('created_at')
		expect(text).not.toContain('updated_at')
	})

	it('pages, which it could not before', async () => {
		const { body } = await call(LABELS, 'fluentcart_label_list', { per_page: 2 })

		expect(body.labels).toHaveLength(2)
		expect(body.total).toBe(3)
		expect(body.has_more).toBe(true)
	})

	it('searches by name, and says how many exist beyond the match', async () => {
		const { body } = await call(LABELS, 'fluentcart_label_list', { search: 'gift' })

		expect(body.labels).toEqual([{ id: 2, value: 'Gift wrap' }])
		expect(body.total).toBe(1)
		expect(body.total_in_store, 'otherwise a narrow answer reads as a small store').toBe(3)
	})

	it('leaves a payload it does not recognise alone', async () => {
		const { body } = await call({ message: 'nothing here' }, 'fluentcart_label_list')
		expect(body.labels).toEqual([])
		expect(body.total).toBe(0)
	})
})

const ORDER = {
	id: 85,
	receipt_number: '24',
	status: 'completed',
	payment_status: 'paid',
	payment_method_title: 'Card',
	currency: 'EUR',
	total_amount: 130000,
	total_paid: '130000',
	total_refund: '0',
	created_at: '2026-05-05T13:56:18+00:00',
	// Everything below belongs to an order you have opened, not a list you are scanning.
	manual_discount_total: 0,
	coupon_discount_total: 0,
	shipping_tax: 0,
	fee_total: 0,
	tax_behavior: 0,
	rate: '1.0000',
	parent_id: null,
	type: 'payment',
	mode: 'live',
	note: 'Remaining balance for payment plan product from order #INV-21.',
}

describe('a customer order list is a list', () => {
	it('keeps what identifies and prices the order', async () => {
		const { body } = await call(
			{ orders: { current_page: 1, total: 1, data: [ORDER] } },
			'fluentcart_order_customer_orders',
			{ customer_id: 1 },
		)

		expect(body.orders.data[0]).toEqual({
			id: 85,
			receipt_number: '24',
			status: 'completed',
			payment_status: 'paid',
			payment_method_title: 'Card',
			currency: 'EUR',
			total_amount: 130000,
			total_paid: '130000',
			total_refund: '0',
			created_at: '2026-05-05T13:56:18+00:00',
		})
	})

	it('keeps total_refund, which is why the list is usually pulled', async () => {
		// "Has this customer ever had a refund" is one of the commonest reasons to ask at all;
		// dropping it would send the caller back for every row.
		const { text } = await call(
			{ orders: { current_page: 1, total: 1, data: [ORDER] } },
			'fluentcart_order_customer_orders',
			{ customer_id: 1 },
		)
		expect(text).toContain('total_refund')
	})

	it('drops the arithmetic that belongs to an opened order', async () => {
		const { text } = await call(
			{ orders: { current_page: 1, total: 1, data: [ORDER] } },
			'fluentcart_order_customer_orders',
			{ customer_id: 1 },
		)

		for (const field of ['manual_discount_total', 'tax_behavior', 'fee_total', 'parent_id']) {
			expect(text, `${field} is not part of a list row`).not.toContain(field)
		}
	})

	it('keeps the paginator', async () => {
		const { body } = await call(
			{ orders: { current_page: 2, total: 19, data: [ORDER] } },
			'fluentcart_order_customer_orders',
			{ customer_id: 1 },
		)

		expect(body.orders.current_page).toBe(2)
		expect(body.orders.total).toBe(19)
	})
})
