/**
 * Simple MCP Scenarios 1–5: product CRUD, digital products, search, price updates, deletion.
 * Run: cd /Users/tomrobak/_projects_/fchub-plugins/fluentcart-mcp && set -a && source .env && set +a && npx tsx tests/_scenarios-simple-1.ts
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
	for (const k of keys) {
		if (typeof obj[k] === 'number') return obj[k] as number
	}
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

// ── Result tracking ────────────────────────────────────────
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
	console.log(`\n❌ SCENARIO FAILED: ${name}`)
	console.log(`   Reason: ${error}`)
}

// ── Scenario 1: Simple Product Creation & Publishing ───────
async function scenario1() {
	const name = '1. Simple Product Creation & Publishing'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	let productId: number | null = null

	try {
		// Step 1: Create draft product
		log('1.1 Create draft product', 'fluentcart_product_create "Basic T-Shirt"')
		const create = await call('fluentcart_product_create', {
			post_title: 'Basic T-Shirt',
			post_status: 'draft',
			post_excerpt: 'A simple cotton t-shirt for testing.',
			detail: { fulfillment_type: 'physical' },
		})
		show(create)
		if (create.isError) throw new Error('Failed to create product')
		productId = extractId(create.data, 'ID', 'id')
		if (!productId) throw new Error('No product ID returned')
		console.log(`  → Product ID: ${productId}`)

		// Step 2: Publish via pricing_update with price 2500 (25 PLN)
		log('1.2 Publish with price 2500', 'fluentcart_product_pricing_update')
		const publish = await call('fluentcart_product_pricing_update', {
			product_id: productId,
			post_status: 'publish',
			variants: [{ title: 'Basic T-Shirt', price: 2500 }],
		})
		show(publish)
		if (publish.isError) throw new Error('Failed to publish product')

		// Step 3: Verify published state
		log('1.3 Verify product is published', 'fluentcart_product_get')
		const get = await call('fluentcart_product_get', { product_id: productId })
		show(get)
		if (get.isError) throw new Error('Failed to get product')

		const product = (get.data as Record<string, unknown>)?.product ?? get.data
		const p = product as Record<string, unknown>
		if (p.post_status !== 'publish') {
			throw new Error(`Expected post_status 'publish', got '${p.post_status}'`)
		}
		console.log(`  → post_status: ${p.post_status} ✓`)

		pass(name)
	} catch (e) {
		fail(name, (e as Error).message)
	} finally {
		if (productId) {
			log('1.x Cleanup', `Deleting product ${productId}`)
			const del = await call('fluentcart_product_delete', { product_id: productId })
			console.log(`  Product ${productId}: ${del.isError ? '❌ cleanup failed' : '✅ deleted'}`)
		}
	}
}

// ── Scenario 2: Digital Product ────────────────────────────
async function scenario2() {
	const name = '2. Digital Product'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	let productId: number | null = null

	try {
		// Step 1: Create digital product
		log('2.1 Create digital product', 'fluentcart_product_create "E-Book Guide"')
		const create = await call('fluentcart_product_create', {
			post_title: 'E-Book Guide',
			post_status: 'draft',
			post_excerpt: 'A digital download for testing.',
			detail: { fulfillment_type: 'digital' },
		})
		show(create)
		if (create.isError) throw new Error('Failed to create product')
		productId = extractId(create.data, 'ID', 'id')
		if (!productId) throw new Error('No product ID returned')
		console.log(`  → Product ID: ${productId}`)

		// Step 2: Publish via pricing_update
		log('2.2 Publish digital product', 'fluentcart_product_pricing_update')
		const publish = await call('fluentcart_product_pricing_update', {
			product_id: productId,
			post_status: 'publish',
			fulfillment_type: 'digital',
			variants: [{ title: 'E-Book Guide', price: 4900 }],
		})
		show(publish)
		if (publish.isError) throw new Error('Failed to publish product')

		// Step 3: Verify digital fulfillment type
		log('2.3 Verify fulfillment_type is digital', 'fluentcart_product_get')
		const get = await call('fluentcart_product_get', { product_id: productId })
		show(get)
		if (get.isError) throw new Error('Failed to get product')

		const product = (get.data as Record<string, unknown>)?.product ?? get.data
		const p = product as Record<string, unknown>
		const detail = p.detail as Record<string, unknown> | undefined
		if (!detail) throw new Error('No detail object in product')
		if (detail.fulfillment_type !== 'digital') {
			throw new Error(
				`Expected fulfillment_type 'digital', got '${detail.fulfillment_type}'`,
			)
		}
		console.log(`  → fulfillment_type: ${detail.fulfillment_type} ✓`)
		console.log(`  → post_status: ${p.post_status} ✓`)

		pass(name)
	} catch (e) {
		fail(name, (e as Error).message)
	} finally {
		if (productId) {
			log('2.x Cleanup', `Deleting product ${productId}`)
			const del = await call('fluentcart_product_delete', { product_id: productId })
			console.log(`  Product ${productId}: ${del.isError ? '❌ cleanup failed' : '✅ deleted'}`)
		}
	}
}

// ── Scenario 3: Product Search & Read ──────────────────────
async function scenario3() {
	const name = '3. Product Search & Read'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		// Step 1: List products
		log('3.1 List products (per_page: 5)', 'fluentcart_product_list')
		const list = await call('fluentcart_product_list', { per_page: 5 })
		show(list)
		if (list.isError) throw new Error('Failed to list products')

		const listData = list.data as Record<string, unknown>
		const productsWrapper = (listData?.products ?? listData) as Record<string, unknown>
		const dataArray = productsWrapper?.data as Array<Record<string, unknown>> | undefined
		if (!Array.isArray(dataArray)) {
			throw new Error('Response does not contain data array')
		}
		console.log(`  → Got ${dataArray.length} products in data array ✓`)

		// Check total exists
		const total = productsWrapper?.total
		if (total === undefined && total !== 0) {
			console.log('  → Warning: no "total" field in response (may be paginated differently)')
		} else {
			console.log(`  → total: ${total} ✓`)
		}

		// Step 2: Get details of first product (if any exist)
		if (dataArray.length > 0) {
			const firstId = dataArray[0].ID as number
			log('3.2 Get product details', `fluentcart_product_get for ID ${firstId}`)
			const get = await call('fluentcart_product_get', { product_id: firstId })
			show(get, 1200)
			if (get.isError) throw new Error(`Failed to get product ${firstId}`)

			const product = (get.data as Record<string, unknown>)?.product ?? get.data
			const p = product as Record<string, unknown>
			if (!p.post_title) throw new Error('Product missing post_title')
			if (!p.detail) throw new Error('Product missing detail')
			// variants may be present as array or absent for some product states
			const hasVariants = Array.isArray(p.variants)
			console.log(`  → post_title: ${p.post_title} ✓`)
			console.log(`  → detail: present ✓`)
			console.log(`  → variants: ${hasVariants ? (p.variants as unknown[]).length : 'not present (ok for some states)'} ✓`)
		} else {
			log('3.2 Get product details', 'SKIPPED — no products exist')
		}

		// Step 3: Public products endpoint
		log('3.3 Public products endpoint', 'fluentcart_public_products')
		const pub = await call('fluentcart_public_products', {})
		show(pub)
		if (pub.isError) throw new Error('Public products endpoint returned error')
		console.log('  → Public endpoint responded without error ✓')

		pass(name)
	} catch (e) {
		fail(name, (e as Error).message)
	}
}

// ── Scenario 4: Price Update ───────────────────────────────
async function scenario4() {
	const name = '4. Price Update'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	let productId: number | null = null

	try {
		// Step 1: Create product
		log('4.1 Create product', 'fluentcart_product_create "Price Test Widget"')
		const create = await call('fluentcart_product_create', {
			post_title: 'Price Test Widget',
			post_status: 'draft',
			detail: { fulfillment_type: 'physical' },
		})
		show(create)
		if (create.isError) throw new Error('Failed to create product')
		productId = extractId(create.data, 'ID', 'id')
		if (!productId) throw new Error('No product ID returned')

		// Extract auto-created variant ID from create response
		const createData = create.data as Record<string, unknown>
		const createWrapper = (createData?.data ?? createData) as Record<string, unknown>
		const autoVariant = createWrapper?.variant as Record<string, unknown> | undefined
		const autoVariantId = autoVariant?.id as number | undefined
		console.log(`  → Product ID: ${productId}, auto-variant ID: ${autoVariantId}`)

		// Step 2: Publish with price 1000 (10 PLN) — include auto-variant ID so we update it, not create a new one
		log('4.2 Publish with price 1000', 'fluentcart_product_pricing_update')
		const publishVariant: Record<string, unknown> = { title: 'Price Test Widget', price: 1000 }
		if (autoVariantId) publishVariant.id = autoVariantId
		const publish = await call('fluentcart_product_pricing_update', {
			product_id: productId,
			post_status: 'publish',
			variants: [publishVariant],
		})
		show(publish)
		if (publish.isError) throw new Error('Failed to publish product')

		// Step 3: Get pricing, find the variant and verify price is 1000
		log('4.3 Verify initial price is 1000', 'fluentcart_product_pricing_get')
		const pricing1 = await call('fluentcart_product_pricing_get', { product_id: productId })
		show(pricing1)
		if (pricing1.isError) throw new Error('Failed to get pricing')

		const pricingData1 = pricing1.data as Record<string, unknown>
		const product1 = (pricingData1?.product ?? pricingData1) as Record<string, unknown>
		const variants1 = product1?.variants as Array<Record<string, unknown>> | undefined
		if (!Array.isArray(variants1) || variants1.length === 0) {
			throw new Error('No variants in pricing response')
		}

		// The variant should have a non-zero item_price after pricing_update with price=1000
		// Note: FluentCart API may store prices in a different unit internally (e.g. item_price * 100)
		// We track the first variant and its item_price, then verify it changes after update
		const targetVariant1 = variants1[0]
		const initialPrice = Number(targetVariant1.item_price)
		if (initialPrice === 0) {
			throw new Error(
				`Expected non-zero item_price after setting price=1000, got 0 (variants: ${variants1.map((v) => `id=${v.id} price=${v.item_price}`).join(', ')})`,
			)
		}
		console.log(`  → item_price: ${initialPrice} (stored internally) ✓`)

		// Step 4: Update price to 2000 using the same variant ID
		const variantId = targetVariant1.id as number
		log('4.4 Update price to 2000', 'fluentcart_product_pricing_update')
		const update = await call('fluentcart_product_pricing_update', {
			product_id: productId,
			variants: [{ id: variantId, title: 'Price Test Widget', price: 2000 }],
		})
		show(update)
		if (update.isError) throw new Error('Failed to update price')

		// Step 5: Verify price changed (should be different from initial)
		log('4.5 Verify price changed after update', 'fluentcart_product_pricing_get')
		const pricing2 = await call('fluentcart_product_pricing_get', { product_id: productId })
		show(pricing2)
		if (pricing2.isError) throw new Error('Failed to get updated pricing')

		const pricingData2 = pricing2.data as Record<string, unknown>
		const product2 = (pricingData2?.product ?? pricingData2) as Record<string, unknown>
		const variants2 = product2?.variants as Array<Record<string, unknown>> | undefined
		if (!Array.isArray(variants2) || variants2.length === 0) {
			throw new Error('No variants in updated pricing response')
		}
		const targetVariant2 = variants2.find((v) => Number(v.id) === variantId) ?? variants2[0]
		const updatedPrice = Number(targetVariant2.item_price)
		if (updatedPrice === 0) {
			throw new Error('Updated price is 0, expected non-zero after setting price=2000')
		}
		if (updatedPrice === initialPrice) {
			throw new Error(
				`Price did not change: still ${updatedPrice} after update from 1000 to 2000`,
			)
		}
		// Verify the ratio is correct (price doubled from 1000 to 2000)
		const ratio = updatedPrice / initialPrice
		if (Math.abs(ratio - 2.0) > 0.01) {
			throw new Error(
				`Expected price to double (ratio 2.0), got ratio ${ratio.toFixed(3)} (${initialPrice} → ${updatedPrice})`,
			)
		}
		console.log(`  → item_price changed: ${initialPrice} → ${updatedPrice} (ratio: ${ratio.toFixed(1)}x) ✓`)

		pass(name)
	} catch (e) {
		fail(name, (e as Error).message)
	} finally {
		if (productId) {
			log('4.x Cleanup', `Deleting product ${productId}`)
			const del = await call('fluentcart_product_delete', { product_id: productId })
			console.log(`  Product ${productId}: ${del.isError ? '❌ cleanup failed' : '✅ deleted'}`)
		}
	}
}

// ── Scenario 5: Product Delete ─────────────────────────────
async function scenario5() {
	const name = '5. Product Delete'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	let productId: number | null = null
	let deleted = false

	try {
		// Step 1: Create product
		log('5.1 Create product', 'fluentcart_product_create "Delete Me"')
		const create = await call('fluentcart_product_create', {
			post_title: 'Delete Me',
			post_status: 'draft',
			detail: { fulfillment_type: 'physical' },
		})
		show(create)
		if (create.isError) throw new Error('Failed to create product')
		productId = extractId(create.data, 'ID', 'id')
		if (!productId) throw new Error('No product ID returned')
		console.log(`  → Product ID: ${productId}`)

		// Step 2: Verify it exists
		log('5.2 Verify product exists', 'fluentcart_product_get')
		const get = await call('fluentcart_product_get', { product_id: productId })
		show(get)
		if (get.isError) throw new Error('Product does not exist after creation')
		const product = (get.data as Record<string, unknown>)?.product ?? get.data
		const p = product as Record<string, unknown>
		console.log(`  → post_title: ${p.post_title} ✓`)

		// Step 3: Delete the product
		log('5.3 Delete product', 'fluentcart_product_delete')
		const del = await call('fluentcart_product_delete', { product_id: productId })
		show(del)
		if (del.isError) throw new Error('Failed to delete product')
		deleted = true
		console.log('  → Product deleted ✓')

		// Step 4: Try to get it again — should fail
		log('5.4 Verify product is gone', 'fluentcart_product_get (expect error)')
		const getAfter = await call('fluentcart_product_get', { product_id: productId })
		show(getAfter)
		if (!getAfter.isError) {
			// Some APIs return the product in trashed state instead of error
			const afterProduct =
				(getAfter.data as Record<string, unknown>)?.product ?? getAfter.data
			const ap = afterProduct as Record<string, unknown>
			if (ap.post_status === 'trash') {
				console.log('  → Product is in trash status (soft-delete) ✓')
			} else {
				throw new Error('Product still accessible after deletion without trash status')
			}
		} else {
			console.log('  → Product returns error after deletion ✓')
		}

		pass(name)
	} catch (e) {
		fail(name, (e as Error).message)
	} finally {
		if (productId && !deleted) {
			log('5.x Cleanup', `Deleting product ${productId}`)
			const del = await call('fluentcart_product_delete', { product_id: productId })
			console.log(`  Product ${productId}: ${del.isError ? '❌ cleanup failed' : '✅ deleted'}`)
		}
	}
}

// ── Main runner ────────────────────────────────────────────
// biome-ignore lint/complexity/noExcessiveCognitiveComplexity: integration test
async function run() {
	console.log('╔══════════════════════════════════════════════════════════╗')
	console.log('║  SIMPLE SCENARIOS 1–5                                   ║')
	console.log('║  Product CRUD, digital, search, pricing, deletion       ║')
	console.log('╚══════════════════════════════════════════════════════════╝')

	await scenario1()
	await scenario2()
	await scenario3()
	await scenario4()
	await scenario5()

	// ── Summary table ──────────────────────────────────────
	console.log(`\n${'═'.repeat(60)}`)
	console.log('RESULTS SUMMARY')
	console.log('═'.repeat(60))

	const passed = results.filter((r) => r.passed).length
	const failed = results.filter((r) => !r.passed).length

	for (const r of results) {
		const icon = r.passed ? '✅ PASS' : '❌ FAIL'
		const reason = r.error ? ` — ${r.error}` : ''
		console.log(`  ${icon}  ${r.name}${reason}`)
	}

	console.log(`\n  Total: ${results.length} | Passed: ${passed} | Failed: ${failed}`)
	console.log('═'.repeat(60))

	if (failed > 0) {
		process.exit(1)
	}
}

run().catch((e) => {
	console.error('\n❌ FATAL:', e)
	process.exit(1)
})
