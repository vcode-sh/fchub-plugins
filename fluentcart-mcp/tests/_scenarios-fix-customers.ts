/**
 * Test script for customer address fixes: C-01, C-02, C-03, C-04, C-05, C-10.
 * Run: cd fluentcart-mcp && set -a && source .env && set +a && npx tsx tests/_scenarios-fix-customers.ts
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

const CUSTOMER_ID = 107
let passed = 0
let failed = 0

function check(label: string, ok: boolean, detail?: string) {
	if (ok) {
		passed++
		console.log(`  PASS  ${label}${detail ? ` — ${detail}` : ''}`)
	} else {
		failed++
		console.log(`  FAIL  ${label}${detail ? ` — ${detail}` : ''}`)
	}
}

async function main() {
	console.log('=== Customer Address Fixes Test Suite ===\n')

	// -------------------------------------------------------
	// C-04 & C-05: Removed tools should not exist
	// -------------------------------------------------------
	console.log('--- C-04: customer_address_select removed ---')
	check('C-04 tool removed', !toolMap.has('fluentcart_customer_address_select'))

	console.log('--- C-05: customer_address_add removed ---')
	check('C-05 tool removed', !toolMap.has('fluentcart_customer_address_add'))

	// -------------------------------------------------------
	// C-10: customer_address_create — label is required, max 15
	// -------------------------------------------------------
	console.log('\n--- C-10: customer_address_create label required & max 15 ---')

	// Missing label should fail validation
	const noLabel = await call('fluentcart_customer_address_create', {
		customer_id: CUSTOMER_ID,
		type: 'billing',
		name: 'Test User',
		email: 'c10-nolabel@test.com',
	})
	check('C-10 missing label rejected', noLabel.isError === true, noLabel.raw.slice(0, 120))

	// Label > 15 chars should fail validation
	const longLabel = await call('fluentcart_customer_address_create', {
		customer_id: CUSTOMER_ID,
		type: 'billing',
		name: 'Test User',
		email: 'c10-longlabel@test.com',
		label: 'ThisLabelIsTooLong',
	})
	check('C-10 long label rejected', longLabel.isError === true, longLabel.raw.slice(0, 120))

	// Valid create with proper label
	const createR = await call('fluentcart_customer_address_create', {
		customer_id: CUSTOMER_ID,
		type: 'billing',
		name: 'C10 Tester',
		email: 'c10-valid@test.com',
		label: 'TestAddr',
		address_1: '10 Downing St',
		city: 'London',
		postcode: 'SW1A 2AA',
		country: 'GB',
	})
	check('C-10 create with valid label', !createR.isError, createR.raw.slice(0, 200))

	// Find the created address ID
	const listR = await call('fluentcart_customer_addresses', { customer_id: CUSTOMER_ID })
	const allAddrs = ((listR.data as Record<string, unknown>)?.addresses ?? []) as Record<string, unknown>[]
	const created = allAddrs.find(a => (a.email as string)?.includes('c10-valid@test.com'))
	const createdId = created?.id as number | undefined

	if (!createdId) {
		console.log('  WARN  Could not find created address for subsequent tests. Addresses:')
		allAddrs.forEach(a => console.log(`    id=${a.id} email=${a.email} label=${a.label}`))
	}

	// -------------------------------------------------------
	// C-01: customer_address_update — sends id in body
	// Backend requires ALL address fields on update (same validation as create).
	// -------------------------------------------------------
	console.log('\n--- C-01: customer_address_update ---')

	if (createdId) {
		const updateR = await call('fluentcart_customer_address_update', {
			customer_id: CUSTOMER_ID,
			address_id: createdId,
			type: 'billing',
			name: 'C10 Tester Updated',
			email: 'c10-valid@test.com',
			label: 'TestAddr',
			address_1: '10 Downing St',
			city: 'Manchester',
			state: 'Greater Manchester',
			postcode: 'M1 1AA',
			country: 'GB',
		})
		check('C-01 address update succeeds', !updateR.isError, updateR.raw.slice(0, 200))

		// Verify update
		const list2 = await call('fluentcart_customer_addresses', { customer_id: CUSTOMER_ID })
		const addrs2 = ((list2.data as Record<string, unknown>)?.addresses ?? []) as Record<string, unknown>[]
		const updated = addrs2.find(a => a.id === createdId) as Record<string, unknown> | undefined
		check('C-01 city updated', updated?.city === 'Manchester', `city=${updated?.city}`)
	} else {
		check('C-01 skipped (no address)', false, 'could not create test address')
	}

	// -------------------------------------------------------
	// C-02: customer_address_delete — nested body { address: { id } }
	// Must run BEFORE make_primary, since primary addresses cannot be deleted.
	// -------------------------------------------------------
	console.log('\n--- C-02: customer_address_delete ---')

	// Create a separate address for delete testing
	const delCreate = await call('fluentcart_customer_address_create', {
		customer_id: CUSTOMER_ID,
		type: 'shipping',
		name: 'Delete Me',
		email: 'c02-delete@test.com',
		label: 'DelTest',
		address_1: '99 Trash Lane',
		city: 'Birmingham',
		state: 'West Midlands',
		postcode: 'B1 1AA',
		country: 'GB',
	})

	if (!delCreate.isError) {
		const listDel = await call('fluentcart_customer_addresses', { customer_id: CUSTOMER_ID })
		const addrsDel = ((listDel.data as Record<string, unknown>)?.addresses ?? []) as Record<string, unknown>[]
		const delAddr = addrsDel.find(a => (a.email as string)?.includes('c02-delete@test.com'))
		const delId = delAddr?.id as number | undefined

		if (delId) {
			const delR = await call('fluentcart_customer_address_delete', {
				customer_id: CUSTOMER_ID,
				address_id: delId,
			})
			check('C-02 delete succeeds', !delR.isError, delR.raw.slice(0, 200))

			// Verify deletion
			const list3 = await call('fluentcart_customer_addresses', { customer_id: CUSTOMER_ID })
			const addrs3 = ((list3.data as Record<string, unknown>)?.addresses ?? []) as Record<string, unknown>[]
			const stillExists = addrs3.find(a => a.id === delId)
			check('C-02 address actually deleted', !stillExists)
		} else {
			check('C-02 could not find created address', false)
		}
	} else {
		check('C-02 skipped (create failed)', false, delCreate.raw.slice(0, 120))
	}

	// -------------------------------------------------------
	// C-03: customer_address_make_primary — camelCase addressId + type
	// -------------------------------------------------------
	console.log('\n--- C-03: customer_address_make_primary ---')

	if (createdId) {
		const primaryR = await call('fluentcart_customer_address_make_primary', {
			customer_id: CUSTOMER_ID,
			address_id: createdId,
			type: 'billing',
		})
		check('C-03 make primary succeeds', !primaryR.isError, primaryR.raw.slice(0, 200))
	} else {
		check('C-03 skipped (no address)', false, 'could not create test address')
	}

	// Also test that type is required (missing type should fail)
	const noTypeR = await call('fluentcart_customer_address_make_primary', {
		customer_id: CUSTOMER_ID,
		address_id: 1,
	})
	check('C-03 missing type rejected', noTypeR.isError === true, noTypeR.raw.slice(0, 120))

	// -------------------------------------------------------
	// Summary
	// -------------------------------------------------------
	console.log(`\n=== Results: ${passed} passed, ${failed} failed ===`)
	process.exit(failed > 0 ? 1 : 0)
}

main().catch(e => {
	console.error('Fatal:', e)
	process.exit(2)
})
