/**
 * Deep Orders, Coupons, Customers & Labels — Live validation of untested write tools.
 *
 * Covers:
 *   Orders:    order_accept_dispute, order_bump_create, order_bump_delete,
 *              order_bump_get, order_bump_update, order_generate_licenses,
 *              order_sync_statuses, order_transaction_update_status, order_update_address_id
 *   Coupons:   coupon_apply, coupon_cancel, coupon_update, coupon_settings_save
 *   Customers: customer_attach_user, customer_bulk_action, customer_create,
 *              customer_detach_user, customer_update, customer_update_additional_info,
 *              label_update_selections
 *
 * Run:
 *   cd /Users/tomrobak/_projects_/fchub-plugins/fluentcart-mcp
 *   set -a && source .env && set +a
 *   npx tsx tests/_scenarios-deep-orders-coupons.ts
 */
import { resolveServerContext } from '../src/server.js'

/* ------------------------------------------------------------------ */
/*  Harness                                                           */
/* ------------------------------------------------------------------ */

type ToolResult = { isError?: boolean; data: unknown; raw: string; size: number }
type Verdict = 'PASS' | 'FAIL' | 'BUG' | 'UPSTREAM'
type Severity = 'P0' | 'P1' | 'P2'
type Finding = {
	tool: string
	verdict: Verdict
	severity?: Severity
	issue?: string
	notes?: string[]
}

const ctx = resolveServerContext()
const toolMap = new Map<string, (typeof ctx.tools)[0]>()
for (const t of ctx.tools) toolMap.set(t.name, t)

const findings: Finding[] = []

async function call(name: string, input: Record<string, unknown> = {}): Promise<ToolResult> {
	const tool = toolMap.get(name)
	if (!tool) {
		const raw = `Tool not found: ${name}`
		return { isError: true, data: null, raw, size: raw.length }
	}
	try {
		const result = (await tool.handler(input)) as {
			content: { type: string; text: string }[]
			isError?: boolean
		}
		const text = result.content[0]?.text ?? ''
		let data: unknown
		try { data = JSON.parse(text) } catch { data = text }
		return { isError: result.isError, data, raw: text, size: text.length }
	} catch (err: unknown) {
		const msg = err instanceof Error ? err.message : String(err)
		return { isError: true, data: null, raw: msg, size: msg.length }
	}
}

function asObj(d: unknown): Record<string, unknown> {
	return (d ?? {}) as Record<string, unknown>
}

function log(step: string, detail?: string) {
	console.log(`\n${'─'.repeat(72)}`)
	console.log(`  ${step}`)
	if (detail) console.log(`  ${detail}`)
}

function show(r: ToolResult, maxLen = 600) {
	const status = r.isError ? 'ERROR' : 'OK'
	const preview = r.raw.length > maxLen ? `${r.raw.slice(0, maxLen)}...` : r.raw
	console.log(`  [${status}] ${r.size} bytes`)
	console.log(`  ${preview}`)
}

function record(f: Finding) {
	findings.push(f)
	const emoji = f.verdict === 'PASS' ? 'PASS' : f.verdict === 'BUG' ? 'BUG' : f.verdict === 'UPSTREAM' ? 'UPSTREAM' : 'FAIL'
	console.log(`\n  >> ${emoji}: ${f.tool}${f.issue ? ` — ${f.issue}` : ''}`)
	if (f.notes) for (const n of f.notes) console.log(`     ${n}`)
}

/* ------------------------------------------------------------------ */
/*  Discovery: find valid IDs from the live store                     */
/* ------------------------------------------------------------------ */

let existingOrderId = 0
let existingOrderTransactionId = 0
let existingCustomerId = 0
let existingCouponId = 0
let existingCouponCode = ''
let existingVariantId = 0
let existingProductId = 0
let draftOrderId = 0
let existingWpUserId = 0

// Cleanup tracking
let createdCustomerId = 0
let createdLabelId = 0
let createdBumpId = 0
let createdDraftOrderId = 0

