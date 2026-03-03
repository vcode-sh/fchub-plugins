/**
 * Verify: the updated MCP tool gives a clear error message for the FluentCart bug
 */
import { resolveServerContext } from '../src/server.js'

const ctx = resolveServerContext()
const toolMap = new Map<string, (typeof ctx.tools)[0]>()
for (const t of ctx.tools) toolMap.set(t.name, t)

async function main() {
	// Create a fresh group
	const groupTool = toolMap.get('fluentcart_attribute_group_create')!
	const grpResult = await groupTool.handler({
		title: 'Verify Fix Group',
		slug: 'verify-fix-group-' + Date.now(),
	})
	const grpText = grpResult.content[0]?.text ?? ''
	const grpData = JSON.parse(grpText) as { data?: { id: number }; id?: number }
	const groupId = grpData?.data?.id ?? grpData?.id
	console.log('Created group:', groupId)

	// Try creating a term (should fail with our improved message)
	const termTool = toolMap.get('fluentcart_attribute_term_create')!
	const termResult = await termTool.handler({
		group_id: groupId,
		title: 'Test Red',
		slug: 'test-red',
	})
	console.log('\nTerm creation result:')
	console.log('  isError:', termResult.isError)
	console.log('  text:', termResult.content[0]?.text)

	// Verify the error message mentions the bug
	const text = termResult.content[0]?.text ?? ''
	const hasBugInfo = text.includes('AttrTermResource') && text.includes('fct_atts_terms')
	console.log('\n  Contains bug explanation:', hasBugInfo)
	console.log('  Contains workaround:', text.includes('admin UI'))

	// Clean up
	const deleteTool = toolMap.get('fluentcart_attribute_group_delete')!
	await deleteTool.handler({ group_id: groupId })
	console.log('\nCleaned up group', groupId)

	console.log('\n' + (hasBugInfo ? 'PASS' : 'FAIL') + ': Custom error message works correctly')
}

main().catch(console.error)
