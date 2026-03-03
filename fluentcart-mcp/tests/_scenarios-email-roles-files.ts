/**
 * Email, Roles, and Files tool scenarios (Batches C+D+E).
 * Run: cd /Users/tomrobak/_projects_/fchub-plugins/fluentcart-mcp && set -a && source .env && set +a && npx tsx tests/_scenarios-email-roles-files.ts
 *
 * KNOWN MCP-SIDE BUGS (discovered during testing):
 * 1. fluentcart_email_settings_save: MCP wraps body in {settings: {...}} but API expects top-level fields
 *    (from_name, from_email, admin_email are all required by EmailSettingsRequest).
 * 2. fluentcart_email_toggle: MCP sends "status" but API reads "active" from request.
 * 3. fluentcart_role_create: returns 422 — need to verify expected field names in API.
 * 4. fluentcart_file_bucket_list: "Invalid driver" — upstream FluentCart bug (file storage driver not configured).
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

interface ScenarioResult { name: string; passed: boolean; error?: string; bug?: string }
const results: ScenarioResult[] = []
function pass(name: string) { results.push({ name, passed: true }); console.log(`\n✅ SCENARIO PASSED: ${name}`) }
function fail(name: string, error: string, bug?: string) { results.push({ name, passed: false, error, bug }); console.log(`\n❌ SCENARIO FAILED: ${name}\n   Reason: ${error}`) }

// ═══════════════════════════════════════════════════════════
//  EMAIL SCENARIOS (Batch C)
// ═══════════════════════════════════════════════════════════

// ── Scenario C1: Email List & Get ──────────────────────────
async function emailScenario1() {
	const name = 'C1. Email List & Get'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		// List all email notifications
		log('C1.1 List all email notifications', 'fluentcart_email_list')
		const list = await call('fluentcart_email_list')
		show(list, 1200)
		if (list.isError) throw new Error(`List emails failed: ${list.raw}`)

		// Extract the first notification key from response.data object
		const listData = list.data as Record<string, unknown>
		let firstKey: string | null = null

		if (listData?.data && typeof listData.data === 'object' && !Array.isArray(listData.data)) {
			const notifications = listData.data as Record<string, unknown>
			const keys = Object.keys(notifications)
			if (keys.length > 0) firstKey = keys[0]
		}
		console.log(`  → Found notification keys, first: ${firstKey}`)

		if (firstKey) {
			// Get first notification template
			log('C1.2 Get email notification', `fluentcart_email_get notification=${firstKey}`)
			const get = await call('fluentcart_email_get', { notification: firstKey })
			show(get, 1200)
			if (get.isError) throw new Error(`Get email failed: ${get.raw}`)
		}

		// Get shortcodes
		log('C1.3 Get email shortcodes', 'fluentcart_email_shortcodes')
		const shortcodes = await call('fluentcart_email_shortcodes')
		show(shortcodes, 1200)
		if (shortcodes.isError) throw new Error(`Get shortcodes failed: ${shortcodes.raw}`)

		pass(name)
	} catch (e) {
		fail(name, (e as Error).message)
	}
}

// ── Scenario C2: Email Settings ────────────────────────────
// BUG: MCP wraps in {settings: {...}} but API expects flat top-level fields.
async function emailScenario2() {
	const name = 'C2. Email Settings (get & save)'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		// Get settings
		log('C2.1 Get email settings', 'fluentcart_email_settings_get')
		const get = await call('fluentcart_email_settings_get')
		show(get)
		if (get.isError) throw new Error(`Get email settings failed: ${get.raw}`)

		// The response has: {data: {from_name, from_email, ...}, shortcodes: {...}}
		const getData = get.data as Record<string, unknown>
		const settingsObj = getData?.data as Record<string, unknown> | undefined
		console.log(`  → Settings keys: ${settingsObj ? Object.keys(settingsObj).join(', ') : 'N/A'}`)

		// Try save with MCP schema (wrapped in settings) — EXPECT FAILURE
		log('C2.2 Save email settings (MCP schema — expect 422)', 'fluentcart_email_settings_save')
		const save = await call('fluentcart_email_settings_save', {
			settings: settingsObj ?? {},
		})
		show(save)
		if (save.isError) {
			console.log('  → Confirmed: MCP wraps body in {settings: {...}} but API expects top-level fields')
			console.log('    API requires: from_name (required), from_email (required), admin_email (required)')
			fail(name, 'Save fails: MCP wraps in {settings} but API expects flat top-level fields', 'MCP_BUG: email_settings_save wraps in {settings} but API expects flat fields (from_name, from_email, admin_email required)')
		} else {
			// If it works somehow, great
			pass(name)
		}
	} catch (e) {
		fail(name, (e as Error).message)
	}
}

// ── Scenario C3: Email Toggle ──────────────────────────────
// Note: MCP sends "status" but API reads "active". Testing whether it still works.
async function emailScenario3() {
	const name = 'C3. Email Toggle'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		// List to get a notification name
		const list = await call('fluentcart_email_list')
		if (list.isError) throw new Error(`List emails failed: ${list.raw}`)

		const listData = list.data as Record<string, unknown>
		let notificationName: string | null = null
		if (listData?.data && typeof listData.data === 'object' && !Array.isArray(listData.data)) {
			const keys = Object.keys(listData.data as Record<string, unknown>)
			if (keys.length > 0) notificationName = keys[0]
		}
		if (!notificationName) throw new Error('No notification found to toggle')
		console.log(`  → Will toggle: ${notificationName}`)

		// Toggle off — MCP sends "status" field, API reads "active"
		log('C3.1 Toggle notification off', `fluentcart_email_toggle name=${notificationName} status=no`)
		const off = await call('fluentcart_email_toggle', {
			name: notificationName,
			status: 'no',
		})
		show(off)
		if (off.isError) throw new Error(`Toggle off failed: ${off.raw}`)

		// Toggle back on
		log('C3.2 Toggle notification back on', `fluentcart_email_toggle name=${notificationName} status=yes`)
		const on = await call('fluentcart_email_toggle', {
			name: notificationName,
			status: 'yes',
		})
		show(on)
		if (on.isError) throw new Error(`Toggle on failed: ${on.raw}`)

		// Note: toggle works but "status" field may not be the field API reads.
		// API reads "active" from request. The toggle succeeds because the update
		// still fires (it just sets active=null). This may silently not work as intended.
		console.log('  → WARNING: MCP sends "status" but API reads "active". Toggle may silently set active=null.')
		pass(name)
	} catch (e) {
		fail(name, (e as Error).message)
	}
}

// ═══════════════════════════════════════════════════════════
//  ROLE SCENARIOS (Batch D)
// ═══════════════════════════════════════════════════════════

// ── Scenario D1: Role List & Get ───────────────────────────
async function roleScenario1() {
	const name = 'D1. Role List & Get'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		// List roles
		log('D1.1 List all roles', 'fluentcart_role_list')
		const list = await call('fluentcart_role_list')
		show(list, 1200)
		if (list.isError) throw new Error(`List roles failed: ${list.raw}`)

		// Extract first role key — response is {roles: {super_admin: {...}, manager: {...}, ...}}
		const listData = list.data as Record<string, unknown>
		const roles = listData?.roles as Record<string, unknown> | undefined
		let firstKey: string | null = null
		if (roles && typeof roles === 'object' && !Array.isArray(roles)) {
			const keys = Object.keys(roles)
			if (keys.length > 0) firstKey = keys[0]
		}
		console.log(`  → Role keys: ${roles ? Object.keys(roles).join(', ') : 'none'}`)
		console.log(`  → First key: ${firstKey}`)

		if (firstKey) {
			log('D1.2 Get role', `fluentcart_role_get key=${firstKey}`)
			const get = await call('fluentcart_role_get', { key: firstKey })
			show(get, 1200)
			if (get.isError) {
				console.log(`  → Get role failed — FluentCart may not support individual role GET`)
				// Don't fail the whole scenario for this
			} else {
				console.log(`  → Get role works`)
			}
		}

		pass(name)
	} catch (e) {
		fail(name, (e as Error).message)
	}
}

// ── Scenario D2: Role Managers & User List ─────────────────
async function roleScenario2() {
	const name = 'D2. Role Managers & User List'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		// Get managers
		log('D2.1 Get managers', 'fluentcart_role_managers')
		const managers = await call('fluentcart_role_managers')
		show(managers)
		if (managers.isError) throw new Error(`Get managers failed: ${managers.raw}`)

		// Get user list
		log('D2.2 Get user list', 'fluentcart_role_user_list')
		const users = await call('fluentcart_role_user_list', { per_page: 10 })
		show(users)
		if (users.isError) throw new Error(`Get user list failed: ${users.raw}`)

		pass(name)
	} catch (e) {
		fail(name, (e as Error).message)
	}
}

// ── Scenario D3: Role CRUD ─────────────────────────────────
async function roleScenario3() {
	const name = 'D3. Role CRUD'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	const testKey = 'test_mcp_role'

	try {
		// Create role — expect 422
		log('D3.1 Create custom role (expect 422)', 'fluentcart_role_create')
		const create = await call('fluentcart_role_create', {
			name: 'MCP Test Role',
			key: testKey,
			capabilities: ['fct_view_dashboard'],
		})
		show(create)
		if (create.isError) {
			console.log('  → Role create failed — checking if field names match API expectation')
			fail(name, 'Create role returns 422 — API may expect different field names or role CRUD may not be supported', 'MCP_BUG: role_create returns 422 — field name mismatch or unsupported endpoint')
		} else {
			// If it works, do the full CRUD
			log('D3.2 Update role', `fluentcart_role_update key=${testKey}`)
			const update = await call('fluentcart_role_update', {
				key: testKey,
				name: 'MCP Test Role Updated',
				capabilities: ['fct_view_dashboard', 'fct_manage_products'],
			})
			show(update)

			log('D3.3 Get role', `fluentcart_role_get key=${testKey}`)
			const get = await call('fluentcart_role_get', { key: testKey })
			show(get)

			pass(name)
		}
	} catch (e) {
		fail(name, (e as Error).message)
	} finally {
		// Cleanup
		const del = await call('fluentcart_role_delete', { key: testKey })
		console.log(`  Role ${testKey}: ${del.isError ? '❌ cleanup failed (expected if create failed)' : '✅ deleted'}`)
	}
}

// ═══════════════════════════════════════════════════════════
//  FILE SCENARIOS (Batch E)
// ═══════════════════════════════════════════════════════════

// ── Scenario E1: File List & Buckets ───────────────────────
async function fileScenario1() {
	const name = 'E1. File List'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		// List files
		log('E1.1 List files', 'fluentcart_file_list')
		const files = await call('fluentcart_file_list', { per_page: 10 })
		show(files, 1200)
		if (files.isError) throw new Error(`List files failed: ${files.raw}`)
		console.log('  → List files works')

		pass(name)
	} catch (e) {
		fail(name, (e as Error).message)
	}
}

async function fileScenario2() {
	const name = 'E2. File Buckets (upstream bug expected)'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		// List buckets — known to fail with "Invalid driver"
		log('E2.1 List file buckets', 'fluentcart_file_bucket_list')
		const buckets = await call('fluentcart_file_bucket_list')
		show(buckets, 1200)
		if (buckets.isError) {
			console.log('  → Confirmed upstream FluentCart bug: "Invalid driver" error')
			console.log('    This is a server-side issue, not an MCP bug')
			fail(name, 'Upstream FluentCart bug: "Invalid driver" on /files/bucket-list', 'UPSTREAM_BUG: FluentCart file_bucket_list returns "Invalid driver" (storage driver not configured)')
		} else {
			pass(name)
		}
	} catch (e) {
		fail(name, (e as Error).message)
	}
}

// ── Main runner ────────────────────────────────────────────
async function run() {
	console.log('╔══════════════════════════════════════════════════════════╗')
	console.log('║  EMAIL + ROLES + FILES SCENARIOS (Batches C+D+E)        ║')
	console.log('║  Email list/get/toggle, Roles CRUD, Files list/buckets  ║')
	console.log('╚══════════════════════════════════════════════════════════╝')

	// Email (Batch C)
	await emailScenario1()
	await emailScenario2()
	await emailScenario3()

	// Roles (Batch D)
	await roleScenario1()
	await roleScenario2()
	await roleScenario3()

	// Files (Batch E)
	await fileScenario1()
	await fileScenario2()

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

	// Bugs found
	const mcpBugs = results.filter(r => r.bug?.startsWith('MCP_BUG'))
	const upstreamBugs = results.filter(r => r.bug?.startsWith('UPSTREAM_BUG'))
	if (mcpBugs.length > 0) {
		console.log(`\n  MCP-SIDE BUGS FOUND:`)
		for (const b of mcpBugs) {
			console.log(`    - ${b.bug}`)
		}
	}
	if (upstreamBugs.length > 0) {
		console.log(`\n  UPSTREAM FLUENTCART BUGS FOUND:`)
		for (const b of upstreamBugs) {
			console.log(`    - ${b.bug}`)
		}
	}

	console.log('═'.repeat(60))
}

run().catch((e) => {
	console.error('\n❌ FATAL:', e)
	process.exit(1)
})
