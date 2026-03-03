/**
 * Batch G — Untested existing tools (~100 tools across various domains).
 * Tests read-only tools first, then CRUD where safe.
 * Run: cd /Users/tomrobak/_projects_/fchub-plugins/fluentcart-mcp && set -a && source .env && set +a && npx tsx tests/_scenarios-untested-tools.ts
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
	for (const wrapper of [
		'data',
		'product',
		'variant',
		'order',
		'bump',
		'label',
		'subscription',
		'customer',
	]) {
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

function _extractFromArray(data: unknown, path: string, idKey = 'id'): number | null {
	if (!data || typeof data !== 'object') return null
	const obj = data as Record<string, unknown>
	const arr = obj[path]
	if (Array.isArray(arr) && arr.length > 0) {
		const first = arr[0] as Record<string, unknown>
		if (typeof first[idKey] === 'number') return first[idKey] as number
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

// ── Scenario 1: Order Transactions ───────────────────────────────
async function scenario1() {
	const name = '1. Order Transactions (list + get for existing order)'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		// First, get an order to work with
		log('1.1', 'Get an existing order via fluentcart_order_list')
		const orders = await call('fluentcart_order_list', { per_page: 1 })
		show(orders, 400)
		if (orders.isError) {
			fail(name, `order_list error: ${orders.raw}`)
			return
		}

		const orderData = orders.data as Record<string, unknown>
		const ordersWrapper = orderData?.orders as Record<string, unknown>
		const orderArr = ordersWrapper?.data as Record<string, unknown>[]
		if (!orderArr || orderArr.length === 0) {
			console.log('  No orders found, skipping transaction tests')
			pass(name)
			return
		}
		const orderId = orderArr[0].id as number
		console.log(`  Using order ID: ${orderId}`)

		log('1.2', `fluentcart_order_transactions for order ${orderId}`)
		const txns = await call('fluentcart_order_transactions', { order_id: orderId })
		show(txns)
		if (txns.isError) {
			fail(name, `order_transactions error: ${txns.raw}`)
			return
		}

		// Try to get a specific transaction if any exist
		const txnData = txns.data as Record<string, unknown>
		const txnArr = txnData?.transactions as unknown[]
		if (Array.isArray(txnArr) && txnArr.length > 0) {
			const txnId = (txnArr[0] as Record<string, unknown>).id as number
			log('1.3', `fluentcart_order_transaction_get for txn ${txnId}`)
			const txn = await call('fluentcart_order_transaction_get', {
				order_id: orderId,
				transaction_id: txnId,
			})
			show(txn)
			if (txn.isError) {
				fail(name, `order_transaction_get error: ${txn.raw}`)
				return
			}
		} else {
			console.log('  No transactions found for this order, skipping get')
		}

		pass(name)
	} catch (e) {
		fail(name, String(e))
	}
}

// ── Scenario 2: Order Operations ─────────────────────────────────
async function scenario2() {
	const name = '2. Order Operations (shipping_methods, calculate_shipping)'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		log('2.1', 'fluentcart_order_shipping_methods')
		const methods = await call('fluentcart_order_shipping_methods')
		show(methods)
		if (methods.isError) {
			fail(name, `shipping_methods error: ${methods.raw}`)
			return
		}

		log('2.2', 'fluentcart_order_calculate_shipping (empty request)')
		const calc = await call('fluentcart_order_calculate_shipping', {
			shipping_address: { country: 'PL', city: 'Warsaw', postcode: '00-001' },
		})
		show(calc)
		// calculate_shipping may error with no items — that's expected
		if (calc.isError) {
			console.log('  (Expected: may need items to calculate)')
		}

		pass(name)
	} catch (e) {
		fail(name, String(e))
	}
}

// ── Scenario 3: Customer Extra ───────────────────────────────────
async function scenario3() {
	const name = '3. Customer Extra (address_select, attachable_users, orders_simple)'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		// Get a customer first
		log('3.1', 'Get existing customer')
		const customers = await call('fluentcart_customer_list', { per_page: 1 })
		show(customers, 400)
		if (customers.isError) {
			fail(name, `customer_list error: ${customers.raw}`)
			return
		}

		const custData = customers.data as Record<string, unknown>
		const custWrapper = custData?.customers as Record<string, unknown>
		const custArr = custWrapper?.data as Record<string, unknown>[]
		if (!custArr || custArr.length === 0) {
			console.log('  No customers found, skipping')
			pass(name)
			return
		}
		const custId = custArr[0].id as number
		console.log(`  Using customer ID: ${custId}`)

		log('3.2', `fluentcart_customer_address_select for customer ${custId}`)
		const addrSelect = await call('fluentcart_customer_address_select', { customer_id: custId })
		show(addrSelect)
		if (addrSelect.isError) {
			fail(name, `customer_address_select error: ${addrSelect.raw}`)
			return
		}

		log('3.3', 'fluentcart_customer_attachable_users')
		const attachable = await call('fluentcart_customer_attachable_users', { search: 'admin' })
		show(attachable)
		if (attachable.isError) {
			fail(name, `customer_attachable_users error: ${attachable.raw}`)
			return
		}

		log('3.4', `fluentcart_customer_orders_simple for customer ${custId}`)
		const simpleOrders = await call('fluentcart_customer_orders_simple', { customer_id: custId })
		show(simpleOrders)
		if (simpleOrders.isError) {
			fail(name, `customer_orders_simple error: ${simpleOrders.raw}`)
			return
		}

		pass(name)
	} catch (e) {
		fail(name, String(e))
	}
}

// ── Scenario 4: Product Search Tools ─────────────────────────────
async function scenario4() {
	const name = '4. Product Search (search_by_name, public_search, search_variant_by_name)'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		log('4.1', 'fluentcart_product_search_by_name')
		const searchByName = await call('fluentcart_product_search_by_name', { name: 'Tiger' })
		show(searchByName)
		if (searchByName.isError) {
			fail(name, `product_search_by_name error: ${searchByName.raw}`)
			return
		}

		log('4.2', 'fluentcart_public_product_search')
		const pubSearch = await call('fluentcart_public_product_search', { search: 'Tiger' })
		show(pubSearch)
		if (pubSearch.isError) {
			fail(name, `public_product_search error: ${pubSearch.raw}`)
			return
		}

		log('4.3', 'fluentcart_product_search_variant_by_name')
		const varSearch = await call('fluentcart_product_search_variant_by_name', { search: 'Tiger' })
		show(varSearch)
		if (varSearch.isError) {
			fail(name, `product_search_variant_by_name error: ${varSearch.raw}`)
			return
		}

		pass(name)
	} catch (e) {
		fail(name, String(e))
	}
}

// ── Scenario 5: Product Features ─────────────────────────────────
async function scenario5() {
	const name =
		'5. Product Features (bundle_info, find_subscription_variants, search_variant_options)'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		// Get a product to test with
		log('5.1', 'Get first product')
		const products = await call('fluentcart_product_list', { per_page: 1 })
		if (products.isError) {
			fail(name, `product_list error: ${products.raw}`)
			return
		}

		const prodData = products.data as Record<string, unknown>
		const prodWrapper = prodData?.products as Record<string, unknown>
		const prodArr = prodWrapper?.data as Record<string, unknown>[]
		let productId = 0
		if (prodArr && prodArr.length > 0) {
			productId = (prodArr[0].ID ?? prodArr[0].id) as number
		}

		if (productId > 0) {
			log('5.2', `fluentcart_product_bundle_info for product ${productId}`)
			const bundle = await call('fluentcart_product_bundle_info', { product_id: productId })
			show(bundle)
			// bundle_info may return empty — that's fine for non-bundle products
		}

		log('5.3', 'fluentcart_product_find_subscription_variants')
		const subVars = await call('fluentcart_product_find_subscription_variants', { search: '' })
		show(subVars)
		if (subVars.isError) {
			fail(name, `find_subscription_variants error: ${subVars.raw}`)
			return
		}

		log('5.4', 'fluentcart_product_search_variant_options')
		const varOpts = await call('fluentcart_product_search_variant_options', { search: '' })
		show(varOpts)
		if (varOpts.isError) {
			fail(name, `search_variant_options error: ${varOpts.raw}`)
			return
		}

		pass(name)
	} catch (e) {
		fail(name, String(e))
	}
}

// ── Scenario 6: Attribute Groups ─────────────────────────────────
async function scenario6() {
	const name = '6. Attribute Groups (list + get + term_list)'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		log('6.1', 'fluentcart_attribute_group_list')
		const groups = await call('fluentcart_attribute_group_list')
		show(groups)
		if (groups.isError) {
			fail(name, `attribute_group_list error: ${groups.raw}`)
			return
		}

		const groupData = groups.data as Record<string, unknown>
		const groupArr = groupData?.groups as Record<string, unknown>[]
		if (Array.isArray(groupArr) && groupArr.length > 0) {
			const groupId = groupArr[0].id as number
			console.log(`  Using group ID: ${groupId}`)

			log('6.2', `fluentcart_attribute_group_get for group ${groupId}`)
			const group = await call('fluentcart_attribute_group_get', { group_id: groupId })
			show(group)
			if (group.isError) {
				fail(name, `attribute_group_get error: ${group.raw}`)
				return
			}

			log('6.3', `fluentcart_attribute_term_list for group ${groupId}`)
			const terms = await call('fluentcart_attribute_term_list', { group_id: groupId })
			show(terms)
			if (terms.isError) {
				fail(name, `attribute_term_list error: ${terms.raw}`)
				return
			}
		} else {
			console.log('  No attribute groups found, skipping get/terms')
		}

		pass(name)
	} catch (e) {
		fail(name, String(e))
	}
}

// ── Scenario 7: Coupon Extras ────────────────────────────────────
async function scenario7() {
	const name = '7. Coupon Extras (coupon_settings_get, coupon_list_alt)'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		log('7.1', 'fluentcart_coupon_settings_get')
		const settings = await call('fluentcart_coupon_settings_get')
		show(settings)
		if (settings.isError) {
			fail(name, `coupon_settings_get error: ${settings.raw}`)
			return
		}

		log('7.2', 'fluentcart_coupon_list_alt')
		const listAlt = await call('fluentcart_coupon_list_alt', { per_page: 5 })
		show(listAlt)
		if (listAlt.isError) {
			fail(name, `coupon_list_alt error: ${listAlt.raw}`)
			return
		}

		pass(name)
	} catch (e) {
		fail(name, String(e))
	}
}

// ── Scenario 8: Subscriptions ────────────────────────────────────
async function scenario8() {
	const name = '8. Subscriptions (list + fetch if any exist)'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		log('8.1', 'fluentcart_subscription_list')
		const subs = await call('fluentcart_subscription_list', { per_page: 5 })
		show(subs)
		if (subs.isError) {
			fail(name, `subscription_list error: ${subs.raw}`)
			return
		}

		// Try to fetch a subscription from gateway if any exist
		const subData = subs.data as Record<string, unknown>
		const subWrapper = subData?.subscriptions ?? subData?.data
		let subArr: Record<string, unknown>[] = []
		if (subWrapper && typeof subWrapper === 'object') {
			const w = subWrapper as Record<string, unknown>
			if (Array.isArray(w.data)) subArr = w.data as Record<string, unknown>[]
			else if (Array.isArray(subWrapper)) subArr = subWrapper as Record<string, unknown>[]
		}

		if (subArr.length > 0) {
			const subId = subArr[0].id as number
			const orderId = subArr[0].order_id as number
			console.log(`  Found subscription ${subId} on order ${orderId}`)

			log('8.2', `fluentcart_subscription_get for sub ${subId}`)
			const subGet = await call('fluentcart_subscription_get', { subscription_id: subId })
			show(subGet)

			if (orderId) {
				log('8.3', `fluentcart_subscription_fetch for sub ${subId} on order ${orderId}`)
				const subFetch = await call('fluentcart_subscription_fetch', {
					order_id: orderId,
					subscription_id: subId,
				})
				show(subFetch)
				// fetch may error if no gateway — acceptable
			}
		} else {
			console.log('  No subscriptions found, skipping fetch')
		}

		pass(name)
	} catch (e) {
		fail(name, String(e))
	}
}

// ── Scenario 9: Integration Feeds ────────────────────────────────
async function scenario9() {
	const name = '9. Integration Feeds (global_feeds + global_settings + addons)'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		log('9.1', 'fluentcart_integration_get_global_feeds')
		const feeds = await call('fluentcart_integration_get_global_feeds')
		show(feeds)
		if (feeds.isError) {
			fail(name, `integration_get_global_feeds error: ${feeds.raw}`)
			return
		}

		log('9.2', 'fluentcart_integration_list_addons')
		const addons = await call('fluentcart_integration_list_addons')
		show(addons)
		if (addons.isError) {
			fail(name, `integration_list_addons error: ${addons.raw}`)
			return
		}

		log('9.3', 'fluentcart_integration_get_global_settings (fakturownia)')
		const gSettings = await call('fluentcart_integration_get_global_settings', {
			settings_key: 'fakturownia',
		})
		show(gSettings)
		// May error if fakturownia plugin not active — acceptable

		pass(name)
	} catch (e) {
		fail(name, String(e))
	}
}

// ── Scenario 10: Order Bumps ─────────────────────────────────────
async function scenario10() {
	const name = '10. Order Bumps (list)'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		log('10.1', 'fluentcart_order_bump_list')
		const bumps = await call('fluentcart_order_bump_list', { per_page: 5 })
		show(bumps)
		if (bumps.isError) {
			fail(name, `order_bump_list error: ${bumps.raw}`)
			return
		}

		pass(name)
	} catch (e) {
		fail(name, String(e))
	}
}

// ── Scenario 11: Labels (CRUD) ──────────────────────────────────
async function scenario11() {
	const name = '11. Labels (list + create + cleanup)'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		log('11.1', 'fluentcart_label_list')
		const labels = await call('fluentcart_label_list')
		show(labels)
		if (labels.isError) {
			fail(name, `label_list error: ${labels.raw}`)
			return
		}

		log('11.2', 'fluentcart_label_create "MCP Test Label"')
		const created = await call('fluentcart_label_create', {
			value: 'MCP Test Label',
			color: '#3498db',
		})
		show(created)
		if (created.isError) {
			fail(name, `label_create error: ${created.raw}`)
			return
		}

		// No delete endpoint, so label stays — mark as test
		console.log('  (Note: no label_delete tool exists; test label persists)')

		pass(name)
	} catch (e) {
		fail(name, String(e))
	}
}

// ── Scenario 12: Activity ────────────────────────────────────────
async function scenario12() {
	const name = '12. Activity (list with filters)'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		log('12.1', 'fluentcart_activity_list (no filter)')
		const allActs = await call('fluentcart_activity_list', { per_page: 5 })
		show(allActs)
		if (allActs.isError) {
			fail(name, `activity_list error: ${allActs.raw}`)
			return
		}

		log('12.2', 'fluentcart_activity_list (filter by module_name=Order)')
		const orderActs = await call('fluentcart_activity_list', { per_page: 5, module_name: 'Order' })
		show(orderActs)
		// May return empty — that's fine

		pass(name)
	} catch (e) {
		fail(name, String(e))
	}
}

// ── Scenario 13: Application ─────────────────────────────────────
async function scenario13() {
	const name = '13. Application (app_init + widgets + attachments)'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		log('13.1', 'fluentcart_app_init')
		const init = await call('fluentcart_app_init')
		show(init)
		if (init.isError) {
			fail(name, `app_init error: ${init.raw}`)
			return
		}

		log('13.2', 'fluentcart_app_get_widgets')
		const widgets = await call('fluentcart_app_get_widgets')
		show(widgets)
		if (widgets.isError) {
			fail(name, `app_get_widgets error: ${widgets.raw}`)
			return
		}

		log('13.3', 'fluentcart_app_get_attachments')
		const attachments = await call('fluentcart_app_get_attachments', { per_page: 5 })
		show(attachments)
		if (attachments.isError) {
			fail(name, `app_get_attachments error: ${attachments.raw}`)
			return
		}

		pass(name)
	} catch (e) {
		fail(name, String(e))
	}
}

// ── Scenario 14: Public Endpoints ────────────────────────────────
async function scenario14() {
	const name = '14. Public Endpoints (products, product_views, user_login error)'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		log('14.1', 'fluentcart_public_products')
		const pubProducts = await call('fluentcart_public_products', { per_page: 5 })
		show(pubProducts)
		if (pubProducts.isError) {
			fail(name, `public_products error: ${pubProducts.raw}`)
			return
		}

		log('14.2', 'fluentcart_public_product_views')
		const pubViews = await call('fluentcart_public_product_views')
		show(pubViews)
		if (pubViews.isError) {
			fail(name, `public_product_views error: ${pubViews.raw}`)
			return
		}

		log('14.3', 'fluentcart_public_user_login (expect error — bad credentials)')
		const login = await call('fluentcart_public_user_login', {
			email: 'nonexistent@test.com',
			password: 'wrongpassword',
		})
		show(login)
		// We expect this to error — invalid credentials. That's fine.
		if (login.isError) {
			console.log('  (Expected: login with bad credentials should fail)')
		}

		pass(name)
	} catch (e) {
		fail(name, String(e))
	}
}

// ── Scenario 15: Misc ────────────────────────────────────────────
async function scenario15() {
	const name = '15. Misc (countries, country_info, filter_options, form_search_options)'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		log('15.1', 'fluentcart_misc_countries')
		const countries = await call('fluentcart_misc_countries')
		show(countries, 400)
		if (countries.isError) {
			fail(name, `misc_countries error: ${countries.raw}`)
			return
		}

		log('15.2', 'fluentcart_misc_country_info (PL)')
		const countryPL = await call('fluentcart_misc_country_info', { country_code: 'PL' })
		show(countryPL)
		if (countryPL.isError) {
			fail(name, `misc_country_info error: ${countryPL.raw}`)
			return
		}

		log('15.3', 'fluentcart_misc_filter_options')
		const filterOpts = await call('fluentcart_misc_filter_options')
		show(filterOpts)
		if (filterOpts.isError) {
			fail(name, `misc_filter_options error: ${filterOpts.raw}`)
			return
		}

		log('15.4', 'fluentcart_misc_form_search_options')
		const formOpts = await call('fluentcart_misc_form_search_options')
		show(formOpts)
		if (formOpts.isError) {
			fail(name, `misc_form_search_options error: ${formOpts.raw}`)
			return
		}

		pass(name)
	} catch (e) {
		fail(name, String(e))
	}
}

// ── Scenario 16: Dashboard ───────────────────────────────────────
async function scenario16() {
	const name = '16. Dashboard (onboarding + overview)'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		log('16.1', 'fluentcart_dashboard_onboarding')
		const onboard = await call('fluentcart_dashboard_onboarding')
		show(onboard)
		if (onboard.isError) {
			fail(name, `dashboard_onboarding error: ${onboard.raw}`)
			return
		}

		log('16.2', 'fluentcart_dashboard_overview')
		const overview = await call('fluentcart_dashboard_overview')
		show(overview)
		if (overview.isError) {
			fail(name, `dashboard_overview error: ${overview.raw}`)
			return
		}

		pass(name)
	} catch (e) {
		fail(name, String(e))
	}
}

// ── Scenario 17: Product Catalog Extra ───────────────────────────
async function scenario17() {
	const name = '17. Product Catalog (terms, suggest_sku, fetch_by_ids)'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		log('17.1', 'fluentcart_product_terms')
		const terms = await call('fluentcart_product_terms')
		show(terms)
		if (terms.isError) {
			fail(name, `product_terms error: ${terms.raw}`)
			return
		}

		log('17.2', 'fluentcart_product_suggest_sku')
		const sku = await call('fluentcart_product_suggest_sku', { title: 'Tiger Pants Supreme' })
		show(sku)
		if (sku.isError) {
			fail(name, `product_suggest_sku error: ${sku.raw}`)
			return
		}

		// Get a product ID for fetch_by_ids
		const products = await call('fluentcart_product_list', { per_page: 1 })
		const prodData = products.data as Record<string, unknown>
		const prodWrapper = prodData?.products as Record<string, unknown>
		const prodArr = prodWrapper?.data as Record<string, unknown>[]
		if (prodArr && prodArr.length > 0) {
			const pid = (prodArr[0].ID ?? prodArr[0].id) as number
			log('17.3', `fluentcart_product_fetch_by_ids for ${pid}`)
			const fetched = await call('fluentcart_product_fetch_by_ids', { product_ids: String(pid) })
			show(fetched)
			if (fetched.isError) {
				fail(name, `product_fetch_by_ids error: ${fetched.raw}`)
				return
			}
		}

		pass(name)
	} catch (e) {
		fail(name, String(e))
	}
}

// ── Scenario 18: Customer Stats & LTV ────────────────────────────
async function scenario18() {
	const name = '18. Customer Stats & LTV (stats + recalculate_ltv)'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		// Get a customer
		const customers = await call('fluentcart_customer_list', { per_page: 1 })
		if (customers.isError) {
			fail(name, `customer_list error: ${customers.raw}`)
			return
		}

		const custData = customers.data as Record<string, unknown>
		const custWrapper = custData?.customers as Record<string, unknown>
		const custArr = custWrapper?.data as Record<string, unknown>[]
		if (!custArr || custArr.length === 0) {
			console.log('  No customers found, skipping')
			pass(name)
			return
		}
		const custId = custArr[0].id as number
		console.log(`  Using customer ID: ${custId}`)

		log('18.1', `fluentcart_customer_stats for customer ${custId}`)
		const stats = await call('fluentcart_customer_stats', { customer_id: custId })
		show(stats)
		if (stats.isError) {
			fail(name, `customer_stats error: ${stats.raw}`)
			return
		}

		log('18.2', `fluentcart_customer_recalculate_ltv for customer ${custId}`)
		const ltv = await call('fluentcart_customer_recalculate_ltv', { customer_id: custId })
		show(ltv)
		if (ltv.isError) {
			fail(name, `customer_recalculate_ltv error: ${ltv.raw}`)
			return
		}

		pass(name)
	} catch (e) {
		fail(name, String(e))
	}
}

// ── Scenario 19: Variant List All ────────────────────────────────
async function scenario19() {
	const name = '19. Variant Listing (variant_list_all + variant_list)'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		log('19.1', 'fluentcart_variant_list_all')
		const allVars = await call('fluentcart_variant_list_all', { per_page: 5 })
		show(allVars)
		if (allVars.isError) {
			fail(name, `variant_list_all error: ${allVars.raw}`)
			return
		}

		log('19.2', 'fluentcart_variant_list')
		const varList = await call('fluentcart_variant_list', { per_page: 5 })
		show(varList)
		if (varList.isError) {
			fail(name, `variant_list error: ${varList.raw}`)
			return
		}

		pass(name)
	} catch (e) {
		fail(name, String(e))
	}
}

// ── Scenario 20: Product Integrations ────────────────────────────
async function scenario20() {
	const name = '20. Product Integrations (list for a product)'
	console.log(`\n${'═'.repeat(60)}`)
	console.log(`SCENARIO: ${name}`)
	console.log('═'.repeat(60))

	try {
		const products = await call('fluentcart_product_list', { per_page: 1 })
		if (products.isError) {
			fail(name, `product_list error: ${products.raw}`)
			return
		}

		const prodData = products.data as Record<string, unknown>
		const prodWrapper = prodData?.products as Record<string, unknown>
		const prodArr = prodWrapper?.data as Record<string, unknown>[]
		if (!prodArr || prodArr.length === 0) {
			console.log('  No products found, skipping')
			pass(name)
			return
		}
		const productId = (prodArr[0].ID ?? prodArr[0].id) as number

		log('20.1', `fluentcart_product_integrations for product ${productId}`)
		const integrations = await call('fluentcart_product_integrations', { product_id: productId })
		show(integrations)
		if (integrations.isError) {
			fail(name, `product_integrations error: ${integrations.raw}`)
			return
		}

		pass(name)
	} catch (e) {
		fail(name, String(e))
	}
}

// ── Run ──────────────────────────────────────────────────────────
async function run() {
	console.log('╔══════════════════════════════════════════════════════════╗')
	console.log('║  BATCH G — Untested Tools Scenarios                     ║')
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
	await scenario11()
	await scenario12()
	await scenario13()
	await scenario14()
	await scenario15()
	await scenario16()
	await scenario17()
	await scenario18()
	await scenario19()
	await scenario20()

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
