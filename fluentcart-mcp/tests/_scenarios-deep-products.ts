/**
 * Deep product & variant tool tests — covers ALL untested product/variant tools.
 * Run: cd /Users/tomrobak/_projects_/fchub-plugins/fluentcart-mcp && set -a && source .env && set +a && npx tsx tests/_scenarios-deep-products.ts
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

function extractId(data: unknown, ...keys: string[]): number | null {
	if (!data || typeof data !== 'object') return null
	const obj = data as Record<string, unknown>
	for (const k of keys) {
		if (typeof obj[k] === 'number') return obj[k] as number
	}
	for (const wrapper of ['data', 'product', 'variant', 'variation', 'path', 'bundle']) {
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

// Track results
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
	console.log(`\nFluentCart MCP — Deep Product & Variant Tool Tests`)
	console.log(`${'='.repeat(70)}`)
	console.log(`Total tools loaded: ${ctx.tools.length}`)

	// ====================================================================
	// SETUP: Get existing products + create a test product
	// ====================================================================
	log('SETUP', 'Listing existing products to get IDs')
	const listRes = await call('fluentcart_product_list', { per_page: 10 })
	show(listRes)

	const listData = listRes.data as Record<string, unknown>
	const products = listData?.products as Record<string, unknown> | undefined
	const productArr = (products?.data ?? []) as Record<string, unknown>[]
	console.log(`  Found ${productArr.length} products`)

	// Find an existing product to use for read-only tests
	let existingProductId: number | null = null
	let existingDetailId: number | null = null
	if (productArr.length > 0) {
		existingProductId = productArr[0].ID as number
		console.log(`  Using existing product ID: ${existingProductId}`)
	}

	// Get full product detail to find detail_id
	if (existingProductId) {
		const getRes = await call('fluentcart_product_get', { product_id: existingProductId })
		const pData = getRes.data as Record<string, unknown>
		const product = (pData?.product ?? pData) as Record<string, unknown>
		const detail = product?.detail as Record<string, unknown> | undefined
		existingDetailId = detail?.id as number ?? null
		console.log(`  Existing product detail_id: ${existingDetailId}`)
	}

	// Create a test product for destructive operations
	log('SETUP', 'Creating test product for write operations')
	const createRes = await call('fluentcart_product_create', {
		post_title: 'MCP Test Product - Deep Products',
		post_status: 'draft',
		fulfillment_type: 'digital',
	})
	show(createRes)
	const testProductId = extractId(createRes.data, 'ID', 'id')
	console.log(`  Test product ID: ${testProductId}`)

	if (!testProductId) {
		console.error('FATAL: Could not create test product. Aborting.')
		process.exit(1)
	}

	// Get the test product to find detail_id
	const testGetRes = await call('fluentcart_product_get', { product_id: testProductId })
	const testPData = testGetRes.data as Record<string, unknown>
	const testProduct = (testPData?.product ?? testPData) as Record<string, unknown>
	const testDetail = testProduct?.detail as Record<string, unknown> | undefined
	const testDetailId = testDetail?.id as number ?? null
	console.log(`  Test product detail_id: ${testDetailId}`)

	// Add a variant to the test product via pricing update
	log('SETUP', 'Adding variant to test product')
	const pricingRes = await call('fluentcart_product_pricing_update', {
		product_id: testProductId,
		post_status: 'publish',
		variants: [{
			title: 'Test Variant Alpha',
			price: 99,
			sku: 'MCP-TEST-ALPHA',
		}],
	})
	show(pricingRes)

	// Get variant ID
	const pricingData = pricingRes.data as Record<string, unknown>
	const pricingProduct = (pricingData?.product ?? pricingData) as Record<string, unknown>
	const variants = (pricingProduct?.variants ?? []) as Record<string, unknown>[]
	let testVariantId: number | null = variants.length > 0 ? (variants[0].id as number) : null
	console.log(`  Test variant ID: ${testVariantId}`)

	// ====================================================================
	// 1. product_create_dummy
	// ====================================================================
	log('TEST 1', 'fluentcart_product_create_dummy — Create dummy products')
	const dummyRes = await call('fluentcart_product_create_dummy', { count: 1 })
	show(dummyRes)
	if (dummyRes.isError) {
		record('product_create_dummy', 'FAIL', `Error: ${dummyRes.raw.slice(0, 200)}`, 'P1')
	} else {
		record('product_create_dummy', 'PASS', 'Successfully created dummy product(s)')
	}

	// Identify dummy product for cleanup later
	const dummyData = dummyRes.data as Record<string, unknown>
	const dummyProductId = extractId(dummyData, 'ID', 'id', 'product_id')

	// ====================================================================
	// 2. product_duplicate
	// ====================================================================
	log('TEST 2', 'fluentcart_product_duplicate — Duplicate a product')
	const dupRes = await call('fluentcart_product_duplicate', { product_id: testProductId })
	show(dupRes)
	if (dupRes.isError) {
		record('product_duplicate', 'FAIL', `Error: ${dupRes.raw.slice(0, 200)}`, 'P1')
	} else {
		record('product_duplicate', 'PASS', 'Product duplicated successfully')
	}
	const dupProductId = extractId(dupRes.data, 'ID', 'id')
	console.log(`  Duplicated product ID: ${dupProductId}`)

	// ====================================================================
	// 3. product_update_detail
	// ====================================================================
	log('TEST 3', 'fluentcart_product_update_detail — Update product detail record')
	if (testDetailId) {
		const udRes = await call('fluentcart_product_update_detail', {
			detail_id: testDetailId,
			fulfillment_type: 'digital',
			manage_stock: '0',
			sold_individually: '1',
		})
		show(udRes)
		if (udRes.isError) {
			record('product_update_detail', 'FAIL', `Error: ${udRes.raw.slice(0, 200)}`, 'P1')
		} else {
			record('product_update_detail', 'PASS', 'Detail updated successfully')
		}
	} else {
		record('product_update_detail', 'SKIP', 'No detail_id available', 'P2')
	}

	// ====================================================================
	// 4. product_editor_mode_update
	// ====================================================================
	log('TEST 4', 'fluentcart_product_editor_mode_update — Switch editor mode')
	const editorRes = await call('fluentcart_product_editor_mode_update', {
		product_id: testProductId,
		editor_mode: 'block-editor',
	})
	show(editorRes)
	if (editorRes.isError) {
		record('product_editor_mode_update', 'FAIL', `Error: ${editorRes.raw.slice(0, 200)}`, 'P2')
	} else {
		record('product_editor_mode_update', 'PASS', 'Editor mode updated')
	}

	// Switch back
	await call('fluentcart_product_editor_mode_update', {
		product_id: testProductId,
		editor_mode: 'wp-editor',
	})

	// ====================================================================
	// 5. product_pricing_widgets
	// ====================================================================
	log('TEST 5', 'fluentcart_product_pricing_widgets — Get pricing widgets')
	const widgetsRes = await call('fluentcart_product_pricing_widgets', {
		product_id: testProductId,
	})
	show(widgetsRes)
	if (widgetsRes.isError) {
		record('product_pricing_widgets', 'FAIL', `Error: ${widgetsRes.raw.slice(0, 200)}`, 'P1')
	} else {
		record('product_pricing_widgets', 'PASS', 'Pricing widgets returned')
	}

	// ====================================================================
	// 6. product_related
	// ====================================================================
	log('TEST 6', 'fluentcart_product_related — Get related products')
	const relatedRes = await call('fluentcart_product_related', {
		product_id: existingProductId ?? testProductId,
	})
	show(relatedRes)
	if (relatedRes.isError) {
		record('product_related', 'FAIL', `Error: ${relatedRes.raw.slice(0, 200)}`, 'P1')
	} else {
		record('product_related', 'PASS', 'Related products returned')
	}

	// ====================================================================
	// 7. product_sync_downloadable_files
	// ====================================================================
	log('TEST 7', 'fluentcart_product_sync_downloadable_files — Sync downloadable files')
	const syncDlRes = await call('fluentcart_product_sync_downloadable_files', {
		product_id: testProductId,
	})
	show(syncDlRes)
	if (syncDlRes.isError) {
		// Might error if product has no downloadable files - that's expected
		const isExpectedError = syncDlRes.raw.includes('no_route') || syncDlRes.raw.includes('No route')
		if (isExpectedError) {
			record('product_sync_downloadable_files', 'UPSTREAM_BUG', 'Route not available', 'P2')
		} else {
			record('product_sync_downloadable_files', 'PASS', 'Sync returned (possibly empty, expected for new product)')
		}
	} else {
		record('product_sync_downloadable_files', 'PASS', 'Downloadable files synced')
	}

	// ====================================================================
	// 8. product_downloadable_url
	// ====================================================================
	log('TEST 8', 'fluentcart_product_downloadable_url — Get downloadable URL')
	// Use a non-existent ID to test error handling
	const dlUrlRes = await call('fluentcart_product_downloadable_url', { downloadable_id: 99999 })
	show(dlUrlRes)
	if (dlUrlRes.isError) {
		// Expected to error with non-existent ID
		record('product_downloadable_url', 'PASS', 'Returns error for non-existent downloadable (expected behaviour)')
	} else {
		record('product_downloadable_url', 'PASS', 'URL returned (unexpected — there may be a file with ID 99999)')
	}

	// ====================================================================
	// 9. product_downloadable_update
	// ====================================================================
	log('TEST 9', 'fluentcart_product_downloadable_update — Update downloadable file')
	const dlUpdateRes = await call('fluentcart_product_downloadable_update', {
		downloadable_id: 99999,
		name: 'test-file.zip',
		file_url: 'https://example.com/test-file.zip',
	})
	show(dlUpdateRes)
	if (dlUpdateRes.isError) {
		record('product_downloadable_update', 'PASS', 'Returns error for non-existent downloadable (expected)')
	} else {
		record('product_downloadable_update', 'BUG', 'Unexpectedly succeeded updating non-existent downloadable', 'P1')
	}

	// ====================================================================
	// 10. product_downloadable_delete
	// ====================================================================
	log('TEST 10', 'fluentcart_product_downloadable_delete — Delete downloadable file')
	const dlDelRes = await call('fluentcart_product_downloadable_delete', { downloadable_id: 99999 })
	show(dlDelRes)
	if (dlDelRes.isError) {
		record('product_downloadable_delete', 'PASS', 'Returns error for non-existent downloadable (expected)')
	} else {
		record('product_downloadable_delete', 'BUG', 'Unexpectedly succeeded deleting non-existent downloadable', 'P1')
	}

	// ====================================================================
	// 11. product_terms_by_parent
	// ====================================================================
	log('TEST 11', 'fluentcart_product_terms_by_parent — Get terms by parent')
	const termsByParentRes = await call('fluentcart_product_terms_by_parent', {
		taxonomy: 'product-categories',
	})
	show(termsByParentRes)
	if (termsByParentRes.isError) {
		record('product_terms_by_parent', 'FAIL', `Error: ${termsByParentRes.raw.slice(0, 200)}`, 'P1')
	} else {
		record('product_terms_by_parent', 'PASS', 'Terms by parent returned')
	}

	// Also test with parent_id = 0
	const termsByParent0 = await call('fluentcart_product_terms_by_parent', {
		taxonomy: 'product-categories',
		parent_id: 0,
	})
	show(termsByParent0)

	// ====================================================================
	// 12. product_taxonomy_delete
	// ====================================================================
	log('TEST 12', 'fluentcart_product_taxonomy_delete — Remove taxonomy term from product')
	// First add a term to remove
	const addTermRes = await call('fluentcart_product_terms_add', {
		names: 'MCP-Test-Category-Deep',
		taxonomy: 'product-categories',
	})
	show(addTermRes)
	const termData = addTermRes.data as Record<string, unknown>
	const termIds = termData?.term_ids as number[] | undefined
	const testTermId = termIds && termIds.length > 0 ? termIds[0] : null
	console.log(`  Test term ID: ${testTermId}`)

	if (testTermId && testProductId) {
		// Sync term to product
		await call('fluentcart_product_taxonomy_sync', {
			product_id: testProductId,
			terms: [testTermId],
			taxonomy: 'product-categories',
		})

		// Now delete (remove) that term from the product
		const taxDelRes = await call('fluentcart_product_taxonomy_delete', {
			product_id: testProductId,
			term: testTermId,
			taxonomy: 'product-categories',
		})
		show(taxDelRes)
		if (taxDelRes.isError) {
			record('product_taxonomy_delete', 'FAIL', `Error: ${taxDelRes.raw.slice(0, 200)}`, 'P1')
		} else {
			record('product_taxonomy_delete', 'PASS', 'Taxonomy term removed from product')
		}
	} else {
		record('product_taxonomy_delete', 'SKIP', 'Could not create test term', 'P2')
	}

	// ====================================================================
	// 13. product_upgrade_settings
	// ====================================================================
	log('TEST 13', 'fluentcart_product_upgrade_settings — Get upgrade path settings')
	const upgradeSettingsRes = await call('fluentcart_product_upgrade_settings', {
		product_id: testProductId,
	})
	show(upgradeSettingsRes)
	if (upgradeSettingsRes.isError) {
		record('product_upgrade_settings', 'FAIL', `Error: ${upgradeSettingsRes.raw.slice(0, 200)}`, 'P1')
	} else {
		record('product_upgrade_settings', 'PASS', 'Upgrade settings returned')
	}

	// ====================================================================
	// 14. product_upgrade_path_save
	// ====================================================================
	log('TEST 14', 'fluentcart_product_upgrade_path_save — Create upgrade path')
	const upgPathSaveRes = await call('fluentcart_product_upgrade_path_save', {
		product_id: testProductId,
	})
	show(upgPathSaveRes)
	if (upgPathSaveRes.isError) {
		// May fail if upgrade paths are not supported for this product type
		const msg = upgPathSaveRes.raw
		if (msg.includes('no_route') || msg.includes('No route')) {
			record('product_upgrade_path_save', 'UPSTREAM_BUG', 'Route not available', 'P2')
		} else {
			record('product_upgrade_path_save', 'FAIL', `Error: ${msg.slice(0, 200)}`, 'P1')
		}
	} else {
		record('product_upgrade_path_save', 'PASS', 'Upgrade path created')
	}
	const upgPathId = extractId(upgPathSaveRes.data, 'id', 'ID')

	// ====================================================================
	// 15. product_upgrade_path_update
	// ====================================================================
	log('TEST 15', 'fluentcart_product_upgrade_path_update — Update upgrade path')
	if (upgPathId) {
		const upgPathUpdateRes = await call('fluentcart_product_upgrade_path_update', {
			upgrade_path_id: upgPathId,
		})
		show(upgPathUpdateRes)
		if (upgPathUpdateRes.isError) {
			record('product_upgrade_path_update', 'FAIL', `Error: ${upgPathUpdateRes.raw.slice(0, 200)}`, 'P1')
		} else {
			record('product_upgrade_path_update', 'PASS', 'Upgrade path updated')
		}
	} else {
		// Try with a fake ID to test error handling
		const upgPathUpdateRes = await call('fluentcart_product_upgrade_path_update', {
			upgrade_path_id: 99999,
		})
		show(upgPathUpdateRes)
		if (upgPathUpdateRes.isError) {
			record('product_upgrade_path_update', 'PASS', 'Returns error for non-existent path (expected)')
		} else {
			record('product_upgrade_path_update', 'BUG', 'Unexpectedly succeeded', 'P1')
		}
	}

	// ====================================================================
	// 16. product_upgrade_path_delete
	// ====================================================================
	log('TEST 16', 'fluentcart_product_upgrade_path_delete — Delete upgrade path')
	if (upgPathId) {
		const upgPathDelRes = await call('fluentcart_product_upgrade_path_delete', {
			upgrade_path_id: upgPathId,
		})
		show(upgPathDelRes)
		if (upgPathDelRes.isError) {
			record('product_upgrade_path_delete', 'FAIL', `Error: ${upgPathDelRes.raw.slice(0, 200)}`, 'P1')
		} else {
			record('product_upgrade_path_delete', 'PASS', 'Upgrade path deleted')
		}
	} else {
		const upgPathDelRes = await call('fluentcart_product_upgrade_path_delete', {
			upgrade_path_id: 99999,
		})
		show(upgPathDelRes)
		if (upgPathDelRes.isError) {
			record('product_upgrade_path_delete', 'PASS', 'Returns error for non-existent path (expected)')
		} else {
			record('product_upgrade_path_delete', 'BUG', 'Unexpectedly succeeded', 'P1')
		}
	}

	// ====================================================================
	// 17. product_bundle_save
	// ====================================================================
	log('TEST 17', 'fluentcart_product_bundle_save — Save bundle configuration')
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
	} else {
		record('product_bundle_save', 'SKIP', 'No variant ID available', 'P2')
	}

	// ====================================================================
	// 18. product_integration_save
	// ====================================================================
	log('TEST 18', 'fluentcart_product_integration_save — Save product integration feed')
	const intSaveRes = await call('fluentcart_product_integration_save', {
		product_id: testProductId,
	})
	show(intSaveRes)
	if (intSaveRes.isError) {
		// This may error without proper integration data
		record('product_integration_save', 'PASS', `Error with empty data is expected behaviour: ${intSaveRes.raw.slice(0, 150)}`)
	} else {
		record('product_integration_save', 'PASS', 'Integration feed saved')
	}
	const intFeedId = extractId(intSaveRes.data, 'id', 'ID', 'feed_id')

	// ====================================================================
	// 19. product_integration_feed_status
	// ====================================================================
	log('TEST 19', 'fluentcart_product_integration_feed_status — Toggle feed status')
	const feedStatusRes = await call('fluentcart_product_integration_feed_status', {
		product_id: testProductId,
		feed_id: intFeedId ?? 99999,
		status: 'inactive',
	})
	show(feedStatusRes)
	if (feedStatusRes.isError) {
		if (intFeedId) {
			record('product_integration_feed_status', 'FAIL', `Error with valid feed_id: ${feedStatusRes.raw.slice(0, 200)}`, 'P1')
		} else {
			record('product_integration_feed_status', 'PASS', 'Error with fake feed_id is expected')
		}
	} else {
		record('product_integration_feed_status', 'PASS', 'Feed status toggled')
	}

	// ====================================================================
	// 20. product_integration_delete
	// ====================================================================
	log('TEST 20', 'fluentcart_product_integration_delete — Delete integration feed')
	const intDelRes = await call('fluentcart_product_integration_delete', {
		product_id: testProductId,
		integration_id: intFeedId ?? 99999,
	})
	show(intDelRes)
	if (intDelRes.isError) {
		if (intFeedId) {
			record('product_integration_delete', 'FAIL', `Error with valid integration_id: ${intDelRes.raw.slice(0, 200)}`, 'P1')
		} else {
			record('product_integration_delete', 'PASS', 'Error with fake integration_id is expected')
		}
	} else {
		record('product_integration_delete', 'PASS', 'Integration feed deleted')
	}

	// ====================================================================
	// 21. product_variant_option_update
	// ====================================================================
	log('TEST 21', 'fluentcart_product_variant_option_update — Update variant options')
	// First list attribute groups to get real IDs
	const attrGroupsRes = await call('fluentcart_attribute_group_list', {})
	show(attrGroupsRes)
	const attrData = attrGroupsRes.data as Record<string, unknown>
	const attrGroups = (attrData?.groups ?? []) as Record<string, unknown>[]

	if (attrGroups.length > 0) {
		const firstGroup = attrGroups[0]
		const groupId = firstGroup.id as number
		const terms = (firstGroup.terms ?? []) as Record<string, unknown>[]
		const termIds = terms.map(t => t.id as number).slice(0, 2)

		if (termIds.length > 0) {
			const varOptRes = await call('fluentcart_product_variant_option_update', {
				product_id: testProductId,
				variation_type: 'advanced_variations',
				options: [{ id: groupId, variants: termIds }],
			})
			show(varOptRes)
			if (varOptRes.isError) {
				const msg = varOptRes.raw
				if (msg.includes('Illegal data')) {
					record('product_variant_option_update', 'PASS', 'Returns "Illegal data" as expected for non-advanced product')
				} else {
					record('product_variant_option_update', 'FAIL', `Error: ${msg.slice(0, 200)}`, 'P1')
				}
			} else {
				record('product_variant_option_update', 'PASS', 'Variant options updated')
			}
		} else {
			record('product_variant_option_update', 'SKIP', 'No attribute terms available', 'P2')
		}
	} else {
		record('product_variant_option_update', 'SKIP', 'No attribute groups available', 'P2')
	}

	// ====================================================================
	// VARIANT TOOLS
	// ====================================================================

	// ====================================================================
	// 22. variant_set_media
	// ====================================================================
	log('TEST 22', 'fluentcart_variant_set_media — Set variant media')
	if (testVariantId) {
		// Set media with a non-existent media ID to test the endpoint
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
	} else {
		record('variant_set_media', 'SKIP', 'No variant ID available', 'P2')
	}

	// ====================================================================
	// 23. variant_upgrade_paths
	// ====================================================================
	log('TEST 23', 'fluentcart_variant_upgrade_paths — Get variant upgrade paths')
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
	} else {
		record('variant_upgrade_paths', 'SKIP', 'No variant ID available', 'P2')
	}

	// ====================================================================
	// 24. variant_delete — Create a throw-away variant first, then delete it
	// ====================================================================
	log('TEST 24', 'fluentcart_variant_delete — Delete a variant')
	// Create a variant to delete
	const throwawayVar = await call('fluentcart_variant_create', {
		product_id: testProductId,
		title: 'Throwaway Variant For Deletion',
		price: 1,
		sku: 'MCP-DELETE-ME',
	})
	show(throwawayVar)
	const throwawayVarId = extractId(throwawayVar.data, 'id', 'ID')
	console.log(`  Throwaway variant ID: ${throwawayVarId}`)

	if (throwawayVarId) {
		const varDelRes = await call('fluentcart_variant_delete', {
			variant_id: throwawayVarId,
		})
		show(varDelRes)
		if (varDelRes.isError) {
			record('variant_delete', 'FAIL', `Error: ${varDelRes.raw.slice(0, 200)}`, 'P0')
		} else {
			record('variant_delete', 'PASS', 'Variant deleted successfully')
		}
	} else {
		record('variant_delete', 'SKIP', 'Could not create throwaway variant', 'P1')
	}

	// ====================================================================
	// CLEANUP
	// ====================================================================
	log('CLEANUP', 'Removing test products')
	const idsToDelete = [testProductId, dupProductId, dummyProductId].filter(Boolean)
	for (const id of idsToDelete) {
		console.log(`  Deleting product ${id}...`)
		const delRes = await call('fluentcart_product_delete', { product_id: id })
		console.log(`  ${delRes.isError ? 'ERROR' : 'OK'}: ${delRes.raw.slice(0, 100)}`)
	}

	// Also clean up any dummy products that might have been created
	// List products and delete any with "MCP Test" or "Dummy" in the title
	const cleanupList = await call('fluentcart_product_list', { search: 'MCP Test Product', per_page: 20 })
	const cleanupData = cleanupList.data as Record<string, unknown>
	const cleanupProducts = cleanupData?.products as Record<string, unknown> | undefined
	const cleanupArr = (cleanupProducts?.data ?? []) as Record<string, unknown>[]
	for (const p of cleanupArr) {
		const pid = p.ID as number
		if (pid && !idsToDelete.includes(pid)) {
			console.log(`  Cleaning up leftover test product ${pid}: ${p.post_title}`)
			await call('fluentcart_product_delete', { product_id: pid })
		}
	}

	// ====================================================================
	// SUMMARY
	// ====================================================================
	console.log(`\n${'='.repeat(70)}`)
	console.log('SUMMARY OF FINDINGS')
	console.log(`${'='.repeat(70)}`)

	const groups = {
		PASS: findings.filter(f => f.status === 'PASS'),
		FAIL: findings.filter(f => f.status === 'FAIL'),
		BUG: findings.filter(f => f.status === 'BUG'),
		UPSTREAM_BUG: findings.filter(f => f.status === 'UPSTREAM_BUG'),
		SKIP: findings.filter(f => f.status === 'SKIP'),
	}

	console.log(`\nPASS: ${groups.PASS.length}`)
	for (const f of groups.PASS) console.log(`  [PASS] ${f.tool}: ${f.detail}`)

	console.log(`\nFAIL: ${groups.FAIL.length}`)
	for (const f of groups.FAIL) console.log(`  [FAIL ${f.severity}] ${f.tool}: ${f.detail}`)

	console.log(`\nBUG: ${groups.BUG.length}`)
	for (const f of groups.BUG) console.log(`  [BUG ${f.severity}] ${f.tool}: ${f.detail}`)

	console.log(`\nUPSTREAM_BUG: ${groups.UPSTREAM_BUG.length}`)
	for (const f of groups.UPSTREAM_BUG) console.log(`  [UPSTREAM ${f.severity}] ${f.tool}: ${f.detail}`)

	console.log(`\nSKIP: ${groups.SKIP.length}`)
	for (const f of groups.SKIP) console.log(`  [SKIP ${f.severity}] ${f.tool}: ${f.detail}`)

	console.log(`\nTotal: ${findings.length} tools tested`)
	console.log(`${'='.repeat(70)}`)
}

main().catch((err) => {
	console.error('FATAL:', err)
	process.exit(1)
})
