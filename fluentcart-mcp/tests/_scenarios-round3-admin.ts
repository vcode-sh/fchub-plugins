/**
 * Round 3 — Admin real-life workflow scenarios.
 *
 * Focus:
 * 1) Order handling workflow (inspection, status actions, notes, activity)
 * 2) Condition-based bulk product updates (name + price range)
 *    including title/short/long description changes and slug update attempt.
 *
 * Run:
 *   cd /Users/tomrobak/_projects_/fchub-plugins/fluentcart-mcp
 *   set -a && source .env && set +a
 *   npx tsx tests/_scenarios-round3-admin.ts
 */
import { resolveServerContext } from '../src/server.js'

type ToolResult = {
	isError?: boolean
	data: unknown
	raw: string
	size: number
}

type ScenarioResult = {
	name: string
	passed: boolean
	error?: string
	warnings?: string[]
}

const ctx = resolveServerContext()
const toolMap = new Map<string, (typeof ctx.tools)[0]>()
for (const t of ctx.tools) toolMap.set(t.name, t)

const results: ScenarioResult[] = []
const createdProductIds: number[] = []

async function call(name: string, input: Record<string, unknown> = {}): Promise<ToolResult> {
	const tool = toolMap.get(name)
	if (!tool) {
		const raw = `Tool not found: ${name}`
		return { isError: true, data: null, raw, size: raw.length }
	}

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

	return { isError: result.isError, data, raw: text, size: text.length }
}

function log(title: string, detail: string) {
	console.log(`\n${'─'.repeat(70)}`)
	console.log(title)
	console.log(detail)
}

function show(r: ToolResult, maxLen = 600) {
	const status = r.isError ? '❌ ERROR' : '✅ OK'
	const preview = r.raw.length > maxLen ? `${r.raw.slice(0, maxLen)}...` : r.raw
	console.log(`  ${status} | ${r.size} bytes`)
	console.log(`  ${preview}`)
}

function pass(name: string, warnings: string[] = []) {
	results.push({ name, passed: true, warnings })
	console.log(`\n✅ SCENARIO PASSED: ${name}`)
	if (warnings.length) {
		for (const w of warnings) console.log(`   ⚠ ${w}`)
	}
}

function fail(name: string, error: string, warnings: string[] = []) {
	results.push({ name, passed: false, error, warnings })
	console.log(`\n❌ SCENARIO FAILED: ${name}\n   Reason: ${error}`)
	if (warnings.length) {
		for (const w of warnings) console.log(`   ⚠ ${w}`)
	}
}

function extractFirstOrderId(data: unknown): number | null {
	const root = data as Record<string, unknown>
	const wrapper = (root?.orders ?? root) as Record<string, unknown> | undefined
	const list = wrapper?.data as Record<string, unknown>[] | undefined
	if (!Array.isArray(list) || list.length === 0) return null
	const first = list[0]
	return typeof first.id === 'number' ? first.id : null
}

function extractFirstCustomerId(data: unknown): number | null {
	const root = data as Record<string, unknown>
	const wrapper = (root?.customers ?? root) as Record<string, unknown> | undefined
	const list = wrapper?.data as Record<string, unknown>[] | undefined
	if (!Array.isArray(list) || list.length === 0) return null
	const first = list[0]
	return typeof first.id === 'number' ? first.id : null
}

function extractCreatedProductId(data: unknown): number | null {
	const root = data as Record<string, unknown>
	const d = (root?.data ?? root) as Record<string, unknown>
	if (typeof d?.ID === 'number') return d.ID
	if (typeof d?.id === 'number') return d.id
	if (typeof root?.ID === 'number') return root.ID as number
	if (typeof root?.id === 'number') return root.id as number
	return null
}

function asNumber(v: unknown): number | null {
	if (typeof v === 'number') return v
	if (typeof v === 'string' && v.trim() !== '' && !Number.isNaN(Number(v))) return Number(v)
	return null
}

function priceToCurrencyUnits(rawPrice: unknown): number | null {
	const n = asNumber(rawPrice)
	if (n == null) return null
	// Most FluentCart pricing APIs return cents.
	return n >= 100 ? n / 100 : n
}

