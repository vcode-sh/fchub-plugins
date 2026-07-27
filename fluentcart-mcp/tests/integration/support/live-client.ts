import { createClient, type FluentCartClient } from '../../../src/api/client.js'
import { FluentCartApiError } from '../../../src/api/errors.js'
import { resolveApiUrls } from '../../../src/config/types.js'
import { getLiveRun } from './live-run.js'

let cached: FluentCartClient | null = null

/**
 * The authenticated client for live lanes. Reachable only from a launcher-started process:
 * getLiveRun() throws when FLUENTCART_TEST_RUN_ID is absent.
 */
export function getLiveClient(): FluentCartClient {
	if (cached) return cached
	getLiveRun()

	const url = process.env.FLUENTCART_URL
	const username = process.env.FLUENTCART_USERNAME
	const appPassword = process.env.FLUENTCART_APP_PASSWORD
	if (!(url && username && appPassword)) {
		throw new Error(
			'Live client requires URL, username and application password from the launcher.',
		)
	}

	cached = createClient(resolveApiUrls({ url, username, appPassword }))
	return cached
}

function statusOf(error: unknown): number | null {
	return error instanceof FluentCartApiError ? error.status : null
}

/**
 * Verifiers below must distinguish "absent" from "we could not tell".
 *
 * Anything that is not a positive absence signal is rethrown, so an expired application
 * password can never be mistaken for a tidy store.
 */

/** FluentCart answers 403 for a deleted product post and 404 for an unknown id. */
export async function verifyProductMissing(id: string | number): Promise<boolean> {
	const client = getLiveClient()
	try {
		const res = await client.get(`/products/${id}`)
		const body = res.data as { product?: unknown } | null
		return body?.product == null
	} catch (error) {
		const status = statusOf(error)
		if (status === 403 || status === 404) return true
		throw error
	}
}

/** FluentCart answers 200 with `{coupon: null}` rather than 404. */
export async function verifyCouponMissing(id: string | number): Promise<boolean> {
	const client = getLiveClient()
	const res = await client.get(`/coupons/${id}`)
	const body = res.data as { coupon?: unknown } | null
	return body?.coupon == null
}

export async function verifyCustomerMissing(id: string | number): Promise<boolean> {
	const client = getLiveClient()
	try {
		await client.get(`/customers/${id}`)
		return false
	} catch (error) {
		if (statusOf(error) === 404) return true
		throw error
	}
}

/** FluentCart answers 200 with `{group: null}` for a deleted attribute group. */
export async function verifyAttributeGroupMissing(id: string | number): Promise<boolean> {
	const client = getLiveClient()
	const res = await client.get(`/options/attr/group/${id}`)
	const body = res.data as { group?: unknown } | null
	return body?.group == null
}

/**
 * Removal is "ensure this exact record is gone", so a record the test body already deleted is
 * not an error. Absence is confirmed with the same verifier the ledger uses afterwards, so
 * skipping the delete never weakens the proof that the record is gone.
 */
async function removeIfPresent(
	id: string | number,
	verifyMissing: (id: string | number) => Promise<boolean>,
	remove: (id: string | number) => Promise<void>,
): Promise<void> {
	if (await verifyMissing(id)) return
	await remove(id)
}

export async function removeProduct(id: string | number): Promise<void> {
	await removeIfPresent(id, verifyProductMissing, async (target) => {
		await getLiveClient().delete(`/products/${target}`)
	})
}

export async function removeCoupon(id: string | number): Promise<void> {
	await removeIfPresent(id, verifyCouponMissing, async (target) => {
		await getLiveClient().delete(`/coupons/${target}`)
	})
}

export async function removeAttributeGroup(id: string | number): Promise<void> {
	await removeIfPresent(id, verifyAttributeGroupMissing, async (target) => {
		await getLiveClient().delete(`/options/attr/group/${target}`)
	})
}

/**
 * Customers have no single-record DELETE route; the reviewed removal path is the bulk action
 * restricted to exactly one owned id. It is never called with a filter or a discovered id.
 */
export async function removeCustomer(id: string | number): Promise<void> {
	await removeIfPresent(id, verifyCustomerMissing, async (target) => {
		await getLiveClient().post('/customers/do-bulk-action', {
			action: 'delete_customers',
			customer_ids: [target],
		})
	})
}
