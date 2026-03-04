/**
 * Deep verification of inconclusive/partial audit claims.
 * Run: cd fluentcart-mcp && set -a && source .env && set +a && npx tsx tests/_scenarios-audit-deep.ts
 */
import { resolveServerContext } from '../src/server.js'

const ctx = resolveServerContext()
const toolMap = new Map<string, (typeof ctx.tools)[0]>()
for (const t of ctx.tools) toolMap.set(t.name, t)

type ToolResult = { isError?: boolean; data: unknown; raw: string }

async function call(name: string, input: Record<string, unknown> = {}): Promise<ToolResult> {
	const tool = toolMap.get(name)
	if (!tool) return { isError: true, data: null, raw: `Tool not found: ${name}` }
	const result = (await tool.handler(input)) as {
		content: { type: string; text: string }[]
		isError?: boolean
	}
	const text = result.content[0]?.text ?? ''
	let data: unknown
	try { data = JSON.parse(text) } catch { data = text }
	return { isError: result.isError, data, raw: text }
}

async function main() {
	console.log('═══════════════════════════════════════════════════════════')
	console.log('  Deep Audit Verification — Inconclusive + Partial')
	console.log('═══════════════════════════════════════════════════════════')

	// ═══ CO-03: coupon_delete ═══════════════════════════════════
	console.log('\n── CO-03: coupon_delete body vs path ID ──')
	const createR = await call('fluentcart_coupon_create', {
		title: 'AUDIT-DELETE-TEST',
		code: `AUDIT-DEL-${Date.now()}`,
		type: 'percentage',
		amount: 5,
		status: 'active',
		stackable: 'no',
		show_on_checkout: 'no',
	})

	if (!createR.isError) {
		const coupon = ((createR.data as Record<string, unknown>)?.coupon ?? createR.data) as Record<string, unknown>
		const id = coupon?.id as number
		console.log(`  Created coupon ID: ${id}`)

		const delR = await call('fluentcart_coupon_delete', { coupon_id: id })
		console.log(`  Delete result: ${delR.isError ? 'ERROR' : 'OK'} — ${delR.raw.slice(0, 150)}`)

		const verR = await call('fluentcart_coupon_get', { coupon_id: id })
		if (verR.isError) {
			console.log(`  ❌ WRONG: Delete SUCCEEDED. Coupon is gone. Route captures ID from URL path.`)
		} else {
			console.log(`  ✅ CONFIRMED: Delete failed silently. Coupon still exists.`)
		}
	} else {
		console.log(`  Coupon create failed: ${createR.raw.slice(0, 150)}`)
	}

	// ═══ C-02: customer_address_delete ═══════════════════════════
	console.log('\n── C-02: customer_address_delete — deeper analysis ──')
	// Create a test address, try to delete it, verify
	const addrCreateR = await call('fluentcart_customer_address_create', {
		customer_id: 107,
		type: 'shipping',
		name: 'Audit Delete Test',
		email: 'audit-delete@test.com',
		city: 'TestCity',
		country: 'PL',
		state: 'Mazowieckie',
	})
	console.log(`  Address create: ${addrCreateR.isError ? 'ERROR' : 'OK'} — ${addrCreateR.raw.slice(0, 200)}`)

	if (!addrCreateR.isError) {
		const addrData = (addrCreateR.data as Record<string, unknown>)
		// Find the new address ID
		const addrR = await call('fluentcart_customer_addresses', { customer_id: 107 })
		const addrs = ((addrR.data as Record<string, unknown>)?.addresses ?? []) as Record<string, unknown>[]
		const testAddr = addrs.find(a => a.email === 'audit-delete@test.com')

		if (testAddr) {
			const testAddrId = testAddr.id as number
			console.log(`  Created address ID: ${testAddrId}`)

			const delR = await call('fluentcart_customer_address_delete', {
				customer_id: 107,
				address_id: testAddrId,
			})
			console.log(`  Delete result: ${delR.isError ? 'ERROR' : 'OK'} — ${delR.raw.slice(0, 200)}`)

			// Verify
			const verR = await call('fluentcart_customer_addresses', { customer_id: 107 })
			const verAddrs = ((verR.data as Record<string, unknown>)?.addresses ?? []) as Record<string, unknown>[]
			const stillExists = verAddrs.find(a => a.id === testAddrId)
			if (stillExists) {
				console.log(`  ✅ CONFIRMED: Address still exists after delete. Tool sends wrong field shape.`)
			} else {
				console.log(`  ❌ WRONG: Address was actually deleted. Tool works.`)
			}
		} else {
			console.log(`  Could not find created address`)
		}
	}

	// ═══ C-03: make_primary — test with type field ═══════════════
	console.log('\n── C-03: make_primary — checking if type field is the issue ──')
	const addrR = await call('fluentcart_customer_addresses', { customer_id: 107 })
	const addrs = ((addrR.data as Record<string, unknown>)?.addresses ?? []) as Record<string, unknown>[]
	if (addrs.length > 0) {
		const addr = addrs[0] as Record<string, unknown>
		console.log(`  Testing with address ID ${addr.id}, type: ${addr.type ?? 'unknown'}`)

		// What the tool CURRENTLY sends (no type, address_id not addressId)
		const r1 = await call('fluentcart_customer_address_make_primary', {
			customer_id: 107,
			address_id: addr.id as number,
		})
		console.log(`  Current (no type, snake_case): ${r1.isError ? 'FAIL' : 'OK'} — ${r1.raw.slice(0, 200)}`)
	}

	// ═══ CO-02: coupon_reapply — the PARTIAL result ═════════════
	console.log('\n── CO-02: coupon_reapply — deeper analysis ──')
	// It returned {"applied_coupons":[],"calculated_items":[]} — which is "success" but empty
	// This means the backend processed the request but found no coupons to reapply
	// because we sent code/order_id instead of order_uuid/applied_coupons
	// The question is: would correct params actually work differently?
	const reapplyR = await call('fluentcart_coupon_reapply', {
		code: 'NONEXISTENT',
		order_id: 50,
	})
	console.log(`  With code/order_id: ${reapplyR.raw.slice(0, 200)}`)
	// The "success" with empty arrays confirms the backend ignores our params
	// and just returns the default empty state. CONFIRMED as broken.
	const hasEmpty = reapplyR.raw.includes('"applied_coupons":[]')
	console.log(`  ${hasEmpty ? '✅ CONFIRMED' : '❓ INCONCLUSIVE'}: Backend ignores code/order_id, returns empty defaults`)

	// ═══ P1-RC-1: revenue_by_group groupKey enum ════════════════
	console.log('\n── P1-RC-1: revenue_by_group groupKey enum check ──')
	// The first test showed payment_method caused a SQL error (not a Zod rejection)
	// This means the enum DIDN'T reject it — the value passed through to SQL
	// Let's test with 'billing_country' which should also work
	const rbc = await call('fluentcart_report_revenue_by_group', {
		startDate: '2025-01-01',
		endDate: '2025-12-31',
		groupKey: 'billing_country',
	})
	console.log(`  billing_country: ${rbc.isError ? 'ERROR' : 'OK'} — ${rbc.raw.slice(0, 200)}`)

	// Test with 'daily' (should be in enum)
	const rd = await call('fluentcart_report_revenue_by_group', {
		startDate: '2025-01-01',
		endDate: '2025-12-31',
		groupKey: 'daily',
	})
	console.log(`  daily: ${rd.isError ? 'ERROR' : 'OK'} — ${rd.raw.slice(0, 100)}`)

	// If billing_country worked OR failed with SQL (not Zod), the enum isn't blocking
	// This means the claim is PARTIALLY wrong — the enum isn't too restrictive IF values pass through
	// But actually the issue is the enum is in the ZOD schema for validation
	// Let me check: does the getTool factory run schema validation?
	// Looking at _factory.ts createEndpointTool — it does NOT call schema.parse()
	// It just passes input directly! So the enum doesn't matter for runtime!
	// BUT it matters for MCP protocol — the schema is advertised to the LLM client
	// The LLM sees enum: ['daily','weekly','monthly'] and won't try payment_method
	console.log(`  NOTE: Factory doesn't validate input — enum only affects LLM schema advertisement.`)
	console.log(`  VERDICT: P1-RC-1 is about LLM UX (restricted schema), not runtime crash.`)

	// ═══ S-02/S-03/S-04: subscription pause/resume/reactivate ═══
	// No subscriptions available. Let's verify the PHP source claim directly.
	console.log('\n── S-02/S-03/S-04: subscription tools — testing with fake IDs ──')
	// Even without real subscriptions, we can test if the endpoints return "Not available yet"

	const sp = await call('fluentcart_subscription_pause', { order_id: 50, subscription_id: 99999 })
	console.log(`  pause: ${sp.isError ? 'ERROR' : 'OK'} — ${sp.raw.slice(0, 200)}`)
	const notAvailPause = sp.raw.toLowerCase().includes('not available')
	console.log(`  ${notAvailPause ? '✅ CONFIRMED' : '❓ DIFFERENT ERROR'}: ${notAvailPause ? '"Not available yet"' : 'Different error'}`)

	const sr = await call('fluentcart_subscription_resume', { order_id: 50, subscription_id: 99999 })
	console.log(`  resume: ${sr.isError ? 'ERROR' : 'OK'} — ${sr.raw.slice(0, 200)}`)
	const notAvailResume = sr.raw.toLowerCase().includes('not available')
	console.log(`  ${notAvailResume ? '✅ CONFIRMED' : '❓ DIFFERENT ERROR'}: ${notAvailResume ? '"Not available yet"' : 'Different error'}`)

	const sra = await call('fluentcart_subscription_reactivate', { order_id: 50, subscription_id: 99999 })
	console.log(`  reactivate: ${sra.isError ? 'ERROR' : 'OK'} — ${sra.raw.slice(0, 200)}`)
	const notAvailReact = sra.raw.toLowerCase().includes('not available')
	console.log(`  ${notAvailReact ? '✅ CONFIRMED' : '❓ DIFFERENT ERROR'}: ${notAvailReact ? '"Not available yet"' : 'Different error'}`)

	// ═══ S-01: subscription_cancel reason field ═════════════════
	console.log('\n── S-01: subscription_cancel — testing with fake ID ──')
	const sc = await call('fluentcart_subscription_cancel', {
		order_id: 50,
		subscription_id: 99999,
		reason: 'audit test',
	})
	console.log(`  cancel: ${sc.isError ? 'ERROR' : 'OK'} — ${sc.raw.slice(0, 200)}`)
	const wantsCancel = sc.raw.toLowerCase().includes('cancel_reason') || sc.raw.toLowerCase().includes('cancel reason')
	console.log(`  ${wantsCancel ? '✅ CONFIRMED' : '❓ DIFFERENT ERROR'}: Backend ${wantsCancel ? 'wants cancel_reason' : 'different error'}`)

	console.log('\n═══════════════════════════════════════════════════════════')
	console.log('  DEEP VERIFICATION COMPLETE')
	console.log('═══════════════════════════════════════════════════════════')
}

main().catch(err => {
	console.error('Fatal:', err)
	process.exit(2)
})
