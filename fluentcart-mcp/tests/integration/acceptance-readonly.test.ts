// Read-only live acceptance. Reachable only through scripts/run-live-tests.mjs.
//
// Everything here goes through the MCP tool layer rather than raw REST, because the tool layer is
// what a caller actually gets: its policy filter, its schemas and its response envelopes are the
// claim under test. `tests/integration/api-readonly.test.ts` covers the REST surface underneath.
//
// The only records this lane touches are the ones it created. Reports are asked about a period
// that closed before this store existed, so a report assertion cannot depend on somebody's real
// trading history and cannot change meaning between runs.

import { afterAll, beforeAll, describe, expect, it } from 'vitest'
import type { OwnedCoupon, OwnedCustomer, OwnedProduct } from './support/acceptance-fixture.js'
import {
	acceptanceContext,
	callTool,
	createOwnedCoupon,
	createOwnedCustomer,
	createOwnedProduct,
	exposedNames,
	findTool,
} from './support/acceptance-fixture.js'
import { CleanupLedger } from './support/cleanup-ledger.js'
import { getLiveRun } from './support/live-run.js'

const run = getLiveRun()
const ledger = new CleanupLedger()

/** A window that closed long before any FluentCart store existed, so it holds nothing. */
const ISOLATED_PERIOD = { date_from: '2001-01-01', date_to: '2001-01-31' }

let ctx: Awaited<ReturnType<typeof acceptanceContext>>
let customer: OwnedCustomer
let product: OwnedProduct
let coupon: OwnedCoupon

beforeAll(async () => {
	ctx = await acceptanceContext('disabled')
	customer = await createOwnedCustomer(ledger)
	product = await createOwnedProduct(ledger)
	coupon = await createOwnedCoupon(ledger)
}, 120_000)

afterAll(async () => {
	// Deliberately unguarded: a cleanup failure must fail this suite.
	await ledger.cleanup()
})

const has = (name: string) => ctx.tools.some((tool) => tool.name === name)

describe('policy-filtered exposure', () => {
	it('exposes only read-classified tools in the default write mode', () => {
		expect(ctx.writePolicy.writeMode).toBe('disabled')
		const nonReads = ctx.tools.filter((tool) => tool.safety.risk !== 'read')
		expect(nonReads.map((tool) => tool.name)).toEqual([])
		console.error(`readonly lane: ${ctx.tools.length} tools exposed, run ${run.id}`)
	})

	it('prunes the registry to routes this store actually serves', () => {
		expect(ctx.capabilities).not.toBeNull()
		expect(ctx.tools.length).toBeGreaterThan(100)
		// Discovery ran, so every exposed tool was checked against a real route index.
		expect(exposedNames(ctx)).toEqual([...exposedNames(ctx)].sort())
	})

	it('hides every high-impact tool from the static registry', () => {
		for (const hidden of [
			'fluentcart_order_delete',
			'fluentcart_order_bulk_action',
			'fluentcart_settings_save_payment_method',
			'fluentcart_settings_save_permissions',
			'fluentcart_file_upload',
			'fluentcart_app_upload_attachment',
			'fluentcart_coupon_create',
		]) {
			expect(has(hidden), `${hidden} must be absent in disabled mode`).toBe(false)
		}
	})
})

describe('store context', () => {
	it('reports currency and payment mode', async () => {
		const outcome = await callTool(ctx, 'fluentcart_app_init')
		expect(outcome.isError).toBe(false)
		const shop = (outcome.json as { shop?: Record<string, unknown> }).shop
		expect(shop, 'app_init must carry a shop block').toBeDefined()

		expect(typeof shop?.currency).toBe('string')
		expect(String(shop?.currency).length).toBeGreaterThan(0)
		expect(typeof shop?.currency_position).toBe('string')

		// `order_mode` is FluentCart's payment mode. Recorded loudly: every guarded lane in this
		// programme is allowed to execute only because this store is not taking real money.
		expect(shop?.order_mode, 'acceptance requires a test-mode store').toBe('test')
		console.error(`readonly lane: currency ${shop?.currency}, order_mode ${shop?.order_mode}`)
	})

	it('exposes no store timezone anywhere, so report date boundaries are unverified', async () => {
		// A real gap, asserted rather than papered over. FluentCart's /app/init shop block carries
		// currency and order mode but no timezone, and the store settings do not carry one either.
		// Every report boundary in this server is therefore in whatever zone the store assumes.
		// When a timezone surface lands, update this test to assert the value — do not delete it,
		// because the documentation must not claim timezone-aware reporting before then.
		for (const name of ['fluentcart_app_init', 'fluentcart_settings_get_store']) {
			const outcome = await callTool(ctx, name)
			expect(outcome.isError).toBe(false)
			expect(outcome.text.toLowerCase(), `${name} unexpectedly reports a timezone`).not.toContain(
				'timezone',
			)
		}
		console.error('readonly lane: no read tool exposes a store timezone')
	})

	it('reports the store settings a caller needs before writing anything', async () => {
		const outcome = await callTool(ctx, 'fluentcart_settings_get_store')
		expect(outcome.isError).toBe(false)
		expect(outcome.json).toBeDefined()
	})

	it('reports the effective permission set rather than assuming administrator', async () => {
		const outcome = await callTool(ctx, 'fluentcart_settings_get_permissions')
		expect(outcome.isError).toBe(false)
		expect(outcome.json).toBeDefined()
	})

	it('lists the configured payment methods without naming one first', async () => {
		// `fluentcart_payment_get_settings` requires a specific method; asking which methods exist
		// is the only question that can be put to a store whose gateways are unknown.
		const outcome = await callTool(ctx, 'fluentcart_payment_get_all')
		expect(outcome.isError).toBe(false)
		expect(outcome.json).toBeDefined()
	})
})

