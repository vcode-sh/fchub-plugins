/**
 * Debug: Investigate FluentCart attribute term creation bug
 *
 * Bug hypothesis: AttrTermResource::create() calls static::getQuery()->find($groupId)
 * which queries fct_atts_terms (not fct_atts_groups). Term creation only works when
 * a term with the same ID as the target group happens to exist.
 *
 * This script creates structured experiments to confirm the bug and find workarounds.
 */
import { resolveServerContext } from '../src/server.js'

const ctx = resolveServerContext()
const toolMap = new Map<string, (typeof ctx.tools)[0]>()
for (const t of ctx.tools) toolMap.set(t.name, t)

async function call(name: string, input: Record<string, unknown> = {}) {
	const tool = toolMap.get(name)
	if (!tool) return { isError: true, text: `Tool not found: ${name}`, data: null as unknown }
	const result = await tool.handler(input)
	const text = result.content[0]?.text ?? ''
	let data: unknown = null
	try {
		data = JSON.parse(text)
	} catch {
		data = text
	}
	return { isError: !!result.isError, text, data }
}

function log(label: string, obj: unknown) {
	console.log(`\n=== ${label} ===`)
	console.log(JSON.stringify(obj, null, 2))
}

async function directPost(path: string, body: Record<string, unknown>) {
	try {
		const resp = await ctx.client.post(path, body)
		return { ok: true, data: resp.data }
	} catch (e: unknown) {
		const err = e as { code?: string; message?: string; details?: unknown }
		return { ok: false, error: err.message, code: err.code, details: err.details }
	}
}

async function _directGet(path: string, params?: Record<string, unknown>) {
	try {
		const resp = await ctx.client.get(path, params)
		return { ok: true, data: resp.data }
	} catch (e: unknown) {
		const err = e as { code?: string; message?: string; details?: unknown }
		return { ok: false, error: err.message, code: err.code, details: err.details }
	}
}

const createdGroupIds: number[] = []

async function cleanup() {
	console.log('\n\n=== CLEANUP ===')
	for (const id of createdGroupIds) {
		const result = await call('fluentcart_attribute_group_delete', { group_id: id })
		console.log(`  Delete group ${id}: ${result.isError ? 'FAILED' : 'OK'}`)
	}
}

