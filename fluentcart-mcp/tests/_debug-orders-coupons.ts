/**
 * Debug script for targeted investigation of bugs found in deep-orders-coupons tests.
 */
import { resolveServerContext } from '../src/server.js'

const ctx = resolveServerContext()
const toolMap = new Map<string, (typeof ctx.tools)[0]>()
for (const t of ctx.tools) toolMap.set(t.name, t)

async function call(name: string, input: Record<string, unknown> = {}) {
	const tool = toolMap.get(name)
	if (!tool) return { isError: true, data: null, raw: `Tool not found: ${name}` }
	try {
		const result = (await tool.handler(input)) as { content: { type: string; text: string }[]; isError?: boolean }
		const text = result.content[0]?.text ?? ''
		let data: unknown
		try { data = JSON.parse(text) } catch { data = text }
		return { isError: result.isError, data, raw: text }
	} catch (err: unknown) {
		return { isError: true, data: null, raw: String(err) }
	}
}

function asObj(d: unknown): Record<string, unknown> {
	return (d ?? {}) as Record<string, unknown>
}

async function main() {
	// 1. coupon_apply — check what field names the backend expects
	console.log('=== coupon_apply: field name mapping ===')
	const r1 = await call('fluentcart_coupon_apply', { code: 'MCP-TEST-1772617147995', order_id: 49 })
	console.log('With code+order_id:', r1.raw.slice(0, 400))
	// The error says "coupon_code is required" — tool sends "code" but backend wants "coupon_code"

	// 2. customer_update — test what fields the backend requires
	console.log('\n=== customer_update: required fields ===')
	const custResp = await call('fluentcart_customer_get', { customer_id: 108 })
	const custData = asObj(custResp.data)
	const cust = asObj(custData.customer ?? custData)
	console.log('Customer 108:', cust.email, cust.full_name, cust.first_name, cust.last_name)

	// Try with all available fields including email
	// Note: customer_update schema does NOT have email or full_name fields
	// So we need to test what the raw API expects
	const r2 = await call('fluentcart_customer_update', {
		customer_id: 108,
		first_name: 'MCP',
		last_name: 'TestCustomer',
		phone: '+44-12345678',
		status: 'active',
	})
	console.log('Without full_name/email:', r2.raw.slice(0, 300))

	// 3. customer_bulk_action — which actions are valid?
	console.log('\n=== customer_bulk_action: valid action names ===')

	const actions = ['update_status', 'change_status', 'changeStatus', 'mark_active', 'delete_customers', 'delete']
	for (const action of actions) {
		const r = await call('fluentcart_customer_bulk_action', {
			action,
			customer_ids: [108],
			data: { status: 'active' },
		})
		const ok = r.isError ? 'ERR' : 'OK'
		console.log(`  ${action}: [${ok}] ${r.raw.slice(0, 120)}`)
	}

	// 4. customer_update_additional_info — field mapping
	console.log('\n=== customer_update_additional_info ===')
	// putTool sends body directly. Schema has `info` as optional key.
	// Backend PUT /customers/:id/additional-info — what does it expect?
	const r4 = await call('fluentcart_customer_update_additional_info', {
		customer_id: 108,
		info: { test_key: 'val1', test_ts: Date.now() },
	})
	console.log('With info:', r4.raw.slice(0, 300))

	// 5. customer_attachable_users — check response
	console.log('\n=== customer_attachable_users ===')
	const r5 = await call('fluentcart_customer_attachable_users', {})
	console.log(r5.raw.slice(0, 500))

	// Also try with search
	const r5b = await call('fluentcart_customer_attachable_users', { search: 'admin' })
	console.log('Search admin:', r5b.raw.slice(0, 500))

	// 6. coupon_cancel — what does it expect?
	console.log('\n=== coupon_cancel: field names ===')
	const r6 = await call('fluentcart_coupon_cancel', { code: 'MCP-TEST-1772617147995', order_id: 49 })
	console.log('With code+order_id:', r6.raw.slice(0, 400))

	// 7. Clean up the test coupon we created
	console.log('\n=== Cleanup: delete test coupon 93 ===')
	const r7 = await call('fluentcart_coupon_delete', { coupon_id: 93 })
	console.log('Delete coupon 93:', r7.raw.slice(0, 200))
}

main().catch(console.error)
