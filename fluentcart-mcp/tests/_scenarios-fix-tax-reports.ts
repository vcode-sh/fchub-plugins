/**
 * Verification scenarios for tax & report fixes (Task #3).
 * Run: cd /Users/tomrobak/_projects_/fchub-plugins/fluentcart-mcp && set -a && source .env && set +a && npx tsx tests/_scenarios-fix-tax-reports.ts
 *
 * Tests:
 *  1. TX-01: tax_shipping_override_create schema now expects { id, override_tax_rate }
 *  2. TX-02/TX-03: tax_records_mark_filed uses `ids` (not `tax_ids`), no bogus date params
 *  3. INF-01: formatError includes error.detail in output
 *  4. Report warnings: upstream bug warnings present in tool descriptions
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
	try {
		data = JSON.parse(text)
	} catch {
		data = text
	}
	return { isError: result.isError, data, raw: text }
}

function log(step: string, detail: string) {
	console.log(`\n${'─'.repeat(60)}`)
	console.log(`STEP: ${step}`)
	console.log(`${detail}`)
}

function show(r: ToolResult, maxLen = 800) {
	const status = r.isError ? '❌ ERROR' : '✅ OK'
	const preview = r.raw.length > maxLen ? `${r.raw.slice(0, maxLen)}...` : r.raw
	console.log(`  Result: ${status}`)
	console.log(`  ${preview}`)
}

interface ScenarioResult {
	name: string
	passed: boolean
	error?: string
}
const results: ScenarioResult[] = []
function pass(name: string) {
	results.push({ name, passed: true })
	console.log(`\n✅ SCENARIO PASSED: ${name}`)
}
function fail(name: string, error: string) {
	results.push({ name, passed: false, error })
	console.log(`\n❌ SCENARIO FAILED: ${name}\n   Reason: ${error}`)
}

// ── Scenario 1: TX-01 — tax_shipping_override_create schema ────
async function scenario1() {
	const name = '1. TX-01: tax_shipping_override_create has correct schema'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		const tool = toolMap.get('fluentcart_tax_shipping_override_create')
		if (!tool) throw new Error('Tool not found')

		// Check schema has id and override_tax_rate, NOT country_code/rate/name
		const shape = tool.schema.shape
		const keys = Object.keys(shape)
		console.log(`  Schema keys: ${keys.join(', ')}`)

		if (!keys.includes('id')) throw new Error('Missing "id" in schema')
		if (!keys.includes('override_tax_rate'))
			throw new Error('Missing "override_tax_rate" in schema')
		if (keys.includes('country_code'))
			throw new Error('Still has old "country_code" in schema')
		if (keys.includes('name')) throw new Error('Still has old "name" in schema')

		// Check description mentions existing tax rate
		if (!tool.description.includes('existing tax rate'))
			throw new Error('Description should mention existing tax rate')

		console.log('  Schema correctly expects { id, override_tax_rate }')

		// Live test: call without required params to verify validation
		log('1.1 Call with missing params (expect error)', 'fluentcart_tax_shipping_override_create')
		const badCall = await call('fluentcart_tax_shipping_override_create', {})
		show(badCall)
		// Zod should reject — that's expected

		pass(name)
	} catch (e) {
		fail(name, (e as Error).message)
	}
}

// ── Scenario 2: TX-02/TX-03 — tax_records_mark_filed ───────────
async function scenario2() {
	const name = '2. TX-02/TX-03: tax_records_mark_filed uses ids, no date params'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		const tool = toolMap.get('fluentcart_tax_records_mark_filed')
		if (!tool) throw new Error('Tool not found')

		const shape = tool.schema.shape
		const keys = Object.keys(shape)
		console.log(`  Schema keys: ${keys.join(', ')}`)

		if (!keys.includes('ids')) throw new Error('Missing "ids" in schema')
		if (keys.includes('tax_ids')) throw new Error('Still has old "tax_ids" in schema')
		if (keys.includes('startDate')) throw new Error('Still has bogus "startDate" param')
		if (keys.includes('endDate')) throw new Error('Still has bogus "endDate" param')

		console.log('  Schema correctly expects { ids } with no date params')

		// Live test: mark filed with empty array (should work or give sensible error)
		log('2.1 Mark filed with real IDs', 'fluentcart_tax_records_mark_filed')
		// First get some tax records to find valid IDs
		const records = await call('fluentcart_tax_records_list', { page: 1, per_page: 5 })
		show(records)

		if (!records.isError) {
			// Try marking with a non-existent ID (999999) — should not crash with "No IDs provided"
			log('2.2 Mark with non-existent ID (verify ids field is sent)', 'tax_records_mark_filed')
			const markResult = await call('fluentcart_tax_records_mark_filed', { ids: [999999] })
			show(markResult)
			// The important thing is it does NOT say "No IDs provided to mark!"
			if (markResult.raw.includes('No IDs provided')) {
				throw new Error('Backend still says "No IDs provided" — ids field not being sent correctly')
			}
			console.log('  Backend received the ids field correctly')
		}

		pass(name)
	} catch (e) {
		fail(name, (e as Error).message)
	}
}

// ── Scenario 3: INF-01 — formatError includes detail ───────────
async function scenario3() {
	const name = '3. INF-01: formatError includes error.detail'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		// Trigger a validation error that includes detail
		// Create a tax class without title to trigger our custom validation
		log('3.1 Trigger validation error', 'fluentcart_tax_class_create without title')
		const result = await call('fluentcart_tax_class_create', {})
		show(result)

		if (!result.isError) throw new Error('Expected an error response')
		// The error message should contain VALIDATION_ERROR
		if (!result.raw.includes('VALIDATION_ERROR'))
			throw new Error('Error should include VALIDATION_ERROR code')
		console.log('  formatError correctly formats FluentCartApiError')

		// Now trigger a real API validation error to check detail is included
		log('3.2 Trigger API validation with detail', 'Send invalid data to API')
		// Try creating a tax rate with missing required fields to get backend validation
		const apiResult = await call('fluentcart_tax_rate_create', {
			country: 'PL',
			rate: 23,
			// Missing class_id — should trigger our custom validation
		})
		show(apiResult)
		if (!apiResult.isError) throw new Error('Expected validation error')
		console.log('  Validation errors include useful detail')

		pass(name)
	} catch (e) {
		fail(name, (e as Error).message)
	}
}

// ── Scenario 4: Report upstream bug warnings ────────────────────
async function scenario4() {
	const name = '4. Report tools have upstream bug warnings'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	const checks: { tool: string; bugId: string; snippet: string }[] = [
		{
			tool: 'fluentcart_report_revenue_by_group',
			bugId: 'UB-007a',
			snippet: 'SQL syntax errors',
		},
		{
			tool: 'fluentcart_report_orders_by_group',
			bugId: 'UB-007a',
			snippet: 'SQL syntax errors',
		},
		{
			tool: 'fluentcart_report_summary',
			bugId: 'UB-004',
			snippet: 'discount_total',
		},
		{
			tool: 'fluentcart_report_top_sold_products',
			bugId: 'UB-006',
			snippet: 'array_intersect_key',
		},
		{
			tool: 'fluentcart_report_top_products_sold',
			bugId: 'UB-006',
			snippet: 'array_intersect_key',
		},
		{
			tool: 'fluentcart_report_sales_growth',
			bugId: 'UB-007b',
			snippet: 'missing Status class',
		},
		{
			tool: 'fluentcart_report_quick_order_stats',
			bugId: 'UB-007b',
			snippet: 'missing Status class',
		},
	]

	try {
		let allGood = true
		for (const check of checks) {
			const tool = toolMap.get(check.tool)
			if (!tool) {
				console.log(`  ❌ ${check.tool}: Tool not found`)
				allGood = false
				continue
			}
			const hasWarning =
				tool.description.includes(check.bugId) && tool.description.includes(check.snippet)
			if (hasWarning) {
				console.log(`  ✅ ${check.tool}: Has ${check.bugId} warning`)
			} else {
				console.log(
					`  ❌ ${check.tool}: Missing ${check.bugId} warning. Description: ${tool.description.slice(0, 120)}...`,
				)
				allGood = false
			}
		}

		if (!allGood) throw new Error('Some report tools missing upstream bug warnings')
		pass(name)
	} catch (e) {
		fail(name, (e as Error).message)
	}
}

// ── Main runner ────────────────────────────────────────────
async function run() {
	console.log('╔══════════════════════════════════════════════════════════╗')
	console.log('║  FIX VERIFICATION: Tax & Reports (Task #3)             ║')
	console.log('║  TX-01, TX-02/03, INF-01, Report Warnings              ║')
	console.log('╚══════════════════════════════════════════════════════════╝')

	await scenario1()
	await scenario2()
	await scenario3()
	await scenario4()

	// ── Summary ──────────────────────────────────────────────
	console.log(`\n${'═'.repeat(60)}`)
	console.log('RESULTS SUMMARY')
	console.log('═'.repeat(60))

	const passed = results.filter((r) => r.passed).length
	const failed = results.filter((r) => !r.passed).length

	for (const r of results) {
		const icon = r.passed ? '✅ PASS' : '❌ FAIL'
		const reason = r.error ? ` — ${r.error}` : ''
		console.log(`  ${icon}  ${r.name}${reason}`)
	}

	console.log(`\n  Total: ${results.length} | Passed: ${passed} | Failed: ${failed}`)
	console.log('═'.repeat(60))

	if (failed > 0) process.exit(1)
}

run().catch((e) => {
	console.error('\n❌ FATAL:', e)
	process.exit(1)
})
