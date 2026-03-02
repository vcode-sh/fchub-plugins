import { afterAll, beforeAll, describe, expect, it } from 'vitest'
import { createClient, type FluentCartClient } from '../../src/api/client.js'
import { FluentCartApiError } from '../../src/api/errors.js'
import { resolveApiUrls } from '../../src/config/types.js'

/**
 * Integration tests for FluentCart CRUD operations against a live WordPress instance.
 *
 * Known FluentCart API bugs discovered during testing:
 *
 * BUG-1: Coupon create does not default `notes` to empty string.
 *        DB error: Column 'notes' cannot be null.
 *        Workaround: Always send notes: "" when creating coupons.
 *
 * BUG-2: Customer address create does not default `label` to empty string.
 *        DB error: Column 'label' cannot be null.
 *        Workaround: Always send label: "..." when creating addresses.
 *
 * BUG-3: GET /coupons/:id returns 200 with { coupon: null } for non-existent IDs.
 *        Should return 404.
 *
 * BUG-4: GET /products/:id returns 403 (FORBIDDEN) for non-existent products.
 *        Should return 404. This is a WordPress post-type quirk.
 *
 * BUG-5: Customer update (PUT) requires email field but then rejects it as duplicate
 *        because uniqueness validation does not exclude the current customer.
 *        Workaround: Send a different email to update, or the API will 422.
 *
 * BUG-6: All PUT endpoints require ALL fields (no partial updates).
 *        Coupon update requires title, code, type, status, stackable, show_on_checkout.
 *        Customer update requires full_name, email.
 *        Attribute group update requires slug.
 *
 * BUG-7: Product pricing update (POST /products/:id/pricing) is really a full product
 *        save endpoint. It requires post_title, post_status, detail.fulfillment_type,
 *        detail.variation_type, and full variants[] array. Cannot just update price.
 *
 * BUG-8: Attribute term creation (POST /options/attr/group/:id/term) returns 500
 *        "information mismatch" even with valid group ID and request body.
 *
 * BUG-9: Customer bulk delete action returns 500 "invalid action".
 *        Neither "delete" nor "bulk_delete" action names work.
 *
 * BUG-10: Customer address create requires `name` and `email` fields,
 *         but the MCP tool sends `first_name`/`last_name` instead.
 *
 * MCP Tool schema issues (derived from testing) — ALL FIXED:
 *
 * SCHEMA-1: [FIXED] fluentcart_product_create now nests fulfillment_type inside detail object.
 *
 * SCHEMA-2: [FIXED] fluentcart_customer_create now includes `full_name` field.
 *
 * SCHEMA-3: [FIXED] fluentcart_coupon_create uses `title`/`amount`, includes stackable,
 *           show_on_checkout, notes, and nests conditions under `conditions` object.
 *
 * SCHEMA-4: [FIXED] fluentcart_attribute_group_create marks slug as required.
 *
 * SCHEMA-5: [FIXED] fluentcart_customer_address_create uses `name`, `email`, `label`
 *           instead of first_name/last_name.
 *
 * SCHEMA-6: [FIXED] fluentcart_product_pricing_update sends full product payload
 *           with post_title, detail, and variants[] array.
 */

const hasCredentials =
	process.env.FLUENTCART_URL &&
	process.env.FLUENTCART_USERNAME &&
	process.env.FLUENTCART_APP_PASSWORD

const config = hasCredentials
	? resolveApiUrls({
			url: process.env.FLUENTCART_URL!,
			username: process.env.FLUENTCART_USERNAME!,
			appPassword: process.env.FLUENTCART_APP_PASSWORD!,
		})
	: undefined

let client: FluentCartClient

