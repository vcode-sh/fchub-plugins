/**
 * Final deep verification of remaining inconclusive audit claims.
 * Run: cd fluentcart-mcp && set -a && source .env && set +a && npx tsx tests/_scenarios-audit-final.ts
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
	console.log('  Final Audit Verification')
	console.log('═══════════════════════════════════════════════════════════')

	// ═══ CO-03: coupon_delete ═══════════════════════════════════
	// Previous create failed with HTML. Try with notes field too.
	console.log('\n── CO-03: coupon_delete ──')
	const createR = await call('fluentcart_coupon_create', {
		title: 'AuditDelTest',
		code: `ADT${Date.now()}`,
		type: 'percentage',
		amount: 10,
		status: 'active',
		stackable: 'no',
		show_on_checkout: 'no',
		notes: '',
	})
	console.log(`  Create: ${createR.isError ? 'FAIL' : 'OK'}`)
	if (createR.isError) {
		console.log(`  ${createR.raw.slice(0, 200)}`)
		// Try listing existing coupons to find one to test delete with
		const listR = await call('fluentcart_coupon_list', { per_page: 50 })
		const ld = listR.data as Record<string, unknown>
		const lw = (ld?.coupons ?? ld) as Record<string, unknown>
		const coupons = (lw?.data ?? []) as Record<string, unknown>[]
		console.log(`  Found ${coupons.length} coupons. Codes: ${coupons.map(c => c.code).join(', ')}`)

		// Create one with a simpler approach — just essential fields
		const createR2 = await call('fluentcart_coupon_create', {
			title: 'Test',
			code: `T${Date.now()}`.slice(0, 15),
			type: 'fixed',
			amount: 100,
			status: 'active',
			stackable: 'yes',
			show_on_checkout: 'no',
		})
		console.log(`  Create attempt 2: ${createR2.isError ? 'FAIL' : 'OK'} — ${createR2.raw.slice(0, 200)}`)

		if (!createR2.isError) {
			const coupon = ((createR2.data as Record<string, unknown>)?.coupon ?? createR2.data) as Record<string, unknown>
			const id = coupon?.id as number
			if (id) {
				const delR = await call('fluentcart_coupon_delete', { coupon_id: id })
				console.log(`  Delete: ${delR.isError ? 'FAIL' : 'OK'} — ${delR.raw.slice(0, 150)}`)
				const verR = await call('fluentcart_coupon_get', { coupon_id: id })
				console.log(`  Verify: ${verR.isError ? 'GONE (delete worked)' : 'STILL EXISTS (delete failed)'}`)
				if (verR.isError) {
					console.log(`  ❌ WRONG: coupon_delete WORKS. Route captures ID from URL path.`)
				} else {
					console.log(`  ✅ CONFIRMED: coupon_delete failed — coupon still exists.`)
				}
			}
		}
	} else {
		const coupon = ((createR.data as Record<string, unknown>)?.coupon ?? createR.data) as Record<string, unknown>
		const id = coupon?.id as number
		console.log(`  Created coupon ID: ${id}`)
		if (id) {
			const delR = await call('fluentcart_coupon_delete', { coupon_id: id })
			console.log(`  Delete: ${delR.isError ? 'FAIL' : 'OK'} — ${delR.raw.slice(0, 150)}`)
			const verR = await call('fluentcart_coupon_get', { coupon_id: id })
			if (verR.isError) {
				console.log(`  ❌ WRONG: coupon_delete WORKS. Route captures ID from URL path.`)
			} else {
				console.log(`  ✅ CONFIRMED: coupon_delete failed — coupon still exists.`)
			}
		}
	}

	// ═══ C-02: customer_address_delete ═══════════════════════════
	console.log('\n── C-02: customer_address_delete ──')
	// Create address with all required fields
	const addrCreateR = await call('fluentcart_customer_address_create', {
		customer_id: 107,
		type: 'shipping',
		name: 'Audit Delete Test',
		email: 'audit-del@test.com',
		address_1: '123 Test Street',
		city: 'Warsaw',
		state: 'Mazowieckie',
		postcode: '00-001',
		country: 'PL',
	})
	console.log(`  Create address: ${addrCreateR.isError ? 'FAIL' : 'OK'} — ${addrCreateR.raw.slice(0, 200)}`)

	if (!addrCreateR.isError) {
		// Find the new address
		const listR = await call('fluentcart_customer_addresses', { customer_id: 107 })
		const addrs = ((listR.data as Record<string, unknown>)?.addresses ?? []) as Record<string, unknown>[]
		const testAddr = addrs.find(a => (a.email as string)?.includes('audit-del@test.com'))

		if (testAddr) {
			const addrId = testAddr.id as number
			console.log(`  Created address ID: ${addrId}`)

			const delR = await call('fluentcart_customer_address_delete', {
				customer_id: 107,
				address_id: addrId,
			})
			console.log(`  Delete: ${delR.isError ? 'FAIL' : 'OK'} — ${delR.raw.slice(0, 200)}`)

			// Verify
			const verR = await call('fluentcart_customer_addresses', { customer_id: 107 })
			const verAddrs = ((verR.data as Record<string, unknown>)?.addresses ?? []) as Record<string, unknown>[]
			const stillExists = verAddrs.find(a => a.id === addrId)
			if (stillExists) {
				console.log(`  ✅ CONFIRMED: Address still exists. Delete sends wrong field shape.`)
			} else {
				console.log(`  ❌ WRONG: Address deleted successfully. Tool works.`)
			}
		} else {
			console.log(`  Could not find created address in list`)
		}
	}

	// ═══ S-01 to S-04: Subscription claims via PHP source ═══════
	// Can't test live (no subscriptions). Verify claim by reading PHP source.
	console.log('\n── S-01 to S-04: Subscription — PHP source verification ──')
	console.log('  Cannot test live (no active subscriptions).')
	console.log('  Claims based on PHP source code analysis:')
	console.log('  S-01: cancel() reads cancel_reason, tool sends reason → CLAIM VALID (code review)')
	console.log('  S-02: pauseSubscription() returns sendError("Not available yet") → CLAIM VALID (code review)')
	console.log('  S-03: resumeSubscription() returns sendError("Not available yet") → CLAIM VALID (code review)')
	console.log('  S-04: reactivateSubscription() returns sendError("Not available yet") → CLAIM VALID (code review)')
	console.log('  NOTE: Fake subscription IDs hit model lookup first (403), masking the "Not available" response.')

	// ═══ EXTRA: revenue_by_group is broken even with valid groupKey ═══
	console.log('\n── EXTRA: revenue_by_group with "daily" (should be valid) ──')
	const rdaily = await call('fluentcart_report_revenue_by_group', {
		startDate: '2025-01-01',
		endDate: '2025-12-31',
		groupKey: 'daily',
	})
	if (rdaily.isError) {
		console.log(`  daily also crashes: ${rdaily.raw.slice(0, 200)}`)
		console.log(`  ⚠️ revenue_by_group endpoint is FULLY BROKEN upstream — not just missing default.`)
	} else {
		console.log(`  daily works: ${rdaily.raw.slice(0, 100)}`)
	}

	// ═══ EXTRA: report_orders_by_group WITH groupKey ═══════════
	console.log('\n── EXTRA: orders_by_group WITH explicit groupKey ──')
	const robg = await call('fluentcart_report_orders_by_group', {
		startDate: '2025-01-01',
		endDate: '2025-12-31',
		groupKey: 'payment_method',
	})
	console.log(`  payment_method: ${robg.isError ? 'FAIL' : 'OK'} — ${robg.raw.slice(0, 200)}`)

	// ═══ C-01: address_update — double check what actually changed ═══
	console.log('\n── C-01: address_update — verify what gets sent ──')
	// The first run confirmed ERROR with validation requiring country/state/name
	// This means the PUT endpoint requires ALL fields, not just changed ones
	// So the issue is twofold: (a) address_id vs id, AND (b) requires full payload (no partial)
	const listR = await call('fluentcart_customer_addresses', { customer_id: 107 })
	const addrs = ((listR.data as Record<string, unknown>)?.addresses ?? []) as Record<string, unknown>[]
	if (addrs.length > 0) {
		const addr = addrs[0] as Record<string, unknown>
		console.log(`  Existing address: ${JSON.stringify({ id: addr.id, city: addr.city, name: addr.name, country: addr.country, state: addr.state })}`)

		// Try with ALL required fields + address_id
		const updateR = await call('fluentcart_customer_address_update', {
			customer_id: 107,
			address_id: addr.id as number,
			name: addr.name as string,
			email: (addr.email as string) || 'test@test.com',
			country: addr.country as string,
			state: addr.state as string,
			address_1: (addr.address_1 as string) || '123 St',
			city: 'AuditUpdatedCity',
		})
		console.log(`  Update with all fields: ${updateR.isError ? 'FAIL' : 'OK'} — ${updateR.raw.slice(0, 200)}`)

		if (!updateR.isError) {
			// Check if city actually changed
			const ver = await call('fluentcart_customer_addresses', { customer_id: 107 })
			const verAddrs = ((ver.data as Record<string, unknown>)?.addresses ?? []) as Record<string, unknown>[]
			const updated = verAddrs.find(a => a.id === addr.id)
			if (updated?.city === 'AuditUpdatedCity') {
				console.log(`  ⚠️ UPDATE WORKED with address_id! Claim about address_id vs id is WRONG.`)
				console.log(`  BUT the real issue is: endpoint requires ALL fields (no partial update).`)
				// Revert
				await call('fluentcart_customer_address_update', {
					customer_id: 107,
					address_id: addr.id as number,
					name: addr.name as string,
					email: (addr.email as string) || 'test@test.com',
					country: addr.country as string,
					state: addr.state as string,
					address_1: (addr.address_1 as string) || '123 St',
					city: addr.city as string,
				})
			} else {
				console.log(`  City not changed. address_id field name issue CONFIRMED.`)
			}
		}
	}

	console.log('\n═══════════════════════════════════════════════════════════')
	console.log('  FINAL VERIFICATION COMPLETE')
	console.log('═══════════════════════════════════════════════════════════')
}

main().catch(err => {
	console.error('Fatal:', err)
	process.exit(2)
})
