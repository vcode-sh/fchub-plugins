// Payload guards for the stock and taxonomy writes.
//
// The live lane in tests/integration/product-stock-taxonomy.test.ts proves the round trips against
// a real store; these run in CI without one and fail fast on the exact shape that mattered.
//
// The defect being pinned: FluentCart's updateInventory reads `intval($request->get('available'))`,
// so a payload that omits the key sends 0 and stamps the variant out-of-stock. The old schema
// exposed only `total_stock`, so zeroing the stock was the only thing the tool could do — verified
// live, `{total_stock: 9}` produced `available 0, stock_status out-of-stock`.
import { describe, expect, it, vi } from 'vitest'
import type { FluentCartClient } from '../../src/api/client.js'
import { createAllTools } from '../../src/tools/index.js'

const VARIANT = {
	id: 5,
	manage_stock: '1',
	total_stock: 12,
	available: 4,
	stock_status: 'in-stock',
}

function stubClient() {
	const get = vi.fn().mockResolvedValue({ data: { product: { variants: [VARIANT] } }, status: 200 })
	const put = vi.fn().mockResolvedValue({ data: {}, status: 200 })
	const post = vi.fn().mockResolvedValue({ data: {}, status: 200 })
	return { client: { get, put, post } as unknown as FluentCartClient, get, put, post }
}

function toolNamed(client: FluentCartClient, name: string) {
	const tool = createAllTools(client, {}).find((candidate) => candidate.name === name)
	if (!tool) throw new Error(`${name} is not registered`)
	return tool
}

async function updateInventory(input: Record<string, unknown>) {
	const stub = stubClient()
	const result = (await toolNamed(stub.client, 'fluentcart_product_inventory_update').handler(
		input as never,
		{} as never,
	)) as { isError?: boolean; content: { text: string }[] }
	return { ...stub, result }
}

describe('inventory update merges instead of replacing', () => {
	it('keeps available when only total_stock is given', async () => {
		const { put } = await updateInventory({ product_id: 1, variant_id: 5, total_stock: 9 })
		expect(put.mock.calls[0]?.[1]).toEqual({ total_stock: 9, available: 4 })
	})

	it('keeps total_stock when only available is given', async () => {
		const { put } = await updateInventory({ product_id: 1, variant_id: 5, available: 2 })
		expect(put.mock.calls[0]?.[1]).toEqual({ total_stock: 12, available: 2 })
	})

	it('sends both when both are given', async () => {
		const { put } = await updateInventory({
			product_id: 1,
			variant_id: 5,
			total_stock: 20,
			available: 20,
		})
		expect(put.mock.calls[0]?.[1]).toEqual({ total_stock: 20, available: 20 })
	})

	it('accepts a zero the caller actually asked for', async () => {
		// Zeroing must remain possible — it is only the SILENT zero that was the bug.
		const { put } = await updateInventory({ product_id: 1, variant_id: 5, available: 0 })
		expect(put.mock.calls[0]?.[1]).toMatchObject({ available: 0 })
	})

	it('reads the variant before writing', async () => {
		const { get } = await updateInventory({ product_id: 1, variant_id: 5, total_stock: 9 })
		expect(get.mock.calls[0]?.[0]).toBe('/products/1/pricing')
	})

	it('refuses a variant that does not belong to the product', async () => {
		const { result, put } = await updateInventory({ product_id: 1, variant_id: 404, available: 1 })
		expect(result.isError).toBe(true)
		expect(put).not.toHaveBeenCalled()
	})
})

describe('stock and taxonomy tools are classified on proven evidence', () => {
	const tools = createAllTools(stubClient().client, {})
	const safetyOf = (name: string) => tools.find((tool) => tool.name === name)?.safety

	it('treats the four proven round trips as reversible writes', () => {
		for (const name of [
			'fluentcart_product_inventory_update',
			'fluentcart_product_manage_stock_update',
			'fluentcart_product_taxonomy_sync',
			'fluentcart_product_taxonomy_delete',
		]) {
			expect(safetyOf(name)?.risk, `${name} should be reversible`).toBe('reversible-write')
			expect(safetyOf(name)?.execution).toBe('rest')
		}
	})

	it('keeps term creation irreversible, because no route deletes a term', () => {
		// FluentCart 1.5.5 registers no delete for product-categories / product-brands terms; the
		// only term DELETE belongs to the attribute library. A created category cannot be removed.
		expect(safetyOf('fluentcart_product_terms_add')?.risk).not.toBe('reversible-write')
	})

	it('offers only the two real taxonomies, so a typo cannot silently no-op', () => {
		const sync = tools.find((tool) => tool.name === 'fluentcart_product_taxonomy_sync')
		const parsed = sync?.schema.safeParse({ product_id: 1, terms: [], taxonomy: 'nonsense' })
		expect(parsed?.success).toBe(false)
	})

	it('says plainly that syncing replaces the whole set', () => {
		// A caller that reads this as "add these" would silently unassign everything else.
		const sync = tools.find((tool) => tool.name === 'fluentcart_product_taxonomy_sync')
		expect(sync?.description).toMatch(/REPLACES/)
		expect(sync?.description).toMatch(/empty list removes every term/i)
	})
})
