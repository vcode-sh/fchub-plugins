import { describe, expect, it } from 'vitest'
import {
	assertIncludes,
	CUSTOMER_INCLUDES,
	ORDER_INCLUDES,
	PRODUCT_INCLUDES,
	ProjectionError,
	projectCustomer,
	projectOrder,
	projectProduct,
	projectSubscription,
	projectTransaction,
} from '../../src/commerce/entity-projections.js'

/** Shaped after fct_orders, including the noise a real row carries. */
const ORDER_ROW = {
	id: 41,
	status: 'completed',
	receipt_number: 1007,
	payment_status: 'paid',
	payment_method: 'stripe',
	payment_method_title: 'Card',
	currency: 'pln',
	total_amount: '12300',
	subtotal: 10000,
	tax_total: 2300,
	ip_address: '203.0.113.9',
	note: 'internal note',
	config: { upgraded_from: 3 },
	uuid: 'b2f1e0a4',
	created_at: '2026-07-20 10:04:11',
	customer: { id: 8, first_name: 'Ada', last_name: 'Lovelace', email: 'ada@example.test' },
	items: [{ id: 1, title: 'Widget' }],
}

const PRODUCT_ROW = {
	ID: 77,
	post_title: 'Analytical Engine',
	post_name: 'analytical-engine',
	post_status: 'publish',
	post_content: 'a very long description',
	guid: 'https://store.test/?p=77',
	edit_url: 'https://store.test/wp-admin/post.php?post=77',
	detail: { variation_type: 'simple', fulfillment_type: 'digital', min_price: '1500' },
}

const CUSTOMER_ROW = {
	id: 8,
	first_name: 'Ada',
	last_name: 'Lovelace',
	email: 'ada@example.test',
	city: 'London',
	state: null,
	country: 'GB',
	purchase_count: 4,
	ltv: '48000',
	currency: 'GBP',
	notes: 'private note',
	uuid: 'c9a1',
}

describe('order projection', () => {
	it('keeps only allowlisted fields', () => {
		expect(Object.keys(projectOrder(ORDER_ROW)).sort()).toEqual([
			'createdAt',
			'customerName',
			'id',
			'paymentMethod',
			'paymentStatus',
			'receiptNumber',
			'status',
			'total',
		])
	})

	it('drops raw fields that carry no contract', () => {
		const row = projectOrder(ORDER_ROW) as unknown as Record<string, unknown>
		for (const leaked of ['ip_address', 'note', 'config', 'uuid', 'subtotal', 'tax_total']) {
			expect(row[leaked]).toBeUndefined()
		}
	})

	it('keeps money as integer minor units with its currency', () => {
		const { total } = projectOrder(ORDER_ROW)
		expect(total).toEqual({ amount: 12300, currency: 'PLN' })
		expect(Number.isInteger(total.amount)).toBe(true)
	})

	it('refuses a fractional amount rather than rounding it', () => {
		expect(projectOrder({ ...ORDER_ROW, total_amount: 123.45 }).total.amount).toBeNull()
	})

	it('prefers the human-readable payment method title', () => {
		expect(projectOrder(ORDER_ROW).paymentMethod).toBe('Card')
		expect(projectOrder({ ...ORDER_ROW, payment_method_title: '' }).paymentMethod).toBe('stripe')
	})

	it('composes the customer display name, falling back to email', () => {
		expect(projectOrder(ORDER_ROW).customerName).toBe('Ada Lovelace')
		expect(
			projectOrder({ ...ORDER_ROW, customer: { email: 'ada@example.test' } }).customerName,
		).toBe('ada@example.test')
	})

	it('reports missing values as null rather than zero or empty string', () => {
		const sparse = projectOrder({ id: 5 })
		expect(sparse.status).toBeNull()
		expect(sparse.receiptNumber).toBeNull()
		expect(sparse.total).toEqual({ amount: null, currency: null })
	})

	it('returns detail collections only through include[]', () => {
		expect(projectOrder(ORDER_ROW).items).toBeUndefined()
		expect(projectOrder(ORDER_ROW, ['items']).items).toEqual([{ id: 1, title: 'Widget' }])
	})
})

