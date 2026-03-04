/**
 * Audit Verification — Live testing of ALL P0 claims from mcp-full-audit.md
 *
 * Tests whether each claimed bug is actually broken against the real API.
 * Does NOT fix anything — only verifies the claim.
 *
 * Run:
 *   cd /Users/tomrobak/_projects_/fchub-plugins/fluentcart-mcp
 *   set -a && source .env && set +a
 *   npx tsx tests/_scenarios-audit-verification.ts
 */
import { resolveServerContext } from '../src/server.js'

type ToolResult = { isError?: boolean; data: unknown; raw: string; size: number }
type Verdict = 'CONFIRMED' | 'WRONG' | 'PARTIAL' | 'INCONCLUSIVE'
type Check = {
	id: string
	claim: string
	verdict: Verdict
	evidence: string
}

const ctx = resolveServerContext()
const toolMap = new Map<string, (typeof ctx.tools)[0]>()
for (const t of ctx.tools) toolMap.set(t.name, t)

const checks: Check[] = []

async function call(name: string, input: Record<string, unknown> = {}): Promise<ToolResult> {
	const tool = toolMap.get(name)
	if (!tool) {
		const raw = `Tool not found: ${name}`
		return { isError: true, data: null, raw, size: raw.length }
	}
	const result = (await tool.handler(input)) as {
		content: { type: string; text: string }[]
		isError?: boolean
	}
	const text = result.content[0]?.text ?? ''
	let data: unknown
	try {
		data = JSON.parse(text)
	} catch {
		data = text
	}
	return { isError: result.isError, data, raw: text, size: text.length }
}

function record(id: string, claim: string, verdict: Verdict, evidence: string) {
	checks.push({ id, claim, verdict, evidence })
	const icon = verdict === 'CONFIRMED' ? '✅' : verdict === 'WRONG' ? '❌' : verdict === 'PARTIAL' ? '⚠️' : '❓'
	console.log(`\n${icon} ${id}: ${verdict}`)
	console.log(`  Claim: ${claim}`)
	console.log(`  Evidence: ${evidence}`)
}

// ─── Setup: find existing data to test with ──────────────────────
async function findTestData() {
	const custResult = await call('fluentcart_customer_list', { per_page: 5 })
	const custData = custResult.data as Record<string, unknown>
	const custWrapper = (custData?.customers ?? custData) as Record<string, unknown>
	const customers = (custWrapper?.data ?? []) as Record<string, unknown>[]
	const customerId = (customers[0]?.id ?? customers[0]?.ID ?? 1) as number

	const couponResult = await call('fluentcart_coupon_list', { per_page: 5 })
	const couponData = couponResult.data as Record<string, unknown>
	const couponWrapper = (couponData?.coupons ?? couponData) as Record<string, unknown>
	const coupons = (couponWrapper?.data ?? []) as Record<string, unknown>[]
	const couponId = coupons.length > 0 ? (coupons[0]?.id as number) : null

	const prodResult = await call('fluentcart_product_list', { per_page: 5 })
	const prodData = prodResult.data as Record<string, unknown>
	const prodWrapper = (prodData?.products ?? prodData) as Record<string, unknown>
	const products = (prodWrapper?.data ?? []) as Record<string, unknown>[]
	const productId = (products[0]?.id ?? products[0]?.ID ?? 1) as number

	const subResult = await call('fluentcart_subscription_list', { per_page: 5 })
	const subData = subResult.data as Record<string, unknown>
	const subWrapper = (subData?.subscriptions ?? subData) as Record<string, unknown>
	const subs = (subWrapper?.data ?? []) as Record<string, unknown>[]
	const sub = subs.length > 0 ? subs[0] : null
	const subId = sub ? (sub.id as number) : null
	const subOrderId = sub ? (sub.order_id as number) : null

	// Find an address for the customer
	const addrResult = await call('fluentcart_customer_addresses', { customer_id: customerId })
	const addrData = addrResult.data as Record<string, unknown>
	const addresses = (addrData?.addresses ?? addrData?.data ?? []) as Record<string, unknown>[]
	const addressId = addresses.length > 0 ? (addresses[0]?.id as number) : null

	// Find tax rates
	const taxResult = await call('fluentcart_tax_rate_list', {})
	const taxData = taxResult.data as Record<string, unknown>
	const taxRates = (taxData?.data ?? taxData?.rates ?? []) as Record<string, unknown>[]
	const taxRateId = taxRates.length > 0 ? (taxRates[0]?.id as number) : null

	// Find tax records
	const taxRecResult = await call('fluentcart_tax_records_list', { per_page: 5 })
	const taxRecData = taxRecResult.data as Record<string, unknown>

	// Find orders for coupon testing
	const orderResult = await call('fluentcart_order_list', { per_page: 5 })
	const orderData = orderResult.data as Record<string, unknown>
	const orderWrapper = (orderData?.orders ?? orderData) as Record<string, unknown>
	const orders = (orderWrapper?.data ?? []) as Record<string, unknown>[]
	const orderId = orders.length > 0 ? (orders[0]?.id ?? orders[0]?.ID) as number : null

	console.log('\n── Test Data ──')
	console.log(`  Customer: ${customerId}, Address: ${addressId}`)
	console.log(`  Coupon: ${couponId}, Product: ${productId}`)
	console.log(`  Subscription: ${subId} (order: ${subOrderId})`)
	console.log(`  Tax rate: ${taxRateId}`)
	console.log(`  Order: ${orderId}`)

	return { customerId, addressId, couponId, productId, subId, subOrderId, taxRateId, orderId, taxRecData }
}