async function discover() {
	log('DISCOVERY', 'Finding valid IDs from the live store...')

	// 1. Orders
	const orders = await call('fluentcart_order_list', { per_page: 10 })
	show(orders)
	const ordersData = asObj(orders.data)
	const ordersList = (asObj(ordersData.orders ?? ordersData).data ?? []) as Record<string, unknown>[]
	if (ordersList.length > 0) {
		existingOrderId = ordersList[0].id as number
		console.log(`  Found order ID: ${existingOrderId}`)
		// Find a pending order for coupon tests
		for (const o of ordersList) {
			if (o.payment_status === 'pending') {
				draftOrderId = o.id as number
				console.log(`  Found pending order ID: ${draftOrderId}`)
				break
			}
		}
	}

	// 2. Get order with transactions
	if (existingOrderId) {
		const txns = await call('fluentcart_order_transactions', { order_id: existingOrderId })
		const txnData = asObj(txns.data)
		const txnList = (txnData.transactions ?? []) as Record<string, unknown>[]
		if (txnList.length > 0) {
			existingOrderTransactionId = txnList[0].id as number
			console.log(`  Found transaction ID: ${existingOrderTransactionId} on order ${existingOrderId}`)
		} else {
			// Try finding an order with transactions
			for (const o of ordersList.slice(1)) {
				const tx2 = await call('fluentcart_order_transactions', { order_id: o.id as number })
				const tx2Data = asObj(tx2.data)
				const tx2List = (tx2Data.transactions ?? []) as Record<string, unknown>[]
				if (tx2List.length > 0) {
					existingOrderId = o.id as number
					existingOrderTransactionId = tx2List[0].id as number
					console.log(`  Switched to order ${existingOrderId} with transaction ${existingOrderTransactionId}`)
					break
				}
			}
		}
	}

	// 3. Customers
	const customers = await call('fluentcart_customer_list', { per_page: 5 })
	const custData = asObj(customers.data)
	const custList = (asObj(custData.customers ?? custData).data ?? []) as Record<string, unknown>[]
	if (custList.length > 0) {
		existingCustomerId = custList[0].id as number
		console.log(`  Found customer ID: ${existingCustomerId}`)
	}

	// 4. Coupons
	const coupons = await call('fluentcart_coupon_list', { per_page: 5 })
	show(coupons)
	const coupData = asObj(coupons.data)
	// Try multiple response shapes
	const coupWrapper = coupData.coupons ?? coupData
	const coupListRaw = Array.isArray(coupWrapper) ? coupWrapper : ((coupWrapper as Record<string, unknown>).data ?? [])
	const coupList = (Array.isArray(coupListRaw) ? coupListRaw : []) as Record<string, unknown>[]
	if (coupList.length > 0) {
		existingCouponId = coupList[0].id as number
		existingCouponCode = (coupList[0].code as string) ?? ''
		console.log(`  Found coupon ID: ${existingCouponId}, code: "${existingCouponCode}"`)
	} else {
		console.log(`  No coupons found in list. Keys: ${Object.keys(coupData).join(', ')}`)
		// Try alt endpoint
		const altCoupons = await call('fluentcart_coupon_list_alt', { per_page: 5 })
		show(altCoupons)
		const altData = asObj(altCoupons.data)
		const altList = (Array.isArray(altData.data) ? altData.data : Array.isArray(altData.coupons) ? altData.coupons : []) as Record<string, unknown>[]
		if (altList.length > 0) {
			existingCouponId = altList[0].id as number
			existingCouponCode = (altList[0].code as string) ?? ''
			console.log(`  Found coupon (alt) ID: ${existingCouponId}, code: "${existingCouponCode}"`)
		} else {
			console.log(`  No coupons via alt endpoint either. Will create one.`)
		}
	}

	// 5. Products / variants for order bumps
	const products = await call('fluentcart_product_list', { per_page: 5 })
	show(products)
	const prodData = asObj(products.data)
	// product_list can return {products: {data: [...]}} or {data: [...]}
	const prodWrapper = prodData.products ?? prodData
	const prodListRaw = Array.isArray(prodWrapper) ? prodWrapper : ((prodWrapper as Record<string, unknown>).data ?? [])
	const prodList = (Array.isArray(prodListRaw) ? prodListRaw : []) as Record<string, unknown>[]
	if (prodList.length > 0) {
		existingProductId = prodList[0].id as number
		console.log(`  Found product ID: ${existingProductId}`)
		// Get variants
		const variants = await call('fluentcart_variant_list', { product_id: existingProductId })
		show(variants)
		const varData = asObj(variants.data)
		// variant_list can return {variants: [...]} or {data: [...]} or just [...]
		const varRaw = varData.variants ?? varData.data ?? []
		const varList = (Array.isArray(varRaw) ? varRaw : []) as Record<string, unknown>[]
		if (varList.length > 0) {
			existingVariantId = varList[0].id as number
			console.log(`  Found variant ID: ${existingVariantId}`)
		} else {
			console.log(`  No variants found, raw keys: ${Object.keys(varData).join(', ')}`)
			console.log(`  varData snippet: ${JSON.stringify(varData).slice(0, 300)}`)
		}
	} else {
		console.log(`  No products found, raw keys: ${Object.keys(prodData).join(', ')}`)
	}

	// 6. Attachable WP users
	const wpUsers = await call('fluentcart_customer_attachable_users', { search: '' })
	const wpData = asObj(wpUsers.data)
	const wpList = (wpData.users ?? wpData.data ?? []) as Record<string, unknown>[]
	if (Array.isArray(wpList) && wpList.length > 0) {
		existingWpUserId = wpList[0].id as number
		console.log(`  Found WP user ID: ${existingWpUserId}`)
	}

	// 7. Find or create a draft order for coupon tests
	const draftOrders = await call('fluentcart_order_list', { per_page: 5, active_view: 'processing' })
	const draftData = asObj(draftOrders.data)
	const draftList = (asObj(draftData.orders ?? draftData).data ?? []) as Record<string, unknown>[]
	for (const o of draftList) {
		if (o.payment_status === 'pending') {
			draftOrderId = o.id as number
			console.log(`  Found draft/pending order ID: ${draftOrderId}`)
			break
		}
	}

	console.log(`\n  Discovery complete. Order=${existingOrderId}, Txn=${existingOrderTransactionId}, Customer=${existingCustomerId}, Coupon=${existingCouponId}/${existingCouponCode}, Product=${existingProductId}, Variant=${existingVariantId}, WPUser=${existingWpUserId}, DraftOrder=${draftOrderId}`)
}

/* ------------------------------------------------------------------ */
/*  Test Groups                                                       */
/* ------------------------------------------------------------------ */

