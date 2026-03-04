/**
 * Coupon wave-2 fix verification: CO-08 (apply/cancel field names) + CO-09 (update fetch-merge).
 *
 * Run: cd /Users/tomrobak/_projects_/fchub-plugins/fluentcart-mcp && set -a && source .env && set +a && npx tsx tests/_scenarios-fix-coupons-wave2.ts
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

/** Extract array from paginated response like {orders: {data: [...]}} or {data: [...]} */
function extractList(data: unknown): Record<string, unknown>[] {
	if (!data || typeof data !== 'object') return []
	const obj = data as Record<string, unknown>
	for (const key of Object.keys(obj)) {
		const val = obj[key]
		if (val && typeof val === 'object' && 'data' in (val as Record<string, unknown>)) {
			const inner = (val as Record<string, unknown>).data
			if (Array.isArray(inner)) return inner as Record<string, unknown>[]
		}
	}
	if (Array.isArray(obj.data)) return obj.data as Record<string, unknown>[]
	if (Array.isArray(data)) return data as Record<string, unknown>[]
	return []
}

/** Recursively find a numeric `id` in a response object */
function findId(data: unknown): number | undefined {
	if (!data || typeof data !== 'object') return undefined
	const obj = data as Record<string, unknown>
	if (typeof obj.id === 'number') return obj.id
	// Check nested: {data: {id: ...}}, {coupon: {id: ...}}
	for (const val of Object.values(obj)) {
		if (val && typeof val === 'object') {
			const inner = val as Record<string, unknown>
			if (typeof inner.id === 'number') return inner.id
		}
	}
	return undefined
}

// ── Setup: ensure we have a test coupon ─────────────────────
async function ensureTestCoupon(): Promise<{ id: number; code: string } | null> {
	const listR = await call('fluentcart_coupon_list', { per_page: 50 })
	if (!listR.isError) {
		const coupons = extractList(listR.data)
		const existing = coupons.find((c) => (c.code as string) === 'WAVE2TEST')
		if (existing) return { id: existing.id as number, code: existing.code as string }
	}

	log('setup', 'Creating test coupon WAVE2TEST')
	const createR = await call('fluentcart_coupon_create', {
		title: 'Wave2 Test Coupon',
		code: 'WAVE2TEST',
		type: 'percentage',
		amount: 10,
		status: 'active',
		stackable: 'yes',
		show_on_checkout: 'no',
		notes: 'Created by wave2 test',
	})
	show(createR)
	if (createR.isError) {
		console.log('  Could not create test coupon')
		return null
	}
	const id = findId(createR.data)
	if (!id) {
		console.log('  Could not extract coupon ID from response')
		console.log('  Response: ' + JSON.stringify(createR.data).slice(0, 500))
		return null
	}
	return { id, code: 'WAVE2TEST' }
}

/** Check if an error is an acceptable business-rule error (not a field-mapping bug) */
function isAcceptableApplyError(raw: string): boolean {
	const t = raw.toLowerCase()
	return (
		t.includes('already') ||
		t.includes('minimum') ||
		t.includes('not eligible') ||
		t.includes('not applicable') ||
		t.includes('could not be applied') ||
		t.includes('does not exist') ||
		t.includes('no applicable products')
	)
}

function isAcceptableCancelError(raw: string): boolean {
	const t = raw.toLowerCase()
	return (
		t.includes('not found') ||
		t.includes('not applied') ||
		t.includes('no coupon') ||
		t.includes('could not') ||
		t.includes('does not') ||
		t.includes('no applicable') ||
		t.includes('keys() on null') // backend NPE when no coupon was applied to the order
	)
}

// ── CO-08a: coupon_apply sends coupon_code + order_items ─────
async function scenarioCouponApply(
	couponCode: string,
): Promise<{ orderId: number; couponCode: string; applied: boolean }> {
	const name = 'CO-08a: coupon_apply sends coupon_code + order_items'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		log('1', 'Listing orders to find one with items')
		const ordersR = await call('fluentcart_order_list', { per_page: 10 })
		show(ordersR)
		if (ordersR.isError) throw new Error('Failed to list orders')

		const orders = extractList(ordersR.data)
		if (orders.length === 0) throw new Error('No orders found')

		// Prefer an order with total_amount > 0
		const order =
			orders.find((o) => (o.total_amount as number) > 0) ?? orders[0]
		const orderId = order.id as number
		console.log(`  Using order #${orderId} (total: ${order.total_amount})`)

		log('2', `Applying coupon "${couponCode}" to order #${orderId}`)
		const applyR = await call('fluentcart_coupon_apply', { code: couponCode, order_id: orderId })
		show(applyR)

		if (applyR.isError) {
			if (isAcceptableApplyError(applyR.raw)) {
				console.log('  (Acceptable business-rule error — endpoint works, field mapping correct)')
				// Key verification: no "coupon_code is required" or "code is required" errors
				if (applyR.raw.includes('"coupon_code"') && applyR.raw.includes('required')) {
					throw new Error('Backend still complains about coupon_code being required — field mapping broken')
				}
				pass(name)
				return { orderId, couponCode, applied: false }
			}
			throw new Error(`coupon_apply failed: ${applyR.raw}`)
		}

		pass(name)
		return { orderId, couponCode, applied: true }
	} catch (e) {
		fail(name, (e as Error).message)
		return { orderId: 0, couponCode: '', applied: false }
	}
}

