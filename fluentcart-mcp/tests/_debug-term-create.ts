/**
 * Debug: test what attribute_term_create actually needs
 */
import { resolveServerContext } from '../src/server.js'

const ctx = resolveServerContext()
const toolMap = new Map<string, (typeof ctx.tools)[0]>()
for (const t of ctx.tools) toolMap.set(t.name, t)

async function call(name: string, input: Record<string, unknown> = {}) {
	const tool = toolMap.get(name)
	if (!tool) return { isError: true, text: `Tool not found: ${name}` }
	const result = await tool.handler(input)
	const text = result.content[0]?.text ?? ''
	console.log(`  [${name}] ${result.isError ? 'ERROR' : 'OK'}: ${text.slice(0, 500)}`)
	return { isError: result.isError, text, data: JSON.parse(text) }
}

async function run() {
	// Create test group
	const group = await call('fluentcart_attribute_group_create', {
		title: 'Debug Color',
		slug: 'debug-color',
	})
	if (group.isError) {
		console.log('Cannot create group, aborting')
		return
	}
	const groupId = group.data?.data?.id ?? group.data?.id
	console.log(`\nGroup ID: ${groupId}\n`)

	// Try creating term with just title
	console.log('--- Test 1: title only ---')
	await call('fluentcart_attribute_term_create', {
		group_id: groupId,
		title: 'White',
	})

	// Try creating term with title + slug
	console.log('--- Test 2: title + slug ---')
	await call('fluentcart_attribute_term_create', {
		group_id: groupId,
		title: 'Blue',
		slug: 'blue',
	})

	// Try with name instead of title
	console.log('--- Test 3: name instead of title ---')
	await call('fluentcart_attribute_term_create', {
		group_id: groupId,
		name: 'Green',
	})

	// Also test with raw curl-like approach via the client
	console.log('\n--- Direct API test ---')
	const client = (ctx as unknown as { client: { post: (path: string, body: Record<string, unknown>) => Promise<{ data: unknown }> } }).client
	if (client) {
		try {
			const resp = await client.post(`/options/attr/group/${groupId}/term`, { title: 'Red' })
			console.log('Direct POST result:', JSON.stringify(resp.data).slice(0, 500))
		} catch (e) {
			console.log('Direct POST error:', e)
		}
	}

	// Cleanup
	console.log('\n--- Cleanup ---')
	await call('fluentcart_attribute_group_delete', { group_id: groupId })
}

run().catch(console.error)