describe.skipIf(!hasCredentials)('Integration: CRUD operations', () => {
	beforeAll(() => {
		client = createClient(config!)
	})

	// ─── Product CRUD lifecycle ─────────────────────────────────────────────────

	describe('Product CRUD lifecycle', { timeout: 30_000 }, () => {
		const cleanup: Array<() => Promise<void>> = []
		let productId: number
		let variantId: number
		let detailId: number | undefined
		const productTitle = `MCP Test Product ${Date.now()}`

		afterAll(async () => {
			for (const fn of cleanup.reverse()) {
				try {
					await fn()
				} catch {
					/* ignore cleanup failures */
				}
			}
		})

		it('should create a product', async () => {
			// SCHEMA-1: API expects fulfillment_type inside detail object
			const res = await client.post('/products', {
				post_title: productTitle,
				post_status: 'draft',
				post_excerpt: 'Integration test product',
				detail: { fulfillment_type: 'digital' },
			})

			expect(res.status).toBe(200)
			const body = res.data as Record<string, unknown>
			const inner = body.data as Record<string, unknown>
			expect(inner).toBeDefined()
			productId = inner.ID as number
			expect(productId).toBeGreaterThan(0)

			// Capture variant ID for pricing test
			const variant = inner.variant as Record<string, unknown>
			if (variant && typeof variant.id === 'number') {
				variantId = variant.id
			}

			cleanup.push(async () => {
				await client.delete(`/products/${productId}`)
			})
		})

		it('should get the created product', async () => {
			const res = await client.get(`/products/${productId}`)
			expect(res.status).toBe(200)

			const body = res.data as Record<string, unknown>
			const product = body.product as Record<string, unknown>
			expect(product).toBeDefined()
			expect(product.post_title).toBe(productTitle)
			expect(product.ID).toBe(productId)

			const detail = product.detail as Record<string, unknown> | undefined
			if (detail && typeof detail.id === 'number') {
				detailId = detail.id
			}
		})

		it('should update product detail', async () => {
			if (!detailId) {
				const res = await client.get(`/products/${productId}`)
				const body = res.data as Record<string, unknown>
				const product = body.product as Record<string, unknown>
				const detail = product?.detail as Record<string, unknown> | undefined
				detailId = detail?.id as number | undefined
			}
			expect(detailId).toBeDefined()

			const res = await client.post(`/products/detail/${detailId}`, {
				fulfillment_type: 'physical',
			})
			expect(res.status).toBe(200)
		})

		it('should update product pricing (full variant payload required)', async () => {
			// BUG-7/SCHEMA-6: Pricing endpoint requires full product + variant data
			expect(variantId).toBeDefined()

			const res = await client.post(`/products/${productId}/pricing`, {
				post_title: productTitle,
				post_status: 'draft',
				detail: { fulfillment_type: 'physical', variation_type: 'simple' },
				variants: [
					{
						id: variantId,
						post_id: productId,
						variation_title: productTitle,
						item_price: 1999,
						compare_price: 2499,
						sku: `MCP-TEST-${Date.now()}`,
						payment_type: 'onetime',
						stock_status: 'in-stock',
						fulfillment_type: 'physical',
						item_status: 'active',
					},
				],
			})
			expect(res.status).toBe(200)
		})

		it('should delete the product', async () => {
			const res = await client.delete(`/products/${productId}`)
			expect(res.status).toBe(200)
			cleanup.length = 0
		})

		it('should return FORBIDDEN when getting deleted product (BUG-4)', async () => {
			// BUG-4: FluentCart returns 403 for deleted/non-existent products
			try {
				await client.get(`/products/${productId}`)
				expect.fail('Expected FluentCartApiError to be thrown')
			} catch (error) {
				expect(error).toBeInstanceOf(FluentCartApiError)
				const apiError = error as FluentCartApiError
				expect(apiError.code).toBe('FORBIDDEN')
				expect(apiError.status).toBe(403)
			}
		})
	})

	// ─── Customer CRUD lifecycle ────────────────────────────────────────────────

	describe('Customer CRUD lifecycle', { timeout: 30_000 }, () => {
		const cleanup: Array<() => Promise<void>> = []
		let customerId: number
		const ts = Date.now()
		const testEmail = `mcp-test-${ts}@test.com`
		const updatedEmail = `mcp-test-updated-${ts}@test.com`

		afterAll(async () => {
			for (const fn of cleanup.reverse()) {
				try {
					await fn()
				} catch {
					/* ignore cleanup failures */
				}
			}
		})

		it('should create a customer', async () => {
			// SCHEMA-2: API requires full_name field
			const res = await client.post('/customers', {
				email: testEmail,
				first_name: 'MCP',
				last_name: 'TestUser',
				full_name: 'MCP TestUser',
				status: 'active',
			})

			expect(res.status).toBe(200)
			const body = res.data as Record<string, unknown>
			const inner = body.data as Record<string, unknown>
			expect(inner).toBeDefined()
			customerId = inner.id as number
			expect(customerId).toBeGreaterThan(0)
			expect(inner.email).toBe(testEmail)
		})

		it('should get the created customer', async () => {
			const res = await client.get(`/customers/${customerId}`)
			expect(res.status).toBe(200)

			const body = res.data as Record<string, unknown>
			const customer = body.customer as Record<string, unknown>
			expect(customer).toBeDefined()
			expect(customer.email).toBe(testEmail)
		})

		it('should update customer (requires full payload + unique email)', async () => {
			// BUG-5/BUG-6: Requires all fields; email uniqueness check is broken.
			// Workaround: provide a new unique email.
			const res = await client.put(`/customers/${customerId}`, {
				first_name: 'MCPUpdated',
				last_name: 'TestUser',
				full_name: 'MCPUpdated TestUser',
				email: updatedEmail,
				status: 'active',
			})
			expect(res.status).toBe(200)
		})

		it('should verify customer update', async () => {
			const res = await client.get(`/customers/${customerId}`)
			expect(res.status).toBe(200)

			const body = res.data as Record<string, unknown>
			const customer = body.customer as Record<string, unknown>
			expect(customer.first_name).toBe('MCPUpdated')
			expect(customer.email).toBe(updatedEmail)
		})

		it('should create a billing address', async () => {
			// BUG-2/SCHEMA-5: Requires name, email, label fields (not first_name/last_name)
			const res = await client.post(`/customers/${customerId}/address`, {
				type: 'billing',
				name: 'MCP TestUser',
				email: updatedEmail,
				label: 'Home',
				address_1: '123 Test Street',
				city: 'London',
				state: 'Greater London',
				postcode: 'SW1A 1AA',
				country: 'GB',
			})

			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('should get customer addresses', async () => {
			const res = await client.get(`/customers/${customerId}/address`)
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()

			const body = res.data as Record<string, unknown>
			expect(Object.keys(body).length).toBeGreaterThan(0)
		})
	})

	// ─── Coupon CRUD lifecycle ──────────────────────────────────────────────────

	describe('Coupon CRUD lifecycle', { timeout: 30_000 }, () => {
		const cleanup: Array<() => Promise<void>> = []
		let couponId: number
		const couponCode = `MCP-TEST-${Date.now()}`

		afterAll(async () => {
			for (const fn of cleanup.reverse()) {
				try {
					await fn()
				} catch {
					/* ignore cleanup failures */
				}
			}
		})

		it('should create a coupon', async () => {
			// BUG-1/SCHEMA-3: API requires title/amount (not name/value),
			// plus stackable, show_on_checkout, and notes (BUG: notes cannot be null)
			const res = await client.post('/coupons', {
				title: 'MCP Integration Test Coupon',
				code: couponCode,
				type: 'percentage',
				amount: 10,
				status: 'active',
				stackable: 'no',
				show_on_checkout: 'no',
				notes: '',
			})

			expect(res.status).toBe(200)
			const body = res.data as Record<string, unknown>
			const inner = body.data as Record<string, unknown>
			expect(inner).toBeDefined()
			couponId = inner.id as number
			expect(couponId).toBeGreaterThan(0)
			expect(inner.code).toBe(couponCode)

			cleanup.push(async () => {
				await client.delete(`/coupons/${couponId}`)
			})
		})

		it('should get the created coupon', async () => {
			const res = await client.get(`/coupons/${couponId}`)
			expect(res.status).toBe(200)

			const body = res.data as Record<string, unknown>
			const coupon = body.coupon as Record<string, unknown>
			expect(coupon).toBeDefined()
			expect(coupon.code).toBe(couponCode)
			expect(coupon.type).toBe('percentage')
		})

		it('should update coupon (full payload required)', async () => {
			// BUG-6: PUT requires ALL fields, not just the changed ones
			const res = await client.put(`/coupons/${couponId}`, {
				title: 'MCP Integration Test Coupon',
				code: couponCode,
				type: 'percentage',
				amount: 20,
				status: 'active',
				stackable: 'no',
				show_on_checkout: 'no',
				notes: '',
			})
			expect(res.status).toBe(200)
		})

		it('should verify coupon update', async () => {
			const res = await client.get(`/coupons/${couponId}`)
			expect(res.status).toBe(200)

			const body = res.data as Record<string, unknown>
			const coupon = body.coupon as Record<string, unknown>
			expect(Number(coupon.amount)).toBe(20)
		})

		it('should delete the coupon', async () => {
			const res = await client.delete(`/coupons/${couponId}`)
			expect(res.status).toBe(200)
			cleanup.length = 0
		})

		it('should return null coupon for deleted coupon (BUG-3)', async () => {
			// BUG-3: Returns 200 with { coupon: null } instead of 404
			const res = await client.get(`/coupons/${couponId}`)
			expect(res.status).toBe(200)
			const body = res.data as Record<string, unknown>
			expect(body.coupon).toBeNull()
		})
	})

	// ─── Label creation test ────────────────────────────────────────────────────

	describe('Label creation', { timeout: 30_000 }, () => {
		const labelValue = `MCP Test Label ${Date.now()}`

		it('should create a label', async () => {
			const res = await client.post('/labels', {
				value: labelValue,
				color: '#3498db',
			})

			expect(res.status).toBe(200)
			const body = res.data as Record<string, unknown>
			const inner = body.data as Record<string, unknown>
			expect(inner).toBeDefined()
			expect(inner.value).toBe(labelValue)
			expect(typeof inner.id).toBe('number')
		})

		it('should list labels and find the new one', async () => {
			const res = await client.get('/labels')
			expect(res.status).toBe(200)

			const body = res.data as Record<string, unknown>
			const labels = Array.isArray(body) ? body : findArray(body)
			expect(labels).toBeDefined()
			if (labels) {
				const found = labels.some((label: Record<string, unknown>) => label.value === labelValue)
				expect(found).toBe(true)
			}
		})
	})

	// ─── Attribute Group CRUD lifecycle ─────────────────────────────────────────

	describe('Attribute Group CRUD lifecycle', { timeout: 30_000 }, () => {
		const cleanup: Array<() => Promise<void>> = []
		let groupId: number
		const ts = Date.now()
		const groupTitle = `MCP Test Group ${ts}`
		const groupSlug = `mcp-test-group-${ts}`

		afterAll(async () => {
			for (const fn of cleanup.reverse()) {
				try {
					await fn()
				} catch {
					/* ignore cleanup failures */
				}
			}
		})

		it('should create an attribute group', async () => {
			// SCHEMA-4: slug is required, not optional
			const res = await client.post('/options/attr/group', {
				title: groupTitle,
				slug: groupSlug,
			})

			expect(res.status).toBe(200)
			const body = res.data as Record<string, unknown>
			const inner = body.data as Record<string, unknown>
			expect(inner).toBeDefined()
			groupId = inner.id as number
			expect(groupId).toBeGreaterThan(0)

			cleanup.push(async () => {
				await client.delete(`/options/attr/group/${groupId}`)
			})
		})

		it('should get the created attribute group', async () => {
			const res = await client.get(`/options/attr/group/${groupId}`)
			expect(res.status).toBe(200)

			const body = res.data as Record<string, unknown>
			const group = body.group as Record<string, unknown>
			expect(group).toBeDefined()
			expect(group.title).toBe(groupTitle)
		})

		it('should update the attribute group (full payload required)', async () => {
			// BUG-6: Requires slug even for title-only update
			const updatedTitle = `${groupTitle} Updated`
			const res = await client.put(`/options/attr/group/${groupId}`, {
				title: updatedTitle,
				slug: groupSlug,
			})
			expect(res.status).toBe(200)
		})

		it('should verify attribute group update', async () => {
			const res = await client.get(`/options/attr/group/${groupId}`)
			expect(res.status).toBe(200)

			const body = res.data as Record<string, unknown>
			const group = body.group as Record<string, unknown>
			expect(group.title).toBe(`${groupTitle} Updated`)
		})

		// BUG-8: Term creation always returns 500 "information mismatch"
		// even with valid group ID and correct request body.
		// Skipping term CRUD tests until this FluentCart bug is fixed.
		it.skip('should create a term in the group (BUG-8: always returns 500)', async () => {
			const res = await client.post(`/options/attr/group/${groupId}/term`, {
				title: 'MCP Test Term',
				slug: `mcp-test-term-${ts}`,
			})
			expect(res.status).toBe(200)
		})

		it('should delete the attribute group', async () => {
			const res = await client.delete(`/options/attr/group/${groupId}`)
			expect(res.status).toBe(200)
			cleanup.length = 0
		})
	})

	// ─── Error handling tests ───────────────────────────────────────────────────

	describe('Error handling', { timeout: 30_000 }, () => {
		it('should return FORBIDDEN for non-existent product (BUG-4)', async () => {
			try {
				await client.get('/products/999999')
				expect.fail('Expected FluentCartApiError to be thrown')
			} catch (error) {
				expect(error).toBeInstanceOf(FluentCartApiError)
				const apiError = error as FluentCartApiError
				expect(apiError.code).toBe('FORBIDDEN')
				expect(apiError.status).toBe(403)
			}
		})

		it('should return NOT_FOUND for non-existent order', async () => {
			try {
				await client.get('/orders/999999')
				expect.fail('Expected FluentCartApiError to be thrown')
			} catch (error) {
				expect(error).toBeInstanceOf(FluentCartApiError)
				const apiError = error as FluentCartApiError
				expect(apiError.code).toBe('NOT_FOUND')
				expect(apiError.status).toBe(404)
			}
		})

		it('should return NOT_FOUND for non-existent customer', async () => {
			try {
				await client.get('/customers/999999')
				expect.fail('Expected FluentCartApiError to be thrown')
			} catch (error) {
				expect(error).toBeInstanceOf(FluentCartApiError)
				const apiError = error as FluentCartApiError
				expect(apiError.code).toBe('NOT_FOUND')
				expect(apiError.status).toBe(404)
			}
		})

		it('should return VALIDATION_ERROR when creating customer without required fields', async () => {
			try {
				await client.post('/customers', {
					first_name: 'NoEmail',
					last_name: 'Test',
				})
				expect.fail('Expected FluentCartApiError to be thrown')
			} catch (error) {
				expect(error).toBeInstanceOf(FluentCartApiError)
				const apiError = error as FluentCartApiError
				expect(apiError.code).toBe('VALIDATION_ERROR')
				expect(apiError.status).toBe(422)
			}
		})

		it('should return null coupon for non-existent coupon (BUG-3)', async () => {
			const res = await client.get('/coupons/999999')
			expect(res.status).toBe(200)
			const body = res.data as Record<string, unknown>
			expect(body.coupon).toBeNull()
		})
	})
}) // end Integration: CRUD operations

// ─── Helpers ────────────────────────────────────────────────────────────────

function findArray(data: Record<string, unknown>): unknown[] | undefined {
	for (const value of Object.values(data)) {
		if (Array.isArray(value)) return value
	}
	return undefined
}