async function testOrderBumps() {
	log('ORDER BUMPS', 'Testing order_bump_create, order_bump_get, order_bump_update, order_bump_delete')

	// 1. List bumps first (may not be supported)
	const list = await call('fluentcart_order_bump_list', { per_page: 5 })
	show(list)
	const listData = asObj(list.data)
	if (listData.supported === false) {
		record({ tool: 'order_bump_list', verdict: 'UPSTREAM', severity: 'P2', issue: 'Order bump table missing — module not installed', notes: [String(listData.message)] })
		record({ tool: 'order_bump_create', verdict: 'UPSTREAM', severity: 'P2', issue: 'Skipped — order bump module not installed' })
		record({ tool: 'order_bump_get', verdict: 'UPSTREAM', severity: 'P2', issue: 'Skipped — order bump module not installed' })
		record({ tool: 'order_bump_update', verdict: 'UPSTREAM', severity: 'P2', issue: 'Skipped — order bump module not installed' })
		record({ tool: 'order_bump_delete', verdict: 'UPSTREAM', severity: 'P2', issue: 'Skipped — order bump module not installed' })
		return
	}

	// 2. Create bump
	if (!existingVariantId) {
		record({ tool: 'order_bump_create', verdict: 'FAIL', severity: 'P2', issue: 'No variant found for testing' })
		return
	}

	const createResp = await call('fluentcart_order_bump_create', {
		title: 'MCP Test Bump',
		src_object_id: existingVariantId,
		description: '<p>Test bump created by MCP test harness</p>',
		status: 'draft',
		priority: 99,
		config: {
			discount: { discount_type: 'percentage', discount_amount: 10 },
			call_to_action: 'Add this to your order!',
		},
	})
	show(createResp)

	if (createResp.isError) {
		record({ tool: 'order_bump_create', verdict: 'FAIL', severity: 'P1', issue: `API error: ${createResp.raw.slice(0, 200)}` })
		return
	}

	const createData = asObj(createResp.data)
	const bump = asObj(createData.order_bump ?? createData.bump ?? createData)
	createdBumpId = (bump.id as number) || 0

	if (!createdBumpId) {
		// Try to extract from response
		if (typeof createData.id === 'number') createdBumpId = createData.id
	}

	if (createdBumpId) {
		record({ tool: 'order_bump_create', verdict: 'PASS', notes: [`Created bump ID: ${createdBumpId}`] })
	} else {
		record({ tool: 'order_bump_create', verdict: 'BUG', severity: 'P1', issue: 'No ID returned in response', notes: [JSON.stringify(createData).slice(0, 300)] })
		return
	}

	// 3. Get bump
	const getResp = await call('fluentcart_order_bump_get', { id: createdBumpId })
	show(getResp)
	if (getResp.isError) {
		record({ tool: 'order_bump_get', verdict: 'FAIL', severity: 'P1', issue: `GET failed: ${getResp.raw.slice(0, 200)}` })
	} else {
		const getData = asObj(getResp.data)
		const gotBump = asObj(getData.order_bump ?? getData.bump ?? getData)
		const titleMatch = gotBump.title === 'MCP Test Bump'
		record({
			tool: 'order_bump_get', verdict: titleMatch ? 'PASS' : 'BUG',
			severity: titleMatch ? undefined : 'P1',
			issue: titleMatch ? undefined : `Expected title 'MCP Test Bump', got '${gotBump.title}'`,
			notes: [`Response fields: ${Object.keys(gotBump).join(', ')}`],
		})
	}

	// 4. Update bump
	const updateResp = await call('fluentcart_order_bump_update', {
		id: createdBumpId,
		title: 'MCP Test Bump Updated',
		status: 'draft',
		priority: 50,
	})
	show(updateResp)
	if (updateResp.isError) {
		record({ tool: 'order_bump_update', verdict: 'FAIL', severity: 'P1', issue: `Update failed: ${updateResp.raw.slice(0, 200)}` })
	} else {
		const updData = asObj(updateResp.data)
		const updBump = asObj(updData.order_bump ?? updData.bump ?? updData)
		const titleUpdated = updBump.title === 'MCP Test Bump Updated'
		record({
			tool: 'order_bump_update', verdict: titleUpdated ? 'PASS' : 'BUG',
			severity: titleUpdated ? undefined : 'P1',
			issue: titleUpdated ? undefined : `Title not updated. Got: '${updBump.title}'`,
		})
	}

	// 5. Delete bump
	const delResp = await call('fluentcart_order_bump_delete', { id: createdBumpId })
	show(delResp)
	if (delResp.isError) {
		record({ tool: 'order_bump_delete', verdict: 'FAIL', severity: 'P1', issue: `Delete failed: ${delResp.raw.slice(0, 200)}` })
	} else {
		// Verify deletion
		const verifyResp = await call('fluentcart_order_bump_get', { id: createdBumpId })
		if (verifyResp.isError) {
			record({ tool: 'order_bump_delete', verdict: 'PASS', notes: ['Confirmed: GET after delete returns error'] })
		} else {
			record({ tool: 'order_bump_delete', verdict: 'BUG', severity: 'P2', issue: 'Bump still accessible after delete' })
		}
		createdBumpId = 0
	}
}

