/**
 * Test script for customer fixes wave 2: C-11, C-12, C-13.
 * Run: cd fluentcart-mcp && set -a && source .env && set +a && npx tsx tests/_scenarios-fix-customers-wave2.ts
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
	console.log('=== Customer Fixes Wave 2 Test Suite ===\n')

	// -------------------------------------------------------
	// Find a real customer to test with
	// -------------------------------------------------------
	const listR = await call('fluentcart_customer_list', { per_page: 1 })
	const listData = listR.data as Record<string, unknown>
	const customers = (listData?.customers as Record<string, unknown>) ?? listData
	const custArr = ((customers as Record<string, unknown>)?.data ?? []) as Record<string, unknown>[]

	if (custArr.length === 0) {
		console.log('  FAIL  No customers found — cannot run tests')
		process.exit(1)
	}
	const testCustomer = custArr[0] as Record<string, unknown>
	const customerId = testCustomer.id as number
	console.log(`Using customer #${customerId} (${testCustomer.email}) for tests\n`)

	// -------------------------------------------------------
	// C-11: customer_bulk_action — enum is ['delete_customers']
	// -------------------------------------------------------
	console.log('--- C-11: customer_bulk_action schema ---')

	const bulkTool = toolMap.get('fluentcart_customer_bulk_action')
	check('C-11 tool exists', !!bulkTool)

	if (bulkTool) {
		const desc = bulkTool.description
		check('C-11 description mentions delete_customers', desc.includes('delete_customers'), desc.slice(0, 120))
		check('C-11 description does NOT mention update_status', !desc.includes('update_status'))
		check('C-11 description does NOT mention export', !desc.includes('export'))

		// Invalid action should fail schema validation
		const badAction = await call('fluentcart_customer_bulk_action', {
			action: 'update_status',
			customer_ids: [1],
		})
		check('C-11 invalid action rejected', badAction.isError === true, badAction.raw.slice(0, 120))

		// Valid enum value should parse (we use a non-existent ID to avoid actually deleting)
		const goodAction = await call('fluentcart_customer_bulk_action', {
			action: 'delete_customers',
			customer_ids: [999999],
		})
		// Schema should accept it — the error (if any) should be from the API, not schema validation
		const isSchemaError = goodAction.raw.includes('Expected') || goodAction.raw.includes('invalid_enum_value')
		check('C-11 valid action passes schema', !isSchemaError, goodAction.raw.slice(0, 120))
	}

	// -------------------------------------------------------
	// C-12: customer_update — fetch-merge preserves full_name & email
	// -------------------------------------------------------
	console.log('\n--- C-12: customer_update with fetch-merge ---')

	// First get the current customer state
	const getR = await call('fluentcart_customer_get', { customer_id: customerId })
	const getCust = getR.data as Record<string, unknown>
	const origCustomer = (getCust?.customer ?? getCust) as Record<string, unknown>
	const origNotes = (origCustomer.notes as string) || ''
	const origFullName = origCustomer.full_name as string
	const origEmail = origCustomer.email as string

	console.log(`  Original: full_name="${origFullName}", email="${origEmail}", notes="${origNotes}"`)

	// Update only notes — full_name and email should be preserved via fetch-merge
	const testNotes = origNotes === 'mcp-wave2-test' ? 'mcp-wave2-test-2' : 'mcp-wave2-test'
	const updateR = await call('fluentcart_customer_update', {
		customer_id: customerId,
		notes: testNotes,
	})
	check('C-12 update succeeds', !updateR.isError, updateR.raw.slice(0, 200))

	// Verify the update preserved full_name and email
	const verifyR = await call('fluentcart_customer_get', { customer_id: customerId })
	const verifyCust = verifyR.data as Record<string, unknown>
	const verifyCustomer = (verifyCust?.customer ?? verifyCust) as Record<string, unknown>

	check('C-12 full_name preserved', verifyCustomer.full_name === origFullName,
		`expected="${origFullName}", got="${verifyCustomer.full_name}"`)
	check('C-12 email preserved', verifyCustomer.email === origEmail,
		`expected="${origEmail}", got="${verifyCustomer.email}"`)
	check('C-12 notes updated', verifyCustomer.notes === testNotes,
		`expected="${testNotes}", got="${verifyCustomer.notes}"`)

	// Restore original notes
	await call('fluentcart_customer_update', {
		customer_id: customerId,
		notes: origNotes,
	})

	// -------------------------------------------------------
	// C-13: customer_update_additional_info — labels endpoint
	// -------------------------------------------------------
	console.log('\n--- C-13: customer_update_additional_info (labels) ---')

	const infoTool = toolMap.get('fluentcart_customer_update_additional_info')
	check('C-13 tool exists', !!infoTool)

	if (infoTool) {
		const desc = infoTool.description
		check('C-13 description mentions labels', desc.toLowerCase().includes('label'), desc.slice(0, 120))

		// Check schema has labels field (array of numbers), not info (record)
		const schemaShape = infoTool.schema.shape as Record<string, unknown>
		check('C-13 schema has labels field', 'labels' in schemaShape)
		check('C-13 schema does NOT have info field', !('info' in schemaShape))

		// Sync with empty labels array — backend returns "no changes" if already empty,
		// which is expected behavior, not an error in our tool
		const infoR = await call('fluentcart_customer_update_additional_info', {
			customer_id: customerId,
			labels: [],
		})
		// Either succeeds or returns "no changes" — both are fine
		const isNoChanges = infoR.raw.includes('does not have any changes')
		const isSuccess = !infoR.isError
		check('C-13 empty labels does not crash', isSuccess || isNoChanges,
			infoR.raw.slice(0, 120))
	}

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