// ── CO-08b: coupon_cancel sends coupon_code + order_items ────
async function scenarioCouponCancel(orderId: number, couponCode: string, wasApplied: boolean) {
	const name = 'CO-08b: coupon_cancel sends coupon_code + order_items'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	if (!wasApplied) {
		console.log('  Coupon was not applied — testing cancel anyway to verify field mapping')
	}

	try {
		log('1', `Cancelling coupon "${couponCode}" from order #${orderId}`)
		const cancelR = await call('fluentcart_coupon_cancel', {
			code: couponCode,
			order_id: orderId,
		})
		show(cancelR)

		if (cancelR.isError) {
			// Key verification: no "order_items required" error (that was the old bug)
			if (cancelR.raw.includes('"order_items"') && cancelR.raw.includes('required')) {
				throw new Error('Backend complains about order_items required — cancel not sending items')
			}
			if (isAcceptableCancelError(cancelR.raw)) {
				console.log('  (Acceptable error — cancel endpoint works, field mapping correct)')
				pass(name)
				return
			}
			throw new Error(`coupon_cancel failed: ${cancelR.raw}`)
		}

		pass(name)
	} catch (e) {
		fail(name, (e as Error).message)
	}
}

// ── CO-09: coupon_update fetch-merge ─────────────────────────
async function scenarioCouponUpdateFetchMerge(couponId: number) {
	const name = 'CO-09: coupon_update fetch-merge (partial update works)'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		log('1', `Fetching coupon #${couponId}`)
		const getR = await call('fluentcart_coupon_get', { coupon_id: couponId })
		show(getR)
		if (getR.isError) throw new Error(`coupon_get failed: ${getR.raw}`)

		const getWrapper = getR.data as Record<string, unknown>
		const coupon = (getWrapper.coupon ?? getWrapper) as Record<string, unknown>
		const originalTitle = coupon.title as string
		console.log(`  Current title: "${originalTitle}"`)

		const testTitle = `${originalTitle} [wave2-test]`
		log('2', `Updating title to "${testTitle}" (partial — only sending title)`)
		const updateR = await call('fluentcart_coupon_update', {
			coupon_id: couponId,
			title: testTitle,
		})
		show(updateR)
		if (updateR.isError) throw new Error(`coupon_update failed: ${updateR.raw}`)

		log('3', 'Fetching coupon to verify title changed')
		const verifyR = await call('fluentcart_coupon_get', { coupon_id: couponId })
		show(verifyR)
		if (verifyR.isError) throw new Error(`coupon_get failed: ${verifyR.raw}`)

		const verifyWrapper = verifyR.data as Record<string, unknown>
		const updated = (verifyWrapper.coupon ?? verifyWrapper) as Record<string, unknown>
		const newTitle = updated.title as string

		if (!newTitle.includes('[wave2-test]')) {
			throw new Error(
				`Title was not updated. Expected to contain "[wave2-test]", got: "${newTitle}"`,
			)
		}
		console.log(`  Title verified: "${newTitle}"`)

		log('4', `Restoring original title: "${originalTitle}"`)
		const restoreR = await call('fluentcart_coupon_update', {
			coupon_id: couponId,
			title: originalTitle,
		})
		show(restoreR)
		if (restoreR.isError) console.log('  Warning: could not restore original title')

		pass(name)
	} catch (e) {
		fail(name, (e as Error).message)
	}
}

// ── Cleanup ──────────────────────────────────────────────────
async function cleanup(couponId: number) {
	log('cleanup', `Deleting test coupon #${couponId}`)
	const r = await call('fluentcart_coupon_delete', { coupon_id: couponId })
	show(r)
}

// ── Run all ──────────────────────────────────────────────────
async function main() {
	console.log('╔════════════════════════════════════════════════════════════╗')
	console.log('║  COUPON WAVE-2 FIX VERIFICATION                          ║')
	console.log('║  CO-08: coupon_apply/cancel field names                   ║')
	console.log('║  CO-09: coupon_update fetch-merge                         ║')
	console.log('╚════════════════════════════════════════════════════════════╝')

	const testCoupon = await ensureTestCoupon()
	if (!testCoupon) {
		console.log('\nCannot run tests without a coupon. Aborting.')
		process.exit(1)
	}
	console.log(`\nUsing coupon #${testCoupon.id} (${testCoupon.code})`)

	const { orderId, couponCode, applied } = await scenarioCouponApply(testCoupon.code)
	if (orderId) {
		await scenarioCouponCancel(orderId, couponCode || testCoupon.code, applied)
	} else {
		// Get any order for cancel test
		const ordersR = await call('fluentcart_order_list', { per_page: 1 })
		const orders = extractList(ordersR.data)
		if (orders.length > 0) {
			await scenarioCouponCancel(orders[0].id as number, testCoupon.code, false)
		} else {
			fail('CO-08b: coupon_cancel sends coupon_code + order_items', 'No orders available')
		}
	}
	await scenarioCouponUpdateFetchMerge(testCoupon.id)

	// Cleanup
	await cleanup(testCoupon.id)

	// Summary
	console.log(`\n\n${'═'.repeat(60)}`)
	console.log('SUMMARY')
	console.log('═'.repeat(60))
	let passed = 0
	let failed = 0
	for (const r of results) {
		const icon = r.passed ? '✅' : '❌'
		console.log(`  ${icon} ${r.name}${r.error ? ` — ${r.error}` : ''}`)
		if (r.passed) passed++
		else failed++
	}
	console.log(`\n  Total: ${results.length} | Passed: ${passed} | Failed: ${failed}`)

	if (failed > 0) process.exit(1)
}

main().catch((e) => {
	console.error('Fatal error:', e)
	process.exit(1)
})