describe('product projection', () => {
	it('keeps only allowlisted fields', () => {
		expect(Object.keys(projectProduct(PRODUCT_ROW)).sort()).toEqual([
			'fulfilment',
			'id',
			'slug',
			'status',
			'title',
			'type',
		])
	})

	it('reads the WordPress post shape and the detail row', () => {
		expect(projectProduct(PRODUCT_ROW)).toMatchObject({
			id: 77,
			title: 'Analytical Engine',
			slug: 'analytical-engine',
			status: 'publish',
			type: 'simple',
			fulfilment: 'digital',
		})
	})

	it('drops post body and admin URLs', () => {
		const row = projectProduct(PRODUCT_ROW) as unknown as Record<string, unknown>
		expect(row.post_content).toBeUndefined()
		expect(row.edit_url).toBeUndefined()
		expect(row.guid).toBeUndefined()
	})

	it('survives a missing detail row', () => {
		expect(projectProduct({ ID: 5 }).type).toBeNull()
	})
})

describe('customer projection', () => {
	it('omits email entirely unless authorised', () => {
		const withheld = projectCustomer(CUSTOMER_ROW)
		// Absent, not null: an explicit null would read as "no email on file", which is a
		// different claim from "you may not see it".
		expect('email' in withheld).toBe(false)
		expect(projectCustomer(CUSTOMER_ROW, { includeEmail: true }).email).toBe('ada@example.test')
	})

	it('keeps lifetime value in minor units and reports order count', () => {
		const row = projectCustomer(CUSTOMER_ROW)
		expect(row.lifetimeValue).toEqual({ amount: 48000, currency: 'GBP' })
		expect(row.orderCount).toBe(4)
	})

	it('leaves an unreported total null instead of zero', () => {
		const row = projectCustomer({ id: 9, first_name: 'Grace' })
		expect(row.orderCount).toBeNull()
		expect(row.lifetimeValue.amount).toBeNull()
	})

	it('exposes location without leaking notes', () => {
		const row = projectCustomer(CUSTOMER_ROW)
		expect(row.location).toEqual({ city: 'London', state: null, country: 'GB' })
		expect((row as unknown as Record<string, unknown>).notes).toBeUndefined()
	})
})

describe('subscription projection', () => {
	const SUB = {
		id: 12,
		parent_order_id: 41,
		product_id: 77,
		variation_id: 90,
		status: 'active',
		billing_interval: 'monthly',
		recurring_total: '2500',
		next_billing_date: '2026-08-20 00:00:00',
		canceled_at: null,
		vendor_response: { raw: 'blob' },
	}

	it('projects the allowlist and drops vendor payloads', () => {
		const row = projectSubscription(SUB)
		expect(row).toMatchObject({
			id: 12,
			parentOrderId: 41,
			productId: 77,
			variationId: 90,
			status: 'active',
			billingInterval: 'monthly',
			canceledAt: null,
		})
		expect((row as unknown as Record<string, unknown>).vendor_response).toBeUndefined()
	})

	it('leaves currency null when the row does not carry one', () => {
		// fct_subscriptions has no currency column; it arrives only when the order is joined.
		expect(projectSubscription(SUB).recurring).toEqual({ amount: 2500, currency: null })
		expect(projectSubscription({ ...SUB, currency: 'eur' }).recurring.currency).toBe('EUR')
	})
})

describe('transaction projection', () => {
	it('projects the allowlist including payment mode', () => {
		const row = projectTransaction({
			id: 3,
			order_id: 41,
			transaction_type: 'charge',
			status: 'paid',
			payment_method: 'stripe',
			payment_mode: 'test',
			total: 12300,
			currency: 'pln',
			created_at: '2026-07-20 10:04:11',
			vendor_charge_id: 'ch_123',
		})

		expect(row).toEqual({
			id: 3,
			orderId: 41,
			type: 'charge',
			status: 'paid',
			paymentMethod: 'stripe',
			paymentMode: 'test',
			amount: { amount: 12300, currency: 'PLN' },
			createdAt: '2026-07-20 10:04:11',
		})
	})

	it('never infers payment mode from another row', () => {
		expect(projectTransaction({ id: 4 }).paymentMode).toBeNull()
	})
})

describe('include validation', () => {
	it('accepts the documented values', () => {
		expect(assertIncludes(['items'], ORDER_INCLUDES, 'order')).toEqual(['items'])
		expect(assertIncludes(undefined, PRODUCT_INCLUDES, 'product')).toEqual([])
	})

	it('rejects anything else locally, naming what is allowed', () => {
		expect(() => assertIncludes(['secrets'], ORDER_INCLUDES, 'order')).toThrow(ProjectionError)
		expect(() => assertIncludes(['addresses', 'nope'], CUSTOMER_INCLUDES, 'customer')).toThrow(
			/nope/,
		)
	})
})