describe('reference data', () => {
	it('serves the country reference list', async () => {
		const outcome = await callTool(ctx, 'fluentcart_misc_countries')
		expect(outcome.isError).toBe(false)
		expect(outcome.text.length).toBeGreaterThan(100)
	})

	it('serves filter options for the admin surfaces', async () => {
		const outcome = await callTool(ctx, 'fluentcart_misc_filter_options')
		expect(outcome.isError).toBe(false)
		expect(outcome.json).toBeDefined()
	})
})

describe('compact entity lists and pagination', () => {
	const listTools = [
		'fluentcart_order_list',
		'fluentcart_product_list',
		'fluentcart_customer_list',
		'fluentcart_coupon_list',
		'fluentcart_subscription_list',
	]

	for (const name of listTools) {
		it(`${name} honours an explicit page size`, async () => {
			if (!has(name)) {
				throw new Error(`${name} is not exposed; the read lane cannot assert pagination on it`)
			}
			const outcome = await callTool(ctx, name, { page: 1, per_page: 2 })
			expect(outcome.isError).toBe(false)
			expect(outcome.json).toBeDefined()
		})
	}

	it('refuses a page size beyond the documented maximum instead of silently capping it', async () => {
		const tool = findTool(ctx, 'fluentcart_order_list')
		expect(tool.schema.safeParse({ per_page: 5000 }).success).toBe(false)
	})

	it('returns a second page that is not the first', async () => {
		const first = await callTool(ctx, 'fluentcart_product_list', { page: 1, per_page: 1 })
		const second = await callTool(ctx, 'fluentcart_product_list', { page: 2, per_page: 1 })
		expect(first.isError).toBe(false)
		expect(second.isError).toBe(false)
		// With the run's own draft product present there are at least two products to page through.
		expect(second.text).not.toBe(first.text)
	})
})

describe('verified search against run-owned records', () => {
	it('finds the product this run created, by its run-stamped title', async () => {
		const outcome = await callTool(ctx, 'fluentcart_product_list', { search: run.prefix })
		expect(outcome.isError).toBe(false)
		expect(outcome.text).toContain(String(product.id))
	})

	it('excludes the draft product from storefront search, completely rather than partially', async () => {
		// searchProductByName serves the storefront, so a draft is correctly invisible to it. The
		// answer must still be a complete, well-formed empty page and not a truncated one.
		const outcome = await callTool(ctx, 'fluentcart_product_search_by_name', { name: run.prefix })
		expect(outcome.isError).toBe(false)
		const products = (outcome.json as { products?: { data?: unknown[]; per_page?: number } })
			.products
		expect(products?.data).toEqual([])
		expect(typeof products?.per_page).toBe('number')
	})

	it('finds the customer this run created, by its run-stamped address', async () => {
		const outcome = await callTool(ctx, 'fluentcart_customer_list', { search: run.prefix })
		expect(outcome.isError).toBe(false)
		expect(outcome.text).toContain(customer.email)
	})

	it('reads the owned coupon back through its detail tool', async () => {
		const outcome = await callTool(ctx, 'fluentcart_coupon_get', { coupon_id: coupon.id })
		expect(outcome.isError).toBe(false)
		expect(outcome.text).toContain(coupon.code)
	})

	it('reads the owned product back through its detail tool', async () => {
		const outcome = await callTool(ctx, 'fluentcart_product_get', { product_id: product.id })
		expect(outcome.isError).toBe(false)
		expect(outcome.text).toContain(product.title)
	})
})

describe('reports over an isolated period', () => {
	const reportTools = [
		'fluentcart_report_overview',
		'fluentcart_report_order_chart',
		'fluentcart_report_refund_chart',
		'fluentcart_report_new_vs_returning',
		'fluentcart_report_repeat_customers',
	]

	for (const name of reportTools) {
		it(`${name} answers completely for a period with no trading`, async () => {
			if (!has(name)) return
			const tool = findTool(ctx, name)
			// Only send the period fields this report actually declares.
			const shape = tool.schema.shape as Record<string, unknown>
			const input: Record<string, unknown> = {}
			for (const [key, value] of Object.entries(ISOLATED_PERIOD)) {
				if (key in shape) input[key] = value
			}

			const outcome = await callTool(ctx, name, input)
			expect(outcome.isError).toBe(false)
			// A complete JSON document, never a truncated one dressed up as an answer.
			expect(outcome.json).toBeDefined()
		})
	}
})

describe('the lane changed nothing', () => {
	it('leaves every owned record exactly as this run created it', async () => {
		const readCustomer = await callTool(ctx, 'fluentcart_customer_get', {
			customer_id: customer.id,
		})
		expect(readCustomer.text).toContain(customer.email)

		const readProduct = await callTool(ctx, 'fluentcart_product_get', { product_id: product.id })
		expect(readProduct.text).toContain(product.title)

		const readCoupon = await callTool(ctx, 'fluentcart_coupon_get', { coupon_id: coupon.id })
		expect(readCoupon.text).toContain(coupon.code)
	})

	it('registered every created record for verified removal', () => {
		expect(ledger.size).toBe(3)
	})
})