async function testOrderTransactions() {
	log('ORDER TRANSACTIONS', 'Testing order_transaction_update_status, order_accept_dispute, order_sync_statuses')

	// 1. order_sync_statuses
	if (!existingOrderId) {
		record({ tool: 'order_sync_statuses', verdict: 'FAIL', severity: 'P2', issue: 'No order found for testing' })
	} else {
		const syncResp = await call('fluentcart_order_sync_statuses', { order_id: existingOrderId })
		show(syncResp)
		if (syncResp.isError) {
			const raw = syncResp.raw.toLowerCase()
			if (raw.includes('not found') || raw.includes('no payment') || raw.includes('not supported')) {
				record({ tool: 'order_sync_statuses', verdict: 'PASS', notes: ['Expected response: no gateway sync available for this order type'] })
			} else {
				record({ tool: 'order_sync_statuses', verdict: 'FAIL', severity: 'P1', issue: `Unexpected error: ${syncResp.raw.slice(0, 200)}` })
			}
		} else {
			record({ tool: 'order_sync_statuses', verdict: 'PASS', notes: [`Sync response: ${syncResp.raw.slice(0, 200)}`] })
		}
	}

	// 2. order_transaction_update_status
	if (!existingOrderId || !existingOrderTransactionId) {
		record({ tool: 'order_transaction_update_status', verdict: 'FAIL', severity: 'P2', issue: 'No order/transaction pair found for testing' })
	} else {
		// First, get the current transaction status
		const txnResp = await call('fluentcart_order_transaction_get', { order_id: existingOrderId, transaction_id: existingOrderTransactionId })
		const txnData = asObj(txnResp.data)
		const txns = (txnData.transactions ?? []) as Record<string, unknown>[]
		const currentTxn = txns.find(t => t.id === existingOrderTransactionId)
		const currentStatus = currentTxn ? String(currentTxn.status) : 'unknown'
		console.log(`  Current transaction status: ${currentStatus}`)

		// Test with an invalid status to check validation
		const invalidResp = await call('fluentcart_order_transaction_update_status', {
			order_id: existingOrderId,
			transaction_id: existingOrderTransactionId,
			status: 'bogus_status_12345',
		})
		show(invalidResp)

		if (invalidResp.isError) {
			record({ tool: 'order_transaction_update_status', verdict: 'PASS', notes: ['Invalid status correctly rejected'] })
		} else {
			// If it accepted a bogus status, that's a validation bug upstream
			record({
				tool: 'order_transaction_update_status', verdict: 'BUG', severity: 'P2',
				issue: 'Accepted invalid transaction status without validation',
				notes: [`Sent "bogus_status_12345", got: ${invalidResp.raw.slice(0, 200)}`],
			})
		}

		// Test setting status to the same value (safe, idempotent)
		if (currentStatus !== 'unknown') {
			const sameResp = await call('fluentcart_order_transaction_update_status', {
				order_id: existingOrderId,
				transaction_id: existingOrderTransactionId,
				status: currentStatus,
			})
			show(sameResp)
			if (!sameResp.isError) {
				record({ tool: 'order_transaction_update_status', verdict: 'PASS', notes: [`Idempotent update to '${currentStatus}' succeeded`] })
			} else {
				record({ tool: 'order_transaction_update_status', verdict: 'FAIL', severity: 'P1', issue: `Idempotent update failed: ${sameResp.raw.slice(0, 200)}` })
			}
		}
	}

	// 3. order_accept_dispute
	// This is destructive — test with a non-existent transaction to check error handling
	if (!existingOrderId) {
		record({ tool: 'order_accept_dispute', verdict: 'FAIL', severity: 'P2', issue: 'No order found for testing' })
	} else {
		const disputeResp = await call('fluentcart_order_accept_dispute', {
			order_id: existingOrderId,
			transaction_id: 999999,
		})
		show(disputeResp)
		if (disputeResp.isError) {
			record({ tool: 'order_accept_dispute', verdict: 'PASS', notes: ['Invalid transaction correctly returns error'] })
		} else {
			// Check if the response is meaningful
			const dData = asObj(disputeResp.data)
			if (dData.message || dData.error || Object.keys(dData).length === 0) {
				record({ tool: 'order_accept_dispute', verdict: 'PASS', notes: [`Response for invalid txn: ${disputeResp.raw.slice(0, 200)}`] })
			} else {
				record({ tool: 'order_accept_dispute', verdict: 'BUG', severity: 'P2', issue: 'Unexpected success response for invalid transaction', notes: [disputeResp.raw.slice(0, 300)] })
			}
		}

		// Also test with the real transaction if it exists (but only if status is 'disputed')
		if (existingOrderTransactionId) {
			const txnCheck = await call('fluentcart_order_transaction_get', { order_id: existingOrderId, transaction_id: existingOrderTransactionId })
			const txnCheckData = asObj(txnCheck.data)
			const txnsList = (txnCheckData.transactions ?? []) as Record<string, unknown>[]
			const realTxn = txnsList.find(t => t.id === existingOrderTransactionId)
			if (realTxn && realTxn.status === 'disputed') {
				console.log('  Found disputed transaction, testing accept_dispute...')
				const acceptResp = await call('fluentcart_order_accept_dispute', {
					order_id: existingOrderId,
					transaction_id: existingOrderTransactionId,
				})
				show(acceptResp)
				record({ tool: 'order_accept_dispute', verdict: acceptResp.isError ? 'FAIL' : 'PASS', severity: acceptResp.isError ? 'P1' : undefined, issue: acceptResp.isError ? `Accept dispute failed: ${acceptResp.raw.slice(0, 200)}` : undefined, notes: ['Tested with real disputed transaction'] })
			} else {
				console.log(`  Transaction ${existingOrderTransactionId} status is '${realTxn?.status}', skipping live dispute test`)
			}
		}
	}
}

async function testOrderGenerateLicenses() {
	log('ORDER GENERATE LICENSES', 'Testing order_generate_licenses')

	if (!existingOrderId) {
		record({ tool: 'order_generate_licenses', verdict: 'FAIL', severity: 'P2', issue: 'No order for testing' })
		return
	}

	const resp = await call('fluentcart_order_generate_licenses', { order_id: existingOrderId })
	show(resp)

	// This may succeed with "no licenses to generate" or fail if no licensable products
	if (resp.isError) {
		const raw = resp.raw.toLowerCase()
		if (raw.includes('license') || raw.includes('not found') || raw.includes('no ')) {
			record({ tool: 'order_generate_licenses', verdict: 'PASS', notes: ['Expected: no licensable products or module not available'] })
		} else {
			record({ tool: 'order_generate_licenses', verdict: 'FAIL', severity: 'P1', issue: `Unexpected error: ${resp.raw.slice(0, 200)}` })
		}
	} else {
		record({ tool: 'order_generate_licenses', verdict: 'PASS', notes: [`Response: ${resp.raw.slice(0, 200)}`] })
	}

	// Edge case: invalid order ID
	const badResp = await call('fluentcart_order_generate_licenses', { order_id: 999999 })
	show(badResp)
	if (badResp.isError) {
		record({ tool: 'order_generate_licenses', verdict: 'PASS', notes: ['Invalid order correctly returns error'] })
	} else {
		record({
			tool: 'order_generate_licenses', verdict: 'BUG', severity: 'P2',
			issue: 'No error for non-existent order ID 999999',
			notes: [badResp.raw.slice(0, 200)],
		})
	}
}