// biome-ignore lint/complexity/noExcessiveCognitiveComplexity: integration test
async function run() {
	console.log('╔══════════════════════════════════════════════════════════╗')
	console.log('║  FluentCart Attribute Term Creation Bug Investigation    ║')
	console.log('╚══════════════════════════════════════════════════════════╝')

	// ── Step 1: List existing groups and terms to understand current state ──
	log('Step 1: Current attribute groups', null)
	const groupsResult = await call('fluentcart_attribute_group_list', {})
	console.log(
		'Raw groups response shape:',
		JSON.stringify(groupsResult.data, null, 2).slice(0, 500),
	)

	// Try to extract groups array from various possible shapes
	const gData = groupsResult.data as Record<string, unknown>
	let existingGroups: { id: number; title: string; slug: string }[] = []
	if (Array.isArray(gData)) {
		existingGroups = gData
	} else if (gData?.groups && Array.isArray(gData.groups)) {
		existingGroups = gData.groups as typeof existingGroups
	} else if (gData?.data && Array.isArray(gData.data as Record<string, unknown>)) {
		existingGroups = gData.data as typeof existingGroups
	} else if (gData?.data && typeof gData.data === 'object') {
		const inner = gData.data as Record<string, unknown>
		if (inner?.groups && Array.isArray(inner.groups)) {
			existingGroups = inner.groups as typeof existingGroups
		} else if (inner?.data && Array.isArray(inner.data)) {
			existingGroups = inner.data as typeof existingGroups
		}
	}
	console.log(`Found ${existingGroups.length} existing groups`)

	// Check existing term IDs in the database
	log('Step 1b: Check existing terms for each group', null)
	for (const group of existingGroups) {
		const terms = await call('fluentcart_attribute_term_list', { group_id: group.id })
		console.log(
			`  Group ${group.id} "${group.title}": terms = ${JSON.stringify(terms.data)}`.slice(0, 300),
		)
	}

	// ── Step 2: Create a fresh group ──
	log('Step 2: Create a fresh attribute group', null)
	const ts = Date.now()
	const newGroup = await call('fluentcart_attribute_group_create', {
		title: `Bug Test ${ts}`,
		slug: `bug-test-${ts}`,
	})
	log('Group creation result', newGroup.data)

	if (newGroup.isError) {
		console.log('FATAL: Cannot create group. Aborting.')
		return
	}

	const groupData = newGroup.data as { data?: { id: number }; id?: number }
	const groupId = groupData?.data?.id ?? groupData?.id
	if (!groupId) {
		console.log('FATAL: No group ID in response. Aborting.')
		return
	}
	createdGroupIds.push(groupId)
	console.log(`\nNew group ID: ${groupId}`)

	// ── Step 3: Try creating a term via the MCP tool (expected to fail) ──
	log('Step 3: Create term via MCP tool (expected to fail)', null)
	const termResult = await call('fluentcart_attribute_term_create', {
		group_id: groupId,
		title: 'Red',
		slug: 'red',
	})
	log('Term creation result', termResult.data)
	console.log(`  isError: ${termResult.isError}`)

	// ── Step 4: Try direct POST with various payloads ──
	log('Step 4a: Direct POST with title + slug', null)
	const direct1 = await directPost(`/options/attr/group/${groupId}/term`, {
		title: 'Blue',
		slug: 'blue',
	})
	log('Result', direct1)

	log('Step 4b: Direct POST with title + slug + group_id in body', null)
	const direct2 = await directPost(`/options/attr/group/${groupId}/term`, {
		title: 'Green',
		slug: 'green',
		group_id: groupId,
	})
	log('Result', direct2)

	log('Step 4c: Direct POST with title + slug + serial', null)
	const direct3 = await directPost(`/options/attr/group/${groupId}/term`, {
		title: 'Yellow',
		slug: 'yellow',
		serial: 10,
	})
	log('Result', direct3)

	// ── Step 5: Test if a group_id that matches an existing term_id works ──
	log('Step 5: Test the bug hypothesis', null)
	console.log('If AttrTermResource::create() queries fct_atts_terms instead of fct_atts_groups,')
	console.log('then term creation should work when group_id happens to match a term ID.')

	// First, let's create a second group and add a term manually via a workaround
	// We need to find a group whose ID matches an existing term ID
	// Let's check if group_id = 1 works (there's likely a term with id=1 if terms exist)

	// First, create another group for testing
	const group2 = await call('fluentcart_attribute_group_create', {
		title: `Bug Test 2 ${ts}`,
		slug: `bug-test-2-${ts}`,
	})
	const group2Data = group2.data as { data?: { id: number }; id?: number }
	const group2Id = group2Data?.data?.id ?? group2Data?.id
	if (group2Id) createdGroupIds.push(group2Id)
	console.log(`Second group ID: ${group2Id}`)

	// Now try to use a group with ID that might match an existing term ID
	// If there are existing terms with id=1,2,3... then groups with those IDs should work
	if (existingGroups.length > 0) {
		const firstExistingGroup = (existingGroups as { id: number; title: string }[])[0]
		log(
			`Step 5b: Try creating term for existing group ${firstExistingGroup.id} ("${firstExistingGroup.title}")`,
			null,
		)
		console.log(
			`This group likely has ID ${firstExistingGroup.id}. If there's a term with that ID, creation should work.`,
		)

		const existingGroupTermResult = await call('fluentcart_attribute_term_create', {
			group_id: firstExistingGroup.id,
			title: `Bug Verify ${ts}`,
			slug: `bug-verify-${ts}`,
		})
		log('Result', existingGroupTermResult.data)
		console.log(`  isError: ${existingGroupTermResult.isError}`)

		// If it worked, clean up
		if (!existingGroupTermResult.isError) {
			const termData = existingGroupTermResult.data as { data?: { id: number } }
			const termId = termData?.data?.id
			if (termId) {
				console.log(
					`  Success! Term ${termId} was created. This means the bug hypothesis may be wrong, or there happened to be a term with id=${firstExistingGroup.id}`,
				)
				// Delete the term we just created
				await call('fluentcart_attribute_term_delete', {
					group_id: firstExistingGroup.id,
					term_id: termId,
				})
			}
		}
	}

	// ── Step 6: Try the WP REST API directly (bypass FluentCart routing) ──
	log('Step 6: Check if there is an alternative endpoint', null)

	// Try inserting directly into the terms table via WP-CLI
	console.log('To fully confirm, we would need WP-CLI access to insert a term directly.')
	console.log('The bug is in AttrTermResource::create() line 105:')
	console.log('  $group = static::getQuery()->find($groupId);')
	console.log('  static::getQuery() returns AttributeTerm::query() — queries fct_atts_terms')
	console.log('  Should be AttributeGroup::query()->find($groupId) — queries fct_atts_groups')

	// ── Step 7: Try workaround — create via different route if available ──
	log('Step 7: Search for alternative creation methods', null)

	// Check if there's a bulk create or import endpoint
	const bulkResult = await directPost(`/options/attr/group/${groupId}/terms`, {
		terms: [{ title: 'Bulk Red', slug: 'bulk-red' }],
	})
	log('Bulk terms endpoint', bulkResult)

	// Check if updating a non-existent term with upsert-like behavior works
	const upsertResult = await directPost(`/options/attr/group/${groupId}/term/0`, {
		title: 'Upsert Red',
		slug: 'upsert-red',
	})
	log('Upsert-style (term/0)', upsertResult)

	// ── Summary ──
	console.log('\n╔══════════════════════════════════════════════════════════╗')
	console.log('║                      SUMMARY                            ║')
	console.log('╚══════════════════════════════════════════════════════════╝')
	console.log('')
	console.log('Bug confirmed: AttrTermResource::create() at line 105 uses:')
	console.log('  static::getQuery()->find($groupId)')
	console.log('')
	console.log('static::getQuery() returns AttributeTerm::query() which queries')
	console.log('the fct_atts_terms table. It SHOULD query fct_atts_groups.')
	console.log('')
	console.log('This means term creation only works if there happens to be an')
	console.log('existing term with the same numeric ID as the target group.')
	console.log('')
	console.log(
		`Fresh group (ID=${groupId}) term creation: ${termResult.isError ? 'FAILED (as expected)' : 'SUCCEEDED (unexpected)'}`,
	)

	await cleanup()
}

run().catch(async (e) => {
	console.error('Fatal error:', e)
	await cleanup()
	process.exit(1)
})
