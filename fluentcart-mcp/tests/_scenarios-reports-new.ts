/**
 * Batch F — Report tools scenarios (18 new report insight tools + core report tools).
 * All read-only: call each tool and verify it responds without error.
 * Run: cd /Users/tomrobak/_projects_/fchub-plugins/fluentcart-mcp && set -a && source .env && set +a && npx tsx tests/_scenarios-reports-new.ts
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

function _extractId(data: unknown, ...keys: string[]): number | null {
	if (!data || typeof data !== 'object') return null
	const obj = data as Record<string, unknown>
	for (const k of keys) {
		if (typeof obj[k] === 'number') return obj[k] as number
	}
	for (const wrapper of ['data', 'product', 'variant', 'order', 'bump', 'label', 'subscription']) {
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
	console.log(`\n❌ SCENARIO FAILED: ${name}\n   Reason: ${error}`)
}

const DATE_RANGE = { startDate: '2024-01-01', endDate: '2026-03-03' }

// ── Scenario 1: Dashboard Reports ────────────────────────────────
async function scenario1() {
	const name = '1. Dashboard Reports (dashboard_summary + report_summary)'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		log('1.1', 'fluentcart_report_dashboard_summary')
		const dashSummary = await call('fluentcart_report_dashboard_summary', DATE_RANGE)
		show(dashSummary)
		if (dashSummary.isError) {
			fail(name, `dashboard_summary error: ${dashSummary.raw}`)
			return
		}

		log('1.2', 'fluentcart_report_summary')
		const reportSummary = await call('fluentcart_report_summary', DATE_RANGE)
		show(reportSummary)
		if (reportSummary.isError) {
			fail(name, `report_summary error: ${reportSummary.raw}`)
			return
		}

		log('1.3', 'fluentcart_report_dashboard_stats')
		const dashStats = await call('fluentcart_report_dashboard_stats', DATE_RANGE)
		show(dashStats)
		if (dashStats.isError) {
			fail(name, `dashboard_stats error: ${dashStats.raw}`)
			return
		}

		pass(name)
	} catch (e) {
		fail(name, String(e))
	}
}

// ── Scenario 2: Product Reports ──────────────────────────────────
async function scenario2() {
	const name = '2. Product Reports (top_sold_products + country_heat_map)'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		log('2.1', 'fluentcart_report_top_sold_products')
		const topSold = await call('fluentcart_report_top_sold_products', {
			...DATE_RANGE,
			per_page: 5,
		})
		show(topSold)
		if (topSold.isError) {
			fail(name, `top_sold_products error: ${topSold.raw}`)
			return
		}

		log('2.2', 'fluentcart_report_country_heat_map')
		const heatmap = await call('fluentcart_report_country_heat_map', DATE_RANGE)
		show(heatmap)
		if (heatmap.isError) {
			fail(name, `country_heat_map error: ${heatmap.raw}`)
			return
		}

		log('2.3', 'fluentcart_report_top_products_sold (insight variant)')
		const topProductsSold = await call('fluentcart_report_top_products_sold', {
			...DATE_RANGE,
			per_page: 5,
		})
		show(topProductsSold)
		if (topProductsSold.isError) {
			fail(name, `top_products_sold error: ${topProductsSold.raw}`)
			return
		}

		pass(name)
	} catch (e) {
		fail(name, String(e))
	}
}

// ── Scenario 3: Cart Analytics ───────────────────────────────────
async function scenario3() {
	const name = '3. Cart Analytics (cart_report + order_value_distribution)'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		log('3.1', 'fluentcart_report_cart')
		const cart = await call('fluentcart_report_cart', DATE_RANGE)
		show(cart)
		if (cart.isError) {
			fail(name, `cart_report error: ${cart.raw}`)
			return
		}

		log('3.2', 'fluentcart_report_order_value_distribution')
		const orderValue = await call('fluentcart_report_order_value_distribution', DATE_RANGE)
		show(orderValue)
		if (orderValue.isError) {
			fail(name, `order_value_distribution error: ${orderValue.raw}`)
			return
		}

		pass(name)
	} catch (e) {
		fail(name, String(e))
	}
}

// ── Scenario 4: Time Analytics ───────────────────────────────────
async function scenario4() {
	const name = '4. Time Analytics (day_and_hour + item_count_distribution)'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		log('4.1', 'fluentcart_report_day_and_hour')
		const dayHour = await call('fluentcart_report_day_and_hour', DATE_RANGE)
		show(dayHour)
		if (dayHour.isError) {
			fail(name, `day_and_hour error: ${dayHour.raw}`)
			return
		}

		log('4.2', 'fluentcart_report_item_count_distribution')
		const itemCount = await call('fluentcart_report_item_count_distribution', DATE_RANGE)
		show(itemCount)
		if (itemCount.isError) {
			fail(name, `item_count_distribution error: ${itemCount.raw}`)
			return
		}

		pass(name)
	} catch (e) {
		fail(name, String(e))
	}
}

// ── Scenario 5: Completion Metrics ───────────────────────────────
async function scenario5() {
	const name = '5. Completion Metrics (order_completion_time + weeks_between_refund)'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		log('5.1', 'fluentcart_report_order_completion_time')
		const completion = await call('fluentcart_report_order_completion_time', DATE_RANGE)
		show(completion)
		if (completion.isError) {
			fail(name, `order_completion_time error: ${completion.raw}`)
			return
		}

		log('5.2', 'fluentcart_report_weeks_between_refund')
		const refundWeeks = await call('fluentcart_report_weeks_between_refund', DATE_RANGE)
		show(refundWeeks)
		if (refundWeeks.isError) {
			fail(name, `weeks_between_refund error: ${refundWeeks.raw}`)
			return
		}

		pass(name)
	} catch (e) {
		fail(name, String(e))
	}
}

// ── Scenario 6: License Reports ──────────────────────────────────
async function scenario6() {
	const name = '6. License Reports (license_chart + license_pie_chart + license_summary)'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		log('6.1', 'fluentcart_report_license_chart')
		const licChart = await call('fluentcart_report_license_chart', {
			...DATE_RANGE,
			groupKey: 'monthly',
		})
		show(licChart)
		if (licChart.isError) {
			fail(name, `license_chart error: ${licChart.raw}`)
			return
		}

		log('6.2', 'fluentcart_report_license_pie_chart')
		const licPie = await call('fluentcart_report_license_pie_chart', DATE_RANGE)
		show(licPie)
		if (licPie.isError) {
			fail(name, `license_pie_chart error: ${licPie.raw}`)
			return
		}

		log('6.3', 'fluentcart_report_license_summary')
		const licSummary = await call('fluentcart_report_license_summary', DATE_RANGE)
		show(licSummary)
		if (licSummary.isError) {
			fail(name, `license_summary error: ${licSummary.raw}`)
			return
		}

		pass(name)
	} catch (e) {
		fail(name, String(e))
	}
}

// ── Scenario 7: Retention Reports ────────────────────────────────
async function scenario7() {
	const name =
		'7. Retention Reports (retention_chart + subscription_retention + subscription_cohorts)'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		log('7.1', 'fluentcart_report_retention_chart')
		const retChart = await call('fluentcart_report_retention_chart', DATE_RANGE)
		show(retChart)
		if (retChart.isError) {
			fail(name, `retention_chart error: ${retChart.raw}`)
			return
		}

		log('7.2', 'fluentcart_report_subscription_retention')
		const subRet = await call('fluentcart_report_subscription_retention', DATE_RANGE)
		show(subRet)
		if (subRet.isError) {
			fail(name, `subscription_retention error: ${subRet.raw}`)
			return
		}

		log('7.3', 'fluentcart_report_subscription_cohorts')
		const cohorts = await call('fluentcart_report_subscription_cohorts', DATE_RANGE)
		show(cohorts)
		if (cohorts.isError) {
			fail(name, `subscription_cohorts error: ${cohorts.raw}`)
			return
		}

		pass(name)
	} catch (e) {
		fail(name, String(e))
	}
}

// ── Scenario 8: Snapshots & Sources ──────────────────────────────
async function scenario8() {
	const name = '8. Snapshots & Sources (retention_snapshots_status + generate + sources)'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		log('8.1', 'fluentcart_report_retention_snapshots_status')
		const snapStatus = await call('fluentcart_report_retention_snapshots_status')
		show(snapStatus)
		if (snapStatus.isError) {
			fail(name, `retention_snapshots_status error: ${snapStatus.raw}`)
			return
		}

		log('8.2', 'fluentcart_report_retention_snapshots_generate')
		const snapGen = await call('fluentcart_report_retention_snapshots_generate')
		show(snapGen)
		if (snapGen.isError) {
			fail(name, `retention_snapshots_generate error: ${snapGen.raw}`)
			return
		}

		log('8.3', 'fluentcart_report_sources')
		const sources = await call('fluentcart_report_sources', DATE_RANGE)
		show(sources)
		if (sources.isError) {
			fail(name, `report_sources error: ${sources.raw}`)
			return
		}

		pass(name)
	} catch (e) {
		fail(name, String(e))
	}
}

// ── Scenario 9: Core Reports (existing but may not have been tested) ─
async function scenario9() {
	const name = '9. Core Reports Sweep (overview, meta, revenue, sales, order_chart, etc.)'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	const tools = [
		{ tool: 'fluentcart_report_overview', input: DATE_RANGE },
		{ tool: 'fluentcart_report_meta', input: {} },
		{ tool: 'fluentcart_report_revenue', input: { ...DATE_RANGE, groupKey: 'monthly' } },
		{ tool: 'fluentcart_report_revenue_by_group', input: { ...DATE_RANGE, groupKey: 'monthly' } },
		{ tool: 'fluentcart_report_sales', input: DATE_RANGE },
		{ tool: 'fluentcart_report_sales_growth', input: DATE_RANGE },
		{ tool: 'fluentcart_report_sales_growth_chart', input: { ...DATE_RANGE, groupKey: 'monthly' } },
		{ tool: 'fluentcart_report_order_chart', input: { ...DATE_RANGE, groupKey: 'monthly' } },
		{ tool: 'fluentcart_report_orders_by_group', input: DATE_RANGE },
		{ tool: 'fluentcart_report_quick_order_stats', input: { day_range: '30' } },
		{ tool: 'fluentcart_report_recent_orders', input: {} },
		{ tool: 'fluentcart_report_unfulfilled_orders', input: { page: 1, per_page: 5 } },
		{ tool: 'fluentcart_report_recent_activities', input: {} },
	]

	let failures = 0
	try {
		for (let i = 0; i < tools.length; i++) {
			const { tool, input } = tools[i]
			log(`9.${i + 1}`, tool)
			const r = await call(tool, input)
			show(r, 400)
			if (r.isError) {
				console.log(`  ⚠️ ${tool} returned error`)
				failures++
			}
		}

		if (failures > 0) {
			fail(name, `${failures}/${tools.length} core report tools returned errors`)
		} else {
			pass(name)
		}
	} catch (e) {
		fail(name, String(e))
	}
}

// ── Scenario 10: Insight Reports Sweep (product, customer, subscription, refund) ─
async function scenario10() {
	const name = '10. Insight Reports Sweep (product_performance, customer, new_vs_returning, etc.)'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	const tools = [
		{ tool: 'fluentcart_report_product', input: DATE_RANGE },
		{ tool: 'fluentcart_report_product_performance', input: DATE_RANGE },
		{ tool: 'fluentcart_report_top_sold_variants', input: { ...DATE_RANGE, per_page: 5 } },
		{ tool: 'fluentcart_report_customer', input: DATE_RANGE },
		{ tool: 'fluentcart_report_new_vs_returning', input: DATE_RANGE },
		{ tool: 'fluentcart_report_daily_signups', input: DATE_RANGE },
		{ tool: 'fluentcart_report_repeat_customers', input: { ...DATE_RANGE, per_page: 5 } },
		{ tool: 'fluentcart_report_refund_chart', input: { ...DATE_RANGE, groupKey: 'monthly' } },
		{ tool: 'fluentcart_report_refund_by_group', input: DATE_RANGE },
		{ tool: 'fluentcart_report_subscription_chart', input: { ...DATE_RANGE, groupKey: 'monthly' } },
		{ tool: 'fluentcart_report_future_renewals', input: DATE_RANGE },
	]

	let failures = 0
	try {
		for (let i = 0; i < tools.length; i++) {
			const { tool, input } = tools[i]
			log(`10.${i + 1}`, tool)
			const r = await call(tool, input)
			show(r, 400)
			if (r.isError) {
				console.log(`  ⚠️ ${tool} returned error`)
				failures++
			}
		}

		if (failures > 0) {
			fail(name, `${failures}/${tools.length} insight report tools returned errors`)
		} else {
			pass(name)
		}
	} catch (e) {
		fail(name, String(e))
	}
}

// ── Run ──────────────────────────────────────────────────────────
async function run() {
	console.log('╔══════════════════════════════════════════════════════════╗')
	console.log('║  BATCH F — Report Tools Scenarios                       ║')
	console.log('╚══════════════════════════════════════════════════════════╝')

	await scenario1()
	await scenario2()
	await scenario3()
	await scenario4()
	await scenario5()
	await scenario6()
	await scenario7()
	await scenario8()
	await scenario9()
	await scenario10()

	// ── Summary ──
	console.log(`\n${'═'.repeat(60)}`)
	console.log('FINAL RESULTS')
	console.log('═'.repeat(60))
	const passed = results.filter((r) => r.passed).length
	const failed = results.filter((r) => !r.passed).length
	console.log(`Total: ${results.length}  |  Passed: ${passed}  |  Failed: ${failed}`)
	for (const r of results) {
		console.log(`  ${r.passed ? '✅' : '❌'} ${r.name}${r.error ? ` — ${r.error}` : ''}`)
	}

	if (failed > 0) process.exit(1)
}

run().catch((err) => {
	console.error('Fatal error:', err)
	process.exit(1)
})
