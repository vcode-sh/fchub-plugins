// Owned fixtures for the live acceptance lanes.
//
// Every factory here creates a record, records the exact id the API returned, and registers a
// removal with an independent verifier before the caller sees anything. Nothing reads a
// pre-existing record and hands it back: a fixture the run did not create is a fixture the run
// must not touch, and an id discovered by searching is somebody else's data wearing our prefix.
//
// The returned objects carry ids and the run-stamped strings needed to assert on them. They never
// carry the created record itself, so a test cannot accidentally assert against a payload rather
// than against a fresh read.

import type { ApiResponse } from '../../../src/api/client.js'
import type { WriteMode } from '../../../src/security/write-policy.js'
import type { ServerContext, ToolsetMode } from '../../../src/server.js'
import { resolveServerContextAsync } from '../../../src/server.js'
import type { ToolDefinition } from '../../../src/tools/_factory.js'
import type { CleanupLedger } from './cleanup-ledger.js'
import {
	getLiveClient,
	removeAttributeGroup,
	removeCoupon,
	removeCustomer,
	removeProduct,
	removeShippingClass,
	verifyAttributeGroupMissing,
	verifyCouponMissing,
	verifyCustomerMissing,
	verifyProductMissing,
	verifyShippingClassMissing,
} from './live-client.js'
import { getLiveRun } from './live-run.js'

/**
 * Lifecycles FluentCart 1.5.5 cannot clean up, each named so a reader can see the gap rather than
 * wonder why a lane is quiet. Every one of these was confirmed against the live store; none is a
 * guess, and none is skipped for convenience.
 */
export const NAMED_BLOCKERS = {
	label: '/labels registers only GET and POST; no route deletes a label',
	customerAddress:
		'the first address is stored primary, CustomerAddressResource::delete refuses a primary address, and deleting the owning customer does not cascade to it',
	productPricing:
		'POST /products/{id}/pricing is a whole-product save that leaves an orphan wp_posts revision FluentCart REST cannot remove',
} as const

export interface OwnedCustomer {
	id: number
	email: string
}

export interface OwnedProduct {
	id: number
	title: string
	detailId: number | undefined
}

export interface OwnedCoupon {
	id: number
	code: string
	/** Which status the store accepted. Recorded because an active coupon is briefly spendable. */
	status: 'inactive' | 'active'
}

export interface OwnedAttributeGroup {
	id: number
	title: string
	slug: string
}

export interface OwnedShippingClass {
	id: number
	name: string
}

const contexts = new Map<string, Promise<ServerContext>>()

/**
 * A production-shaped server context for one write mode.
 *
 * Deliberately the asynchronous constructor: it discovers the store's real routes first, so the
 * registry these lanes assert on is the route-pruned one a real connection would get, not the
 * unpruned registry a unit test sees.
 */
export function acceptanceContext(writeMode: WriteMode): Promise<ServerContext> {
	const existing = contexts.get(writeMode)
	if (existing) return existing

	const previous = process.env.FLUENTCART_WRITE_MODE
	process.env.FLUENTCART_WRITE_MODE = writeMode
	const pending = resolveServerContextAsync().finally(() => {
		if (previous === undefined) delete process.env.FLUENTCART_WRITE_MODE
		else process.env.FLUENTCART_WRITE_MODE = previous
	})
	contexts.set(writeMode, pending)
	return pending
}

/** Every public tool name a mode would register, for exposure assertions. */
export function exposedNames(ctx: ServerContext): string[] {
	return ctx.tools.map((tool) => tool.name).sort()
}

export function findTool(ctx: ServerContext, name: string): ToolDefinition {
	const tool = ctx.tools.find((entry) => entry.name === name)
	if (!tool) throw new Error(`tool "${name}" is not exposed under this policy`)
	return tool
}

export interface ToolOutcome {
	isError: boolean
	text: string
	json: unknown
}

/** Invoke a tool exactly as the server would, and parse the single text block it returns. */
export async function callTool(
	ctx: ServerContext,
	name: string,
	input: Record<string, unknown> = {},
): Promise<ToolOutcome> {
	const tool = findTool(ctx, name)
	const parsed = tool.schema.safeParse(input)
	if (!parsed.success) {
		throw new Error(`input rejected by ${name} schema: ${JSON.stringify(parsed.error.issues)}`)
	}

	const result = await tool.handler(parsed.data as Record<string, unknown>)
	const text = result.content.map((block) => block.text).join('')
	let json: unknown
	try {
		json = JSON.parse(text)
	} catch {
		json = undefined
	}
	return { isError: result.isError === true, text, json }
}

function inner(payload: unknown): Record<string, unknown> {
	const body = payload as Record<string, unknown> | null
	return (body?.data ?? {}) as Record<string, unknown>
}

