/**
 * Tax tool scenarios (Batch B): classes, rates, settings, EU VAT, config, records.
 * Run: cd /Users/tomrobak/_projects_/fchub-plugins/fluentcart-mcp && set -a && source .env && set +a && npx tsx tests/_scenarios-tax.ts
 *
 * KNOWN MCP-SIDE BUGS (discovered during testing):
 * 1. fluentcart_tax_class_create: MCP sends "name" but API expects "title" (required). MCP "slug" field does not exist in API validator.
 * 2. fluentcart_tax_class_update: same — "name" should be "title".
 * 3. fluentcart_tax_rate_create: MCP sends "country_code" (path param eaten by resolveEndpoint) but API expects "country" in body.
 *    MCP sends "tax_class_id" but API expects "class_id" (required). MCP "compound" → API "is_compound" (numeric). MCP "shipping" → API "for_shipping" (numeric).
 * 4. fluentcart_tax_rate_update: same field name mismatches as create.
 * 5. fluentcart_tax_rate_country: uses /:country_code path param, but the controller reads request->get('country_code') — endpoint may need query param instead of path param.
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
	bug?: string
}
const results: ScenarioResult[] = []
function pass(name: string) {
	results.push({ name, passed: true })
	console.log(`\n✅ SCENARIO PASSED: ${name}`)
}
function fail(name: string, error: string, bug?: string) {
	results.push({ name, passed: false, error, bug })
	console.log(`\n❌ SCENARIO FAILED: ${name}\n   Reason: ${error}`)
}

// ── Scenario 1: Tax Class CRUD ─────────────────────────────
// BUG: MCP sends "name" but API expects "title".
async function scenario1() {
	const name = '1. Tax Class CRUD (MCP field name bug)'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		// Try with MCP schema — EXPECT FAILURE
		log('1.1 Create tax class (MCP field: "name" — expect 422)', 'fluentcart_tax_class_create')
		const create = await call('fluentcart_tax_class_create', {
			name: 'Reduced Rate',
			slug: 'reduced-rate-test',
			description: 'Test reduced rate tax class',
		})
		show(create)
		if (create.isError) {
			console.log(
				'  → Confirmed: MCP sends "name" but API expects "title"; "slug" is not a valid API field',
			)
		}

		// List (read-only) should work
		log('1.2 List tax classes', 'fluentcart_tax_class_list')
		const list = await call('fluentcart_tax_class_list')
		show(list)
		if (list.isError) throw new Error(`List tax classes failed: ${list.raw}`)
		console.log('  → List tax classes works')

		fail(
			name,
			'Create fails: MCP sends "name" but API expects "title"',
			'MCP_BUG: tax_class_create "name" should be "title"; "slug" field does not exist in API',
		)
	} catch (e) {
		fail(name, (e as Error).message)
	}
}

// ── Scenario 2: Tax Rate for PL ────────────────────────────
// BUG: MCP "country_code" consumed by path but API needs "country" in body; "tax_class_id" should be "class_id".
async function scenario2() {
	const name = '2. Tax Rate for PL (MCP field name bugs)'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		// Try with MCP schema — EXPECT FAILURE
		log('2.1 Create PL VAT rate 23% (MCP schema — expect 422)', 'fluentcart_tax_rate_create')
		const create = await call('fluentcart_tax_rate_create', {
			country_code: 'PL',
			rate: 23,
			name: 'PL VAT Test',
			shipping: true,
		})
		show(create)
		if (create.isError) {
			console.log('  → Confirmed: MCP field mismatches:')
			console.log('    - "country_code" consumed by path param, but body needs "country"')
			console.log('    - "tax_class_id" should be "class_id" (required)')
			console.log('    - "compound" should be "is_compound" (numeric)')
			console.log('    - "shipping" should be "for_shipping" (numeric)')
		}

		// Get country rates (read-only)
		log('2.2 Get PL country rates', 'fluentcart_tax_rate_country country_code=PL')
		const countryRates = await call('fluentcart_tax_rate_country', { country_code: 'PL' })
		show(countryRates)
		// Note: this endpoint may also have path param issues
		if (countryRates.isError) {
			console.log('  → Get country rates also failed — may be a path param issue')
		} else {
			console.log('  → Get country rates works')
		}

		// List all rates (read-only)
		log('2.3 List all tax rates', 'fluentcart_tax_rate_list')
		const allRates = await call('fluentcart_tax_rate_list')
		show(allRates)
		if (allRates.isError) throw new Error(`List rates failed: ${allRates.raw}`)
		console.log('  → List rates works')

		fail(
			name,
			'Create fails: multiple field name mismatches',
			'MCP_BUG: tax_rate_create field mismatches (country_code→country, tax_class_id→class_id, compound→is_compound, shipping→for_shipping)',
		)
	} catch (e) {
		fail(name, (e as Error).message)
	}
}

// ── Scenario 3: Tax Settings ───────────────────────────────
async function scenario3() {
	const name = '3. Tax Settings (get & save)'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		// Get settings
		log('3.1 Get tax settings', 'fluentcart_tax_settings_get')
		const get = await call('fluentcart_tax_settings_get')
		show(get)
		if (get.isError) throw new Error(`Get tax settings failed: ${get.raw}`)

		// Save settings back (round-trip)
		const getData = get.data as Record<string, unknown>
		const settings = (getData?.settings ?? getData?.data ?? getData) as Record<string, unknown>
		log('3.2 Save tax settings (round-trip)', 'fluentcart_tax_settings_save')
		const save = await call('fluentcart_tax_settings_save', {
			settings: settings,
		})
		show(save)
		if (save.isError) throw new Error(`Save tax settings failed: ${save.raw}`)

		// Verify
		log('3.3 Verify settings persisted', 'fluentcart_tax_settings_get')
		const verify = await call('fluentcart_tax_settings_get')
		show(verify)
		if (verify.isError) throw new Error(`Verify tax settings failed: ${verify.raw}`)

		pass(name)
	} catch (e) {
		fail(name, (e as Error).message)
	}
}

// ── Scenario 4: EU VAT Rates ───────────────────────────────
async function scenario4() {
	const name = '4. EU VAT Rates'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		log('4.1 Get EU VAT rates', 'fluentcart_tax_eu_rates')
		const euRates = await call('fluentcart_tax_eu_rates')
		show(euRates, 1200)
		if (euRates.isError) throw new Error(`Get EU rates failed: ${euRates.raw}`)

		pass(name)
	} catch (e) {
		fail(name, (e as Error).message)
	}
}

// ── Scenario 5: Tax Config Rates ───────────────────────────
async function scenario5() {
	const name = '5. Tax Config Rates Overview'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		log('5.1 Get tax config rates', 'fluentcart_tax_config_rates')
		const config = await call('fluentcart_tax_config_rates')
		show(config, 1200)
		if (config.isError) throw new Error(`Get tax config rates failed: ${config.raw}`)

		pass(name)
	} catch (e) {
		fail(name, (e as Error).message)
	}
}

// ── Scenario 6: Tax Records ───────────────────────────────
async function scenario6() {
	const name = '6. Tax Records'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		log('6.1 List tax records', 'fluentcart_tax_records_list')
		const records = await call('fluentcart_tax_records_list', {
			page: 1,
			per_page: 10,
		})
		show(records, 1200)
		if (records.isError) throw new Error(`List tax records failed: ${records.raw}`)

		pass(name)
	} catch (e) {
		fail(name, (e as Error).message)
	}
}

// ── Main runner ────────────────────────────────────────────
async function run() {
	console.log('╔══════════════════════════════════════════════════════════╗')
	console.log('║  TAX SCENARIOS (Batch B)                                ║')
	console.log('║  Classes, Rates, Settings, EU VAT, Config, Records      ║')
	console.log('╚══════════════════════════════════════════════════════════╝')

	await scenario1()
	await scenario2()
	await scenario3()
	await scenario4()
	await scenario5()
	await scenario6()

	// ── Summary table ──────────────────────────────────────
	console.log(`\n${'═'.repeat(60)}`)
	console.log('RESULTS SUMMARY')
	console.log('═'.repeat(60))

	const passed = results.filter((r) => r.passed).length
	const failed = results.filter((r) => !r.passed).length

	for (const r of results) {
		const icon = r.passed ? '✅ PASS' : '❌ FAIL'
		const reason = r.error ? ` — ${r.error}` : ''
		const bugNote = r.bug ? ` [${r.bug}]` : ''
		console.log(`  ${icon}  ${r.name}${reason}${bugNote}`)
	}

	console.log(`\n  Total: ${results.length} | Passed: ${passed} | Failed: ${failed}`)

	// MCP bugs found
	const bugs = results.filter((r) => r.bug)
	if (bugs.length > 0) {
		console.log(`\n  MCP-SIDE BUGS FOUND:`)
		for (const b of bugs) {
			console.log(`    - ${b.bug}`)
		}
	}

	console.log('═'.repeat(60))
}

run().catch((e) => {
	console.error('\n❌ FATAL:', e)
	process.exit(1)
})
