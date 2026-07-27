/**
 * Compact allowlist projections for the commerce entities.
 *
 * Upstream rows are wide, deeply nested and inconsistent between endpoints. Handing them to an
 * agent whole burns the context window on fields nobody asked for and, worse, invites the model
 * to reason over values it has no contract for. A projection is an allowlist: a field appears
 * because it was verified against the schema, or it does not appear at all.
 *
 * Two rules run through every projection here:
 *
 * Money stays in integer minor units with its ISO currency beside it. FluentCart stores order
 * and subscription amounts as BIGINT minor units, so a float conversion would lose pennies at
 * scale, and a bare number with no currency invites cross-currency addition. Nothing in this
 * file sums anything.
 *
 * A value that upstream did not send is `null`, never a guess. `orderCount: null` means "this
 * endpoint did not report it"; zero would be a claim the merchant has no orders.
 *
 * Field evidence: fct_orders / fct_customers / fct_subscriptions / fct_order_transactions in
 * database/Migrations, and the captured `/products` read contract.
 */

/** Detail collections a caller may opt into. Nothing here is returned by default. */
export const ORDER_INCLUDES = ['items', 'addresses', 'transactions'] as const
export const PRODUCT_INCLUDES = ['variants', 'media'] as const
export const CUSTOMER_INCLUDES = ['addresses'] as const

export type OrderInclude = (typeof ORDER_INCLUDES)[number]
export type ProductInclude = (typeof PRODUCT_INCLUDES)[number]
export type CustomerInclude = (typeof CUSTOMER_INCLUDES)[number]

export class ProjectionError extends Error {
	readonly code = 'INVALID_INCLUDE'
	constructor(message: string) {
		super(message)
		this.name = 'ProjectionError'
	}
}

export interface Money {
	/** Integer minor units, e.g. 4000 for 40.00 PLN. Never a float, never rounded here. */
	amount: number | null
	/** ISO 4217 code as the store reported it, or null when it did not. */
	currency: string | null
}

export interface OrderRow {
	id: number | null
	receiptNumber: number | null
	status: string | null
	paymentStatus: string | null
	paymentMethod: string | null
	total: Money
	customerName: string | null
	createdAt: string | null
	items?: unknown
	addresses?: unknown
	transactions?: unknown
}

export interface ProductRow {
	id: number | null
	title: string | null
	slug: string | null
	status: string | null
	type: string | null
	fulfilment: string | null
	variants?: unknown
	media?: unknown
}

export interface CustomerRow {
	id: number | null
	name: string | null
	/** Present only when the caller is authorised to read contact details. */
	email?: string | null
	location: { city: string | null; state: string | null; country: string | null }
	orderCount: number | null
	lifetimeValue: Money
	addresses?: unknown
}

export interface SubscriptionRow {
	id: number | null
	parentOrderId: number | null
	productId: number | null
	variationId: number | null
	status: string | null
	billingInterval: string | null
	recurring: Money
	nextBillingDate: string | null
	canceledAt: string | null
}

export interface TransactionRow {
	id: number | null
	orderId: number | null
	type: string | null
	status: string | null
	paymentMethod: string | null
	paymentMode: string | null
	amount: Money
	createdAt: string | null
}

function read(row: unknown, key: string): unknown {
	if (!row || typeof row !== 'object') return undefined
	return (row as Record<string, unknown>)[key]
}

/**
 * First key that carries a value, so a renamed upstream field degrades to null, not 0.
 *
 * An empty string counts as absent. Most FluentCart columns are `NOT NULL DEFAULT ''`, so `''`
 * is how the schema spells "not set" — treating it as present would stop the fallback chain on
 * a value that says nothing.
 */
function pick(row: unknown, ...keys: string[]): unknown {
	for (const key of keys) {
		const value = read(row, key)
		if (value === undefined || value === null) continue
		if (typeof value === 'string' && value.trim() === '') continue
		return value
	}
	return undefined
}

function asString(value: unknown): string | null {
	if (typeof value === 'string') return value.trim() === '' ? null : value
	if (typeof value === 'number' && Number.isFinite(value)) return String(value)
	return null
}

function asId(value: unknown): number | null {
	if (typeof value === 'number' && Number.isInteger(value)) return value
	if (typeof value === 'string' && /^\d+$/.test(value.trim())) return Number(value.trim())
	return null
}

/**
 * Read a monetary column as integer minor units.
 *
 * FluentCart returns these as BIGINT, which arrives as a numeric string often enough that a
 * plain `typeof value === 'number'` check would silently drop the amount. A non-integer value
 * is refused rather than rounded: a half-penny in a total means the assumption is wrong, and a
 * rounded answer would hide that.
 */
function asMinorUnits(value: unknown): number | null {
	if (typeof value === 'number') return Number.isInteger(value) ? value : null
	if (typeof value === 'string' && /^-?\d+$/.test(value.trim())) return Number(value.trim())
	return null
}

