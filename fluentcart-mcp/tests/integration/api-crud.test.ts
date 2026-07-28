import { afterAll, beforeAll, describe, expect, it } from 'vitest'
import type { FluentCartClient } from '../../src/api/client.js'
import { FluentCartApiError } from '../../src/api/errors.js'
import { CleanupLedger } from './support/cleanup-ledger.js'
import {
	getLiveClient,
	removeAttributeGroup,
	removeCoupon,
	removeCustomer,
	removeProduct,
	verifyAttributeGroupMissing,
	verifyCouponMissing,
	verifyCustomerMissing,
	verifyProductMissing,
} from './support/live-client.js'
import { getLiveRun } from './support/live-run.js'

/**
 * Live CRUD lifecycles against the configured FluentCart store.
 *
 * Every record created here is stamped with this run's prefix, registered in the cleanup
 * ledger by the exact id the API returned, and independently proven gone afterwards. Nothing
 * is deleted by prefix search, and no pre-existing record is read-modified-written.
 *
 * Verified FluentCart request/response behaviour this suite depends on (1.5.5 + Pro 1.5.4):
 *
 * - Coupon create rejects a null `notes` column, so `notes: ''` is always sent.
 * - Customer address create requires `name`, `email` and `label`.
 * - `GET /coupons/{id}` answers 200 with `{coupon: null}` instead of 404.
 * - `GET /products/{id}` answers 403 for a deleted product post.
 * - PUT endpoints require the full payload; there are no partial updates.
 * - Customer update re-validates email uniqueness without excluding the current record, so an
 *   update must supply a fresh unique address.
 * - Product pricing is a whole-product save and needs post_title, detail and variants[].
 * - Customers have no DELETE route; `POST /customers/do-bulk-action` with
 *   `{action:'delete_customers', customer_ids:[id]}` is the reviewed single-record removal.
 * - Attribute terms are created through `POST /options/attr/group/{id}/terms` with a
 *   `{terms:[{title}]}` batch body. The legacy singular `/term` route is gone in 1.5.5.
 *
 * Two lifecycles are disabled because FluentCart 1.5.5 provides no way to clean them up. They
 * are skipped with a named blocker rather than quietly leaving residue behind:
 *
 * - label: `/labels` registers only GET and POST. No route deletes a label.
 * - customer address: the first address is primary, primary addresses cannot be deleted, and
 *   deleting the owning customer does not cascade to the address row.
 *
 * A further WordPress-level side effect is worth knowing: saving a product creates a post
 * revision, and deleting the product through FluentCart REST leaves that revision orphaned in
 * `wp_posts`. It is invisible to the FluentCart API and cannot be removed through it, so this
 * suite avoids repeated product saves rather than pretending REST cleanup covers it.
 */

const run = getLiveRun()
const ledger = new CleanupLedger()
let client: FluentCartClient

beforeAll(() => {
	client = getLiveClient()
})

afterAll(async () => {
	// Deliberately unguarded: a cleanup failure must fail this suite.
	await ledger.cleanup()
})

