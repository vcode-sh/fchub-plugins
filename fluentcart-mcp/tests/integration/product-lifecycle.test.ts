// Live lane: can this server create a product a customer could actually buy?
//
// Until now it could not, and nothing said so. Three defects stacked up:
//
//  1. `product_create` never sent `detail.variation_type`. ProductCreateRequest declares a
//     `'simple'` fallback, but Sanitizer::sanitize only applies a rule to a key that is present
//     (Sanitizer.php:459), so an omitted key gets no default. ProductController::create then reads
//     `variation_type === 'simple'` to decide whether to make the starter variant, and skips it —
//     leaving a product with zero variants, which `canPurchase()` can never accept.
//  2. `product_get` never asked for the variants relation, so it returned a product with no
//     `variants` key at all while its description promised full detail.
//  3. `variant_update` rebuilt the whole row from defaults, so setting a price also nulled
//     `serial_index`, `manage_stock` and `downloadable`, and deleted the variant's thumbnail.
//
// Every fixture here is created by this run, recorded by exact id, removed, and independently
// proven gone. Nothing pre-existing is touched: the seeded thumbnail references an attachment
// already in the media library but never modifies it.
import { afterAll, beforeAll, describe, expect, it } from 'vitest'
import type { FluentCartClient } from '../../src/api/client.js'
import type { ToolDefinition } from '../../src/tools/_factory.js'
import { createAllTools } from '../../src/tools/index.js'
import { CleanupLedger } from './support/cleanup-ledger.js'
import { getLiveClient, verifyProductMissing } from './support/live-client.js'
import { getLiveRun } from './support/live-run.js'

const run = getLiveRun()
const ledger = new CleanupLedger()

let client: FluentCartClient
let tools: ToolDefinition[]

interface ToolOutcome {
	isError: boolean
	data: unknown
}

async function call(name: string, input: Record<string, unknown>): Promise<ToolOutcome> {
	const tool = tools.find((candidate) => candidate.name === name)
	if (!tool) throw new Error(`${name} is not registered`)
	const result = (await tool.handler(input as never, {} as never)) as {
		isError?: boolean
		content: { text: string }[]
	}
	const text = result.content[0]?.text ?? ''
	let data: unknown = text
	try {
		data = JSON.parse(text)
	} catch {
		// Errors come back as prose; the caller asserts on isError instead.
	}
	return { isError: Boolean(result.isError), data }
}

function asRecord(value: unknown): Record<string, unknown> {
	return value !== null && typeof value === 'object' ? (value as Record<string, unknown>) : {}
}

/** Create a product through the tool under test and register it for removal immediately. */
async function createOwnedProduct(label: string): Promise<number> {
	const created = await call('fluentcart_product_create', {
		post_title: `${run.prefix}-${label}`,
		post_status: 'publish',
		fulfillment_type: 'physical',
	})
	expect(created.isError, `product_create failed: ${JSON.stringify(created.data)}`).toBe(false)

	const id = Number(asRecord(asRecord(created.data).data).ID)
	expect(Number.isInteger(id) && id > 0, 'create returned no usable product id').toBe(true)

	ledger.track({
		type: 'product',
		id,
		remove: async (productId) => {
			await client.delete(`/products/${productId}`)
		},
		verifyMissing: verifyProductMissing,
	})
	return id
}

/** The variant rows FluentCart holds for a product, read from the pricing view. */
async function variantsOf(productId: number): Promise<Record<string, unknown>[]> {
	const response = await client.get(`/products/${productId}/pricing`)
	const body = asRecord(response.data)
	const product = asRecord(body.product ?? body)
	return Array.isArray(product.variants) ? (product.variants as Record<string, unknown>[]) : []
}

beforeAll(() => {
	client = getLiveClient()
	tools = createAllTools(client, {})
})

afterAll(async () => {
	await ledger.cleanup()
})

