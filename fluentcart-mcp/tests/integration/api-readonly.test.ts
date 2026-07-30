// Read-only integration lane. Reachable only through scripts/run-live-tests.mjs, which owns
// credential loading, target policy and the run identity. Nothing here mutates the store.
import { beforeAll, describe, expect, it } from 'vitest'
import type { FluentCartClient } from '../../src/api/client.js'
import { FluentCartApiError } from '../../src/api/errors.js'
import { getLiveClient } from './support/live-client.js'
import { getLiveRun } from './support/live-run.js'

getLiveRun()

function record(value: unknown): Record<string, unknown> {
	return value !== null && typeof value === 'object' && !Array.isArray(value)
		? (value as Record<string, unknown>)
		: {}
}

function firstId(value: unknown): number | undefined {
	const body = record(value)
	const rows = Array.isArray(body.data) ? body.data : Array.isArray(value) ? value : []
	const id = record(rows[0]).id
	return typeof id === 'number' ? id : undefined
}

/**
 * Ask the store which integrations it has rather than naming one and hoping.
 *
 * `AddonsController::getAddons` answers `{addons: {slug: {enabled, ...}}}`, where `enabled`
 * reflects whether the companion plugin is actually active. Anything not in that map is an
 * integration this store has never heard of, and asking about it proves nothing.
 */
async function discoverAddons(
	client: FluentCartClient,
): Promise<{ all: string[]; enabled: string[] }> {
	const res = await client.get('/integration/addons')
	const body = res.data as { addons?: Record<string, { enabled?: unknown }> } | null
	const addons = body?.addons ?? {}
	const all = Object.keys(addons).sort()
	const enabled = all.filter((slug) => addons[slug]?.enabled === true)
	return { all, enabled }
}

/**
 * `GlobalIntegrationSettings::getGlobalSettingsData` answers 422 for any key with no registered
 * field set, which is the correct answer for an integration that is present but has nothing to
 * configure. Anything else — a 500, an expired credential, a permission failure — is rethrown, so
 * a broken store can never be mistaken for an unconfigured one.
 */
async function integrationSettingsOutcome(
	client: FluentCartClient,
	settingsKey: string,
): Promise<'configured' | 'not-configured'> {
	try {
		const res = await client.get('/integration/global-settings', { settings_key: settingsKey })
		expect(res.status).toBe(200)
		expect(res.data).toBeDefined()
		return 'configured'
	} catch (error) {
		if (error instanceof FluentCartApiError && error.code === 'VALIDATION_ERROR') {
			return 'not-configured'
		}
		throw error
	}
}