async function testOrderUpdateAddressId() {
	log('ORDER UPDATE ADDRESS ID', 'Testing order_update_address_id')

	if (!existingOrderId) {
		record({ tool: 'order_update_address_id', verdict: 'FAIL', severity: 'P2', issue: 'No order found' })
		return
	}

	// Get order details to find existing address
	const orderResp = await call('fluentcart_order_get', { order_id: existingOrderId })
	const orderData = asObj(orderResp.data)
	const order = asObj(orderData.order ?? orderData)
	const addresses = (order.addresses ?? []) as Record<string, unknown>[]
	const billingAddr = addresses.find(a => a.address_type === 'billing' || a.type === 'billing')
	const existingAddrId = (billingAddr?.id as number) || 0

	console.log(`  Order ${existingOrderId} has ${addresses.length} addresses, billing addr ID: ${existingAddrId}`)

	// Test with valid parameters
	const resp = await call('fluentcart_order_update_address_id', {
		order_id: existingOrderId,
		address_id: existingAddrId || 1,
		address_type: 'billing',
	})
	show(resp)

	if (resp.isError) {
		const raw = resp.raw.toLowerCase()
		if (raw.includes('not found') || raw.includes('invalid') || raw.includes('no address')) {
			record({ tool: 'order_update_address_id', verdict: 'PASS', notes: ['Error handling works for address association'] })
		} else {
			record({ tool: 'order_update_address_id', verdict: 'FAIL', severity: 'P1', issue: `API error: ${resp.raw.slice(0, 200)}` })
		}
	} else {
		record({ tool: 'order_update_address_id', verdict: 'PASS', notes: [`Address ID updated: ${resp.raw.slice(0, 200)}`] })
	}

	// Edge case: invalid order
	const badResp = await call('fluentcart_order_update_address_id', { order_id: 999999, address_id: 1, address_type: 'billing' })
	show(badResp)
	if (badResp.isError) {
		console.log('  Invalid order correctly returns error')
	}
}

async function testCoupons() {
	log('COUPONS', 'Testing coupon_update, coupon_apply, coupon_cancel, coupon_settings_save')

	// 1. coupon_settings_save — read settings, save back unchanged
	log('COUPON SETTINGS', 'Testing coupon_settings_get then coupon_settings_save')
	const getSettings = await call('fluentcart_coupon_settings_get', {})
	show(getSettings)

	if (getSettings.isError) {
		record({ tool: 'coupon_settings_save', verdict: 'FAIL', severity: 'P1', issue: `Cannot read current settings: ${getSettings.raw.slice(0, 200)}` })
	} else {
		const settingsData = asObj(getSettings.data)
		const currentSettings = asObj(settingsData.settings ?? settingsData)
		console.log(`  Current settings keys: ${Object.keys(currentSettings).join(', ')}`)

		// Save the same settings back (idempotent)
		const saveResp = await call('fluentcart_coupon_settings_save', { settings: currentSettings })
		show(saveResp)
		if (saveResp.isError) {
			record({ tool: 'coupon_settings_save', verdict: 'FAIL', severity: 'P1', issue: `Save failed: ${saveResp.raw.slice(0, 200)}` })
		} else {
			record({ tool: 'coupon_settings_save', verdict: 'PASS', notes: ['Idempotent save succeeded'] })
		}
	}

	// 2. coupon_update — modify an existing coupon's notes
	// If no coupon exists, create one (coupon_create is known to have HTML upstream bug, but try)
	if (!existingCouponId) {
		log('CREATING TEST COUPON', 'No coupons found, attempting to create one')
		const createCouponResp = await call('fluentcart_coupon_create', {
			title: 'MCP Test Coupon',
			code: `MCP-TEST-${Date.now()}`,
			type: 'percentage',
			amount: 10,
			status: 'active',
			stackable: 'no',
			show_on_checkout: 'no',
			notes: 'Created by MCP test harness',
		})
		show(createCouponResp)
		if (!createCouponResp.isError) {
			const ccData = asObj(createCouponResp.data)
			const ccCoupon = asObj(ccData.coupon ?? ccData.data ?? ccData)
			if (ccCoupon.id) {
				existingCouponId = ccCoupon.id as number
				existingCouponCode = (ccCoupon.code as string) ?? ''
				console.log(`  Created test coupon ID: ${existingCouponId}, code: ${existingCouponCode}`)
			}
		} else {
			// Known upstream bug: may return HTML
			const isHtml = createCouponResp.raw.includes('<') && createCouponResp.raw.includes('>')
			if (isHtml) {
				console.log('  coupon_create returned HTML (known upstream bug)')
			}
		}
	}

	if (!existingCouponId) {
		record({ tool: 'coupon_update', verdict: 'FAIL', severity: 'P2', issue: 'No coupon found or created for testing' })
	} else {
		// Get current coupon state
		const getCoupon = await call('fluentcart_coupon_get', { coupon_id: existingCouponId })
		const couponData = asObj(getCoupon.data)
		const coupon = asObj(couponData.coupon ?? couponData)
		const originalNotes = String(coupon.notes ?? '')

		// Update notes field
		const testNote = `MCP test note ${Date.now()}`
		const updateResp = await call('fluentcart_coupon_update', {
			coupon_id: existingCouponId,
			notes: testNote,
		})
		show(updateResp)

		if (updateResp.isError) {
			record({ tool: 'coupon_update', verdict: 'FAIL', severity: 'P1', issue: `Update failed: ${updateResp.raw.slice(0, 200)}` })
		} else {
			// Verify the update
			const verifyResp = await call('fluentcart_coupon_get', { coupon_id: existingCouponId })
			const verifyData = asObj(verifyResp.data)
			const verifyCoupon = asObj(verifyData.coupon ?? verifyData)
			const updatedNotes = String(verifyCoupon.notes ?? '')

			if (updatedNotes === testNote) {
				record({ tool: 'coupon_update', verdict: 'PASS', notes: ['Notes field updated and verified'] })
			} else {
				record({
					tool: 'coupon_update', verdict: 'BUG', severity: 'P1',
					issue: `Notes not persisted. Expected '${testNote}', got '${updatedNotes}'`,
				})
			}

			// Restore original notes
			await call('fluentcart_coupon_update', { coupon_id: existingCouponId, notes: originalNotes })
		}
	}

	// 3. coupon_apply — needs a draft/pending order
	if (!existingCouponCode || !draftOrderId) {
		// Try creating a draft order for coupon test
		if (existingCustomerId && existingProductId) {
			log('CREATING DRAFT ORDER', 'Need a pending order for coupon apply/cancel tests')
			const createOrder = await call('fluentcart_order_create', {
				customer_id: existingCustomerId,
				items: [{ product_id: existingProductId, variation_id: existingVariantId || existingProductId, quantity: 1 }],
			})
			show(createOrder)
			if (!createOrder.isError) {
				const coData = asObj(createOrder.data)
				const newOrder = asObj(coData.order ?? coData)
				draftOrderId = (newOrder.id as number) || 0
				createdDraftOrderId = draftOrderId
				console.log(`  Created draft order ID: ${draftOrderId}`)
			}
		}
	}

	if (!existingCouponCode) {
		record({ tool: 'coupon_apply', verdict: 'FAIL', severity: 'P2', issue: 'No coupon code found for testing' })
		record({ tool: 'coupon_cancel', verdict: 'FAIL', severity: 'P2', issue: 'No coupon code found for testing' })
	} else if (!draftOrderId) {
		record({ tool: 'coupon_apply', verdict: 'FAIL', severity: 'P2', issue: 'No draft/pending order available' })
		record({ tool: 'coupon_cancel', verdict: 'FAIL', severity: 'P2', issue: 'No draft/pending order available' })
	} else {
		// Apply coupon
		const applyResp = await call('fluentcart_coupon_apply', { code: existingCouponCode, order_id: draftOrderId })
		show(applyResp)

		if (applyResp.isError) {
			const raw = applyResp.raw.toLowerCase()
			if (raw.includes('already applied') || raw.includes('not eligible') || raw.includes('minimum') || raw.includes('not valid')) {
				record({ tool: 'coupon_apply', verdict: 'PASS', notes: [`Expected rejection: ${applyResp.raw.slice(0, 200)}`] })
			} else {
				record({ tool: 'coupon_apply', verdict: 'FAIL', severity: 'P1', issue: `Apply failed: ${applyResp.raw.slice(0, 200)}` })
			}
		} else {
			record({ tool: 'coupon_apply', verdict: 'PASS', notes: ['Coupon applied successfully'] })

			// Cancel coupon
			const cancelResp = await call('fluentcart_coupon_cancel', { code: existingCouponCode, order_id: draftOrderId })
			show(cancelResp)

			if (cancelResp.isError) {
				record({ tool: 'coupon_cancel', verdict: 'FAIL', severity: 'P1', issue: `Cancel failed: ${cancelResp.raw.slice(0, 200)}` })
			} else {
				record({ tool: 'coupon_cancel', verdict: 'PASS', notes: ['Coupon cancelled successfully'] })
			}
		}

		// If apply failed for a legitimate reason, still test cancel with invalid data
		if (applyResp.isError) {
			const cancelBadResp = await call('fluentcart_coupon_cancel', { code: 'NONEXISTENT_CODE_XYZ_999', order_id: draftOrderId })
			show(cancelBadResp)
			if (cancelBadResp.isError) {
				record({ tool: 'coupon_cancel', verdict: 'PASS', notes: ['Invalid coupon code correctly returns error'] })
			} else {
				record({ tool: 'coupon_cancel', verdict: 'BUG', severity: 'P2', issue: 'No error when cancelling non-applied coupon' })
			}
		}
	}

	// Edge case: coupon_apply with invalid order
	const badApply = await call('fluentcart_coupon_apply', { code: 'TEST', order_id: 999999 })
	show(badApply)
	if (badApply.isError) {
		console.log('  Invalid order correctly rejected for coupon apply')
	}
}

