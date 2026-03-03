/**
 * Quick test: variant_update fix (fetch-then-merge pattern)
 * Run: set -a && source .env && set +a && npx tsx tests/_debug-variant-update.ts
 */
import { resolveServerContext } from '../src/server.js'

const ctx = resolveServerContext()
const toolMap = new Map<string, (typeof ctx.tools)[0]>()
for (const t of ctx.tools) toolMap.set(t.name, t)

async function call(name: string, input: Record<string, unknown> = {}) {
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

async function run() {
	console.log('=== Testing variant_update (fetch-then-merge) ===\n')

	// 1. Create product
	const product = await call('fluentcart_product_create', {
		post_title: 'Variant Update Test',
		post_status: 'draft',
		detail: { fulfillment_type: 'physical' },
	})
	// Response: { data: { ID: 123, variant: {...} }, message: "..." }
	const pd = product.data as Record<string, unknown>
	const pData = pd?.data as Record<string, unknown>
	const productId = pData?.ID as number
	console.log(`1. Created product: ID ${productId}`)
	console.log(`   raw keys: ${JSON.stringify(Object.keys(pd || {}))}`)

	if (!productId) {
		console.log('   FULL RESPONSE:', product.raw.slice(0, 500))
		console.log('   Cannot continue without product ID')
		return
	}

	// 2. Create variant
	const variant = await call('fluentcart_variant_create', {
		product_id: productId,
		title: 'Original Title',
		price: 1000,
		sku: 'VU-TEST',
		stock_quantity: 50,
	})
	// Response: { message: "...", data: { id: 456, ... } }
	const vd = variant.data as Record<string, unknown>
	const vData = vd?.data as Record<string, unknown>
	const variantId = vData?.id as number
	console.log(`2. Created variant: ID ${variantId}`)
	console.log(`   raw keys: ${JSON.stringify(Object.keys(vd || {}))}`)

	if (!variantId) {
		console.log('   FULL RESPONSE:', variant.raw.slice(0, 500))
		console.log('   Cannot continue without variant ID')
		// Cleanup
		await call('fluentcart_product_delete', { product_id: productId })
		return
	}

	// 3. Update title only
	console.log('\n3. Updating title only...')
	const update1 = await call('fluentcart_variant_update', {
		product_id: productId,
		variant_id: variantId,
		title: 'Updated Title',
	})
	console.log(`   Result: ${update1.isError ? '❌ ERROR' : '✅ OK'}`)
	if (update1.isError) console.log(`   ${update1.raw.slice(0, 300)}`)
	else {
		const ud = update1.data as Record<string, unknown>
		const v = (ud?.data ?? ud) as Record<string, unknown>
		console.log(`   Title: ${v?.variation_title}`)
		console.log(`   Price: ${v?.item_price} (should still be 1000 or equivalent)`)
		console.log(`   SKU: ${v?.sku} (should still be VU-TEST)`)
	}

	// 4. Update price only
	console.log('\n4. Updating price only...')
	const update2 = await call('fluentcart_variant_update', {
		product_id: productId,
		variant_id: variantId,
		price: 2500,
	})
	console.log(`   Result: ${update2.isError ? '❌ ERROR' : '✅ OK'}`)
	if (update2.isError) console.log(`   ${update2.raw.slice(0, 300)}`)
	else {
		const ud = update2.data as Record<string, unknown>
		const v = (ud?.data ?? ud) as Record<string, unknown>
		console.log(`   Title: ${v?.variation_title} (should be Updated Title)`)
		console.log(`   Price: ${v?.item_price} (should be 2500 or equivalent)`)
	}

	// 5. Update stock only
	console.log('\n5. Updating stock only...')
	const update3 = await call('fluentcart_variant_update', {
		product_id: productId,
		variant_id: variantId,
		stock_quantity: 100,
	})
	console.log(`   Result: ${update3.isError ? '❌ ERROR' : '✅ OK'}`)
	if (update3.isError) console.log(`   ${update3.raw.slice(0, 300)}`)
	else {
		const ud = update3.data as Record<string, unknown>
		const v = (ud?.data ?? ud) as Record<string, unknown>
		console.log(`   Stock: ${v?.total_stock} (should be 100)`)
	}

	// Cleanup
	console.log('\n--- Cleanup ---')
	await call('fluentcart_product_delete', { product_id: productId })
	console.log(`Product ${productId}: deleted`)

	console.log('\n=== DONE ===')
}

run().catch((e) => {
	console.error('FATAL:', e)
	process.exit(1)
})