export async function createOwnedCustomer(ledger: CleanupLedger): Promise<OwnedCustomer> {
	const run = getLiveRun()
	const email = `${run.prefix}-acceptance@example.invalid`
	const res = await getLiveClient().post('/customers', {
		email,
		first_name: 'MCP',
		last_name: 'Acceptance',
		full_name: 'MCP Acceptance',
		status: 'active',
	})

	const id = inner(res.data).id as number
	if (!(typeof id === 'number' && id > 0)) {
		throw new Error(`customer create returned no usable id: ${JSON.stringify(res.data)}`)
	}
	ledger.track({
		type: 'customer',
		id,
		remove: removeCustomer,
		verifyMissing: verifyCustomerMissing,
	})
	return { id, email }
}

/**
 * A draft product with no pricing save.
 *
 * `post_status: 'draft'` keeps it off the storefront for its whole life, and the pricing route is
 * never called — see NAMED_BLOCKERS.productPricing.
 */
export async function createOwnedProduct(ledger: CleanupLedger): Promise<OwnedProduct> {
	const run = getLiveRun()
	const title = `${run.prefix} acceptance product`
	const res = await getLiveClient().post('/products', {
		post_title: title,
		post_status: 'draft',
		post_excerpt: 'Run-owned acceptance fixture',
		detail: { fulfillment_type: 'digital' },
	})

	const id = inner(res.data).ID as number
	if (!(typeof id === 'number' && id > 0)) {
		throw new Error(`product create returned no usable id: ${JSON.stringify(res.data)}`)
	}
	ledger.track({ type: 'product', id, remove: removeProduct, verifyMissing: verifyProductMissing })

	const read = await getLiveClient().get(`/products/${id}`)
	const product = (read.data as Record<string, unknown>).product as Record<string, unknown>
	const detail = product.detail as Record<string, unknown> | undefined
	return { id, title, detailId: typeof detail?.id === 'number' ? detail.id : undefined }
}

/**
 * The least spendable coupon the store will accept.
 *
 * Inactive is attempted first so the code is never redeemable by an unrelated checkout. If
 * FluentCart refuses the status, the fallback is the minimum valid active coupon, and the caller
 * is told which it got so the lane can delete it promptly rather than assume it was inert.
 */
export async function createOwnedCoupon(ledger: CleanupLedger): Promise<OwnedCoupon> {
	const run = getLiveRun()
	const code = `${run.prefix}-ACC`.toUpperCase()
	const body = (status: string) => ({
		title: `${run.prefix} acceptance coupon`,
		code,
		type: 'percentage',
		amount: 1,
		status,
		stackable: 'no',
		show_on_checkout: 'no',
		notes: '',
	})

	let status: OwnedCoupon['status'] = 'inactive'
	let res: ApiResponse
	try {
		res = await getLiveClient().post('/coupons', body('inactive'))
	} catch {
		status = 'active'
		res = await getLiveClient().post('/coupons', body('active'))
	}

	const id = inner(res.data).id as number
	if (!(typeof id === 'number' && id > 0)) {
		throw new Error(`coupon create returned no usable id: ${JSON.stringify(res.data)}`)
	}
	ledger.track({ type: 'coupon', id, remove: removeCoupon, verifyMissing: verifyCouponMissing })
	return { id, code, status }
}

export async function createOwnedShippingClass(ledger: CleanupLedger): Promise<OwnedShippingClass> {
	const run = getLiveRun()
	const name = `${run.prefix} shipping class`
	const res = await getLiveClient().post('/shipping/classes', {
		name,
		description: 'Run-owned shipping profile fixture',
		cost: 0,
		type: 'fixed',
	})
	const body = res.data as Record<string, unknown>
	const shippingClass = (body.shipping_class ?? inner(body).shipping_class) as
		| Record<string, unknown>
		| undefined
	const id = shippingClass?.id
	if (!(typeof id === 'number' && id > 0)) {
		throw new Error(`shipping class create returned no usable id: ${JSON.stringify(res.data)}`)
	}
	ledger.track({
		type: 'shipping class',
		id,
		remove: removeShippingClass,
		verifyMissing: verifyShippingClassMissing,
	})
	return { id, name }
}

export async function createOwnedAttributeGroup(
	ledger: CleanupLedger,
): Promise<OwnedAttributeGroup> {
	const run = getLiveRun()
	const title = `${run.prefix} acceptance group`
	const slug = `${run.prefix}-acceptance-group`
	const res = await getLiveClient().post('/options/attr/group', { title, slug })

	const id = inner(res.data).id as number
	if (!(typeof id === 'number' && id > 0)) {
		throw new Error(`attribute group create returned no usable id: ${JSON.stringify(res.data)}`)
	}
	ledger.track({
		type: 'attribute-group',
		id,
		remove: removeAttributeGroup,
		verifyMissing: verifyAttributeGroupMissing,
	})
	return { id, title, slug }
}

/** Modes an acceptance lane may construct. Code mode needs the asynchronous constructor. */
export const ASYNC_ONLY_MODE: ToolsetMode = 'code'