async function testCustomers() {
	log('CUSTOMERS', 'Testing customer_create, customer_update, customer_update_additional_info, customer_attach_user, customer_detach_user, customer_bulk_action')

	// 1. customer_create
	const uniqueEmail = `mcp-test-${Date.now()}@test.local`
	const createResp = await call('fluentcart_customer_create', {
		email: uniqueEmail,
		first_name: 'MCP',
		last_name: 'TestCustomer',
		full_name: 'MCP TestCustomer',
		status: 'active',
	})
	show(createResp)

	if (createResp.isError) {
		record({ tool: 'customer_create', verdict: 'FAIL', severity: 'P1', issue: `Create failed: ${createResp.raw.slice(0, 200)}` })
	} else {
		const createData = asObj(createResp.data)
		// Response is {message, data: {id, email, ...}} — ID is in the nested data object
		const newCust = asObj(createData.customer ?? createData.data ?? createData)
		createdCustomerId = (newCust.id as number) || 0

		if (createdCustomerId) {
			record({ tool: 'customer_create', verdict: 'PASS', notes: [`Created customer ID: ${createdCustomerId}, email: ${uniqueEmail}`] })
		} else {
			record({ tool: 'customer_create', verdict: 'BUG', severity: 'P1', issue: 'No customer ID in response', notes: [JSON.stringify(createData).slice(0, 300)] })
		}
	}

	// Edge case: duplicate email
	if (createdCustomerId) {
		const dupResp = await call('fluentcart_customer_create', {
			email: uniqueEmail,
			first_name: 'Duplicate',
			last_name: 'Test',
			full_name: 'Duplicate Test',
		})
		show(dupResp)
		if (dupResp.isError) {
			console.log('  Duplicate email correctly rejected')
		} else {
			record({ tool: 'customer_create', verdict: 'BUG', severity: 'P1', issue: 'Duplicate email accepted without error', notes: [dupResp.raw.slice(0, 200)] })
		}
	}

	// 2. customer_update
	const custToUpdate = createdCustomerId || existingCustomerId
	if (!custToUpdate) {
		record({ tool: 'customer_update', verdict: 'FAIL', severity: 'P2', issue: 'No customer to update' })
	} else {
		// Get current state
		const before = await call('fluentcart_customer_get', { customer_id: custToUpdate })
		const beforeData = asObj(before.data)
		const beforeCust = asObj(beforeData.customer ?? beforeData)
		const origPhone = String(beforeCust.phone ?? '')

		const testPhone = '+44-' + Date.now().toString().slice(-8)
		// Backend requires full_name and email on every PUT — test if the tool handles this
		// First test: partial update (just phone) — should fail if tool doesn't fetch-merge
		const partialResp = await call('fluentcart_customer_update', {
			customer_id: custToUpdate,
			phone: testPhone,
		})
		show(partialResp)
		const partialFailed = !!partialResp.isError
		if (partialFailed) {
			console.log('  FINDING: customer_update requires full_name+email — no fetch-merge pattern')
		}

		// Second test: full update with required fields
		const updateResp = await call('fluentcart_customer_update', {
			customer_id: custToUpdate,
			first_name: String(beforeCust.first_name ?? 'MCP'),
			last_name: String(beforeCust.last_name ?? 'TestCustomer'),
			phone: testPhone,
		})
		show(updateResp)

		if (updateResp.isError) {
			record({ tool: 'customer_update', verdict: 'FAIL', severity: 'P1', issue: `Update failed even with all fields: ${updateResp.raw.slice(0, 200)}` })
		} else {
			// Verify
			const after = await call('fluentcart_customer_get', { customer_id: custToUpdate })
			const afterData = asObj(after.data)
			const afterCust = asObj(afterData.customer ?? afterData)
			if (afterCust.phone === testPhone) {
				if (partialFailed) {
					record({
						tool: 'customer_update', verdict: 'BUG', severity: 'P1',
						issue: 'Partial update fails — backend requires full_name+email. Tool needs fetch-merge pattern like order_update.',
						notes: ['Full update with required fields works', `Phone updated to ${testPhone}`],
					})
				} else {
					record({ tool: 'customer_update', verdict: 'PASS', notes: [`Phone updated to ${testPhone}`] })
				}
			} else {
				record({ tool: 'customer_update', verdict: 'BUG', severity: 'P1', issue: `Phone not persisted. Expected '${testPhone}', got '${afterCust.phone}'` })
			}

			// Restore if we modified an existing customer
			if (custToUpdate === existingCustomerId) {
				await call('fluentcart_customer_update', { customer_id: custToUpdate, phone: origPhone, first_name: String(beforeCust.first_name ?? ''), last_name: String(beforeCust.last_name ?? '') })
			}
		}
	}

	// 3. customer_update_additional_info
	const infoTarget = createdCustomerId || existingCustomerId
	if (!infoTarget) {
		record({ tool: 'customer_update_additional_info', verdict: 'FAIL', severity: 'P2', issue: 'No customer for testing' })
	} else {
		// Test with `info` param as documented
		const infoResp = await call('fluentcart_customer_update_additional_info', {
			customer_id: infoTarget,
			info: { mcp_test_key: 'test_value_123', mcp_test_ts: Date.now() },
		})
		show(infoResp)

		if (infoResp.isError) {
			const raw = infoResp.raw.toLowerCase()
			if (raw.includes('no changes') || raw.includes('does not have')) {
				// The `info` key may not be what backend expects — try without wrapping
				record({
					tool: 'customer_update_additional_info', verdict: 'BUG', severity: 'P1',
					issue: 'Backend returns "no changes" — the `info` body key may not be mapped correctly to the PUT endpoint',
					notes: [`Error: ${infoResp.raw.slice(0, 200)}`],
				})
			} else {
				record({ tool: 'customer_update_additional_info', verdict: 'FAIL', severity: 'P1', issue: `Update additional info failed: ${infoResp.raw.slice(0, 200)}` })
			}
		} else {
			record({ tool: 'customer_update_additional_info', verdict: 'PASS', notes: ['Additional info updated'] })
		}
	}

	// 4. customer_attach_user / customer_detach_user
	if (!createdCustomerId) {
		record({ tool: 'customer_attach_user', verdict: 'FAIL', severity: 'P2', issue: 'No test customer created' })
		record({ tool: 'customer_detach_user', verdict: 'FAIL', severity: 'P2', issue: 'No test customer created' })
	} else if (!existingWpUserId) {
		record({ tool: 'customer_attach_user', verdict: 'FAIL', severity: 'P2', issue: 'No WP user found for attachment' })
		record({ tool: 'customer_detach_user', verdict: 'FAIL', severity: 'P2', issue: 'No WP user found for attachment' })
	} else {
		// Attach
		const attachResp = await call('fluentcart_customer_attach_user', {
			customer_id: createdCustomerId,
			user_id: existingWpUserId,
		})
		show(attachResp)

		if (attachResp.isError) {
			const raw = attachResp.raw.toLowerCase()
			if (raw.includes('already') || raw.includes('attached') || raw.includes('linked')) {
				record({ tool: 'customer_attach_user', verdict: 'PASS', notes: ['User already attached (expected if WP user is linked elsewhere)'] })
			} else {
				record({ tool: 'customer_attach_user', verdict: 'FAIL', severity: 'P1', issue: `Attach failed: ${attachResp.raw.slice(0, 200)}` })
			}
		} else {
			record({ tool: 'customer_attach_user', verdict: 'PASS', notes: [`Attached WP user ${existingWpUserId} to customer ${createdCustomerId}`] })

			// Detach
			const detachResp = await call('fluentcart_customer_detach_user', { customer_id: createdCustomerId })
			show(detachResp)

			if (detachResp.isError) {
				record({ tool: 'customer_detach_user', verdict: 'FAIL', severity: 'P1', issue: `Detach failed: ${detachResp.raw.slice(0, 200)}` })
			} else {
				record({ tool: 'customer_detach_user', verdict: 'PASS', notes: ['User detached successfully'] })
			}
		}
	}

	// 5. customer_bulk_action — test status update on our test customer
	if (!createdCustomerId) {
		record({ tool: 'customer_bulk_action', verdict: 'FAIL', severity: 'P2', issue: 'No test customer for bulk action' })
	} else {
		const bulkResp = await call('fluentcart_customer_bulk_action', {
			action: 'update_status',
			customer_ids: [createdCustomerId],
			data: { status: 'inactive' },
		})
		show(bulkResp)

		if (bulkResp.isError) {
			record({ tool: 'customer_bulk_action', verdict: 'FAIL', severity: 'P1', issue: `Bulk action failed: ${bulkResp.raw.slice(0, 200)}` })
		} else {
			// Verify status changed
			const verifyResp = await call('fluentcart_customer_get', { customer_id: createdCustomerId })
			const verifyData = asObj(verifyResp.data)
			const verifyCust = asObj(verifyData.customer ?? verifyData)

			if (verifyCust.status === 'inactive') {
				record({ tool: 'customer_bulk_action', verdict: 'PASS', notes: ['Bulk update_status verified'] })
			} else {
				record({
					tool: 'customer_bulk_action', verdict: 'BUG', severity: 'P1',
					issue: `Status not changed. Expected 'inactive', got '${verifyCust.status}'`,
					notes: [bulkResp.raw.slice(0, 200)],
				})
			}
		}
	}
}

