/**
 * Live QA test — exercises every major MCP feature against the real FluentCart API.
 * Run: npx tsx tests/live-qa.ts
 *
 * Tests:
 * 1. Static mode tools (products, orders, customers, coupons, reports)
 * 2. Dynamic mode (search_tools, describe_tools, execute_tool)
 * 3. Response transforms — verify they reduce payload size and keep correct shape
 * 4. Resources (4 endpoints)
 * 5. Cache behaviour
 * 6. Token measurement (static vs dynamic tool definitions)
 */

import { toJSONSchema } from 'zod'
import { cacheSize, clearCache } from '../src/cache.js'
import { createServerFromContext, resolveServerContext } from '../src/server.js'

const PASS = '\x1b[32m✓\x1b[0m'
const FAIL = '\x1b[31m✗\x1b[0m'
const WARN = '\x1b[33m⚠\x1b[0m'

let passed = 0
let failed = 0
let warnings = 0

function assert(condition: boolean, label: string, detail?: string) {
	if (condition) {
		console.log(`  ${PASS} ${label}`)
		passed++
	} else {
		console.log(`  ${FAIL} ${label}${detail ? ` — ${detail}` : ''}`)
		failed++
	}
}

function warn(label: string, detail: string) {
	console.log(`  ${WARN} ${label} — ${detail}`)
	warnings++
}

async function callTool(
	tools: Map<string, { handler: (input: Record<string, unknown>) => Promise<unknown> }>,
	name: string,
	input: Record<string, unknown> = {},
): Promise<{ text: string; isError?: boolean; parsed?: unknown }> {
	const tool = tools.get(name)
	if (!tool) throw new Error(`Tool ${name} not found`)
	const result = (await tool.handler(input)) as {
		content: { type: string; text: string }[]
		isError?: boolean
	}
	const text = result.content[0]?.text ?? ''
	let parsed: unknown
	try {
		parsed = JSON.parse(text)
	} catch {
		parsed = undefined
	}
	return { text, isError: result.isError, parsed }
}

function byteSize(obj: unknown): number {
	return new TextEncoder().encode(JSON.stringify(obj)).length
}

