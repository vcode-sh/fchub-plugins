/**
 * Re-test skipped product & variant tools from the first run.
 * The first run had variant ID extraction issues and missing attribute groups.
 * Run: cd /Users/tomrobak/_projects_/fchub-plugins/fluentcart-mcp && set -a && source .env && set +a && npx tsx tests/_scenarios-deep-products-skipped.ts
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
	console.log(`\n${'='.repeat(70)}`)
	console.log(`STEP: ${step}`)
	console.log(`${detail}`)
}

function show(r: ToolResult, maxLen = 1200) {
	const status = r.isError ? 'ERROR' : 'OK'
	const preview = r.raw.length > maxLen ? `${r.raw.slice(0, maxLen)}...` : r.raw
	console.log(`  Result: ${status}`)
	console.log(`  ${preview}`)
}

type Finding = {
	tool: string
	status: 'PASS' | 'FAIL' | 'BUG' | 'UPSTREAM_BUG' | 'SKIP'
	severity?: 'P0' | 'P1' | 'P2'
	detail: string
}
const findings: Finding[] = []

function record(tool: string, status: Finding['status'], detail: string, severity?: Finding['severity']) {
	findings.push({ tool, status, detail, severity })
	const sev = severity ? ` [${severity}]` : ''
	console.log(`  >> ${status}${sev}: ${detail}`)
}

async function main() {
	console.log(`\nFluentCart MCP — Deep Products: Skipped Tool Re-tests`)
	console.log(`${'='.repeat(70)}`)

	// SETUP: Create a test product with a variant (fix the variant ID issue)
	log('SETUP', 'Creating test product')
	const createRes = await call('fluentcart_product_create', {
		post_title: 'MCP Retest Product - Skipped',
		post_status: 'draft',
		fulfillment_type: 'digital',
	})
	show(createRes)
	const createData = createRes.data as Record<string, unknown>
	const createInner = createData?.data as Record<string, unknown> | undefined
	const testProductId = createInner?.ID as number ?? null
	const firstVariant = createInner?.variant as Record<string, unknown> | undefined
	let testVariantId = firstVariant?.id as number ?? null
	console.log(`  Test product ID: ${testProductId}, initial variant ID: ${testVariantId}`)

	if (!testProductId) {
		console.error('FATAL: Could not create test product. Aborting.')
		process.exit(1)
	}

	// Publish and add a proper variant
	log('SETUP', 'Adding variant via pricing update')
	const pricingRes = await call('fluentcart_product_pricing_update', {
		product_id: testProductId,
		post_status: 'publish',
		variants: [{
			title: 'Retest Variant',
			price: 50,
			sku: 'MCP-RETEST-001',
		}],
	})
	show(pricingRes)

	// Get the product to find variant IDs
	const getRes = await call('fluentcart_product_get', { product_id: testProductId })
	const pData = getRes.data as Record<string, unknown>
	const product = (pData?.product ?? pData) as Record<string, unknown>
	const variants = (product?.variants ?? []) as Record<string, unknown>[]
	if (variants.length > 0) {
		testVariantId = variants[0].id as number
	}
	console.log(`  Final variant ID: ${testVariantId}`)

	// ====================================================================
	// TEST: product_bundle_save
	// ====================================================================
	log('TEST', 'fluentcart_product_bundle_save — Save bundle configuration')
	if (testVariantId) {
		const bundleSaveRes = await call('fluentcart_product_bundle_save', {
			variation_id: testVariantId,
			bundle_items: [],
		})
		show(bundleSaveRes)
		if (bundleSaveRes.isError) {
			const msg = bundleSaveRes.raw
			if (msg.includes('no_route') || msg.includes('No route') || msg.includes('rest_no_route')) {
				record('product_bundle_save', 'UPSTREAM_BUG', 'Bundle route not available (module not enabled)', 'P2')
			} else {
				record('product_bundle_save', 'FAIL', `Error: ${msg.slice(0, 200)}`, 'P1')
			}
		} else {
			record('product_bundle_save', 'PASS', 'Bundle saved successfully')
		}
	}

	// ====================================================================
	// TEST: variant_set_media
	// ====================================================================
	log('TEST', 'fluentcart_variant_set_media — Set variant media')
	if (testVariantId) {
		const setMediaRes = await call('fluentcart_variant_set_media', {
			variant_id: testVariantId,
			media_id: 0,
		})
		show(setMediaRes)
		if (setMediaRes.isError) {
			record('variant_set_media', 'FAIL', `Error: ${setMediaRes.raw.slice(0, 200)}`, 'P1')
		} else {
			record('variant_set_media', 'PASS', 'Set media endpoint works (media_id=0 clears media)')
		}
	}

	// ====================================================================
	// TEST: variant_upgrade_paths
	// ====================================================================
	log('TEST', 'fluentcart_variant_upgrade_paths — Get variant upgrade paths')
	if (testVariantId) {
		const varUpgradeRes = await call('fluentcart_variant_upgrade_paths', {
			variant_id: testVariantId,
		})
		show(varUpgradeRes)
		if (varUpgradeRes.isError) {
			record('variant_upgrade_paths', 'FAIL', `Error: ${varUpgradeRes.raw.slice(0, 200)}`, 'P1')
		} else {
			record('variant_upgrade_paths', 'PASS', 'Variant upgrade paths returned')
		}
	}

	// ====================================================================
	// TEST: product_variant_option_update (needs attribute groups with terms)
	// ====================================================================
	log('TEST', 'fluentcart_product_variant_option_update — Update variant options')
	// First check attribute groups
	const attrGroupsRes = await call('fluentcart_attribute_group_list', {})
	const attrData = attrGroupsRes.data as Record<string, unknown>

	// The response may have groups inside a paginated wrapper
	let attrGroups: Record<string, unknown>[] = []
	if (attrData?.groups) {
		const groupsWrapper = attrData.groups as Record<string, unknown>
		if (Array.isArray(groupsWrapper.data)) {
			attrGroups = groupsWrapper.data as Record<string, unknown>[]
		} else if (Array.isArray(groupsWrapper)) {
			attrGroups = groupsWrapper as unknown as Record<string, unknown>[]
		}
	}
	console.log(`  Found ${attrGroups.length} attribute groups`)

	// Get terms for the first group
	if (attrGroups.length > 0) {
		const groupId = attrGroups[0].id as number
		console.log(`  Using group ID: ${groupId}`)

		const termsRes = await call('fluentcart_attribute_term_list', { group_id: groupId })
		show(termsRes)
		const termsData = termsRes.data as Record<string, unknown>

		let terms: Record<string, unknown>[] = []
		if (Array.isArray(termsData?.terms)) {
			terms = termsData.terms as Record<string, unknown>[]
		} else if (termsData?.terms && typeof termsData.terms === 'object') {
			const tw = termsData.terms as Record<string, unknown>
			if (Array.isArray(tw.data)) terms = tw.data as Record<string, unknown>[]
		}
		console.log(`  Found ${terms.length} terms in group ${groupId}`)

		if (terms.length > 0) {
			const termIds = terms.map(t => t.id as number).slice(0, 2)
			console.log(`  Using term IDs: ${termIds}`)

			const varOptRes = await call('fluentcart_product_variant_option_update', {
				product_id: testProductId,
				variation_type: 'advanced_variations',
				options: [{ id: groupId, variants: termIds }],
			})
			show(varOptRes)
			if (varOptRes.isError) {
				const msg = varOptRes.raw
				if (msg.includes('Illegal data')) {
					record('product_variant_option_update', 'PASS', 'Returns "Illegal data" for non-advanced product (expected per tool description)')
				} else {
					record('product_variant_option_update', 'FAIL', `Error: ${msg.slice(0, 200)}`, 'P1')
				}
			} else {
				record('product_variant_option_update', 'PASS', 'Variant options updated successfully')
			}
		} else {
			record('product_variant_option_update', 'SKIP', `No terms found in group ${groupId}`, 'P2')
		}
	} else {
		record('product_variant_option_update', 'SKIP', 'No attribute groups found on the store', 'P2')
	}

	// ====================================================================
	// TEST: product_create_dummy — try with a category param
	// ====================================================================
	log('TEST', 'fluentcart_product_create_dummy — Retry with category workaround')
	// The error was: Argument #1 ($category) must be of type string, null given
	// The MCP schema only has `count` but the API needs a category
	const dummyRes = await call('fluentcart_product_create_dummy', { count: 1 })
	show(dummyRes)
	if (dummyRes.isError) {
		const msg = dummyRes.raw
		if (msg.includes('$category') && msg.includes('null given')) {
			record('product_create_dummy', 'BUG',
				'MCP schema missing required `category` param. FluentCart DummyProductService::create() requires a string category arg but MCP only sends count. Schema needs category field.',
				'P1')
		} else {
			record('product_create_dummy', 'FAIL', `Error: ${msg.slice(0, 200)}`, 'P1')
		}
	} else {
		record('product_create_dummy', 'PASS', 'Dummy product(s) created')
	}

	// ====================================================================
	// TEST: product_upgrade_path_save — with proper params
	// ====================================================================
	log('TEST', 'fluentcart_product_upgrade_path_save — Retry with required fields')
	// The error was: from_variant and to_variants are required
	// The MCP schema only has product_id - it's missing from_variant and to_variants
	if (testVariantId) {
		// Create a second variant for the upgrade path
		const v2Res = await call('fluentcart_variant_create', {
			product_id: testProductId,
			title: 'Upgrade Target Variant',
			price: 100,
			sku: 'MCP-UPGRADE-TARGET',
		})
		show(v2Res)
		const v2Data = v2Res.data as Record<string, unknown>
		const v2Inner = v2Data?.data as Record<string, unknown> | undefined
		const v2Id = v2Inner?.id as number ?? null
		console.log(`  Second variant ID: ${v2Id}`)

		if (v2Id) {
			// Try upgrade path save — the schema doesn't expose from_variant/to_variants
			// but we can test if passing them as extra body params works
			const upgRes = await call('fluentcart_product_upgrade_path_save', {
				product_id: testProductId,
				from_variant: testVariantId,
				to_variants: [v2Id],
			})
			show(upgRes)
			if (upgRes.isError) {
				const msg = upgRes.raw
				if (msg.includes('from_variant') || msg.includes('to_variants')) {
					record('product_upgrade_path_save', 'BUG',
						'MCP schema missing required fields: from_variant (number) and to_variants (number[]). The MCP tool only exposes product_id but the API requires from_variant and to_variants.',
						'P1')
				} else {
					record('product_upgrade_path_save', 'FAIL', `Error: ${msg.slice(0, 200)}`, 'P1')
				}
			} else {
				record('product_upgrade_path_save', 'PASS', 'Upgrade path created with extra params passed through')
			}

			// Clean up second variant
			await call('fluentcart_variant_delete', { variant_id: v2Id })
		}
	}

	// ====================================================================
	// CLEANUP
	// ====================================================================
	log('CLEANUP', 'Removing test products')
	await call('fluentcart_product_delete', { product_id: testProductId })
	console.log(`  Deleted product ${testProductId}`)

	// Clean up leftover dummy products / test products
	const cleanupList = await call('fluentcart_product_list', { search: 'MCP', per_page: 50 })
	const cleanupData = cleanupList.data as Record<string, unknown>
	const cleanupProducts = cleanupData?.products as Record<string, unknown> | undefined
	const cleanupArr = (cleanupProducts?.data ?? []) as Record<string, unknown>[]
	for (const p of cleanupArr) {
		const pid = p.ID as number
		const title = p.post_title as string
		if (title?.includes('MCP') && title?.includes('Test') || title?.includes('Retest') || title?.includes('Copy')) {
			console.log(`  Cleaning up: ${pid} - ${title}`)
			await call('fluentcart_product_delete', { product_id: pid })
		}
	}

	// ====================================================================
	// SUMMARY
	// ====================================================================
	console.log(`\n${'='.repeat(70)}`)
	console.log('SUMMARY OF RE-TEST FINDINGS')
	console.log(`${'='.repeat(70)}`)

	for (const f of findings) {
		const sev = f.severity ? ` [${f.severity}]` : ''
		console.log(`  [${f.status}${sev}] ${f.tool}: ${f.detail}`)
	}
	console.log(`\nTotal: ${findings.length} tools re-tested`)
}

main().catch((err) => {
	console.error('FATAL:', err)
	process.exit(1)
})
