// Live lane: the evidence behind promoting the stock and taxonomy writes to `reversible-write`.
//
// A write earns that class only when FluentCart offers an exact read-back AND a supported way to
// put the record back. `GET /products/{id}/pricing` supplies the read-back for both: it carries
// every variant's manage_stock, total_stock, available and stock_status, plus the product's
// assigned categories and brands. This lane proves the restore half — capture, change, restore,
// compare field by field.
//
// It also pins the defect that made the inventory tool dangerous. FluentCart's updateInventory
// does `intval($request->get('available'))`, so a payload omitting that key sends 0 and stamps the
// variant out-of-stock. The old schema exposed only `total_stock`, which made zeroing the stock
// the only thing the tool could do. The handler now reads the variant first and keeps whatever the
// caller left out.
//
// Every fixture is created by this run, recorded by exact id, removed and independently proven
// gone. Terms are referenced, never created: `add-product-terms` stays hidden precisely because
// FluentCart registers no route that deletes a term.
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
/** Existing category term ids, read from the store. Never created, never deleted. */
let termIds: number[] = []

function asRecord(value: unknown): Record<string, unknown> {
	return value !== null && typeof value === 'object' ? (value as Record<string, unknown>) : {}
}

async function call(name: string, input: Record<string, unknown>) {
	const tool = tools.find((candidate) => candidate.name === name)
	if (!tool) throw new Error(`${name} is not registered`)
	const result = (await tool.handler(input as never, {} as never)) as {
		isError?: boolean
		content: { text: string }[]
	}
	return { isError: Boolean(result.isError), text: result.content[0]?.text ?? '' }
}

interface VariantState {
	id: number
	manage_stock: unknown
	total_stock: unknown
	available: unknown
	stock_status: unknown
}

/** Everything this lane can change about a product, in one comparable snapshot. */
async function snapshot(
	productId: number,
): Promise<{ variants: VariantState[]; categories: string[] }> {
	const response = await client.get(`/products/${productId}/pricing`)
	const product = asRecord(asRecord(response.data).product ?? response.data)
	const variants = (product.variants ?? []) as Record<string, unknown>[]
	const categories = (product['product-categories'] ?? []) as Record<string, unknown>[]

	return {
		variants: variants.map((variant) => ({
			id: Number(variant.id),
			manage_stock: variant.manage_stock,
			total_stock: variant.total_stock,
			available: variant.available,
			stock_status: variant.stock_status,
		})),
		categories: categories
			.map((term) => String(term.term_id ?? term.id ?? term))
			.sort((a, b) => a.localeCompare(b)),
	}
}