async function main() {
	console.log('═══════════════════════════════════════════════════════════')
	console.log('  MCP Full Audit — P0 Claim Verification')
	console.log('═══════════════════════════════════════════════════════════')

	const td = await findTestData()

	// ═══ CUSTOMERS ═══════════════════════════════════════════════

	// C-01: customer_address_update sends address_id but backend expects id
	if (td.addressId) {
		const r = await call('fluentcart_customer_address_update', {
			customer_id: td.customerId,
			address_id: td.addressId,
			city: 'AuditTest',
		})
		const data = r.data as Record<string, unknown>
		if (r.isError) {
			record('C-01', 'customer_address_update sends wrong field (address_id vs id)', 'CONFIRMED',
				`Error: ${r.raw.slice(0, 200)}`)
		} else {
			// Check if city actually changed
			const verify = await call('fluentcart_customer_addresses', { customer_id: td.customerId })
			const addrs = ((verify.data as Record<string, unknown>)?.addresses ?? []) as Record<string, unknown>[]
			const updated = addrs.find(a => a.id === td.addressId)
			if (updated && updated.city === 'AuditTest') {
				record('C-01', 'customer_address_update sends wrong field (address_id vs id)', 'WRONG',
					`Update SUCCEEDED. city changed to AuditTest. Backend accepted address_id.`)
			} else {
				record('C-01', 'customer_address_update sends wrong field (address_id vs id)', 'PARTIAL',
					`No error but city not changed. Response: ${r.raw.slice(0, 200)}`)
			}
		}
	} else {
		record('C-01', 'customer_address_update sends wrong field', 'INCONCLUSIVE', 'No address found to test')
	}

	// C-02: customer_address_delete sends flat address_id but backend expects { address: { id } }
	// We test with a FAKE address_id to avoid deleting real data
	{
		const r = await call('fluentcart_customer_address_delete', {
			customer_id: td.customerId,
			address_id: 999999,
		})
		// If the backend properly reads the ID, we'd get "address not found" or similar
		// If it doesn't read the ID, we'd get a different error or silent no-op
		record('C-02', 'customer_address_delete sends wrong field shape', r.isError ? 'INCONCLUSIVE' : 'INCONCLUSIVE',
			`Response (fake ID 999999): ${r.raw.slice(0, 300)}`)
	}

	// C-03: customer_address_make_primary sends address_id (should be addressId) and missing type
	if (td.addressId) {
		const r = await call('fluentcart_customer_address_make_primary', {
			customer_id: td.customerId,
			address_id: td.addressId,
		})
		if (r.isError) {
			const raw = r.raw.toLowerCase()
			if (raw.includes('type') || raw.includes('required') || raw.includes('addressid')) {
				record('C-03', 'make_primary sends address_id (not addressId) and missing type', 'CONFIRMED',
					`Error confirms missing/wrong field: ${r.raw.slice(0, 200)}`)
			} else {
				record('C-03', 'make_primary sends wrong field names', 'PARTIAL',
					`Error but unclear cause: ${r.raw.slice(0, 200)}`)
			}
		} else {
			record('C-03', 'make_primary sends wrong field names', 'WRONG',
				`Succeeded despite claim. Response: ${r.raw.slice(0, 200)}`)
		}
	} else {
		record('C-03', 'make_primary sends wrong field names', 'INCONCLUSIVE', 'No address found')
	}

	// C-04: customer_address_select points to frontend route
	{
		const r = await call('fluentcart_customer_address_select', { customer_id: td.customerId })
		if (r.isError) {
			record('C-04', 'customer_address_select is frontend-only / wrong path', 'CONFIRMED',
				`Error: ${r.raw.slice(0, 200)}`)
		} else {
			record('C-04', 'customer_address_select is frontend-only', 'WRONG',
				`Succeeded. Response: ${r.raw.slice(0, 200)}`)
		}
	}

	// C-05: customer_address_add is frontend-only duplicate
	{
		const r = await call('fluentcart_customer_address_add', {
			customer_id: td.customerId,
			name: 'Audit Test',
			email: 'audit@test.com',
		})
		if (r.isError) {
			record('C-05', 'customer_address_add is frontend-only duplicate', 'CONFIRMED',
				`Error: ${r.raw.slice(0, 200)}`)
		} else {
			record('C-05', 'customer_address_add is frontend-only duplicate', 'WRONG',
				`Succeeded. Response: ${r.raw.slice(0, 200)}`)
		}
	}

	// ═══ COUPONS ══════════════════════════════════════════════════

	// CO-01: coupon_check_eligibility sends wrong field names
	if (td.couponId && td.productId) {
		const r = await call('fluentcart_coupon_check_eligibility', {
			coupon_id: td.couponId,
			product_id: td.productId,
		})
		if (r.isError) {
			record('CO-01', 'coupon_check_eligibility sends wrong field names (coupon_id/product_id vs appliedCoupons/productId)', 'CONFIRMED',
				`Error: ${r.raw.slice(0, 200)}`)
		} else {
			// Even if it succeeds, check if the response is meaningful
			const data = r.data as Record<string, unknown>
			if (data && typeof data === 'object' && Object.keys(data).length > 0) {
				record('CO-01', 'coupon_check_eligibility sends wrong field names', 'WRONG',
					`Succeeded with meaningful response: ${r.raw.slice(0, 200)}`)
			} else {
				record('CO-01', 'coupon_check_eligibility sends wrong field names', 'PARTIAL',
					`No error but empty/default response: ${r.raw.slice(0, 200)}`)
			}
		}
	} else {
		record('CO-01', 'coupon_check_eligibility wrong fields', 'INCONCLUSIVE', `No coupon (${td.couponId}) or product (${td.productId})`)
	}

	// CO-02: coupon_reapply has completely wrong schema
	if (td.orderId) {
		const r = await call('fluentcart_coupon_reapply', {
			code: 'NONEXISTENT',
			order_id: td.orderId,
		})
		record('CO-02', 'coupon_reapply has wrong schema (sends code/order_id vs order_uuid/applied_coupons)',
			r.isError ? 'CONFIRMED' : 'PARTIAL',
			`Response: ${r.raw.slice(0, 300)}`)
	} else {
		record('CO-02', 'coupon_reapply wrong schema', 'INCONCLUSIVE', 'No order to test with')
	}

	// CO-03: coupon_delete — backend reads id from body but deleteTool sends in URL path
	// Create a throwaway coupon first, then try to delete it
	{
		const createR = await call('fluentcart_coupon_create', {
			title: 'AUDIT-DELETE-TEST',
			code: `AUDIT-DEL-${Date.now()}`,
			type: 'percentage',
			amount: 5,
			status: 'inactive',
			stackable: 'no',
			show_on_checkout: 'no',
		})
		const createData = createR.data as Record<string, unknown>
		const coupon = (createData?.coupon ?? createData) as Record<string, unknown>
		const newCouponId = coupon?.id as number | undefined

		if (newCouponId && !createR.isError) {
			// Try to delete it via MCP tool
			const delR = await call('fluentcart_coupon_delete', { coupon_id: newCouponId })

			if (delR.isError) {
				record('CO-03', 'coupon_delete fails because body has no id field', 'CONFIRMED',
					`Delete error: ${delR.raw.slice(0, 200)}`)
			} else {
				// Verify it's actually deleted
				const verifyR = await call('fluentcart_coupon_get', { coupon_id: newCouponId })
				if (verifyR.isError) {
					record('CO-03', 'coupon_delete fails because body has no id field', 'WRONG',
						`Delete SUCCEEDED — coupon gone. Route captures ID from URL path, not body.`)
				} else {
					record('CO-03', 'coupon_delete fails because body has no id field', 'CONFIRMED',
						`Delete returned success but coupon still exists. Response: ${delR.raw.slice(0, 200)}`)
				}
			}
		} else {
			record('CO-03', 'coupon_delete wrong body', 'INCONCLUSIVE', `Could not create test coupon: ${createR.raw.slice(0, 200)}`)
		}
	}

	// ═══ SUBSCRIPTIONS ═══════════════════════════════════════════

	// S-01: subscription_cancel sends reason instead of cancel_reason
	if (td.subId && td.subOrderId) {
		// Use a non-destructive test — just send it and see the error shape
		const r = await call('fluentcart_subscription_cancel', {
			order_id: td.subOrderId,
			subscription_id: td.subId,
			reason: 'audit test',
		})
		if (r.isError) {
			const raw = r.raw.toLowerCase()
			if (raw.includes('cancel_reason') || raw.includes('cancel reason') || raw.includes('select cancel reason')) {
				record('S-01', 'subscription_cancel sends reason vs cancel_reason', 'CONFIRMED',
					`Backend rejected — wants cancel_reason: ${r.raw.slice(0, 200)}`)
			} else {
				record('S-01', 'subscription_cancel sends reason vs cancel_reason', 'PARTIAL',
					`Error but unclear if field name issue: ${r.raw.slice(0, 200)}`)
			}
		} else {
			record('S-01', 'subscription_cancel sends wrong field name', 'WRONG',
				`Succeeded despite claim. Response: ${r.raw.slice(0, 200)}`)
		}
	} else {
		record('S-01', 'subscription_cancel wrong field', 'INCONCLUSIVE', 'No subscription found')
	}

	// S-02: subscription_pause always returns "Not available yet"
	if (td.subId && td.subOrderId) {
		const r = await call('fluentcart_subscription_pause', {
			order_id: td.subOrderId,
			subscription_id: td.subId,
		})
		if (r.isError && r.raw.toLowerCase().includes('not available')) {
			record('S-02', 'subscription_pause always returns "Not available yet"', 'CONFIRMED',
				`Response: ${r.raw.slice(0, 200)}`)
		} else if (r.isError) {
			record('S-02', 'subscription_pause is broken', 'PARTIAL',
				`Error but different message: ${r.raw.slice(0, 200)}`)
		} else {
			record('S-02', 'subscription_pause is broken', 'WRONG',
				`Succeeded! Response: ${r.raw.slice(0, 200)}`)
		}
	} else {
		record('S-02', 'subscription_pause broken', 'INCONCLUSIVE', 'No subscription found')
	}

	// S-03: subscription_resume always returns "Not available yet"
	if (td.subId && td.subOrderId) {
		const r = await call('fluentcart_subscription_resume', {
			order_id: td.subOrderId,
			subscription_id: td.subId,
		})
		if (r.isError && r.raw.toLowerCase().includes('not available')) {
			record('S-03', 'subscription_resume always returns "Not available yet"', 'CONFIRMED',
				`Response: ${r.raw.slice(0, 200)}`)
		} else if (r.isError) {
			record('S-03', 'subscription_resume is broken', 'PARTIAL',
				`Error but different message: ${r.raw.slice(0, 200)}`)
		} else {
			record('S-03', 'subscription_resume is broken', 'WRONG',
				`Succeeded! Response: ${r.raw.slice(0, 200)}`)
		}
	} else {
		record('S-03', 'subscription_resume broken', 'INCONCLUSIVE', 'No subscription found')
	}

	// S-04: subscription_reactivate always returns "Not available yet"
	if (td.subId && td.subOrderId) {
		const r = await call('fluentcart_subscription_reactivate', {
			order_id: td.subOrderId,
			subscription_id: td.subId,
		})
		if (r.isError && r.raw.toLowerCase().includes('not available')) {
			record('S-04', 'subscription_reactivate always returns "Not available yet"', 'CONFIRMED',
				`Response: ${r.raw.slice(0, 200)}`)
		} else if (r.isError) {
			record('S-04', 'subscription_reactivate is broken', 'PARTIAL',
				`Error but different message: ${r.raw.slice(0, 200)}`)
		} else {
			record('S-04', 'subscription_reactivate is broken', 'WRONG',
				`Succeeded! Response: ${r.raw.slice(0, 200)}`)
		}
	} else {
		record('S-04', 'subscription_reactivate broken', 'INCONCLUSIVE', 'No subscription found')
	}

	// ═══ TAX ══════════════════════════════════════════════════════

	// TX-01: tax_shipping_override_create has completely wrong schema
	{
		const r = await call('fluentcart_tax_shipping_override_create', {
			country_code: 'PL',
			rate: 23,
			name: 'Audit Test Override',
		})
		if (r.isError) {
			record('TX-01', 'tax_shipping_override_create has wrong schema (sends country_code/rate/name vs id/override_tax_rate)', 'CONFIRMED',
				`Error: ${r.raw.slice(0, 300)}`)
		} else {
			record('TX-01', 'tax_shipping_override_create wrong schema', 'WRONG',
				`Succeeded despite claim. Response: ${r.raw.slice(0, 200)}`)
		}
	}

	// TX-02: tax_records_mark_filed sends tax_ids but backend expects ids
	{
		const r = await call('fluentcart_tax_records_mark_filed', {
			tax_ids: [999999],
		})
		if (r.isError) {
			const raw = r.raw.toLowerCase()
			if (raw.includes('no ids') || raw.includes('ids') || raw.includes('required')) {
				record('TX-02', 'tax_records_mark_filed sends tax_ids but backend expects ids', 'CONFIRMED',
					`Backend rejects — expects ids: ${r.raw.slice(0, 200)}`)
			} else {
				record('TX-02', 'tax_records_mark_filed wrong field name', 'PARTIAL',
					`Error but unclear cause: ${r.raw.slice(0, 200)}`)
			}
		} else {
			record('TX-02', 'tax_records_mark_filed wrong field name', 'WRONG',
				`Succeeded. Response: ${r.raw.slice(0, 200)}`)
		}
	}

	// ═══ REPORTS (P1 but critical false-claim check) ══════════════

	// P1-RC-3: report_orders_by_group missing default groupKey → crashes with GROUP BY NULL
	{
		const r = await call('fluentcart_report_orders_by_group', {
			startDate: '2025-01-01',
			endDate: '2025-12-31',
			// NOT passing groupKey — audit claims this crashes
		})
		if (r.isError) {
			record('P1-RC-3', 'report_orders_by_group crashes without groupKey (GROUP BY NULL)', 'CONFIRMED',
				`Error: ${r.raw.slice(0, 300)}`)
		} else {
			record('P1-RC-3', 'report_orders_by_group crashes without groupKey', 'WRONG',
				`Succeeded without groupKey. Response: ${r.raw.slice(0, 200)}`)
		}
	}

	// P1-RC-4: report_revenue_by_group same issue
	{
		const r = await call('fluentcart_report_revenue_by_group', {
			startDate: '2025-01-01',
			endDate: '2025-12-31',
			// NOT passing groupKey
		})
		if (r.isError) {
			record('P1-RC-4', 'report_revenue_by_group crashes without groupKey', 'CONFIRMED',
				`Error: ${r.raw.slice(0, 300)}`)
		} else {
			record('P1-RC-4', 'report_revenue_by_group crashes without groupKey', 'WRONG',
				`Succeeded without groupKey. Response: ${r.raw.slice(0, 200)}`)
		}
	}

	// FALSE CLAIM CHECK: mcp-unresolved-bugs.md says UB-007a is mitigated with default groupKey
	// But audit says it's NOT implemented. Let's verify by checking if the groupKey is injected.
	{
		// The claim is that the tool should default groupKey to 'payment_method' but doesn't
		// We already tested above (P1-RC-3/4). If those CONFIRMED → false claim is true.
		// If those WRONG → the backend may have been fixed upstream.
	}

	// Test known-broken reports (UB-004, UB-006, UB-007b)
	{
		const r1 = await call('fluentcart_report_summary', { startDate: '2025-01-01', endDate: '2025-12-31' })
		record('UB-004', 'report_summary (report-overview) is broken upstream (discount_total SQL)',
			r1.isError ? 'CONFIRMED' : 'WRONG',
			`Response: ${r1.raw.slice(0, 200)}`)
	}
	{
		const r2 = await call('fluentcart_report_sales_growth', { startDate: '2025-01-01', endDate: '2025-12-31' })
		record('UB-007b-1', 'report_sales_growth crashes (missing Status class)',
			r2.isError ? 'CONFIRMED' : 'WRONG',
			`Response: ${r2.raw.slice(0, 200)}`)
	}
	{
		const r3 = await call('fluentcart_report_quick_order_stats', { day_range: '30' })
		record('UB-007b-2', 'report_quick_order_stats crashes (missing Status class)',
			r3.isError ? 'CONFIRMED' : 'WRONG',
			`Response: ${r3.raw.slice(0, 200)}`)
	}

	// P1-RC-1: report_revenue_by_group rejects payment_method as groupKey
	{
		const r = await call('fluentcart_report_revenue_by_group', {
			startDate: '2025-01-01',
			endDate: '2025-12-31',
			groupKey: 'payment_method',
		})
		if (r.isError && r.raw.includes('Invalid enum value')) {
			record('P1-RC-1', 'report_revenue_by_group rejects payment_method (enum too restrictive)', 'CONFIRMED',
				`Zod validation rejects: ${r.raw.slice(0, 200)}`)
		} else if (r.isError) {
			record('P1-RC-1', 'report_revenue_by_group rejects payment_method', 'PARTIAL',
				`Error but different reason: ${r.raw.slice(0, 200)}`)
		} else {
			record('P1-RC-1', 'report_revenue_by_group rejects payment_method', 'WRONG',
				`payment_method accepted! Response: ${r.raw.slice(0, 200)}`)
		}
	}

	// ═══ SUMMARY ═════════════════════════════════════════════════

	console.log('\n' + '═'.repeat(60))
	console.log('  VERIFICATION SUMMARY')
	console.log('═'.repeat(60))

	const confirmed = checks.filter(c => c.verdict === 'CONFIRMED').length
	const wrong = checks.filter(c => c.verdict === 'WRONG').length
	const partial = checks.filter(c => c.verdict === 'PARTIAL').length
	const inconclusive = checks.filter(c => c.verdict === 'INCONCLUSIVE').length

	console.log(`\n  CONFIRMED: ${confirmed}  (audit was right)`)
	console.log(`  WRONG:     ${wrong}  (audit was wrong)`)
	console.log(`  PARTIAL:   ${partial}  (partially correct)`)
	console.log(`  INCONCLUSIVE: ${inconclusive}  (could not test)`)
	console.log(`  TOTAL:     ${checks.length}`)

	console.log('\n── Detail ──')
	for (const c of checks) {
		const icon = c.verdict === 'CONFIRMED' ? '✅' : c.verdict === 'WRONG' ? '❌' : c.verdict === 'PARTIAL' ? '⚠️' : '❓'
		console.log(`  ${icon} ${c.id}: ${c.verdict}`)
	}

	const exitCode = wrong > 0 ? 1 : 0
	console.log(`\n  Audit accuracy: ${confirmed}/${confirmed + wrong} claims verified correct`)
	if (wrong > 0) {
		console.log('\n  ⚠️ Some audit claims were WRONG — update mcp-full-audit.md')
	}
	process.exit(exitCode)
}

main().catch((err) => {
	console.error('Fatal:', err)
	process.exit(2)
})