describe('Integration: Read-only API endpoints', { timeout: 30_000 }, () => {
	let client: FluentCartClient

	beforeAll(() => {
		client = getLiveClient()
	})

	// ── Dashboard ────────────────────────────────────────────────────

	describe('Dashboard', () => {
		it('GET /dashboard — onboarding data', async () => {
			const res = await client.get('/dashboard')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /dashboard/stats — overview stats', async () => {
			const res = await client.get('/dashboard/stats')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})
	})

	// ── Products ─────────────────────────────────────────────────────

	describe('Products', () => {
		let productId: number | undefined

		beforeAll(async () => {
			const res = await client.get('/products', { per_page: 1 })
			productId = firstId(res.data)
		})

		it('GET /products — lists products', async () => {
			const res = await client.get('/products')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /products — with pagination params', async () => {
			const res = await client.get('/products', { page: 1, per_page: 5 })
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /products — with search filter', async () => {
			const res = await client.get('/products', { search: 'test' })
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /products — with type filter', async () => {
			const res = await client.get('/products', { filter_type: 'simple' })
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /products/:product_id — gets a single product', async ({ skip }) => {
			if (!productId) skip('the live store has no product to read')
			const res = await client.get(`/products/${productId}`)
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /products/searchProductByName — searches by name', async () => {
			const res = await client.get('/products/searchProductByName', {
				name: 'test',
			})
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /products/searchVariantByName — searches variants by name', async () => {
			const res = await client.get('/products/searchVariantByName', {
				search: 'test',
			})
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /products/search-product-variant-options — searches variant options', async () => {
			const res = await client.get('/products/search-product-variant-options', {
				search: 'test',
			})
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /products/suggest-sku — suggests a SKU', async () => {
			const res = await client.get('/products/suggest-sku', {
				title: 'Test Product',
			})
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /products/fetchProductsByIds — fetches by IDs', async ({ skip }) => {
			if (!productId) skip('the live store has no product to fetch')
			const res = await client.get('/products/fetchProductsByIds', {
				product_ids: String(productId),
			})
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /products/findSubscriptionVariants — finds subscription variants', async () => {
			const res = await client.get('/products/findSubscriptionVariants')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		// ── Product Catalog ──

		it('GET /products/fetch-term — gets product terms', async () => {
			const res = await client.get('/products/fetch-term')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		// ── Product Pricing ──

		it('GET /products/:product_id/pricing — gets pricing', async ({ skip }) => {
			if (!productId) skip('the live store has no product pricing to read')
			const res = await client.get(`/products/${productId}/pricing`)
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /products/:product_id/pricing-widgets — gets pricing widgets', async ({ skip }) => {
			if (!productId) skip('the live store has no product pricing widgets to read')
			const res = await client.get(`/products/${productId}/pricing-widgets`)
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /products/:product_id/related-products — gets related products', async ({ skip }) => {
			if (!productId) skip('the live store has no product for a related-products query')
			const res = await client.get(`/products/${productId}/related-products`)
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /products/:product_id/get-bundle-info — gets bundle info', async ({ skip }) => {
			if (!productId) skip('the live store has no product for a bundle-info query')
			const res = await client.get(`/products/${productId}/get-bundle-info`)
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		// ── Product Catalog (requires product_id) ──

		it('GET /products/:product_id/upgrade-paths — gets upgrade settings', async ({ skip }) => {
			if (!productId) skip('the live store has no product for an upgrade-path query')
			const res = await client.get(`/products/${productId}/upgrade-paths`)
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /products/:product_id/integrations — gets product integrations', async ({ skip }) => {
			if (!productId) skip('the live store has no product integrations to read')
			const res = await client.get(`/products/${productId}/integrations`)
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})
	})

	// ── Variants ─────────────────────────────────────────────────────

	describe('Variants', () => {
		let variantId: number | undefined

		beforeAll(async () => {
			const res = await client.get('/variants')
			const body = res.data as Record<string, unknown>
			const variants = body?.data ?? body
			if (Array.isArray(variants) && variants.length > 0) {
				variantId = variants[0]?.id
			}
		})

		it('GET /variants — lists all variants', async () => {
			const res = await client.get('/variants')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /products/variants — lists variations (needs product_id)', async ({ skip }) => {
			if (!variantId) skip('the live store has no product variation')
			// The server requires product_id even though the schema marks it optional
			const productRes = await client.get('/products', { per_page: 1 })
			const pid = firstId(productRes.data)
			if (!pid) skip('the live store has no product for the variation query')
			const res = await client.get('/products/variants', { product_id: pid })
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /products/fetchVariationsByIds — fetches by IDs', async ({ skip }) => {
			if (!variantId) skip('the live store has no product variation to fetch')
			const res = await client.get('/products/fetchVariationsByIds', {
				variation_ids: String(variantId),
			})
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /products/variation/:variant_id/upgrade-paths — gets variant upgrade paths', async ({
			skip,
		}) => {
			if (!variantId) skip('the live store has no variation for an upgrade-path query')
			const res = await client.get(`/products/variation/${variantId}/upgrade-paths`)
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})
	})

	// ── Customers ────────────────────────────────────────────────────

	describe('Customers', () => {
		let customerId: number | undefined

		beforeAll(async () => {
			const res = await client.get('/customers', { per_page: 1 })
			customerId = firstId(res.data)
		})

		it('GET /customers — lists customers', async () => {
			const res = await client.get('/customers')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /customers — with pagination', async () => {
			const res = await client.get('/customers', { page: 1, per_page: 5 })
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /customers — with search', async () => {
			const res = await client.get('/customers', { search: 'test' })
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /customers/:customer_id — gets a single customer', async ({ skip }) => {
			if (!customerId) skip('the live store has no customer to read')
			const res = await client.get(`/customers/${customerId}`)
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /customers/:customer_id/order — gets customer orders (simple)', async ({ skip }) => {
			if (!customerId) skip('the live store has no customer for an order query')
			const res = await client.get(`/customers/${customerId}/order`)
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /customers/get-stats/:customer_id — gets customer stats', async ({ skip }) => {
			if (!customerId) skip('the live store has no customer statistics to read')
			const res = await client.get(`/customers/get-stats/${customerId}`)
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /customers/:customer_id/address — gets customer addresses', async ({ skip }) => {
			if (!customerId) skip('the live store has no customer addresses to read')
			const res = await client.get(`/customers/${customerId}/address`)
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /customers/:customer_id/update-address-select — gets address select options', async ({
			skip,
		}) => {
			if (!customerId) skip('the live store has no customer address options to read')
			const res = await client.get(`/customers/${customerId}/update-address-select`)
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /customers/attachable-user — gets attachable users', async () => {
			const res = await client.get('/customers/attachable-user')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /customers/:customerId/orders — gets customer orders (paginated)', async ({ skip }) => {
			if (!customerId) skip('the live store has no customer for a paginated order query')
			const res = await client.get(`/customers/${customerId}/orders`)
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})
	})

	// ── Orders ───────────────────────────────────────────────────────

	describe('Orders', () => {
		let orderId: number | undefined

		beforeAll(async () => {
			const res = await client.get('/orders', { per_page: 1 })
			orderId = firstId(res.data)
		})

		it('GET /orders — lists orders', async () => {
			const res = await client.get('/orders')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /orders — with pagination', async () => {
			const res = await client.get('/orders', { page: 1, per_page: 5 })
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /orders — with search', async () => {
			const res = await client.get('/orders', { search: 'test' })
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /orders/:order_id — gets a single order', async ({ skip }) => {
			if (!orderId) skip('the live store has no order to read')
			const res = await client.get(`/orders/${orderId}`)
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /orders/:order_id/transactions — gets order transactions', async ({ skip }) => {
			if (!orderId) skip('the live store has no order transaction history to read')
			const res = await client.get(`/orders/${orderId}/transactions`)
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /orders/shipping_methods — gets shipping methods', async () => {
			const res = await client.get('/orders/shipping_methods')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})
	})

	// ── Subscriptions ────────────────────────────────────────────────

	describe('Subscriptions', () => {
		let subscriptionId: number | undefined

		beforeAll(async () => {
			const res = await client.get('/subscriptions', { per_page: 1 })
			subscriptionId = firstId(res.data)
		})

		it('GET /subscriptions — lists subscriptions', async () => {
			const res = await client.get('/subscriptions')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /subscriptions — with pagination', async () => {
			const res = await client.get('/subscriptions', { page: 1, per_page: 5 })
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /subscriptions/:subscription_id — gets a single subscription', async ({ skip }) => {
			if (!subscriptionId) skip('the live store has no subscription to read')
			const res = await client.get(`/subscriptions/${subscriptionId}`)
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})
	})

	// ── Coupons ──────────────────────────────────────────────────────

	describe('Coupons', () => {
		let couponId: number | undefined

		beforeAll(async () => {
			const res = await client.get('/coupons', { per_page: 1 })
			couponId = firstId(res.data)
		})

		it('GET /coupons — lists coupons', async () => {
			const res = await client.get('/coupons')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /coupons — with pagination', async () => {
			const res = await client.get('/coupons', { page: 1, per_page: 5 })
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /coupons/:coupon_id — gets a single coupon', async ({ skip }) => {
			if (!couponId) skip('the live store has no coupon to read')
			const res = await client.get(`/coupons/${couponId}`)
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /coupons/listCoupons — lists coupons (simple)', async () => {
			const res = await client.get('/coupons/listCoupons')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /coupons/getSettings — gets coupon settings', async () => {
			const res = await client.get('/coupons/getSettings')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})
	})

	// ── Reports Core ─────────────────────────────────────────────────

	describe('Reports Core', () => {
		it('GET /reports/overview — high-level financial overview', async () => {
			const res = await client.get('/reports/overview')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /reports/report-overview — summarised report overview', async () => {
			const res = await client.get('/reports/report-overview')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /reports/fetch-report-meta — report metadata', async () => {
			const res = await client.get('/reports/fetch-report-meta')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /reports/dashboard-stats — dashboard stats', async () => {
			const res = await client.get('/reports/dashboard-stats')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /reports/revenue — revenue report', async () => {
			const res = await client.get('/reports/revenue')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /reports/revenue — with date range params', async () => {
			const res = await client.get('/reports/revenue', {
				'params[startDate]': '2025-01-01',
				'params[endDate]': '2025-12-31',
				'params[groupKey]': 'monthly',
			})
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /reports/revenue-by-group — revenue by group', async () => {
			const res = await client.get('/reports/revenue-by-group')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /reports/sales-report — sales report', async () => {
			const res = await client.get('/reports/sales-report')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		// BUG: FluentCart server 500 — Class "FluentCart\App\Modules\ReportingModule\Status" not found
		it.fails('GET /reports/sales-growth — sales growth (SERVER BUG)', async () => {
			const res = await client.get('/reports/sales-growth')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /reports/sales-growth-chart — sales growth chart', async () => {
			const res = await client.get('/reports/sales-growth-chart')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /reports/order-chart — order chart', async () => {
			const res = await client.get('/reports/order-chart')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /reports/fetch-order-by-group — orders by group', async () => {
			const res = await client.get('/reports/fetch-order-by-group')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /reports/quick-order-stats — quick order stats', async () => {
			const res = await client.get('/reports/quick-order-stats', {
				day_range: '30',
			})
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /reports/get-recent-orders — recent orders', async () => {
			const res = await client.get('/reports/get-recent-orders')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		/**
		 * COMPATIBILITY: `/reports/get-unfulfilled-orders` does not exist in this runtime.
		 *
		 * `app/Http/Routes/reports.php` registers no such route in FluentCart 1.5.5, and the only
		 * surviving mention of the word is a commented-out order filter. This is recorded as an
		 * absent-route fact rather than an expected API success, so a later release that adds the
		 * route fails here and gets reviewed instead of silently changing what we believe exists.
		 */
		it('GET /reports/get-unfulfilled-orders — absent from this runtime', async () => {
			await expect(client.get('/reports/get-unfulfilled-orders')).rejects.toMatchObject({
				code: 'NOT_FOUND',
				status: 404,
			})
		})

		it('GET /reports/get-dashboard-summary — dashboard summary', async () => {
			const res = await client.get('/reports/get-dashboard-summary')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /reports/get-recent-activities — recent activities', async () => {
			const res = await client.get('/reports/get-recent-activities')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})
	})

	// ── Reports Insights ─────────────────────────────────────────────

	describe('Reports Insights', () => {
		it('GET /reports/product-report — product report', async () => {
			const res = await client.get('/reports/product-report')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /reports/product-performance — product performance', async () => {
			const res = await client.get('/reports/product-performance')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		// FluentCart 1.6 normalises the missing params object before the deprecated resource query,
		// so the route now returns its deprecation envelope instead of the 1.5.5 server error.
		it('GET /reports/top-products-sold — deprecated top products response', async () => {
			const res = await client.get('/reports/top-products-sold')
			expect(res.status).toBe(200)
			expect(res.data).toMatchObject({
				_deprecated: expect.stringContaining('/reports/fetch-top-sold-products'),
				top_products_sold: expect.any(Array),
			})
		})

		it('GET /reports/fetch-top-sold-products — top sold products', async () => {
			const res = await client.get('/reports/fetch-top-sold-products')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /reports/fetch-top-sold-variants — top sold variants', async () => {
			const res = await client.get('/reports/fetch-top-sold-variants')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /reports/customer-report — customer report', async () => {
			const res = await client.get('/reports/customer-report')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /reports/fetch-new-vs-returning-customer — new vs returning', async () => {
			const res = await client.get('/reports/fetch-new-vs-returning-customer')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /reports/daily-signups — daily signups', async () => {
			const res = await client.get('/reports/daily-signups')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /reports/search-repeat-customer — repeat customers', async () => {
			const res = await client.get('/reports/search-repeat-customer')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /reports/refund-chart — refund chart', async () => {
			const res = await client.get('/reports/refund-chart')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /reports/refund-data-by-group — refund by group', async () => {
			const res = await client.get('/reports/refund-data-by-group')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /reports/subscription-chart — subscription chart', async () => {
			const res = await client.get('/reports/subscription-chart')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /reports/future-renewals — future renewals', async () => {
			const res = await client.get('/reports/future-renewals')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		// BUG: Returns HTML instead of JSON — Licensing module likely not enabled, route not registered
		it.fails('GET /reports/license-summary — license summary (MODULE NOT ENABLED)', async () => {
			const res = await client.get('/reports/license-summary')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})
	})

	// ── Activity ─────────────────────────────────────────────────────

	describe('Activity', () => {
		it('GET /activity — lists activities', async () => {
			const res = await client.get('/activity')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /activity — with pagination', async () => {
			const res = await client.get('/activity', { page: 1, per_page: 5 })
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /activity — with module filter', async () => {
			const res = await client.get('/activity', { module_name: 'Order' })
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})
	})

	// ── Labels ───────────────────────────────────────────────────────

	describe('Labels', () => {
		it('GET /labels — lists labels', async () => {
			const res = await client.get('/labels')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})
	})

	// ── Settings ─────────────────────────────────────────────────────

	describe('Settings', () => {
		it('GET /settings/store — gets store settings', async () => {
			const res = await client.get('/settings/store')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /settings/store — with settings_name', async () => {
			const res = await client.get('/settings/store', {
				settings_name: 'store',
			})
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /settings/payment-methods/all — gets all payment methods', async () => {
			const res = await client.get('/settings/payment-methods/all')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /settings/payment-methods — gets payment method settings', async () => {
			const res = await client.get('/settings/payment-methods', {
				method: 'stripe',
			})
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /settings/modules — gets module settings', async () => {
			const res = await client.get('/settings/modules')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /settings/permissions — gets permissions', async () => {
			const res = await client.get('/settings/permissions')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /settings/confirmation/shortcode — gets confirmation shortcodes', async () => {
			const res = await client.get('/settings/confirmation/shortcode')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})
	})

	// ── Misc ─────────────────────────────────────────────────────────

	describe('Misc', () => {
		it('GET /address-info/countries — gets countries list', async () => {
			const res = await client.get('/address-info/countries')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /address-info/get-country-info — gets country info', async () => {
			const res = await client.get('/address-info/get-country-info', {
				country_code: 'US',
			})
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /address-info/get-country-info — with timezone', async () => {
			const res = await client.get('/address-info/get-country-info', {
				country_code: 'PL',
				timezone: 'Europe/Warsaw',
			})
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /advance_filter/get-filter-options — gets filter options', async () => {
			const res = await client.get('/advance_filter/get-filter-options')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /forms/search_options — gets form search options', async () => {
			const res = await client.get('/forms/search_options')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})
	})

	// ── Application ──────────────────────────────────────────────────

	describe('Application', () => {
		it('GET /app/init — initialises application', async () => {
			const res = await client.get('/app/init')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /app/attachments — gets attachments', async () => {
			const res = await client.get('/app/attachments')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /widgets — gets dashboard widgets', async () => {
			const res = await client.get('/widgets')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})
	})

	// ── Public Endpoints ─────────────────────────────────────────────

	describe('Public', () => {
		it('GET /public/product-views — public product views', async () => {
			const res = await client.get('/public/product-views', undefined, true)
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /public/product-search — public product search', async () => {
			const res = await client.get('/public/product-search', { search: 'test' }, true)
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /public/products — public products list', async () => {
			const res = await client.get('/public/products', undefined, true)
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})
	})

	// ── Integrations ─────────────────────────────────────────────────

	describe('Integrations', () => {
		it('GET /integration/addons — lists integration addons', async () => {
			const res = await client.get('/integration/addons')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		/**
		 * Capability discovery rather than a hard-coded key. The previous revision asked for
		 * `fakturownia`, which this store does not have, and then recorded the store's entirely
		 * correct 422 as a test failure.
		 */
		it('GET /integration/global-settings — only for integrations this store reports', async ({
			skip,
		}) => {
			const { all, enabled } = await discoverAddons(client)
			expect(all.length, 'addon discovery returned nothing to test against').toBeGreaterThan(0)

			// No active integration means there is no settings payload to fetch. We do not invent a
			// key to manufacture a refusal and call that coverage.
			if (enabled.length === 0) skip('the live store reports no enabled integration settings')

			for (const key of enabled) {
				const outcome = await integrationSettingsOutcome(client, key)
				expect(['configured', 'not-configured']).toContain(outcome)
			}
		})

		it('GET /integration/global-feeds — gets global feeds', async () => {
			const res = await client.get('/integration/global-feeds')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /integration/global-feeds/settings — gets feed settings (with valid integration)', async ({
			skip,
		}) => {
			// This endpoint requires a valid integration_name for an installed & configured integration.
			// First, get available addons to find a valid integration_name.
			const addonsRes = await client.get('/integration/addons')
			const addons = record(addonsRes.data)
			const addonList = addons.addons ?? record(addons.data).addons ?? addons
			let integrationName: string | undefined
			if (Array.isArray(addonList)) {
				const installed = addonList.find(
					(a: Record<string, unknown>) => a.is_installed || a.is_configured,
				)
				if (installed) {
					integrationName = installed.key ?? installed.name
				}
			}
			if (!integrationName) skip('the live store reports no installed integration')
			const res = await client.get('/integration/global-feeds/settings', {
				integration_name: integrationName,
			})
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /integration/feed/lists — gets feed lists', async () => {
			const res = await client.get('/integration/feed/lists', {
				provider: 'fluent-crm',
			})
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /integration/feed/dynamic_options — gets dynamic options', async () => {
			const res = await client.get('/integration/feed/dynamic_options', {
				option_key: 'test',
			})
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})
	})

	// ── Order Bumps ──────────────────────────────────────────────────

	describe('Order Bumps', () => {
		let bumpId: number | undefined

		beforeAll(async () => {
			try {
				const res = await client.get('/order_bump', { per_page: 1 })
				bumpId = firstId(res.data)
			} catch {
				// order bumps module may not be enabled
			}
		})

		// BUG: Returns HTML instead of JSON — Order Bump module likely not enabled, route not registered
		it.fails('GET /order_bump — lists order bumps (MODULE NOT ENABLED)', async () => {
			const res = await client.get('/order_bump')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /order_bump/:id — gets a single order bump', async ({ skip }) => {
			if (!bumpId) skip('the live store has no enabled order-bump record')
			const res = await client.get(`/order_bump/${bumpId}`)
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})
	})

	// ── Product Options (Attribute Groups & Terms) ───────────────────

	describe('Product Options', () => {
		let groupId: number | undefined

		beforeAll(async () => {
			try {
				const res = await client.get('/options/attr/groups')
				const body = res.data as Record<string, unknown>
				const groups = body?.data ?? body
				if (Array.isArray(groups) && groups.length > 0) {
					groupId = groups[0]?.id
				}
			} catch {
				// may not exist
			}
		})

		it('GET /options/attr/groups — lists attribute groups', async () => {
			const res = await client.get('/options/attr/groups')
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /options/attr/group/:group_id — gets a single group', async ({ skip }) => {
			if (!groupId) skip('the live store has no attribute group to read')
			const res = await client.get(`/options/attr/group/${groupId}`)
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})

		it('GET /options/attr/group/:group_id/terms — lists group terms', async ({ skip }) => {
			if (!groupId) skip('the live store has no attribute group terms to read')
			const res = await client.get(`/options/attr/group/${groupId}/terms`)
			expect(res.status).toBe(200)
			expect(res.data).toBeDefined()
		})
	})
})