async function createOwnedProduct(label: string): Promise<number> {
	const response = await client.post('/products', {
		post_title: `${run.prefix}-${label}`,
		post_status: 'draft',
		detail: { fulfillment_type: 'physical', variation_type: 'simple' },
	})
	const body = asRecord(response.data)
	const id = Number(asRecord(body.data).ID ?? body.ID)
	expect(Number.isInteger(id) && id > 0, 'fixture product was not created').toBe(true)

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

beforeAll(async () => {
	client = getLiveClient()
	tools = createAllTools(client, {})

	const response = await client.get('/products/fetch-term', { taxonomy: 'product-categories' })
	const taxonomies = asRecord(asRecord(response.data).taxonomies)
	const terms = (asRecord(taxonomies['product-categories']).terms ?? []) as Record<
		string,
		unknown
	>[]
	termIds = terms
		.map((term) => Number(term.value ?? term.term_id ?? term.id))
		.filter((id) => Number.isInteger(id) && id > 0)
		.slice(0, 2)
}, 60_000)

afterAll(async () => {
	await ledger.cleanup()
})

describe('inventory quantities', () => {
	it('keeps the figure the caller did not send', async () => {
		// The whole reason this tool was unsafe: `{total_stock: 9}` used to send available 0.
		const productId = await createOwnedProduct('stock-merge')
		const before = await snapshot(productId)
		const variantId = before.variants[0]?.id as number

		await call('fluentcart_product_inventory_update', {
			product_id: productId,
			variant_id: variantId,
			available: 5,
		})
		const seeded = await snapshot(productId)
		expect(Number(seeded.variants[0]?.available)).toBe(5)

		// Now set only total_stock. `available` must survive untouched.
		const updated = await call('fluentcart_product_inventory_update', {
			product_id: productId,
			variant_id: variantId,
			total_stock: 9,
		})
		expect(updated.isError, updated.text).toBe(false)

		const after = await snapshot(productId)
		expect(Number(after.variants[0]?.total_stock)).toBe(9)
		expect(
			Number(after.variants[0]?.available),
			'available was reset by an update that never mentioned it',
		).toBe(5)
		expect(after.variants[0]?.stock_status).toBe('in-stock')
	})

	it('restores exactly what the read-back reported', async () => {
		const productId = await createOwnedProduct('stock-restore')
		const before = await snapshot(productId)
		const variantId = before.variants[0]?.id as number

		await call('fluentcart_product_inventory_update', {
			product_id: productId,
			variant_id: variantId,
			total_stock: 7,
			available: 7,
		})
		expect(await snapshot(productId)).not.toEqual(before)

		await call('fluentcart_product_inventory_update', {
			product_id: productId,
			variant_id: variantId,
			total_stock: Number(before.variants[0]?.total_stock),
			available: Number(before.variants[0]?.available),
		})
		await call('fluentcart_product_manage_stock_update', {
			product_id: productId,
			manage_stock: String(before.variants[0]?.manage_stock) === '1' ? '1' : '0',
		})

		expect(await snapshot(productId)).toEqual(before)
	})

	it('reports a variant that does not belong to the product instead of writing anyway', async () => {
		const productId = await createOwnedProduct('stock-guard')
		const result = await call('fluentcart_product_inventory_update', {
			product_id: productId,
			variant_id: 999_999_999,
			available: 1,
		})
		expect(result.isError).toBe(true)
		expect(result.text).toMatch(/not found/i)
	})
})

describe('stock tracking toggle', () => {
	it('turns tracking on and off for the product, and back', async () => {
		const productId = await createOwnedProduct('manage-stock')
		const before = await snapshot(productId)

		const enabled = await call('fluentcart_product_manage_stock_update', {
			product_id: productId,
			manage_stock: '1',
		})
		expect(enabled.isError, enabled.text).toBe(false)
		expect(String((await snapshot(productId)).variants[0]?.manage_stock)).toBe('1')

		await call('fluentcart_product_manage_stock_update', {
			product_id: productId,
			manage_stock: String(before.variants[0]?.manage_stock) === '1' ? '1' : '0',
		})
		expect(await snapshot(productId)).toEqual(before)
	})
})

describe('categories and brands', () => {
	it('assigns a set of terms and puts the previous set back', async () => {
		if (termIds.length < 2) return // Store has no category vocabulary to assign.
		const productId = await createOwnedProduct('taxonomy')
		const before = await snapshot(productId)

		const assigned = await call('fluentcart_product_taxonomy_sync', {
			product_id: productId,
			taxonomy: 'product-categories',
			terms: termIds,
		})
		expect(assigned.isError, assigned.text).toBe(false)
		expect((await snapshot(productId)).categories).toEqual(
			termIds.map(String).sort((a, b) => a.localeCompare(b)),
		)

		await call('fluentcart_product_taxonomy_sync', {
			product_id: productId,
			taxonomy: 'product-categories',
			terms: before.categories.map(Number),
		})
		expect((await snapshot(productId)).categories).toEqual(before.categories)
	})

	it('removes one term without disturbing the others, and can restore it', async () => {
		if (termIds.length < 2) return
		const productId = await createOwnedProduct('taxonomy-one')

		await call('fluentcart_product_taxonomy_sync', {
			product_id: productId,
			taxonomy: 'product-categories',
			terms: termIds,
		})

		const removed = await call('fluentcart_product_taxonomy_delete', {
			product_id: productId,
			taxonomy: 'product-categories',
			term: termIds[0] as number,
		})
		expect(removed.isError, removed.text).toBe(false)

		const afterDelete = await snapshot(productId)
		expect(afterDelete.categories).toEqual([String(termIds[1])])

		await call('fluentcart_product_taxonomy_sync', {
			product_id: productId,
			taxonomy: 'product-categories',
			terms: termIds,
		})
		expect((await snapshot(productId)).categories).toEqual(
			termIds.map(String).sort((a, b) => a.localeCompare(b)),
		)
	})

	it('leaves the term itself in the store after unassigning it', async () => {
		// The distinction that makes these reversible: the assignment changes, the vocabulary does
		// not. If unassigning destroyed the term, restoring it would be impossible.
		if (termIds.length < 1) return
		const productId = await createOwnedProduct('taxonomy-vocab')

		await call('fluentcart_product_taxonomy_sync', {
			product_id: productId,
			taxonomy: 'product-categories',
			terms: [termIds[0] as number],
		})
		await call('fluentcart_product_taxonomy_delete', {
			product_id: productId,
			taxonomy: 'product-categories',
			term: termIds[0] as number,
		})

		const response = await client.get('/products/fetch-term', { taxonomy: 'product-categories' })
		const taxonomies = asRecord(asRecord(response.data).taxonomies)
		const terms = (asRecord(taxonomies['product-categories']).terms ?? []) as Record<
			string,
			unknown
		>[]
		const stillThere = terms.some((term) => Number(term.value ?? term.term_id) === termIds[0])

		expect(stillThere, 'unassigning a term must not delete it from the store').toBe(true)
	})
})
