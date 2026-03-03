/**
 * Confirm: after SQL-inserting a term with id=1 into group_id=1,
 * can the API now create terms for group_id=1?
 */
import { resolveServerContext } from '../src/server.js'

const ctx = resolveServerContext()

async function main() {
	// Step 1: Try creating a term for group 1 via API
	// We just inserted term id=1 via SQL, so find(1) on fct_atts_terms should return it
	console.log('Test: Create term for group_id=1 (term id=1 exists in fct_atts_terms)')
	try {
		const resp = await ctx.client.post('/options/attr/group/1/term', {
			title: 'API Via Workaround',
			slug: 'api-via-workaround-' + Date.now(),
		})
		console.log('SUCCESS:', JSON.stringify(resp.data, null, 2))
	} catch (e) {
		console.log('FAILED:', (e as Error).message)
	}

	// Step 2: Create a new group (will get id=2 or higher) and try to create a term
	console.log('\nTest: Create term for a fresh group (no matching term ID)')
	try {
		const grp = await ctx.client.post('/options/attr/group', {
			title: 'Fresh Group',
			slug: 'fresh-group-' + Date.now(),
		})
		const groupId = (grp.data as { data: { id: number } }).data.id
		console.log('Created group with id:', groupId)

		try {
			const term = await ctx.client.post(`/options/attr/group/${groupId}/term`, {
				title: 'Should Fail',
				slug: 'should-fail-' + Date.now(),
			})
			console.log('UNEXPECTED SUCCESS:', JSON.stringify(term.data, null, 2))
		} catch (e) {
			console.log('EXPECTED FAILURE:', (e as Error).message)
		}

		// Clean up
		await ctx.client.delete(`/options/attr/group/${groupId}`)
		console.log(`Cleaned up group ${groupId}`)
	} catch (e) {
		console.log('Error:', (e as Error).message)
	}
}

main().catch(console.error)
