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
				// NOTE: slug is required by FluentCart API validation (AttrTermRequest)
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
					console.log(
						'     ⚠️  Known FluentCart bug: AttrTermResource::create() uses',
					)
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

		// ── STEP 4: Check if "Pants" category exists ────────────────
		log('4. Check product categories', 'Look for "Pants" category')
		const terms = await call('fluentcart_product_terms')
		show(terms, 500)

		let pantsCategoryId: number | null = null
		if (!terms.isError) {
			const termData = terms.data as Record<string, unknown>
			const taxonomies = (termData?.taxonomies ?? termData) as Record<string, unknown>
			console.log(`  → Taxonomy keys: ${Object.keys(taxonomies).join(', ')}`)

			// Search in product-categories taxonomy
			for (const [key, val] of Object.entries(taxonomies)) {
				const taxonomy = val as Record<string, unknown>
				const termList = taxonomy?.terms as Array<Record<string, unknown>> | undefined
				if (Array.isArray(termList)) {
					const found = termList.find(
						(t) =>
							(t.label as string)?.toLowerCase() === 'pants' ||
							(t.name as string)?.toLowerCase() === 'pants',
					)
					if (found) {
						pantsCategoryId = Number(found.value ?? found.term_id ?? found.id)
						console.log(`  → Found "Pants" in ${key}: ID ${pantsCategoryId}`)
					}
				}
			}
			if (!pantsCategoryId) {
				console.log('  → "Pants" category NOT found.')
				console.log('  → ⚠️  MCP has NO tool to CREATE categories!')
				console.log('  → Agent would need to TELL USER to create it manually.')
			}
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
				defaultVariantId = ((p.variants as Record<string, unknown>[])[0].id) as number
				console.log(`  → Default variant ID: ${defaultVariantId}`)
			}
			const detail = p.detail as Record<string, unknown> | undefined
			console.log(`  → detail_id: ${detail?.id}`)
		}

		// ── STEP 7: Set variant option (Color attribute) ────────────
		if (colorGroupId) {
			log('7. Assign Color attribute to product', 'fluentcart_product_variant_option_update')
			const setOption = await call('fluentcart_product_variant_option_update', {
				product_id: productId,
				option_name: 'Color',
				option_values: colours.map((c) => c.toLowerCase()),
			})
			show(setOption)
		} else {
			log('7. Assign Color attribute', '⚠️  SKIPPED — no Color group created')
		}

		// ── STEP 8: Create colour variants with pricing ─────────────
		log('8. Create colour variations', 'One variant per colour, 1000 groszy = 10 PLN')
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

		// ── STEP 9: Enable stock management ─────────────────────────
		log('9. Enable stock management', 'fluentcart_product_manage_stock_update')
		const stockMgmt = await call('fluentcart_product_manage_stock_update', {
			product_id: productId,
			manage_stock: '1',
		})
		show(stockMgmt)

		// ── STEP 10: Set inventory for each variant ─────────────────
		log('10. Set inventory (25 each)', 'fluentcart_product_inventory_update per variant')
		for (let i = 0; i < variantIds.length; i++) {
			const inv = await call('fluentcart_product_inventory_update', {
				product_id: productId,
				variant_id: variantIds[i],
				total_stock: 25,
			})
			const label = colours[i] ?? `variant ${variantIds[i]}`
			console.log(
				`  → ${label}: ${inv.isError ? `❌ ${inv.raw.slice(0, 100)}` : '✅ stock=25'}`,
			)
		}

		// ── STEP 11: Assign category ────────────────────────────────
		if (pantsCategoryId) {
			log('11. Assign "Pants" category', 'fluentcart_product_taxonomy_sync')
			const catSync = await call('fluentcart_product_taxonomy_sync', {
				product_id: productId,
				term_ids: [pantsCategoryId],
				taxonomy: 'product_cat',
			})
			show(catSync)
		} else {
			log('11. Category assignment', '⚠️  SKIPPED — no "Pants" category found, no create tool')
		}

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
                                       to validate group existence. Also: slug is required but MCP
                                       marks it optional.
4. Check categories for "Pants"     → ✅ tool exists
5. Create "Pants" category          → ❌ NO TOOL (agent must ask user)
6. Create product                   → ✅ tool exists
7. Set variant options (Color)      → ✅ tool exists
8. Create 5 colour variants         → ✅ tool exists
9. Enable stock management          → ✅ tool exists
10. Set inventory per variant       → ✅ tool exists
11. Assign category                 → ✅ tool exists (if category exists)
12. Upload product images           → ❌ NO TOOL (MCP can't upload media)
13. Publish product                 → ✅ via product_pricing_update

BUGS FOUND:
- ⛔ FluentCart bug: AttrTermResource::create() line 105 uses
     static::getQuery()->find($groupId) which queries fct_atts_terms
     instead of fct_atts_groups. Term creation fails with 404 for any
     group_id that doesn't match an existing term_id.

MCP GAPS:
- ❌ No category/taxonomy CREATE tool
- ❌ No media upload capability (images)
- ⚠️  slug should be required on attribute_term_create (API requires it)
- ⚠️  Agent must know prices are in GROSZY (cents), not PLN
- ⚠️  Product created as DRAFT — agent would need extra step to publish
- ⚠️  ~12+ API calls minimum for this simple request
`)
}

run().catch((e) => {
	console.error('\n❌ FATAL:', e)
	process.exit(1)
})
