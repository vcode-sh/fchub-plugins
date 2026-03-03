/**
 * Simulates the full "Tiger Pants" product creation flow an LLM agent would follow.
 * Run: set -a && source .env && set +a && npx tsx tests/_tiger-pants-flow.ts
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

/** Dig through typical FluentCart response shapes to extract an ID */
function extractId(data: unknown, ...keys: string[]): number | null {
	if (!data || typeof data !== 'object') return null
	const obj = data as Record<string, unknown>
	// Try direct keys first
	for (const k of keys) {
		if (typeof obj[k] === 'number') return obj[k] as number
	}
	// Try nested under common wrapper keys
	for (const wrapper of ['data', 'product', 'variant']) {
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

// biome-ignore lint/complexity/noExcessiveCognitiveComplexity: sequential integration flow test
async function run() {
	console.log('╔══════════════════════════════════════════════════════════╗')
	console.log('║  SCENARIO: "Add Tiger Pants with colour variations"     ║')
	console.log('║  Price: 10 PLN each, Stock: 25 each                    ║')
	console.log('║  Colours: white, blue, green, red, pink                 ║')
	console.log('║  Category: Pants                                        ║')
	console.log('╚══════════════════════════════════════════════════════════╝')

	let colorGroupId: number | null = null
	let productId: number | null = null

	try {
		// ── STEP 1: Check if "Color" attribute group exists ──────────
		log('1. Check attribute groups', 'Agent needs colours → check if Color attribute exists')
		const groups = await call('fluentcart_attribute_group_list')
		show(groups)

		if (!groups.isError) {
			const groupData = groups.data as Record<string, unknown>
			// Response: { groups: { data: [...] } }
			const groupsWrapper = groupData?.groups as Record<string, unknown> | undefined
			const list = (groupsWrapper?.data ?? groupData?.data ?? groupData) as Array<
				Record<string, unknown>
			>
			if (Array.isArray(list)) {
				const colorGroup = list.find(
					(g) =>
						(g.title as string)?.toLowerCase() === 'color' ||
						(g.title as string)?.toLowerCase() === 'colour' ||
						(g.slug as string)?.toLowerCase() === 'color',
				)
				if (colorGroup) {
					colorGroupId = colorGroup.id as number
					console.log(`  → Found Color group: ID ${colorGroupId}`)
				} else {
					console.log('  → Color group NOT found. Agent would CREATE it.')
				}
			}
		}

		// ── STEP 2: Create Color attribute group if missing ─────────
		if (!colorGroupId) {
			log('2. Create "Color" attribute group', 'fluentcart_attribute_group_create')
			const createGroup = await call('fluentcart_attribute_group_create', {
				title: 'Color',
				slug: 'color',
			})
			show(createGroup)
			if (!createGroup.isError) {
				colorGroupId = extractId(createGroup.data, 'id')
				console.log(`  → Created Color group: ID ${colorGroupId}`)
			}
		}

		// ── STEP 3: Create colour terms ─────────────────────────────
		const colours = ['White', 'Blue', 'Green', 'Red', 'Pink']
		const termIds: number[] = []

		if (colorGroupId) {
			log(
				'3. Create colour terms',
				`Adding ${colours.join(', ')} to Color group (slug is REQUIRED by API)`,
			)

			// First check existing terms
			const existingTerms = await call('fluentcart_attribute_term_list', {
				group_id: colorGroupId,
			})
			const existingData = existingTerms.data as Record<string, unknown>
			const termsWrapper = existingData?.terms as Record<string, unknown> | undefined
			const existingList = (termsWrapper?.data ?? existingData?.data ?? existingData) as Array<
				Record<string, unknown>
			>
			const existingMap = new Map<string, number>()
			if (Array.isArray(existingList)) {
				for (const t of existingList) {
					existingMap.set((t.title as string).toLowerCase(), t.id as number)
				}
			}

			for (const colour of colours) {
				if (existingMap.has(colour.toLowerCase())) {
					const id = existingMap.get(colour.toLowerCase())!
					termIds.push(id)
					console.log(`  → ${colour} already exists (ID ${id})`)
					continue
				}
				// slug is required by FluentCart API validation (AttrTermRequest)
				const term = await call('fluentcart_attribute_term_create', {
					group_id: colorGroupId,
					title: colour,
					slug: colour.toLowerCase(),
				})
				if (!term.isError) {
					const id = extractId(term.data, 'id')
					if (id) termIds.push(id)
					console.log(`  → Created ${colour}: ID ${id}`)
				} else {
					console.log(`  → ❌ Failed to create ${colour}: ${term.raw}`)
					console.log('     ⚠️  Known FluentCart bug: AttrTermResource::create() uses')
					console.log(
						'        AttributeTerm::query()->find($groupId) instead of AttributeGroup::query()',
					)
				}
			}

			if (termIds.length === 0) {
				console.log('\n  ⚠️  No colour terms created — FluentCart bug blocks this step.')
				console.log('  ⚠️  Agent would need to inform user that attribute terms cannot be created.')
				console.log('  → Continuing flow without attribute terms...')
			}
		}

		// ── STEP 4: Create "Pants" category ─────────────────────────
		log('4. Create "Pants" category', 'fluentcart_product_terms_add (creates taxonomy terms)')
		const createCat = await call('fluentcart_product_terms_add', {
			names: 'Pants',
			taxonomy: 'product-categories',
		})
		show(createCat)

		let pantsCategoryId: number | null = null
		if (!createCat.isError) {
			// Response shape: { term_ids: [37], names: ["Pants"] }
			const catData = createCat.data as Record<string, unknown>
			const termIds = catData?.term_ids as number[] | undefined
			if (Array.isArray(termIds) && termIds.length > 0) {
				pantsCategoryId = termIds[0]
			}
			console.log(`  → Pants category ID: ${pantsCategoryId}`)
		}

		// ── STEP 5: Create the product ──────────────────────────────
		log('5. Create product "Tiger Pants"', 'fluentcart_product_create')
		const product = await call('fluentcart_product_create', {
			post_title: 'Tiger Pants',
			post_status: 'draft',
			post_excerpt: 'Comfortable tiger-print pants in multiple colours.',
			detail: { fulfillment_type: 'physical' },
		})
		show(product)

		if (!product.isError) {
			// Response shape: { data: { ID: 267, variant: {...} }, message: "..." }
			productId = extractId(product.data, 'ID', 'id')
			console.log(`  → Created product: ID ${productId}`)
		}

		if (!productId) {
			console.log('\n❌ Cannot continue without product ID')
			return
		}

		// ── STEP 6: Get product to see default variant ──────────────
		log('6. Get product details', 'Check what was auto-created (default variant, etc.)')
		const productDetail = await call('fluentcart_product_get', { product_id: productId })
		show(productDetail, 1200)

		let defaultVariantId: number | null = null
		if (!productDetail.isError) {
			const pd = productDetail.data as Record<string, unknown>
			const p = (pd?.product ?? pd) as Record<string, unknown>
			if (Array.isArray(p.variants) && p.variants.length > 0) {
				defaultVariantId = (p.variants as Record<string, unknown>[])[0].id as number
				console.log(`  → Default variant ID: ${defaultVariantId}`)
			}
			const detail = p.detail as Record<string, unknown> | undefined
			console.log(`  → detail_id: ${detail?.id}`)
		}

		// ── STEP 7: Create colour variants (nested structure) ───────
		log('7. Create colour variations', 'One variant per colour, 1000 groszy = 10 PLN (nested body)')
		const variantIds: number[] = []

		for (const colour of colours) {
			const variant = await call('fluentcart_variant_create', {
				product_id: productId,
				title: `Tiger Pants - ${colour}`,
				price: 1000, // 10 PLN in cents/groszy
				sku: `TIGER-${colour.toUpperCase()}`,
				stock_quantity: 25,
			})
			if (!variant.isError) {
				const vid = extractId(variant.data, 'id', 'variant_id')
				if (vid) variantIds.push(vid)
				console.log(`  → Created ${colour} variant: ID ${vid}`)
			} else {
				console.log(`  → ❌ ${colour}: ${variant.raw.slice(0, 200)}`)
			}
		}

		// ── STEP 8: Enable stock management ─────────────────────────
		log('8. Enable stock management', 'fluentcart_product_manage_stock_update')
		const stockMgmt = await call('fluentcart_product_manage_stock_update', {
			product_id: productId,
			manage_stock: '1',
		})
		show(stockMgmt)

		// ── STEP 9: Set inventory for each variant ──────────────────
		log('9. Set inventory (25 each)', 'fluentcart_product_inventory_update per variant')
		for (let i = 0; i < variantIds.length; i++) {
			const inv = await call('fluentcart_product_inventory_update', {
				product_id: productId,
				variant_id: variantIds[i],
				total_stock: 25,
			})
			const label = colours[i] ?? `variant ${variantIds[i]}`
			console.log(`  → ${label}: ${inv.isError ? `❌ ${inv.raw.slice(0, 100)}` : '✅ stock=25'}`)
		}

		// ── STEP 10: Assign category ────────────────────────────────
		if (pantsCategoryId) {
			log('10. Assign "Pants" category', 'fluentcart_product_taxonomy_sync')
			const catSync = await call('fluentcart_product_taxonomy_sync', {
				product_id: productId,
				term_ids: [pantsCategoryId],
				taxonomy: 'product-categories',
			})
			show(catSync)
		} else {
			log('10. Category assignment', '⚠️  SKIPPED — no "Pants" category ID extracted')
		}

		// ── STEP 11: Publish product via pricing_update ─────────────
		log('11. Publish product', 'fluentcart_product_pricing_update (sets post_status to publish)')
		const publish = await call('fluentcart_product_pricing_update', {
			product_id: productId,
			post_status: 'publish',
		})
		show(publish)

		// ── STEP 12: Verify final product ───────────────────────────
		log('12. Verify final product', 'fluentcart_product_get')
		const final = await call('fluentcart_product_get', { product_id: productId })
		show(final, 2000)
	} finally {
		// ── CLEANUP ─────────────────────────────────────────────────
		console.log(`\n${'─'.repeat(60)}`)
		console.log('CLEANUP')
		if (productId) {
			const del = await call('fluentcart_product_delete', { product_id: productId })
			console.log(`  Product ${productId}: ${del.isError ? '❌' : '✅ deleted'}`)
		}
		if (colorGroupId) {
			const del = await call('fluentcart_attribute_group_delete', { group_id: colorGroupId })
			console.log(`  Color group ${colorGroupId}: ${del.isError ? '❌' : '✅ deleted'}`)
		}
	}

	// ── SUMMARY ─────────────────────────────────────────────────────
	console.log(`\n${'═'.repeat(60)}`)
	console.log('FLOW SUMMARY — What an LLM agent would do:')
	console.log('═'.repeat(60))
	console.log(`
1. Check attribute groups           → ✅ tool exists
2. Create "Color" attribute group   → ✅ tool exists
3. Create colour terms (5x)         → ⛔ BROKEN — FluentCart bug in AttrTermResource::create()
                                       Uses AttributeTerm::query() instead of AttributeGroup::query()
                                       to validate group existence. Also: slug is now required in schema.
4. Create "Pants" category          → ✅ FIXED — fluentcart_product_terms_add now creates categories
5. Create product                   → ✅ tool exists
6. Get product details              → ✅ tool exists
7. Create 5 colour variants         → ✅ FIXED — variant_create now uses nested body structure
8. Enable stock management          → ✅ tool exists
9. Set inventory per variant        → ✅ tool exists
10. Assign category                 → ✅ tool exists (if category exists)
11. Publish product                 → ✅ FIXED — pricing_update fetches current state + merges changes
12. Upload product images           → ❌ NO TOOL (MCP can't upload media)

BUGS FOUND:
- ⛔ FluentCart bug: AttrTermResource::create() line 105 uses
     static::getQuery()->find($groupId) which queries fct_atts_terms
     instead of fct_atts_groups. Term creation fails with 404 for any
     group_id that doesn't match an existing term_id.

FIXES APPLIED:
- ✅ variant_create: now sends nested {variants: {...}} structure matching API expectation
- ✅ variant_update: now sends nested {variants: {...}} structure with partial updates
- ✅ product_variant_option_update: REMOVED (requires admin UI matrix, unusable by LLM)
- ✅ attribute_term_create: slug is now required in schema
- ✅ product_terms_add: now CREATES categories (was broken schema for wrong operation)
- ✅ product_pricing_update: now fetches current state + merges changes (LLM-friendly)

REMAINING GAPS:
- ❌ No media upload capability (images)
- ⛔ FluentCart upstream bug blocks attribute term creation
- ⚠️  Agent must know prices are in GROSZY (cents), not PLN
`)
}

run().catch((e) => {
	console.error('\n❌ FATAL:', e)
	process.exit(1)
})