describe('a created product is sellable', () => {
	it('gives a new product a starter variant', async () => {
		const productId = await createOwnedProduct('starter')
		const variants = await variantsOf(productId)

		// The whole defect in one assertion: without variation_type this was zero.
		expect(variants.length, 'a product with no variants can never be purchased').toBe(1)
	})

	it('satisfies every condition canPurchase checks', async () => {
		const productId = await createOwnedProduct('purchasable')

		const raw = asRecord((await client.get(`/products/${productId}`)).data)
		const product = asRecord(raw.product ?? raw)
		const variant = (await variantsOf(productId))[0]

		// ProductVariation::canPurchase requires an active variant on a published product.
		expect(product.post_status).toBe('publish')
		expect(variant?.item_status).toBe('active')
	})

	it('returns the variants from product_get', async () => {
		const productId = await createOwnedProduct('readback')
		const got = await call('fluentcart_product_get', { product_id: productId })
		const product = asRecord(asRecord(got.data).product ?? got.data)

		expect(Array.isArray(product.variants), 'product_get returned no variants key').toBe(true)
		expect((product.variants as unknown[]).length).toBe(1)
	})

	it('lists the variants from variant_list', async () => {
		const productId = await createOwnedProduct('listing')
		const listed = await call('fluentcart_variant_list', { product_id: productId })
		const body = asRecord(listed.data)

		// The fallback path used to read a payload with no variants and report total 0.
		expect(body.total).toBe(1)
		expect((body.variants as unknown[]).length).toBe(1)
	})
})

describe('setting a price changes only the price', () => {
	it('preserves every other field on the variant, including its thumbnail', async () => {
		const productId = await createOwnedProduct('pricing')
		const variantId = Number((await variantsOf(productId))[0]?.id)
		expect(Number.isInteger(variantId)).toBe(true)

		// Seed the run's own variant with the fields the rebuild used to discard. The media entry
		// references an attachment already in the library; it is read, never altered.
		await client.post(`/products/variants/${variantId}`, {
			variants: {
				id: variantId,
				post_id: productId,
				variation_title: 'preserve',
				item_price: 10,
				compare_price: 0,
				fulfillment_type: 'physical',
				stock_status: 'in-stock',
				item_status: 'active',
				total_stock: 5,
				available: 5,
				committed: 0,
				on_hold: 0,
				manage_stock: 1,
				serial_index: 1,
				item_cost: 3,
				manage_cost: 'true',
				downloadable: 'false',
				other_info: { payment_type: 'onetime' },
				media: [{ id: 7, title: 'seed', url: 'https://example.invalid/seed.jpeg' }],
			},
		})

		const before = (await variantsOf(productId))[0] ?? {}
		const updated = await call('fluentcart_variant_update', {
			product_id: productId,
			variant_id: variantId,
			price: 129.99,
		})
		expect(updated.isError, `variant_update failed: ${JSON.stringify(updated.data)}`).toBe(false)

		const after = (await variantsOf(productId))[0] ?? {}

		// The price is the one thing that may move.
		expect(after.item_price).toBe(12999)

		for (const field of [
			'serial_index',
			'manage_stock',
			'item_cost',
			'manage_cost',
			'downloadable',
			'total_stock',
			'available',
			'item_status',
			'thumbnail',
		]) {
			expect(after[field], `${field} was altered by a price change`).toEqual(before[field])
		}
	})

	it('leaves the product sellable afterwards', async () => {
		const productId = await createOwnedProduct('sellable')
		const variantId = Number((await variantsOf(productId))[0]?.id)

		await call('fluentcart_variant_update', {
			product_id: productId,
			variant_id: variantId,
			price: 49.5,
		})

		const raw = asRecord((await client.get(`/products/${productId}`)).data)
		const product = asRecord(raw.product ?? raw)
		const variant = (await variantsOf(productId))[0] ?? {}

		expect(product.post_status).toBe('publish')
		expect(variant.item_status).toBe('active')
		expect(Number(variant.item_price)).toBeGreaterThan(0)
	})
})