describe('Integration: CRUD operations', () => {
	describe('Product lifecycle', { timeout: 60_000 }, () => {
		let productId: number
		let detailId: number | undefined
		const productTitle = `${run.prefix} product`

		it('creates a product and records its id immediately', async () => {
			const res = await client.post('/products', {
				post_title: productTitle,
				post_status: 'draft',
				post_excerpt: 'Run-owned integration fixture',
				detail: { fulfillment_type: 'digital' },
			})

			expect(res.status).toBe(200)
			const inner = (res.data as Record<string, unknown>).data as Record<string, unknown>
			productId = inner.ID as number
			expect(productId).toBeGreaterThan(0)

			ledger.track({
				type: 'product',
				id: productId,
				remove: removeProduct,
				verifyMissing: verifyProductMissing,
			})
		})

		it('reads the created product back exactly', async () => {
			const res = await client.get(`/products/${productId}`)
			expect(res.status).toBe(200)

			const product = (res.data as Record<string, unknown>).product as Record<string, unknown>
			expect(product.post_title).toBe(productTitle)
			expect(product.ID).toBe(productId)

			const detail = product.detail as Record<string, unknown> | undefined
			if (detail && typeof detail.id === 'number') detailId = detail.id
		})

		it('updates the owned product detail', async () => {
			expect(detailId).toBeDefined()
			const res = await client.post(`/products/detail/${detailId}`, {
				fulfillment_type: 'physical',
			})
			expect(res.status).toBe(200)
		})

		it('reads the pricing projection for the owned product', async () => {
			const res = await client.get(`/products/${productId}/pricing`)
			expect(res.status).toBe(200)
			const body = res.data as { product?: { variants?: unknown[] } }
			expect(body.product).toBeDefined()
			// A digital simple product is created without a variation.
			expect(body.product?.variants).toEqual([])
		})

		// BLOCKER — POST /products/{id}/pricing. The route is a whole-product save, so WordPress
		// records a post revision for the product. Deleting the product through FluentCart REST
		// removes the product post but leaves that revision orphaned in wp_posts, and FluentCart
		// exposes no route that can delete it. Isolated probing confirmed the attribution: a bare
		// create/delete and a POST /products/detail/{id} update leave no revision, while the
		// pricing save leaves exactly one. Executing it here would therefore guarantee one
		// unremovable row per run. Re-enable when the product delete also removes its revisions.
		it.skip('accepts the whole-product save contract on the pricing route (BLOCKED: leaves an orphan wp_posts revision)', () => {
			throw new Error('unreachable while blocked')
		})

		it('answers FORBIDDEN for the product once it is deleted', async () => {
			const res = await client.delete(`/products/${productId}`)
			expect(res.status).toBe(200)

			await expect(client.get(`/products/${productId}`)).rejects.toSatisfy(
				(error: unknown) =>
					error instanceof FluentCartApiError && (error.status === 403 || error.status === 404),
			)

			// Already gone; the ledger verifier still proves absence independently.
			expect(await verifyProductMissing(productId)).toBe(true)
		})
	})

	describe('Customer lifecycle', { timeout: 60_000 }, () => {
		let customerId: number
		const email = `${run.prefix}@example.invalid`
		const updatedEmail = `${run.prefix}-updated@example.invalid`

		it('creates a customer and records its id immediately', async () => {
			const res = await client.post('/customers', {
				email,
				first_name: 'MCP',
				last_name: 'RunOwned',
				full_name: 'MCP RunOwned',
				status: 'active',
			})

			expect(res.status).toBe(200)
			const inner = (res.data as Record<string, unknown>).data as Record<string, unknown>
			customerId = inner.id as number
			expect(customerId).toBeGreaterThan(0)
			expect(inner.email).toBe(email)

			ledger.track({
				type: 'customer',
				id: customerId,
				remove: removeCustomer,
				verifyMissing: verifyCustomerMissing,
			})
		})

		it('reads the created customer back', async () => {
			const res = await client.get(`/customers/${customerId}`)
			const customer = (res.data as Record<string, unknown>).customer as Record<string, unknown>
			expect(customer.email).toBe(email)
		})

		it('updates the owned customer with a full payload and fresh unique email', async () => {
			const res = await client.put(`/customers/${customerId}`, {
				first_name: 'MCPUpdated',
				last_name: 'RunOwned',
				full_name: 'MCPUpdated RunOwned',
				email: updatedEmail,
				status: 'active',
			})
			expect(res.status).toBe(200)

			const check = await client.get(`/customers/${customerId}`)
			const customer = (check.data as Record<string, unknown>).customer as Record<string, unknown>
			expect(customer.first_name).toBe('MCPUpdated')
			expect(customer.email).toBe(updatedEmail)
		})

		it('lists addresses for the owned customer without creating one', async () => {
			// Read-only on purpose. See the address BLOCKER note below.
			const listed = await client.get(`/customers/${customerId}/address`)
			expect(listed.status).toBe(200)
			const addresses = (listed.data as { addresses?: Array<Record<string, unknown>> }).addresses
			expect(Array.isArray(addresses)).toBe(true)
			expect(addresses).toHaveLength(0)
		})

		// BLOCKER — customer address creation. Two verified FluentCart 1.5.5 behaviours combine
		// to make a created address impossible to clean up through REST:
		//   1. The first address created for a customer is stored with is_primary = 1, and
		//      CustomerAddressResource::delete refuses a primary address with
		//      "Primary address cannot be deleted!" (HTTP 403).
		//   2. Deleting the owning customer through /customers/do-bulk-action does not cascade
		//      to wp_fct_customer_addresses, so the row survives as an orphan.
		// Creating an address therefore guarantees permanent residue. Re-enable when FluentCart
		// either cascades the delete or permits removing the last remaining primary address.
		it.skip('creates a billing address (BLOCKED: primary address cannot be deleted and does not cascade)', () => {
			throw new Error('unreachable while blocked')
		})
	})

	describe('Coupon lifecycle', { timeout: 60_000 }, () => {
		let couponId: number
		const couponCode = `${run.prefix}-coupon`.toUpperCase()

		it('creates a coupon and records its id immediately', async () => {
			const res = await client.post('/coupons', {
				title: `${run.prefix} coupon`,
				code: couponCode,
				type: 'percentage',
				amount: 10,
				status: 'active',
				stackable: 'no',
				show_on_checkout: 'no',
				notes: '',
			})

			expect(res.status).toBe(200)
			const inner = (res.data as Record<string, unknown>).data as Record<string, unknown>
			couponId = inner.id as number
			expect(couponId).toBeGreaterThan(0)
			expect(inner.code).toBe(couponCode)

			ledger.track({
				type: 'coupon',
				id: couponId,
				remove: removeCoupon,
				verifyMissing: verifyCouponMissing,
			})
		})

		it('reads the created coupon back exactly', async () => {
			const res = await client.get(`/coupons/${couponId}`)
			const coupon = (res.data as Record<string, unknown>).coupon as Record<string, unknown>
			expect(coupon.code).toBe(couponCode)
			expect(coupon.type).toBe('percentage')
		})

		it('updates the owned coupon with a full payload', async () => {
			const res = await client.put(`/coupons/${couponId}`, {
				title: `${run.prefix} coupon`,
				code: couponCode,
				type: 'percentage',
				amount: 20,
				status: 'active',
				stackable: 'no',
				show_on_checkout: 'no',
				notes: '',
			})
			expect(res.status).toBe(200)

			const check = await client.get(`/coupons/${couponId}`)
			const coupon = (check.data as Record<string, unknown>).coupon as Record<string, unknown>
			expect(Number(coupon.amount)).toBe(20)
		})

		it('answers with a null coupon once it is deleted', async () => {
			const res = await client.delete(`/coupons/${couponId}`)
			expect(res.status).toBe(200)
			expect(await verifyCouponMissing(couponId)).toBe(true)
		})
	})

	describe('Attribute group lifecycle', { timeout: 60_000 }, () => {
		let groupId: number
		const groupTitle = `${run.prefix} group`
		const groupSlug = `${run.prefix}-group`

		it('creates an attribute group and records its id immediately', async () => {
			const res = await client.post('/options/attr/group', {
				title: groupTitle,
				slug: groupSlug,
			})

			expect(res.status).toBe(200)
			const inner = (res.data as Record<string, unknown>).data as Record<string, unknown>
			groupId = inner.id as number
			expect(groupId).toBeGreaterThan(0)

			ledger.track({
				type: 'attribute-group',
				id: groupId,
				remove: removeAttributeGroup,
				verifyMissing: verifyAttributeGroupMissing,
			})
		})

		it('reads the created attribute group back', async () => {
			const res = await client.get(`/options/attr/group/${groupId}`)
			const group = (res.data as Record<string, unknown>).group as Record<string, unknown>
			expect(group.title).toBe(groupTitle)
		})

		it('updates the owned attribute group with a full payload', async () => {
			const updatedTitle = `${groupTitle} updated`
			const res = await client.put(`/options/attr/group/${groupId}`, {
				title: updatedTitle,
				slug: groupSlug,
			})
			expect(res.status).toBe(200)

			const check = await client.get(`/options/attr/group/${groupId}`)
			const group = (check.data as Record<string, unknown>).group as Record<string, unknown>
			expect(group.title).toBe(updatedTitle)
		})

		it('creates terms through the 1.5.5 batch route beneath the owned group', async () => {
			const res = await client.post(`/options/attr/group/${groupId}/terms`, {
				terms: [{ title: `${run.prefix} term` }],
			})
			expect(res.status).toBe(200)

			const created = (res.data as { data?: Array<Record<string, unknown>> }).data
			expect(Array.isArray(created)).toBe(true)
			expect(created?.[0]?.id).toBeDefined()
			// Terms are children of an owned group and are removed when the group is removed.
			expect(String(created?.[0]?.group_id)).toBe(String(groupId))
		})
	})

	// BLOCKER: no DELETE route exists for labels in FluentCart 1.5.5, so a created label cannot
	// be registered with a verifiable cleanup handler. See the file header.
	describe.skip('Label lifecycle (BLOCKED: FluentCart exposes no DELETE /labels/{id})', () => {
		it('creates a label', async () => {
			throw new Error('unreachable while blocked')
		})
	})

	describe('Error contracts', { timeout: 60_000 }, () => {
		it('calls an unknown product id NOT_FOUND, while reporting the 403 the store sent', async () => {
			// FluentCart resolves the model inside its permission callback, so the ORM's
			// ModelNotFoundException escapes there and WordPress answers HTTP 403 with
			// `code: "Permission Callback Error"`. This lane used to assert only the 403, which
			// recorded the wire faithfully and left the tool telling callers "Permission denied" for a
			// mistyped id — a message that sends a merchant to check their application password.
			// Both facts now hold at once: the code is semantic, the status is what arrived.
			await expect(client.get('/products/999999')).rejects.toSatisfy(
				(error: unknown) =>
					error instanceof FluentCartApiError &&
					error.code === 'NOT_FOUND' &&
					error.status === 403 &&
					!/permission denied/i.test(error.message),
			)
		})

		it('answers NOT_FOUND for an unknown order id', async () => {
			await expect(client.get('/orders/999999')).rejects.toSatisfy(
				(error: unknown) => error instanceof FluentCartApiError && error.status === 404,
			)
		})

		it('answers NOT_FOUND for an unknown customer id', async () => {
			await expect(client.get('/customers/999999')).rejects.toSatisfy(
				(error: unknown) => error instanceof FluentCartApiError && error.status === 404,
			)
		})

		it('answers VALIDATION_ERROR when required customer fields are absent', async () => {
			await expect(
				client.post('/customers', { first_name: 'NoEmail', last_name: 'Test' }),
			).rejects.toSatisfy(
				(error: unknown) => error instanceof FluentCartApiError && error.status === 422,
			)
		})

		it('answers with a null coupon for an unknown coupon id', async () => {
			const res = await client.get('/coupons/999999')
			expect(res.status).toBe(200)
			expect((res.data as Record<string, unknown>).coupon).toBeNull()
		})
	})
})