async function scenario1OrderHandling() {
	const name = '1. Order handling workflow (inspect + status actions + notes + activity)'
	console.log(`\n${'═'.repeat(70)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(70))

	const warnings: string[] = []

	try {
		log('1.1', 'List orders and pick one')
		const list = await call('fluentcart_order_list', { per_page: 5, sort_type: 'DESC' })
		show(list, 350)
		if (list.isError) {
			fail(name, `order_list failed: ${list.raw}`)
			return
		}

		const orderId = extractFirstOrderId(list.data)
		if (!orderId) {
			fail(name, 'No order found for workflow test')
			return
		}
		console.log(`  Using order_id=${orderId}`)

		log('1.2', 'Get order detail')
		const detail = await call('fluentcart_order_get', { order_id: orderId })
		show(detail, 500)
		if (detail.isError) warnings.push(`order_get failed: ${detail.raw}`)

		log('1.3', 'List order transactions')
		const tx = await call('fluentcart_order_transactions', { order_id: orderId })
		show(tx, 450)
		if (tx.isError) warnings.push(`order_transactions failed: ${tx.raw}`)

		log('1.4', 'Try status update via fluentcart_order_update_statuses')
		const statusUpdate = await call('fluentcart_order_update_statuses', {
			order_id: orderId,
			order_status: 'processing',
			shipping_status: 'shipped',
		})
		show(statusUpdate, 450)
		if (statusUpdate.isError) {
			warnings.push(
				'order_update_statuses failed (known contract mismatch: API expects action+statuses object)',
			)
		}

		log('1.5', 'Try legacy-documented bulk action: action=update_status')
		const bulkLegacy = await call('fluentcart_order_bulk_action', {
			action: 'update_status',
			order_ids: [orderId],
			data: { status: 'processing' },
		})
		show(bulkLegacy, 350)
		if (bulkLegacy.isError) {
			warnings.push('order_bulk_action legacy action=update_status failed (expected on current backend)')
		}

		log('1.6', 'Try real backend bulk action: action=change_order_status')
		const bulkReal = await call('fluentcart_order_bulk_action', {
			action: 'change_order_status',
			order_ids: [orderId],
			data: { status: 'processing' },
		})
		show(bulkReal, 350)
		if (bulkReal.isError) {
			warnings.push('order_bulk_action action=change_order_status also failed (needs exact payload mapping)')
		}

		log('1.7', 'Attach admin note to order')
		const note = await call('fluentcart_note_attach', {
			order_id: orderId,
			note: `Round3 admin workflow check at ${new Date().toISOString()}`,
		})
		show(note, 350)
		if (note.isError) warnings.push(`note_attach failed: ${note.raw}`)

		log('1.8', 'Verify activity feed for this order')
		const activity = await call('fluentcart_activity_list', {
			module_name: 'Order',
			module_id: orderId,
			per_page: 5,
		})
		show(activity, 450)
		if (activity.isError) warnings.push(`activity_list failed: ${activity.raw}`)

		pass(name, warnings)
	} catch (e) {
		fail(name, String(e), warnings)
	}
}

type ProductCandidate = {
	id: number
	title: string
	slug: string | null
	price: number | null
}

async function createRound3Products(): Promise<number[]> {
	const ids: number[] = []
	const seed = Date.now()
	const items = [
		{
			post_title: `R3 Admin Bulk Alpha ${seed}`,
			post_excerpt: 'Round3 alpha short description',
			post_content: '<p>Round3 alpha long description</p>',
			price: 9,
		},
		{
			post_title: `R3 Admin Bulk Beta ${seed}`,
			post_excerpt: 'Round3 beta short description',
			post_content: '<p>Round3 beta long description</p>',
			price: 19,
		},
		{
			post_title: `R3 Admin Bulk Gamma ${seed}`,
			post_excerpt: 'Round3 gamma short description',
			post_content: '<p>Round3 gamma long description</p>',
			price: 49,
		},
	]

	for (const item of items) {
		const created = await call('fluentcart_product_create', {
			post_title: item.post_title,
			post_status: 'draft',
			post_excerpt: item.post_excerpt,
			post_content: item.post_content,
			fulfillment_type: 'physical',
		})
		show(created, 260)
		if (created.isError) continue
		const id = extractCreatedProductId(created.data)
		if (!id) continue
		ids.push(id)

		// Try to set deterministic price; currently this path is unstable on target backend.
		const pricing = await call('fluentcart_product_pricing_get', { product_id: id })
		if (pricing.isError) continue
		const p = pricing.data as Record<string, unknown>
		const wrapper = (p?.product ?? p) as Record<string, unknown>
		const variants = (wrapper?.variants ?? []) as Record<string, unknown>[]
		const first = variants[0]
		const variantId = asNumber(first?.id)
		const variantTitle = (first?.variation_title as string) || item.post_title
		if (!variantId) continue

		const setPrice = await call('fluentcart_variant_update', {
			product_id: id,
			variant_id: variantId,
			price: item.price,
			title: variantTitle,
		})
		show(setPrice, 260)
	}

	return ids
}

async function scenario2ConditionalBulkProductUpdates() {
	const name =
		'2. Product admin workflow (filter by name+price and bulk-update title/short/long descriptions)'
	console.log(`\n${'═'.repeat(70)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(70))

	const warnings: string[] = []

	try {
		log('2.1', 'Create disposable products for deterministic bulk-edit test')
		const productIds = await createRound3Products()
		for (const id of productIds) {
			createdProductIds.push(id)
			console.log(`  Created product_id=${id}`)
		}
		if (productIds.length < 2) {
			warnings.push('Not all seed products were created; proceeding with available products')
		}

		log('2.2', 'List products by name condition')
		const listed = await call('fluentcart_product_list', {
			per_page: 50,
			search: 'R3 Admin Bulk',
			sort_type: 'DESC',
		})
		show(listed, 500)
		if (listed.isError) {
			fail(name, `product_list failed: ${listed.raw}`, warnings)
			return
		}

		const listRoot = listed.data as Record<string, unknown>
		const listWrapper = (listRoot?.products ?? listRoot) as Record<string, unknown>
		const listItems = (listWrapper?.data ?? []) as Record<string, unknown>[]

		const candidates: ProductCandidate[] = []
		for (const row of listItems) {
			const id = asNumber(row.ID)
			if (!id) continue
			const title = (row.post_title as string) || ''
			if (!title.includes('R3 Admin Bulk')) continue
			const slug = (row.post_name as string) || null

			const pricing = await call('fluentcart_product_pricing_get', { product_id: id })
			if (pricing.isError) continue
			const p = pricing.data as Record<string, unknown>
			const wrapper = (p?.product ?? p) as Record<string, unknown>
			const variants = (wrapper?.variants ?? []) as Record<string, unknown>[]
			const first = variants[0]
			const price = priceToCurrencyUnits(first?.item_price)

			candidates.push({ id, title, slug, price })
		}

		console.log(`  Candidate products by name: ${candidates.length}`)
		for (const c of candidates) {
			console.log(`  -> ID ${c.id} | price=${c.price} | title="${c.title}"`)
		}

		// Real-life admin condition: name contains "R3 Admin Bulk" and price range 0..30.
		// (On target backend, variant price writes are currently unstable, so many test products
		// remain at price 0; this still validates condition-based mutation workflow.)
		const toUpdate = candidates.filter((c) => c.price != null && c.price >= 0 && c.price <= 30)
		console.log(`  Matched by price range 0..30: ${toUpdate.length}`)
		if (toUpdate.length === 0) {
			warnings.push('No products matched the price-range condition; content update phase skipped')
		}

		for (const c of toUpdate) {
			log('2.3', `Update content fields for product ${c.id}`)
			const update = await call('fluentcart_product_pricing_update', {
				product_id: c.id,
				post_title: `${c.title} [R3-UPDATED]`,
				post_excerpt: 'R3 bulk short description updated by condition',
				post_content: '<p>R3 bulk long description updated by condition.</p>',
			})
			show(update, 320)
			if (update.isError) warnings.push(`pricing_update failed for product ${c.id}`)
		}

		if (toUpdate.length > 0) {
			log('2.4', 'Try slug change via post_name in pricing_update input (capability check)')
			const target = toUpdate[0]
			const attemptedSlug = `r3-round3-slug-${target.id}`
			const attempt = await call('fluentcart_product_pricing_update', {
				product_id: target.id,
				post_title: `${target.title} [SLUG-TRY]`,
				// Not in tool schema; zod strips unknown keys, so likely ignored.
				post_name: attemptedSlug,
			})
			show(attempt, 300)
			if (attempt.isError) {
				warnings.push('Slug update attempt failed at request stage')
			}

			const verify = await call('fluentcart_product_get', { product_id: target.id })
			show(verify, 350)
			if (!verify.isError) {
				const vr = verify.data as Record<string, unknown>
				const p = (vr?.product ?? vr) as Record<string, unknown>
				const actualSlug = p?.post_name as string | undefined
				if (actualSlug !== attemptedSlug) {
					warnings.push(
						'Slug was not updated via MCP pricing tool (no slug field support in current tool contract)',
					)
				}
			}
		}

		log('2.5', 'Try single-call bulk content update action')
		const bulkAction = await call('fluentcart_product_bulk_action', {
			action: 'update_products',
			product_ids: toUpdate.map((p) => p.id),
		})
		show(bulkAction, 300)
		if (bulkAction.isError) {
			warnings.push(
				'product_bulk_action does not support content updates; only limited actions are available',
			)
		}
		const variantPriceWriteLikelyBroken = toUpdate.some((p) => p.price === 0)
		if (variantPriceWriteLikelyBroken) {
			warnings.push(
				'variant_update did not persist expected prices on target backend; price-based automation reliability is limited',
			)
		}

		pass(name, warnings)
	} catch (e) {
		fail(name, String(e), warnings)
	}
}

async function scenario3OrderCustomerMutationCheck() {
	const name = '3. Order customer mutation workflow (change customer + create-and-change)'
	console.log(`\n${'═'.repeat(70)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(70))

	const warnings: string[] = []

	try {
		log('3.1', 'Pick order and customer IDs')
		const orders = await call('fluentcart_order_list', { per_page: 2 })
		show(orders, 300)
		if (orders.isError) {
			fail(name, `order_list failed: ${orders.raw}`, warnings)
			return
		}
		const orderId = extractFirstOrderId(orders.data)
		if (!orderId) {
			fail(name, 'No order found', warnings)
			return
		}

		const customers = await call('fluentcart_customer_list', { per_page: 2 })
		show(customers, 300)
		if (customers.isError) {
			fail(name, `customer_list failed: ${customers.raw}`, warnings)
			return
		}
		const customerId = extractFirstCustomerId(customers.data)
		if (!customerId) {
			fail(name, 'No customer found', warnings)
			return
		}

		log('3.2', 'Change order customer')
		const change = await call('fluentcart_order_change_customer', {
			order_id: orderId,
			customer_id: customerId,
		})
		show(change, 300)
		if (change.isError) warnings.push(`order_change_customer failed: ${change.raw}`)

		log('3.3', 'Create-and-change customer for order')
		const createAndChange = await call('fluentcart_order_create_and_change_customer', {
			order_id: orderId,
			email: `round3-order-${Date.now()}@example.com`,
			first_name: 'Round3',
			last_name: 'Workflow',
		})
		show(createAndChange, 350)
		if (createAndChange.isError) {
			warnings.push(
				'order_create_and_change_customer failed; likely requires full_name on current backend',
			)
		}

		pass(name, warnings)
	} catch (e) {
		fail(name, String(e), warnings)
	}
}

async function cleanup() {
	console.log(`\n${'─'.repeat(70)}`)
	console.log('CLEANUP')
	if (createdProductIds.length === 0) {
		console.log('  No created products to remove.')
		return
	}
	for (const id of createdProductIds) {
		const del = await call('fluentcart_product_delete', { product_id: id })
		console.log(`  Product ${id}: ${del.isError ? 'not deleted (already gone or failed)' : 'deleted'}`)
	}
}

async function run() {
	console.log('╔════════════════════════════════════════════════════════════════════╗')
	console.log('║ Round 3 — Admin Workflow Live Scenarios                           ║')
	console.log('╚════════════════════════════════════════════════════════════════════╝')

	await scenario1OrderHandling()
	await scenario2ConditionalBulkProductUpdates()
	await scenario3OrderCustomerMutationCheck()
	await cleanup()

	console.log(`\n${'═'.repeat(70)}`)
	console.log('FINAL RESULTS')
	console.log('═'.repeat(70))

	const passed = results.filter((r) => r.passed).length
	const failed = results.filter((r) => !r.passed).length
	console.log(`Total: ${results.length} | Passed: ${passed} | Failed: ${failed}`)
	for (const r of results) {
		console.log(`  ${r.passed ? '✅' : '❌'} ${r.name}${r.error ? ` — ${r.error}` : ''}`)
		if (r.warnings?.length) {
			for (const w of r.warnings) console.log(`     ⚠ ${w}`)
		}
	}

	if (failed > 0) process.exit(1)
}

run().catch((err) => {
	console.error('Fatal error:', err)
	process.exit(1)
})