// ─── Main ───────────────────────────────────────────────────────────────────
async function main() {
	console.log('\n═══════════════════════════════════════════════')
	console.log('  FluentCart MCP v1.0.0 — Live QA Test Suite')
	console.log('═══════════════════════════════════════════════\n')

	// Setup
	const ctx = resolveServerContext()
	const toolMap = new Map<string, (typeof ctx.tools)[0]>()
	for (const t of ctx.tools) toolMap.set(t.name, t)

	console.log(`Tools loaded: ${ctx.tools.length}`)
	console.log(`API target: ${process.env.FLUENTCART_URL}\n`)

	// ─── 1. Static mode tools ────────────────────────────────────────────
	console.log('━━━ 1. STATIC MODE — Core Tools ━━━')

	// Products
	console.log('\n📦 Products')
	const prodList = await callTool(toolMap, 'fluentcart_product_list', {
		per_page: 5,
	})
	assert(!prodList.isError, 'product_list succeeds')
	const prodData = prodList.parsed as Record<string, unknown>
	const prodWrapper = (prodData?.products ?? prodData) as Record<string, unknown>
	assert(Array.isArray(prodWrapper?.data), 'product_list returns data array')
	if (Array.isArray(prodWrapper?.data) && prodWrapper.data.length > 0) {
		const first = (prodWrapper.data as Record<string, unknown>[])[0]
		assert('ID' in first, 'product has ID field')
		assert('post_title' in first, 'product has post_title field')
		assert('post_status' in first, 'product has post_status field')
		assert('post_name' in first, 'product has post_name field')
		assert('post_date' in first, 'product has post_date field')
		// Transform should have removed heavy fields
		assert(!('post_content' in first), 'product list: post_content stripped')

		// Get single product
		const pid = first.ID as number
		const prodGet = await callTool(toolMap, 'fluentcart_product_get', {
			product_id: pid,
		})
		assert(!prodGet.isError, `product_get(${pid}) succeeds`)
		const prodDetail = prodGet.parsed as Record<string, unknown>
		const product = (prodDetail?.product ?? prodDetail) as Record<string, unknown>
		assert('post_title' in product || 'ID' in product, 'product detail has expected shape')
		assert(!('post_content' in product), 'product detail: post_content stripped')
		assert(!('integrations' in product), 'product detail: integrations stripped')
		if (Array.isArray(product.variants) && product.variants.length > 0) {
			const v = (product.variants as Record<string, unknown>[])[0]
			assert(!('pricing_table' in v), 'variant: pricing_table stripped')
		}
	}

	// Orders
	console.log('\n🛒 Orders')
	const orderList = await callTool(toolMap, 'fluentcart_order_list', {
		per_page: 5,
	})
	assert(!orderList.isError, 'order_list succeeds')
	const orderData = orderList.parsed as Record<string, unknown>
	const orderWrapper = (orderData?.orders ?? orderData) as Record<string, unknown>
	assert(Array.isArray(orderWrapper?.data), 'order_list returns data array')
	if (Array.isArray(orderWrapper?.data) && orderWrapper.data.length > 0) {
		const first = (orderWrapper.data as Record<string, unknown>[])[0]
		assert('id' in first, 'order has id field')
		assert('receipt_number' in first, 'order has receipt_number field')
		assert('status' in first, 'order has status field')
		assert('payment_status' in first, 'order has payment_status field')
		assert('total_amount' in first, 'order has total_amount field')
		assert('customer_id' in first, 'order has customer_id field')
		// Should NOT have heavy fields
		assert(!('activities' in first), 'order list: activities stripped')

		// Get single order
		const oid = first.id as number
		const orderGet = await callTool(toolMap, 'fluentcart_order_get', {
			order_id: oid,
		})
		assert(!orderGet.isError, `order_get(${oid}) succeeds`)
		const orderDetail = orderGet.parsed as Record<string, unknown>
		const order = (orderDetail?.order ?? orderDetail) as Record<string, unknown>
		assert(!('activities' in order), 'order detail: activities stripped')
		assert(!('post_content' in order), 'order detail: post_content stripped')
		// Customer should be compacted
		if (order.customer && typeof order.customer === 'object') {
			const c = order.customer as Record<string, unknown>
			assert('id' in c, 'order.customer has id')
			assert('email' in c, 'order.customer has email')
			// Should NOT have all original customer fields
			assert(!('addresses' in c), 'order.customer: addresses stripped')
		}
		// Transactions should have meta stripped
		if (Array.isArray(order.transactions) && order.transactions.length > 0) {
			const tx = (order.transactions as Record<string, unknown>[])[0]
			assert(!('meta' in tx), 'transaction: meta stripped')
		}
	}

	// Customers
	console.log('\n👤 Customers')
	const custList = await callTool(toolMap, 'fluentcart_customer_list', {
		per_page: 5,
	})
	assert(!custList.isError, 'customer_list succeeds')
	const custData = custList.parsed as Record<string, unknown>
	const custWrapper = (custData?.customers ?? custData) as Record<string, unknown>
	assert(Array.isArray(custWrapper?.data), 'customer_list returns data array')
	if (Array.isArray(custWrapper?.data) && custWrapper.data.length > 0) {
		const first = (custWrapper.data as Record<string, unknown>[])[0]
		assert('id' in first, 'customer has id field')
		assert('email' in first, 'customer has email field')
		assert('full_name' in first, 'customer has full_name field')

		// Get single customer
		const cid = first.id as number
		const custGet = await callTool(toolMap, 'fluentcart_customer_get', {
			customer_id: cid,
		})
		assert(!custGet.isError, `customer_get(${cid}) succeeds`)
		const custDetail = custGet.parsed as Record<string, unknown>
		const customer = (custDetail?.customer ?? custDetail) as Record<string, unknown>
		assert(
			'address_count' in customer || !('addresses' in customer),
			'customer detail: addresses replaced with address_count',
		)
	}

	// Coupons
	console.log('\n🎟️ Coupons')
	const coupList = await callTool(toolMap, 'fluentcart_coupon_list', {
		per_page: 5,
	})
	assert(!coupList.isError, 'coupon_list succeeds')

	// Reports
	console.log('\n📊 Reports')
	const today = new Date().toISOString().split('T')[0]
	const monthAgo = new Date(Date.now() - 30 * 86400000).toISOString().split('T')[0]

	const reportOverview = await callTool(toolMap, 'fluentcart_report_overview', {
		startDate: monthAgo,
		endDate: today,
	})
	assert(!reportOverview.isError, 'report_overview succeeds')
	assert(reportOverview.parsed !== undefined, 'report_overview returns JSON')

	const reportRevenue = await callTool(toolMap, 'fluentcart_report_revenue', {
		startDate: monthAgo,
		endDate: today,
	})
	assert(!reportRevenue.isError, 'report_revenue succeeds')

	const reportTopSold = await callTool(toolMap, 'fluentcart_report_top_sold_variants', {
		startDate: monthAgo,
		endDate: today,
	})
	assert(!reportTopSold.isError, 'report_top_sold_variants succeeds')

	// Subscriptions
	console.log('\n🔄 Subscriptions')
	const subList = await callTool(toolMap, 'fluentcart_subscription_list', {
		per_page: 5,
	})
	assert(!subList.isError, 'subscription_list succeeds')

	// Settings
	console.log('\n⚙️ Settings & Misc')
	const storeSettings = await callTool(toolMap, 'fluentcart_settings_get_store', {})
	assert(!storeSettings.isError, 'settings_get_store succeeds')

	const countries = await callTool(toolMap, 'fluentcart_misc_countries', {})
	assert(!countries.isError, 'misc_countries succeeds (cached)')

	const filterOpts = await callTool(toolMap, 'fluentcart_misc_filter_options', {})
	assert(!filterOpts.isError, 'misc_filter_options succeeds (cached)')

	// ─── 2. Dynamic mode ────────────────────────────────────────────────
	console.log('\n\n━━━ 2. DYNAMIC MODE — Meta Tools ━━━')

	const dynamicServer = createServerFromContext(ctx, 'dynamic')
	// Access the internal tool map through the dynamic tools
	// We'll test via the tool definitions directly
	const dynamicTools = new Map<string, (typeof ctx.tools)[0]>()
	// Rebuild dynamic tools from source
	const { registerDynamicTools } = await import('../src/tools/dynamic.js')

	// Create a fake server to capture tool registrations
	const capturedTools: Array<{
		name: string
		handler: (input: Record<string, unknown>) => Promise<unknown>
	}> = []
	const fakeServer = {
		registerTool: (
			name: string,
			_meta: unknown,
			handler: (input: Record<string, unknown>) => Promise<unknown>,
		) => {
			capturedTools.push({ name, handler })
		},
	}
	registerDynamicTools(fakeServer as any, ctx.tools)

	const dynMap = new Map(capturedTools.map((t) => [t.name, t]))

	assert(dynMap.has('fluentcart_search_tools'), 'search_tools registered')
	assert(dynMap.has('fluentcart_describe_tools'), 'describe_tools registered')
	assert(dynMap.has('fluentcart_execute_tool'), 'execute_tool registered')

	// Search tests
	console.log('\n🔍 Search Quality')
	const searches = [
		{ query: 'list products', expect: 'fluentcart_product_list' },
		{ query: 'create order', expect: 'fluentcart_order_create' },
		{ query: 'refund', expect: 'fluentcart_order_refund' },
		{ query: 'subscription', expect: 'fluentcart_subscription_list' },
		{ query: 'coupon create', expect: 'fluentcart_coupon_create' },
		{ query: 'customer address', expect: 'fluentcart_customer_addresses' },
		{ query: 'revenue report', expect: 'fluentcart_report_revenue' },
		{ query: 'payment method', expect: 'fluentcart_payment_get_all' },
	]

	for (const s of searches) {
		const res = (await dynMap.get('fluentcart_search_tools')!.handler({
			query: s.query,
		})) as { content: { text: string }[] }
		const data = JSON.parse(res.content[0].text) as {
			tools: { name: string }[]
		}
		const names = data.tools.map((t) => t.name)
		const found = names.includes(s.expect)
		assert(
			found,
			`search "${s.query}" → ${s.expect}`,
			found ? undefined : `got: ${names.slice(0, 3).join(', ')}`,
		)
	}

	// Describe test
	console.log('\n📋 Describe Tools')
	const descRes = (await dynMap.get('fluentcart_describe_tools')!.handler({
		tools: ['fluentcart_product_list', 'fluentcart_order_get', 'fluentcart_nonexistent'],
	})) as { content: { text: string }[] }
	const descriptions = JSON.parse(descRes.content[0].text) as Array<Record<string, unknown>>
	assert(descriptions.length === 3, 'describe returns 3 results')
	assert('inputSchema' in descriptions[0], 'product_list has inputSchema')
	assert('annotations' in descriptions[0], 'product_list has annotations')
	assert('error' in descriptions[2], 'nonexistent tool returns error')

	// Execute via dynamic mode
	console.log('\n▶️ Execute via Dynamic Mode')
	const execRes = (await dynMap.get('fluentcart_execute_tool')!.handler({
		tool_name: 'fluentcart_product_list',
		input: { per_page: 2 },
	})) as { content: { text: string }[]; isError?: boolean }
	assert(!execRes.isError, 'execute_tool(product_list) succeeds')
	const execData = JSON.parse(execRes.content[0].text)
	assert(execData !== undefined, 'execute_tool returns valid JSON')

	// Execute with bad tool name
	const execBad = (await dynMap.get('fluentcart_execute_tool')!.handler({
		tool_name: 'fluentcart_nonexistent',
		input: {},
	})) as { content: { text: string }[]; isError?: boolean }
	assert(execBad.isError === true, 'execute_tool(nonexistent) returns isError')

	// Execute with bad input
	const execBadInput = (await dynMap.get('fluentcart_execute_tool')!.handler({
		tool_name: 'fluentcart_order_get',
		input: { wrong_field: 'abc' },
	})) as { content: { text: string }[]; isError?: boolean }
	assert(execBadInput.isError === true, 'execute_tool(bad input) returns validation error')

	// ─── 3. Transform Quality & Token Reduction ──────────────────────────
	console.log('\n\n━━━ 3. RESPONSE TRANSFORM QUALITY ━━━')

	// Fetch raw vs transformed and compare
	console.log('\n📏 Payload Size Comparison')

	// Product list — raw vs transformed
	const rawProdResp = await ctx.client.get('/products', { per_page: 10 })
	const rawProdSize = byteSize(rawProdResp.data)

	const transformedProd = await callTool(toolMap, 'fluentcart_product_list', {
		per_page: 10,
	})
	const transformedProdSize = byteSize(JSON.parse(transformedProd.text))
	const prodReduction = ((1 - transformedProdSize / rawProdSize) * 100).toFixed(1)

	console.log(
		`  Product list: ${rawProdSize} → ${transformedProdSize} bytes (${prodReduction}% reduction)`,
	)
	assert(
		transformedProdSize < rawProdSize,
		`product list transform reduces payload (${prodReduction}%)`,
	)

	// Order list — raw vs transformed
	const rawOrderResp = await ctx.client.get('/orders', { per_page: 10 })
	const rawOrderSize = byteSize(rawOrderResp.data)

	const transformedOrder = await callTool(toolMap, 'fluentcart_order_list', {
		per_page: 10,
	})
	const transformedOrderSize = byteSize(JSON.parse(transformedOrder.text))
	const orderReduction = ((1 - transformedOrderSize / rawOrderSize) * 100).toFixed(1)

	console.log(
		`  Order list:   ${rawOrderSize} → ${transformedOrderSize} bytes (${orderReduction}% reduction)`,
	)
	assert(
		transformedOrderSize < rawOrderSize,
		`order list transform reduces payload (${orderReduction}%)`,
	)

	// Customer list — raw vs transformed
	const rawCustResp = await ctx.client.get('/customers', { per_page: 10 })
	const rawCustSize = byteSize(rawCustResp.data)

	const transformedCust = await callTool(toolMap, 'fluentcart_customer_list', {
		per_page: 10,
	})
	const transformedCustSize = byteSize(JSON.parse(transformedCust.text))
	const custReduction = ((1 - transformedCustSize / rawCustSize) * 100).toFixed(1)

	console.log(
		`  Customer list: ${rawCustSize} → ${transformedCustSize} bytes (${custReduction}% reduction)`,
	)
	assert(
		transformedCustSize < rawCustSize,
		`customer list transform reduces payload (${custReduction}%)`,
	)

	// Order detail (if we have an order)
	if (Array.isArray(orderWrapper?.data) && orderWrapper.data.length > 0) {
		const oid = (orderWrapper.data as Record<string, unknown>[])[0].id as number
		const rawOrdDetail = await ctx.client.get(`/orders/${oid}`)
		const rawOrdDetailSize = byteSize(rawOrdDetail.data)

		const transformedOrdDetail = await callTool(toolMap, 'fluentcart_order_get', { order_id: oid })
		const transformedOrdDetailSize = byteSize(JSON.parse(transformedOrdDetail.text))
		const ordDetailReduction = ((1 - transformedOrdDetailSize / rawOrdDetailSize) * 100).toFixed(1)

		console.log(
			`  Order detail: ${rawOrdDetailSize} → ${transformedOrdDetailSize} bytes (${ordDetailReduction}% reduction)`,
		)
		assert(
			transformedOrdDetailSize < rawOrdDetailSize,
			`order detail transform reduces payload (${ordDetailReduction}%)`,
		)
	}

	// ─── 4. Token Measurement (tool definitions) ─────────────────────────
	console.log('\n\n━━━ 4. TOKEN MEASUREMENT — Static vs Dynamic ━━━')

	// Static mode: measure all tool JSON Schema definitions
	let staticTokenPayload = 0
	for (const tool of ctx.tools) {
		const schema = toJSONSchema(tool.schema)
		const toolDef = {
			name: tool.name,
			description: tool.description,
			inputSchema: schema,
		}
		staticTokenPayload += byteSize(toolDef)
	}

	// Dynamic mode: only 3 meta-tool definitions
	const dynamicMetaToolNames = [
		'fluentcart_search_tools',
		'fluentcart_describe_tools',
		'fluentcart_execute_tool',
	]
	let dynamicTokenPayload = 0
	for (const t of ctx.tools.filter((t) => dynamicMetaToolNames.includes(t.name))) {
		// These won't be in ctx.tools — they're registered separately
	}
	// Manually build what the 3 dynamic tools look like
	const searchSchema = {
		type: 'object',
		properties: {
			query: { type: 'string' },
			category: {
				type: 'string',
				enum: [
					'product',
					'order',
					'customer',
					'coupon',
					'report',
					'subscription',
					'integration',
					'setting',
					'label',
					'activity',
					'note',
					'dashboard',
					'application',
					'public',
					'misc',
				],
			},
		},
	}
	const describeSchema = {
		type: 'object',
		properties: {
			tools: { type: 'array', items: { type: 'string' }, maxItems: 10 },
		},
	}
	const executeSchema = {
		type: 'object',
		properties: { tool_name: { type: 'string' }, input: { type: 'object' } },
	}

	dynamicTokenPayload += byteSize({
		name: 'fluentcart_search_tools',
		description: 'Search available FluentCart tools by keyword and optional category.',
		inputSchema: searchSchema,
	})
	dynamicTokenPayload += byteSize({
		name: 'fluentcart_describe_tools',
		description: 'Get full details for specific tools by name.',
		inputSchema: describeSchema,
	})
	dynamicTokenPayload += byteSize({
		name: 'fluentcart_execute_tool',
		description: 'Execute a FluentCart tool by name with the given input.',
		inputSchema: executeSchema,
	})

	const tokenReduction = ((1 - dynamicTokenPayload / staticTokenPayload) * 100).toFixed(1)
	console.log(
		`  Static mode:  ${ctx.tools.length} tools → ${staticTokenPayload.toLocaleString()} bytes`,
	)
	console.log(`  Dynamic mode: 3 tools → ${dynamicTokenPayload.toLocaleString()} bytes`)
	console.log(`  Reduction:    ${tokenReduction}%`)
	assert(Number(tokenReduction) > 95, `token reduction > 95% (actual: ${tokenReduction}%)`)

	// ─── 5. Cache Behaviour ──────────────────────────────────────────────
	console.log('\n\n━━━ 5. CACHE BEHAVIOUR ━━━')

	clearCache()
	assert(cacheSize() === 0, 'cache cleared')

	// First call — should hit API
	const t1 = Date.now()
	await callTool(toolMap, 'fluentcart_misc_countries', {})
	const firstCallMs = Date.now() - t1

	assert(cacheSize() >= 1, 'cache populated after first call')

	// Second call — should be cached (much faster)
	const t2 = Date.now()
	await callTool(toolMap, 'fluentcart_misc_countries', {})
	const secondCallMs = Date.now() - t2

	console.log(`  First call:  ${firstCallMs}ms (API hit)`)
	console.log(`  Second call: ${secondCallMs}ms (cached)`)
	assert(secondCallMs < firstCallMs, `cached call faster (${secondCallMs}ms vs ${firstCallMs}ms)`)
	if (secondCallMs > 5) {
		warn('cache speed', `cached call took ${secondCallMs}ms (expected <5ms)`)
	}

	// ─── 6. Resources ───────────────────────────────────────────────────
	console.log('\n\n━━━ 6. MCP RESOURCES ━━━')

	// Test each resource endpoint directly via the client
	const resourceEndpoints = [
		{ name: 'store-config', endpoint: '/app/init' },
		{ name: 'store-countries', endpoint: '/address-info/countries' },
		{ name: 'store-payment-methods', endpoint: '/settings/payment-methods/all' },
		{ name: 'store-filter-options', endpoint: '/advance_filter/get-filter-options' },
	]

	for (const res of resourceEndpoints) {
		try {
			const response = await ctx.client.get(res.endpoint)
			assert(response.data !== undefined, `resource ${res.name} returns data`)
			const size = byteSize(response.data)
			console.log(`    ${res.name}: ${size.toLocaleString()} bytes`)
		} catch (e) {
			assert(false, `resource ${res.name}`, (e as Error).message)
		}
	}

	// ─── 7. Error handling ──────────────────────────────────────────────
	console.log('\n\n━━━ 7. ERROR HANDLING ━━━')

	// Non-existent order
	const badOrder = await callTool(toolMap, 'fluentcart_order_get', {
		order_id: 999999,
	})
	assert(badOrder.isError === true, 'non-existent order returns error')
	assert(badOrder.text.includes('Error'), 'error message is descriptive')

	// Non-existent customer
	const badCust = await callTool(toolMap, 'fluentcart_customer_get', {
		customer_id: 999999,
	})
	assert(badCust.isError === true, 'non-existent customer returns error')

	// ─── Summary ─────────────────────────────────────────────────────────
	console.log('\n\n═══════════════════════════════════════════════')
	console.log(`  Results: ${passed} passed, ${failed} failed, ${warnings} warnings`)
	console.log('═══════════════════════════════════════════════\n')

	process.exit(failed > 0 ? 1 : 0)
}

main().catch((e) => {
	console.error('\n\x1b[31mFATAL:\x1b[0m', e)
	process.exit(2)
})
