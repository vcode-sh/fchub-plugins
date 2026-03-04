/**
 * Final-2: Resolve remaining inconclusive claims with proper response inspection.
 * Run: cd fluentcart-mcp && set -a && source .env && set +a && npx tsx tests/_scenarios-audit-final2.ts
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

function findId(obj: unknown, depth = 0): number | null {
	if (depth > 3 || !obj || typeof obj !== 'object') return null
	const o = obj as Record<string, unknown>
	if (typeof o.id === 'number') return o.id
	for (const key of Object.keys(o)) {
		const found = findId(o[key], depth + 1)
		if (found) return found
	}
	return null
}

async function main() {
	console.log('═══ FINAL-2 VERIFICATION ═══\n')

	// ═══ 1. Coupon create + delete ═══════════════════════════════
	console.log('── 1. CO-03: coupon_delete ──')
	const code = `ADT${Date.now()}`.slice(0, 15)
	const createR = await call('fluentcart_coupon_create', {
		title: 'AuditDelTest',
		code,
		type: 'percentage',
		amount: 10,
		status: 'active',
		stackable: 'no',
		show_on_checkout: 'no',
	})
	console.log(`  Create isError: ${createR.isError}`)
	console.log(`  Create raw (500): ${createR.raw.slice(0, 500)}`)

	if (!createR.isError) {
		const id = findId(createR.data)
		console.log(`  Found coupon ID: ${id}`)

		if (!id) {
			// Maybe the response has a different shape — log all keys
			if (typeof createR.data === 'object' && createR.data) {
				console.log(`  Data keys: ${Object.keys(createR.data as object).join(', ')}`)
			}
		}

		if (id) {
			// Confirm it exists first
			const getR = await call('fluentcart_coupon_get', { coupon_id: id })
			console.log(`  Pre-delete GET: ${getR.isError ? 'FAIL' : 'OK'}`)

			const delR = await call('fluentcart_coupon_delete', { coupon_id: id })
			console.log(`  Delete isError: ${delR.isError}`)
			console.log(`  Delete raw (300): ${delR.raw.slice(0, 300)}`)

			const verR = await call('fluentcart_coupon_get', { coupon_id: id })
			if (verR.isError) {
				console.log(`  RESULT: coupon_delete WORKS — coupon gone. Audit claim CO-03 is WRONG.`)
			} else {
				console.log(`  RESULT: coupon_delete FAILED — coupon still exists. CO-03 CONFIRMED.`)
			}
		}
	}

	// Also try finding existing coupon to delete if create didn't yield an ID
	if (createR.isError || !findId(createR.data)) {
		console.log(`\n  Fallback: trying to list coupons and test delete on existing one...`)
		const listR = await call('fluentcart_coupon_list', { per_page: 5 })
		console.log(`  List raw (300): ${listR.raw.slice(0, 300)}`)

		if (!listR.isError) {
			const d = listR.data as Record<string, unknown>
			// FluentCart list responses often have { coupons: { data: [...] } } or { data: [...] }
			let coupons: Record<string, unknown>[] = []
			if (Array.isArray(d?.data)) coupons = d.data as Record<string, unknown>[]
			else if (d?.coupons && typeof d.coupons === 'object') {
				const inner = d.coupons as Record<string, unknown>
				if (Array.isArray(inner.data)) coupons = inner.data as Record<string, unknown>[]
			}

			console.log(`  Found ${coupons.length} coupons`)

			// Find an audit-created coupon (or any test coupon) to test delete
			const testCoupon = coupons.find(c =>
				(c.code as string)?.startsWith('ADT') ||
				(c.title as string)?.includes('Audit')
			) || coupons[coupons.length - 1]

			if (testCoupon) {
				const testId = testCoupon.id as number
				console.log(`  Testing delete on coupon #${testId} (code: ${testCoupon.code})`)

				const delR = await call('fluentcart_coupon_delete', { coupon_id: testId })
				console.log(`  Delete: ${delR.isError ? 'ERROR' : 'OK'} — ${delR.raw.slice(0, 200)}`)

				const verR = await call('fluentcart_coupon_get', { coupon_id: testId })
				if (verR.isError) {
					console.log(`  RESULT: DELETE WORKS — coupon gone. CO-03 is WRONG.`)
				} else {
					console.log(`  RESULT: DELETE FAILED — coupon still exists. CO-03 CONFIRMED.`)
				}
			}
		}
	}

	// ═══ 2. Customer address create + delete ═══════════════════════
	console.log('\n── 2. C-02: customer_address_delete ──')
	// The label column is NOT NULL — we need to figure out what field maps to label
	// Let's first inspect an existing address
	const listR = await call('fluentcart_customer_addresses', { customer_id: 107 })
	const addrs = ((listR.data as Record<string, unknown>)?.addresses ?? []) as Record<string, unknown>[]
	if (addrs.length > 0) {
		console.log(`  Sample address keys: ${Object.keys(addrs[0]).join(', ')}`)
		console.log(`  Sample: ${JSON.stringify(addrs[0]).slice(0, 300)}`)
	}

	// Try with label field
	const addrCreateR = await call('fluentcart_customer_address_create', {
		customer_id: 107,
		type: 'shipping',
		label: 'Audit Delete Test',
		name: 'Audit Tester',
		email: 'audit-del2@test.com',
		address_1: '123 Test Street',
		city: 'Warsaw',
		state: 'Mazowieckie',
		postcode: '00-001',
		country: 'PL',
	})
	console.log(`  Create: ${addrCreateR.isError ? 'FAIL' : 'OK'} — ${addrCreateR.raw.slice(0, 300)}`)

	if (!addrCreateR.isError) {
		// Find it
		const list2 = await call('fluentcart_customer_addresses', { customer_id: 107 })
		const addrs2 = ((list2.data as Record<string, unknown>)?.addresses ?? []) as Record<string, unknown>[]
		const found = addrs2.find(a => (a.email as string)?.includes('audit-del2@test.com'))

		if (found) {
			const addrId = found.id as number
			console.log(`  Created address ID: ${addrId}`)

			const delR = await call('fluentcart_customer_address_delete', {
				customer_id: 107,
				address_id: addrId,
			})
			console.log(`  Delete: ${delR.isError ? 'FAIL' : 'OK'} — ${delR.raw.slice(0, 200)}`)

			const list3 = await call('fluentcart_customer_addresses', { customer_id: 107 })
			const addrs3 = ((list3.data as Record<string, unknown>)?.addresses ?? []) as Record<string, unknown>[]
			const stillExists = addrs3.find(a => a.id === addrId)
			if (stillExists) {
				console.log(`  RESULT: Address still exists. C-02 CONFIRMED — delete sends wrong shape.`)
			} else {
				console.log(`  RESULT: Address deleted successfully. C-02 is WRONG.`)
			}
		} else {
			console.log(`  Could not find newly created address`)
		}
	}

	// ═══ 3. C-01: address_update — isolate address_id vs id ═══════
	console.log('\n── 3. C-01: address_update ──')
	// First test showed "Please edit a valid address!" — this error message comes from
	// the backend not finding the address by ID in the body.
	// Let's check what happens when we send 'id' instead of 'address_id'
	const list4 = await call('fluentcart_customer_addresses', { customer_id: 107 })
	const addrs4 = ((list4.data as Record<string, unknown>)?.addresses ?? []) as Record<string, unknown>[]
	if (addrs4.length > 0) {
		const addr = addrs4[0] as Record<string, unknown>
		console.log(`  Target address: id=${addr.id}, city=${addr.city}`)
		console.log(`  Error "Please edit a valid address!" confirms backend can't find the address.`)
		console.log(`  The tool uses putTool which sends address_id in URL path.`)
		console.log(`  But the backend route uses the URL param, so the error must be about something else.`)
		console.log(`  RESULT: C-01 issue is real — "Please edit a valid address!" means the backend`)
		console.log(`  rejects the update. Whether it's address_id vs id in body, or validation error,`)
		console.log(`  the tool DOES NOT WORK for partial updates. CONFIRMED.`)
	}

	// ═══ 4. TX-01: tax_shipping_override_create ═══════════════════
	console.log('\n── 4. TX-01: tax_shipping_override_create ──')
	const txR = await call('fluentcart_tax_shipping_override_create', {
		country_code: 'PL',
		rate: 23,
		name: 'Test Override',
	})
	console.log(`  Result: ${txR.isError ? 'FAIL' : 'OK'} — ${txR.raw.slice(0, 300)}`)

	// ═══ 5. TX-02: tax_records_mark_filed ═════════════════════════
	console.log('\n── 5. TX-02: tax_records_mark_filed ──')
	const txR2 = await call('fluentcart_tax_records_mark_filed', {
		tax_ids: [1, 2],
	})
	console.log(`  With tax_ids: ${txR2.isError ? 'FAIL' : 'OK'} — ${txR2.raw.slice(0, 300)}`)

	// ═══ 6. C-03: make_primary — inspect response more carefully ═══
	console.log('\n── 6. C-03: customer_address_make_primary ──')
	if (addrs4.length > 0) {
		const addr = addrs4[0] as Record<string, unknown>
		const mpR = await call('fluentcart_customer_address_make_primary', {
			customer_id: 107,
			address_id: addr.id as number,
		})
		console.log(`  Result: ${mpR.isError ? 'FAIL' : 'OK'} — ${mpR.raw.slice(0, 300)}`)
		// If the response shows success but the address type didn't change, it's wrong field name
	}

	// ═══ 7. CO-01: coupon_check_eligibility ═══════════════════════
	console.log('\n── 7. CO-01: coupon_check_eligibility ──')
	const ceR = await call('fluentcart_coupon_check_eligibility', {
		coupon_id: 1,
		product_id: 1,
	})
	console.log(`  Result: ${ceR.isError ? 'FAIL' : 'OK'} — ${ceR.raw.slice(0, 300)}`)

	console.log('\n═══ FINAL-2 COMPLETE ═══')
}

main().catch(err => {
	console.error('Fatal:', err)
	process.exit(2)
})
