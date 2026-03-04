/**
 * Final-3: Resolve C-02 (address delete) with correct label length.
 * Run: cd fluentcart-mcp && set -a && source .env && set +a && npx tsx tests/_scenarios-audit-final3.ts
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
	console.log('═══ FINAL-3: C-02 address_delete ═══\n')

	// Create address with short label (max 15 chars)
	const createR = await call('fluentcart_customer_address_create', {
		customer_id: 107,
		type: 'shipping',
		label: 'AuditDel',
		name: 'Audit Tester',
		email: 'audit-del3@test.com',
		address_1: '123 Test St',
		city: 'Warsaw',
		state: 'Mazowieckie',
		postcode: '00-001',
		country: 'PL',
	})
	console.log(`Create: ${createR.isError ? 'FAIL' : 'OK'} — ${createR.raw.slice(0, 300)}`)

	if (!createR.isError) {
		// Find the new address
		const listR = await call('fluentcart_customer_addresses', { customer_id: 107 })
		const addrs = ((listR.data as Record<string, unknown>)?.addresses ?? []) as Record<string, unknown>[]
		const found = addrs.find(a => (a.email as string)?.includes('audit-del3@test.com'))

		if (found) {
			const addrId = found.id as number
			console.log(`Created address ID: ${addrId}`)

			// Try delete
			const delR = await call('fluentcart_customer_address_delete', {
				customer_id: 107,
				address_id: addrId,
			})
			console.log(`Delete: ${delR.isError ? 'FAIL' : 'OK'} — ${delR.raw.slice(0, 300)}`)

			// Verify
			const list2 = await call('fluentcart_customer_addresses', { customer_id: 107 })
			const addrs2 = ((list2.data as Record<string, unknown>)?.addresses ?? []) as Record<string, unknown>[]
			const stillExists = addrs2.find(a => a.id === addrId)

			if (stillExists) {
				console.log(`RESULT: Address still exists after delete. C-02 CONFIRMED.`)
			} else {
				console.log(`RESULT: Address deleted. C-02 is WRONG — tool works fine.`)
			}
		} else {
			console.log(`Could not find address. Full list:`)
			addrs.forEach(a => console.log(`  id=${a.id} email=${a.email} label=${a.label}`))
		}
	}

	// Also: let's check if coupon_create works with 'fixed' type
	console.log('\n═══ BONUS: coupon_create with fixed type ═══')
	const cc = await call('fluentcart_coupon_create', {
		title: 'TestFixed',
		code: `TF${Date.now()}`.slice(0, 12),
		type: 'fixed',
		amount: 100,
		status: 'active',
		stackable: 'yes',
		show_on_checkout: 'no',
	})
	console.log(`Create fixed: ${cc.isError ? 'FAIL' : 'OK'} — ${cc.raw.slice(0, 300)}`)

	// If create worked but returned HTML, the issue is upstream (WP rendering)
	// If it's a proper JSON response, something about 'percentage' type is off

	console.log('\n═══ FINAL-3 COMPLETE ═══')
}

main().catch(err => {
	console.error('Fatal:', err)
	process.exit(2)
})
