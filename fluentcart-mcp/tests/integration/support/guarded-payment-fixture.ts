/**
 * Run-owned fixtures for the guarded money actions — and the reason there are none.
 *
 * BLOCKER — a succeeded charge cannot be cleaned up. Refunding requires a persisted charge
 * transaction: `OrderController::refundOrder` resolves `refund_info.transaction_id` with
 * `findOrFail` against `wp_fct_order_transactions`, and the refund preview refuses any order
 * without a succeeded charge. Creating an order persists that charge row, `DELETE /orders/{id}`
 * does not cascade to it, and the captured 1.5.5 + Pro 1.5.4 route inventory registers no DELETE
 * for a transaction at all — only `GET /orders/{id}/transactions`,
 * `GET /orders/{id}/transactions/{txn}`, `PUT /orders/{id}/transactions/{txn}/status` and
 * `POST /orders/{id}/transactions/{txn}/accept-dispute`. So the row can be neither removed nor
 * proven gone through the API this package is allowed to use, and every earlier run that built
 * such a fixture left an orphan transaction pointing at a deleted order.
 *
 * Both guarded lanes depend on that same charge, so both are blocked — the preview lane too,
 * even though the previews themselves are mutation-free. This module therefore creates nothing.
 *
 * It is the same class of blocker already recorded for three other resources: labels have no
 * DELETE route; a customer's first address is primary, undeletable, and does not cascade from
 * customer deletion; and `POST /products/{id}/pricing` leaves an orphan wp_posts revision.
 *
 * The rest of the recipe is verified and recorded here so it is not rediscovered from scratch
 * when a transaction DELETE route exists. Against FluentCart 1.5.5 the working sequence was:
 *
 * - `POST /customers` requires `full_name`; first/last alone are rejected with 422.
 * - `POST /products` must use `post_status: 'private'` or `'publish'`.
 *   `ProductVariation::canPurchase()` rejects any other status, so a draft product cannot be
 *   ordered. `private` keeps the fixture out of the storefront.
 * - Price the product through `POST /products/variants`, never `POST /products/{id}/pricing`;
 *   the latter is a whole-product save that orphans a wp_posts revision. `item_price` there is
 *   in whole currency units.
 * - `POST /orders` rejects an empty `order_items`, and an item that only references the variant
 *   is created with a zero total, so `unit_price` must be explicit.
 * - `POST /orders` answers `{message, order_id, uuid}`, not an order object.
 * - Read `order.mode` back and refuse anything but `test` before paying: `Order::canBeDeleted()`
 *   only permits deleting a paid order in test mode, so a paid live-mode order is permanent.
 * - Order creation already yields a succeeded charge; `POST /orders/{id}/mark-as-paid` answers
 *   423 when nothing is due.
 */

/** Why a lane cannot run, in the exact terms an operator would need to unblock it. */
export interface FixtureBlocked {
	kind: 'blocked'
	/** Exactly what the API would have to provide for this lane to run. */
	prerequisite: string
	/** What was verified, for the acceptance record. */
	evidence: string[]
}

export type RefundFixture = FixtureBlocked
export type CancellationFixture = FixtureBlocked

const TRANSACTION_CLEANUP_EVIDENCE = [
	'route inventory: GET /orders/{id}/transactions and GET /orders/{id}/transactions/{txn} are the only transaction reads',
	'route inventory: PUT /orders/{id}/transactions/{txn}/status and POST /orders/{id}/transactions/{txn}/accept-dispute are the only transaction writes',
	'route inventory: no DELETE route exists for a transaction in FluentCart 1.5.5 + Pro 1.5.4',
	'DELETE /orders/{id} removes the order but leaves its transaction rows behind',
	'OrderController::refundOrder resolves refund_info.transaction_id with findOrFail, so a persisted charge is mandatory',
]

/**
 * The refund lanes have no owned fixture, and this function creates nothing to prove it.
 *
 * Returning the blocker rather than building an order is the whole point: the previous version
 * of this file created customer, product, variant and order records, and each run left behind a
 * transaction row it had no way to delete.
 */
export function describeRefundFixture(): RefundFixture {
	return {
		kind: 'blocked',
		prerequisite:
			'Executing a guarded refund requires a persisted test-mode charge, and FluentCart 1.5.5 exposes no route to remove a transaction, so the fixture cannot prove cleanup.',
		evidence: TRANSACTION_CLEANUP_EVIDENCE,
	}
}

/**
 * Subscriptions cannot be created by this run either, so the cancellation lanes have no owned
 * fixture even before the transaction blocker applies.
 *
 * Verified against the captured 1.5.5 + Pro 1.5.4 route inventory (386 operations): the admin
 * namespace exposes `GET /subscriptions` and `GET /subscriptions/{id}` for reading, and only
 * pause/resume/cancel/fetch/reactivate for writing — every one of which acts on a subscription
 * that already exists. The single creation path is `POST /checkout/place-order`, a storefront
 * checkout that requires a recurring product and a completed gateway payment flow.
 *
 * Cancelling a pre-existing subscription is forbidden outright: it belongs to a real customer,
 * and it is exactly the "never mutate a record this run did not create" rule.
 */
export function describeCancellationFixture(): CancellationFixture {
	return {
		kind: 'blocked',
		prerequisite:
			'A run-owned subscription. FluentCart 1.5.5 has no admin REST route that creates one — POST /checkout/place-order plus a gateway payment flow is the only path — and no pre-existing subscription may be substituted.',
		evidence: [
			'route inventory: GET /subscriptions and GET /subscriptions/{id} are the only admin subscription reads',
			'route inventory: every admin subscription write (cancel, pause, resume, reactivate, fetch) requires an existing subscription id',
			'no POST /subscriptions route exists in the captured 1.5.5 + Pro 1.5.4 inventory',
			...TRANSACTION_CLEANUP_EVIDENCE.slice(0, 4),
		],
	}
}