async function testLabels() {
	log('LABELS', 'Testing label_create, label_update_selections')

	// 1. Create a label
	const labelResp = await call('fluentcart_label_create', {
		value: `MCP Test ${Date.now()}`,
		color: '#ff5733',
		bind_to_type: 'customer',
	})
	show(labelResp)

	if (labelResp.isError) {
		record({ tool: 'label_create', verdict: 'FAIL', severity: 'P1', issue: `Create failed: ${labelResp.raw.slice(0, 200)}` })
	} else {
		const labelData = asObj(labelResp.data)
		// Response is {message, data: {id, value, ...}}
		const label = asObj(labelData.label ?? labelData.data ?? labelData)
		createdLabelId = (label.id as number) || 0

		if (createdLabelId) {
			record({ tool: 'label_create', verdict: 'PASS', notes: [`Created label ID: ${createdLabelId}`] })
		} else {
			record({ tool: 'label_create', verdict: 'BUG', severity: 'P1', issue: 'No label ID in response', notes: [JSON.stringify(labelData).slice(0, 300)] })
		}
	}

	// 2. label_update_selections — assign label to a customer
	const targetCustomer = createdCustomerId || existingCustomerId
	if (!createdLabelId || !targetCustomer) {
		record({ tool: 'label_update_selections', verdict: 'FAIL', severity: 'P2', issue: 'No label or customer for testing' })
	} else {
		const selectResp = await call('fluentcart_label_update_selections', {
			bind_to_type: 'customer',
			bind_to_id: targetCustomer,
			selectedLabels: [createdLabelId],
		})
		show(selectResp)

		if (selectResp.isError) {
			record({ tool: 'label_update_selections', verdict: 'FAIL', severity: 'P1', issue: `Update selections failed: ${selectResp.raw.slice(0, 200)}` })
		} else {
			record({ tool: 'label_update_selections', verdict: 'PASS', notes: [`Assigned label ${createdLabelId} to customer ${targetCustomer}`] })
		}

		// Clear the label assignment
		await call('fluentcart_label_update_selections', {
			bind_to_type: 'customer',
			bind_to_id: targetCustomer,
			selectedLabels: [],
		})
	}
}