function money(amount: unknown, currency: unknown): Money {
	return { amount: asMinorUnits(amount), currency: asString(currency)?.toUpperCase() ?? null }
}

function fullName(
	row: unknown,
	first: string,
	last: string,
	...fallbacks: string[]
): string | null {
	const firstName = asString(read(row, first))
	const lastName = asString(read(row, last))
	const joined = [firstName, lastName].filter(Boolean).join(' ').trim()
	if (joined !== '') return joined
	return asString(pick(row, ...fallbacks))
}

/** Reject an unknown `include` locally rather than sending it upstream to be ignored. */
export function assertIncludes<T extends string>(
	requested: readonly string[] | undefined,
	allowed: readonly T[],
	entity: string,
): T[] {
	if (!requested || requested.length === 0) return []
	const unknown = requested.filter((value) => !allowed.includes(value as T))
	if (unknown.length > 0) {
		throw new ProjectionError(
			`Unknown include for ${entity}: ${unknown.join(', ')}. Allowed: ${allowed.join(', ')}.`,
		)
	}
	return requested as T[]
}

function attach(target: Record<string, unknown>, row: unknown, includes: readonly string[]): void {
	for (const name of includes) {
		const value = read(row, name)
		if (value !== undefined) target[name] = value
	}
}

export function projectOrder(row: unknown, includes: readonly OrderInclude[] = []): OrderRow {
	const customer = read(row, 'customer')
	const projected: OrderRow = {
		id: asId(pick(row, 'id', 'ID')),
		receiptNumber: asId(read(row, 'receipt_number')),
		status: asString(read(row, 'status')),
		paymentStatus: asString(read(row, 'payment_status')),
		paymentMethod: asString(pick(row, 'payment_method_title', 'payment_method')),
		total: money(read(row, 'total_amount'), read(row, 'currency')),
		customerName: fullName(customer, 'first_name', 'last_name', 'email'),
		createdAt: asString(read(row, 'created_at')),
	}
	attach(projected as unknown as Record<string, unknown>, row, includes)
	return projected
}

export function projectProduct(row: unknown, includes: readonly ProductInclude[] = []): ProductRow {
	const detail = read(row, 'detail')
	const projected: ProductRow = {
		id: asId(pick(row, 'ID', 'id', 'post_id')),
		title: asString(pick(row, 'post_title', 'title')),
		slug: asString(pick(row, 'post_name', 'slug')),
		status: asString(pick(row, 'post_status', 'status')),
		type: asString(read(detail, 'variation_type')),
		fulfilment: asString(read(detail, 'fulfillment_type')),
	}
	attach(projected as unknown as Record<string, unknown>, row, includes)
	return projected
}

/**
 * @param options.includeEmail — only ever true when the caller passed an authorisation check.
 *   The key is absent, not null, when unauthorised: an explicit null reads as "no email on
 *   file", which is a different and misleading claim.
 */
export function projectCustomer(
	row: unknown,
	options: { includeEmail?: boolean; includes?: readonly CustomerInclude[] } = {},
): CustomerRow {
	const projected: CustomerRow = {
		id: asId(pick(row, 'id', 'ID')),
		name: fullName(row, 'first_name', 'last_name'),
		location: {
			city: asString(read(row, 'city')),
			state: asString(read(row, 'state')),
			country: asString(read(row, 'country')),
		},
		orderCount: asId(read(row, 'purchase_count')),
		lifetimeValue: money(read(row, 'ltv'), read(row, 'currency')),
	}
	if (options.includeEmail === true) projected.email = asString(read(row, 'email'))
	attach(projected as unknown as Record<string, unknown>, row, options.includes ?? [])
	return projected
}

export function projectSubscription(row: unknown): SubscriptionRow {
	return {
		id: asId(pick(row, 'id', 'ID')),
		parentOrderId: asId(read(row, 'parent_order_id')),
		productId: asId(read(row, 'product_id')),
		variationId: asId(read(row, 'variation_id')),
		status: asString(read(row, 'status')),
		billingInterval: asString(read(row, 'billing_interval')),
		// fct_subscriptions has no currency column; it arrives only when the parent order is
		// joined, so it stays null rather than borrowing the store default.
		recurring: money(read(row, 'recurring_total'), pick(row, 'currency', 'order_currency')),
		nextBillingDate: asString(read(row, 'next_billing_date')),
		canceledAt: asString(read(row, 'canceled_at')),
	}
}

export function projectTransaction(row: unknown): TransactionRow {
	return {
		id: asId(pick(row, 'id', 'ID')),
		orderId: asId(read(row, 'order_id')),
		type: asString(read(row, 'transaction_type')),
		status: asString(read(row, 'status')),
		paymentMethod: asString(read(row, 'payment_method')),
		paymentMode: asString(read(row, 'payment_mode')),
		amount: money(read(row, 'total'), read(row, 'currency')),
		createdAt: asString(read(row, 'created_at')),
	}
}
