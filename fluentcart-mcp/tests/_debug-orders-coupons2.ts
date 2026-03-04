/**
 * Final targeted debug for customer_update_additional_info and customer_update.
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
	// Find a valid customer
	const custList = await call('fluentcart_customer_list', { per_page: 3 })
	const custData = asObj(custList.data)
	const customers = (asObj(custData.customers ?? custData).data ?? []) as Record<string, unknown>[]
	if (customers.length === 0) {
		console.log('No customers found')
		return
	}
	const custId = customers[0].id as number
	console.log(`Using customer ID: ${custId} (${customers[0].email})`)

	// Test customer_update_additional_info
	console.log('\n=== customer_update_additional_info ===')
	const r1 = await call('fluentcart_customer_update_additional_info', {
		customer_id: custId,
		info: { test_key: 'val_' + Date.now() },
	})
	console.log('Result:', r1.raw.slice(0, 300))

	// Try again with different customer
	if (customers.length > 1) {
		const custId2 = customers[1].id as number
		console.log(`\nTrying customer ${custId2}`)
		const r2 = await call('fluentcart_customer_update_additional_info', {
			customer_id: custId2,
			info: { test_key: 'val_' + Date.now() },
		})
		console.log('Result:', r2.raw.slice(0, 300))
	}

	// Now test order_sync_statuses on different orders
	console.log('\n=== order_sync_statuses ===')
	const orderList = await call('fluentcart_order_list', { per_page: 5 })
	const orderData = asObj(orderList.data)
	const orders = (asObj(orderData.orders ?? orderData).data ?? []) as Record<string, unknown>[]

	for (const o of orders.slice(0, 3)) {
		const r = await call('fluentcart_order_sync_statuses', { order_id: o.id as number })
		const ok = r.isError ? 'ERR' : 'OK'
		console.log(`  Order ${o.id}: [${ok}] ${r.raw.slice(0, 200)}`)
	}

	// Test customer_bulk_action with various action names
	console.log('\n=== customer_bulk_action: exhaustive action test ===')
	const testActions = [
		'update_status', 'change_status', 'changeStatus', 'status',
		'delete_customers', 'deleteCustomers', 'delete',
		'export', 'export_customers', 'exportCustomers',
	]
	for (const action of testActions) {
		const r = await call('fluentcart_customer_bulk_action', {
			action,
			customer_ids: [custId],
			data: { status: 'active' },
		})
		const ok = r.isError ? 'ERR' : 'OK'
		console.log(`  ${action.padEnd(20)}: [${ok}] ${r.raw.slice(0, 100)}`)
	}
}

main().catch(console.error)
