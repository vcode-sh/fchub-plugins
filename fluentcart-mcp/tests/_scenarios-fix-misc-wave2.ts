/**
 * Misc Wave 2 fix verification scenarios.
 * Validates: EMAIL-01, FILE-01, PROD-04, PA-006
 *
 * Run: cd /Users/tomrobak/_projects_/fchub-plugins/fluentcart-mcp && set -a && source .env && set +a && npx tsx tests/_scenarios-fix-misc-wave2.ts
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { resolveServerContext } from '../src/server.js'

const ctx = resolveServerContext()
const toolMap = new Map<string, (typeof ctx.tools)[0]>()
for (const t of ctx.tools) toolMap.set(t.name, t)

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
	console.log(`\n❌ SCENARIO FAILED: ${name}`)
	console.log(`   Reason: ${error}`)
}

// ── EMAIL-01: email_template_preview uses `template` field ───
async function scenarioEmailTemplatePreview() {
	const name = 'EMAIL-01: email_template_preview uses template (not notification)'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		const tool = toolMap.get('fluentcart_email_template_preview')
		if (!tool) throw new Error('Tool fluentcart_email_template_preview not found')

		const shape = tool.schema.shape as Record<string, { _def?: { description?: string } }>
		if (!shape.template) {
			throw new Error('Schema missing "template" field')
		}
		if (shape.notification) {
			throw new Error('Schema still has old "notification" field — should be renamed to "template"')
		}
		console.log(`  → "template" field present ✓`)
		console.log(`  → old "notification" field removed ✓`)

		pass(name)
	} catch (e) {
		fail(name, (e as Error).message)
	}
}

// ── FILE-01: file_upload description contains WARNING ────────
async function scenarioFileUploadWarning() {
	const name = 'FILE-01: file_upload description has multipart WARNING'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		const tool = toolMap.get('fluentcart_file_upload')
		if (!tool) throw new Error('Tool fluentcart_file_upload not found')

		if (!tool.description.includes('WARNING')) {
			throw new Error('Description missing WARNING about multipart')
		}
		if (!tool.description.includes('multipart/form-data')) {
			throw new Error('Description does not mention multipart/form-data')
		}
		if (!tool.description.includes('$request->files()')) {
			throw new Error('Description does not mention $request->files()')
		}
		console.log(`  → WARNING present in description ✓`)
		console.log(`  → multipart/form-data mentioned ✓`)
		console.log(`  → $request->files() mentioned ✓`)

		// Check field descriptions updated — walk zod's _def tree for description
		const shape = tool.schema.shape as Record<string, { _def?: Record<string, unknown>; description?: string }>
		const fileUrlField = shape.file_url
		const fileUrlDesc =
			(fileUrlField?._def?.description as string) ??
			(fileUrlField?.description as string) ??
			((fileUrlField?._def?.innerType as { _def?: { description?: string } })?._def?.description) ??
			''
		if (!fileUrlDesc.includes('NOT SUPPORTED')) {
			throw new Error(`file_url description does not mention NOT SUPPORTED (got: "${fileUrlDesc}")`)
		}
		console.log(`  → file_url field description updated ✓`)

		pass(name)
	} catch (e) {
		fail(name, (e as Error).message)
	}
}

// ── PROD-04: product_create_dummy has category field ─────────
async function scenarioProductCreateDummyCategory() {
	const name = 'PROD-04: product_create_dummy has required category field'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		const tool = toolMap.get('fluentcart_product_create_dummy')
		if (!tool) throw new Error('Tool fluentcart_product_create_dummy not found')

		const shape = tool.schema.shape as Record<string, { _def?: unknown }>
		if (!shape.category) {
			throw new Error('Schema missing "category" field')
		}
		console.log(`  → "category" field present ✓`)

		// Verify it's required (not optional)
		const catDef = shape.category._def as Record<string, unknown>
		const isOptional = catDef?.typeName === 'ZodOptional'
		if (isOptional) {
			throw new Error('"category" field should be required, not optional')
		}
		console.log(`  → "category" field is required ✓`)

		// Check description mentions category requirement
		if (!tool.description.includes('Category is required')) {
			throw new Error('Tool description does not mention category requirement')
		}
		console.log(`  → description mentions category requirement ✓`)

		pass(name)
	} catch (e) {
		fail(name, (e as Error).message)
	}
}

// ── PA-006: auth.ts uses timingSafeEqual ─────────────────────
async function scenarioTimingSafeAuth() {
	const name = 'PA-006: auth.ts uses timingSafeEqual for API key comparison'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		const authPath = resolve(import.meta.dirname ?? '.', '../src/transport/auth.ts')
		const authSource = readFileSync(authPath, 'utf-8')

		if (!authSource.includes('timingSafeEqual')) {
			throw new Error('auth.ts does not use timingSafeEqual')
		}
		console.log(`  → timingSafeEqual used ✓`)

		if (authSource.includes("token !== apiKey") || authSource.includes("token != apiKey")) {
			throw new Error('auth.ts still has direct string comparison')
		}
		console.log(`  → direct string comparison removed ✓`)

		if (!authSource.includes("import { timingSafeEqual }")) {
			throw new Error('auth.ts missing timingSafeEqual import')
		}
		console.log(`  → timingSafeEqual imported from node:crypto ✓`)

		if (!authSource.includes("Buffer.from(token)")) {
			throw new Error('auth.ts does not convert token to Buffer')
		}
		console.log(`  → token converted to Buffer ✓`)

		pass(name)
	} catch (e) {
		fail(name, (e as Error).message)
	}
}

// ── Main runner ────────────────────────────────────────────
async function run() {
	console.log('╔══════════════════════════════════════════════════════════╗')
	console.log('║  MISC WAVE 2 FIX VERIFICATION                          ║')
	console.log('║  EMAIL-01, FILE-01, PROD-04, PA-006                     ║')
	console.log('╚══════════════════════════════════════════════════════════╝')

	// All schema/code-level checks — no live API calls needed
	await scenarioEmailTemplatePreview()
	await scenarioFileUploadWarning()
	await scenarioProductCreateDummyCategory()
	await scenarioTimingSafeAuth()

	// ── Summary table ──────────────────────────────────────
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

	if (failed > 0) {
		process.exit(1)
	}
}

run().catch((e) => {
	console.error('\n❌ FATAL:', e)
	process.exit(1)
})
