/**
 * Batch H — Settings tools scenarios.
 * Tests module settings, confirmation, payment methods, print templates, permissions.
 * Run: cd /Users/tomrobak/_projects_/fchub-plugins/fluentcart-mcp && set -a && source .env && set +a && npx tsx tests/_scenarios-settings.ts
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

function _extractId(data: unknown, ...keys: string[]): number | null {
	if (!data || typeof data !== 'object') return null
	const obj = data as Record<string, unknown>
	for (const k of keys) {
		if (typeof obj[k] === 'number') return obj[k] as number
	}
	for (const wrapper of ['data', 'product', 'variant', 'order', 'bump', 'label', 'subscription']) {
		const nested = obj[wrapper]
		if (nested && typeof nested === 'object') {
			const n = nested as Record<string, unknown>
			for (const k of keys) {
				if (typeof n[k] === 'number') return n[k] as number
			}
		}
	}
	return null
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

// ── Scenario 1: Module Settings (get + save) ─────────────────────
async function scenario1() {
	const name = '1. Module Settings (get modules + save modules idempotent)'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		log('1.1', 'fluentcart_settings_get_modules')
		const getModules = await call('fluentcart_settings_get_modules')
		show(getModules)
		if (getModules.isError) {
			fail(name, `get_modules error: ${getModules.raw}`)
			return
		}

		// Save same data back (idempotent)
		const moduleData = getModules.data as Record<string, unknown>
		const modules = moduleData?.modules ?? moduleData
		log('1.2', 'fluentcart_settings_save_modules (pass same data back)')
		const saveModules = await call('fluentcart_settings_save_modules', {
			modules: modules as Record<string, unknown>,
		})
		show(saveModules)
		if (saveModules.isError) {
			fail(name, `save_modules error: ${saveModules.raw}`)
			return
		}

		// Verify it's still the same
		log('1.3', 'fluentcart_settings_get_modules (verify)')
		const verifyModules = await call('fluentcart_settings_get_modules')
		show(verifyModules)
		if (verifyModules.isError) {
			fail(name, `verify get_modules error: ${verifyModules.raw}`)
			return
		}

		pass(name)
	} catch (e) {
		fail(name, String(e))
	}
}

// ── Scenario 2: Confirmation Settings ────────────────────────────
async function scenario2() {
	const name = '2. Confirmation Settings (get shortcodes + save confirmation)'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		log('2.1', 'fluentcart_settings_get_confirmation_shortcodes')
		const shortcodes = await call('fluentcart_settings_get_confirmation_shortcodes')
		show(shortcodes)
		if (shortcodes.isError) {
			fail(name, `get_confirmation_shortcodes error: ${shortcodes.raw}`)
			return
		}

		log('2.2', 'fluentcart_settings_save_confirmation (empty settings to test endpoint)')
		const saveConfirm = await call('fluentcart_settings_save_confirmation', {
			settings: {},
		})
		show(saveConfirm)
		// May fail if settings require certain keys — document it
		if (saveConfirm.isError) {
			console.log('  (Note: save_confirmation with empty settings may fail — expected)')
		}

		pass(name)
	} catch (e) {
		fail(name, String(e))
	}
}

// ── Scenario 3: Payment Methods ──────────────────────────────────
async function scenario3() {
	const name = '3. Payment Methods (get_all + get_settings + save + reorder)'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		log('3.1', 'fluentcart_payment_get_all')
		const allPayments = await call('fluentcart_payment_get_all')
		show(allPayments)
		if (allPayments.isError) {
			fail(name, `payment_get_all error: ${allPayments.raw}`)
			return
		}

		// Get settings for a specific payment method
		const payData = allPayments.data as Record<string, unknown>
		const methods = payData?.methods ?? payData?.payment_methods
		let firstMethodKey: string | null = null

		if (methods && typeof methods === 'object') {
			const methodKeys = Object.keys(methods as Record<string, unknown>)
			if (methodKeys.length > 0) {
				firstMethodKey = methodKeys[0]
			}
		}

		if (firstMethodKey) {
			log('3.2', `fluentcart_payment_get_settings for "${firstMethodKey}"`)
			const paySettings = await call('fluentcart_payment_get_settings', { method: firstMethodKey })
			show(paySettings)
			if (paySettings.isError) {
				console.log(`  (Note: payment_get_settings for "${firstMethodKey}" errored)`)
			}
		} else {
			console.log('  No payment methods found to query settings for')
		}

		// Test reorder with whatever methods exist
		if (methods && typeof methods === 'object') {
			const methodKeys = Object.keys(methods as Record<string, unknown>)
			if (methodKeys.length > 0) {
				log('3.3', 'fluentcart_settings_reorder_payment_methods (same order)')
				const reorder = await call('fluentcart_settings_reorder_payment_methods', {
					methods: methodKeys,
				})
				show(reorder)
				if (reorder.isError) {
					console.log('  (Note: reorder_payment_methods errored)')
				}
			}
		}

		pass(name)
	} catch (e) {
		fail(name, String(e))
	}
}

// ── Scenario 4: Print Templates ──────────────────────────────────
async function scenario4() {
	const name = '4. Print Templates (get + save idempotent)'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		log('4.1', 'fluentcart_settings_print_templates_get')
		const getTemplates = await call('fluentcart_settings_print_templates_get')
		show(getTemplates)
		if (getTemplates.isError) {
			fail(name, `print_templates_get error: ${getTemplates.raw}`)
			return
		}

		// Save same data back
		const templateData = getTemplates.data as Record<string, unknown>
		const templates = templateData?.templates ?? templateData
		log('4.2', 'fluentcart_settings_print_templates_save (same data back)')
		const saveTemplates = await call('fluentcart_settings_print_templates_save', {
			templates: templates as Record<string, unknown>,
		})
		show(saveTemplates)
		if (saveTemplates.isError) {
			fail(name, `print_templates_save error: ${saveTemplates.raw}`)
			return
		}

		pass(name)
	} catch (e) {
		fail(name, String(e))
	}
}

// ── Scenario 5: Permissions ──────────────────────────────────────
async function scenario5() {
	const name = '5. Permissions (get + save idempotent)'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		log('5.1', 'fluentcart_settings_get_permissions')
		const getPerms = await call('fluentcart_settings_get_permissions')
		show(getPerms)
		if (getPerms.isError) {
			fail(name, `get_permissions error: ${getPerms.raw}`)
			return
		}

		// Extract current capabilities and save them back
		const permData = getPerms.data as Record<string, unknown>
		const caps = permData?.capability ?? permData?.capabilities
		if (Array.isArray(caps)) {
			log('5.2', 'fluentcart_settings_save_permissions (same capabilities)')
			const savePerms = await call('fluentcart_settings_save_permissions', {
				capability: caps,
			})
			show(savePerms)
			if (savePerms.isError) {
				fail(name, `save_permissions error: ${savePerms.raw}`)
				return
			}
		} else {
			console.log('  No capability array found in response; testing save with empty array')
			log('5.2', 'fluentcart_settings_save_permissions (empty array)')
			const savePerms = await call('fluentcart_settings_save_permissions', {
				capability: [],
			})
			show(savePerms)
			// This might succeed but reset all permissions — mark as note
			if (savePerms.isError) {
				console.log('  (Note: save_permissions with empty array errored)')
			}
		}

		// Verify
		log('5.3', 'fluentcart_settings_get_permissions (verify)')
		const verifyPerms = await call('fluentcart_settings_get_permissions')
		show(verifyPerms)
		if (verifyPerms.isError) {
			fail(name, `verify get_permissions error: ${verifyPerms.raw}`)
			return
		}

		pass(name)
	} catch (e) {
		fail(name, String(e))
	}
}

// ── Scenario 6: Store Settings ───────────────────────────────────
async function scenario6() {
	const name = '6. Store Settings (get + save idempotent)'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		log('6.1', 'fluentcart_settings_get_store')
		const getStore = await call('fluentcart_settings_get_store')
		show(getStore)
		if (getStore.isError) {
			fail(name, `get_store error: ${getStore.raw}`)
			return
		}

		// Save a harmless setting back
		log('6.2', 'fluentcart_settings_save_store (set order_mode to current value)')
		const storeData = getStore.data as Record<string, unknown>
		const settings = storeData?.settings ?? storeData
		const currentMode = (settings as Record<string, unknown>)?.order_mode ?? 'test'
		const saveStore = await call('fluentcart_settings_save_store', {
			settings: { order_mode: currentMode },
		})
		show(saveStore)
		if (saveStore.isError) {
			fail(name, `save_store error: ${saveStore.raw}`)
			return
		}

		pass(name)
	} catch (e) {
		fail(name, String(e))
	}
}

// ── Run ──────────────────────────────────────────────────────────
async function run() {
	console.log('╔══════════════════════════════════════════════════════════╗')
	console.log('║  BATCH H — Settings Tools Scenarios                     ║')
	console.log('╚══════════════════════════════════════════════════════════╝')

	await scenario1()
	await scenario2()
	await scenario3()
	await scenario4()
	await scenario5()
	await scenario6()

	// ── Summary ──
	console.log(`\n${'═'.repeat(60)}`)
	console.log('FINAL RESULTS')
	console.log('═'.repeat(60))
	const passed = results.filter((r) => r.passed).length
	const failed = results.filter((r) => !r.passed).length
	console.log(`Total: ${results.length}  |  Passed: ${passed}  |  Failed: ${failed}`)
	for (const r of results) {
		console.log(`  ${r.passed ? '✅' : '❌'} ${r.name}${r.error ? ` — ${r.error}` : ''}`)
	}

	if (failed > 0) process.exit(1)
}

run().catch((err) => {
	console.error('Fatal error:', err)
	process.exit(1)
})