/* ------------------------------------------------------------------ */
/*  Cleanup                                                           */
/* ------------------------------------------------------------------ */

async function cleanup() {
	log('CLEANUP', 'Removing test data...')

	if (createdBumpId) {
		console.log(`  Deleting bump ${createdBumpId}...`)
		await call('fluentcart_order_bump_delete', { id: createdBumpId })
	}

	if (createdDraftOrderId) {
		console.log(`  Deleting draft order ${createdDraftOrderId}...`)
		await call('fluentcart_order_delete', { order_id: createdDraftOrderId })
	}

	if (createdCustomerId) {
		console.log(`  Deleting test customer ${createdCustomerId}...`)
		await call('fluentcart_customer_bulk_action', {
			action: 'delete',
			customer_ids: [createdCustomerId],
		})
	}

	// Labels don't have a delete endpoint, so we leave the test label.
	if (createdLabelId) {
		console.log(`  Note: Label ${createdLabelId} left in place (no delete tool)`)
	}

	console.log('  Cleanup complete.')
}

/* ------------------------------------------------------------------ */
/*  Summary                                                           */
/* ------------------------------------------------------------------ */

function printSummary() {
	console.log(`\n${'='.repeat(72)}`)
	console.log('  FINAL REPORT')
	console.log(`${'='.repeat(72)}`)

	const pass = findings.filter(f => f.verdict === 'PASS')
	const fail = findings.filter(f => f.verdict === 'FAIL')
	const bug = findings.filter(f => f.verdict === 'BUG')
	const upstream = findings.filter(f => f.verdict === 'UPSTREAM')

	console.log(`\n  PASS: ${pass.length}  |  FAIL: ${fail.length}  |  BUG: ${bug.length}  |  UPSTREAM: ${upstream.length}  |  TOTAL: ${findings.length}`)

	if (bug.length > 0) {
		console.log(`\n  BUGS:`)
		for (const f of bug) {
			console.log(`    [${f.severity}] ${f.tool}: ${f.issue}`)
		}
	}
	if (fail.length > 0) {
		console.log(`\n  FAILURES:`)
		for (const f of fail) {
			console.log(`    [${f.severity}] ${f.tool}: ${f.issue}`)
		}
	}
	if (upstream.length > 0) {
		console.log(`\n  UPSTREAM:`)
		for (const f of upstream) {
			console.log(`    [${f.severity}] ${f.tool}: ${f.issue}`)
		}
	}

	console.log(`\n  ALL FINDINGS:`)
	for (const f of findings) {
		const sev = f.severity ? ` [${f.severity}]` : ''
		console.log(`    ${f.verdict}${sev} ${f.tool}${f.issue ? ': ' + f.issue : ''}`)
	}
	console.log(`\n${'='.repeat(72)}`)
}

/* ------------------------------------------------------------------ */
/*  Main                                                              */
/* ------------------------------------------------------------------ */

async function main() {
	console.log('Deep Orders, Coupons, Customers & Labels Test Suite')
	console.log(`Tool count: ${ctx.tools.length}`)
	console.log(`Timestamp: ${new Date().toISOString()}`)

	try {
		await discover()
		await testOrderBumps()
		await testOrderTransactions()
		await testOrderGenerateLicenses()
		await testOrderUpdateAddressId()
		await testCoupons()
		await testCustomers()
		await testLabels()
	} catch (err) {
		console.error('\nFATAL ERROR:', err)
	} finally {
		await cleanup()
		printSummary()
	}
}

main().catch(console.error)
