import { describe, expect, it, vi } from 'vitest'
import type { FluentCartClient } from '../../src/api/client.js'
import { REDACTED } from '../../src/security/redaction.js'
import { productVariantWriteTools } from '../../src/tools/products-variant-writes.js'

function stubClient() {
	const get = vi.fn()
	const post = vi.fn().mockResolvedValue({ data: { ok: true }, status: 200 })
	const put = vi.fn().mockResolvedValue({ data: { ok: true }, status: 200 })
	const del = vi.fn().mockResolvedValue({ data: { ok: true }, status: 200 })
	return {
		client: { get, post, put, delete: del } as unknown as FluentCartClient,
		get,
		post,
		put,
		del,
	}
}

function toolNamed(client: FluentCartClient, name: string) {
	const tool = productVariantWriteTools(client).find((candidate) => candidate.name === name)
	if (!tool) throw new Error(`${name} is not registered`)
	return tool
}

describe('variant reversible writes', () => {
	it('creates a complete subscription variant and trims its SKU', async () => {
		const stub = stubClient()
		stub.post.mockResolvedValue({
			data: { variant: { id: 7, private_key: 'secret' } },
			status: 200,
		})

		const result = await toolNamed(stub.client, 'fluentcart_variant_create').handler({
			product_id: 2,
			title: 'Monthly',
			price: 40,
			compare_price: 50,
			sku: '  PLAN-M  ',
			stock_quantity: 9,
			fulfillment_type: 'digital',
			item_status: 'inactive',
			payment_type: 'subscription',
			times: 12,
			repeat_interval: 'monthly',
			trial_days: 7,
			billing_summary: 'Monthly for one year',
			manage_setup_fee: 'yes',
			signup_fee_name: 'Setup',
			signup_fee: 5,
			setup_fee_per_item: 'yes',
		})

		expect(stub.post).toHaveBeenCalledWith('/products/variants', {
			product_id: 2,
			variants: {
				post_id: 2,
				variation_title: 'Monthly',
				item_price: 40,
				compare_price: 50,
				sku: 'PLAN-M',
				fulfillment_type: 'digital',
				total_stock: 9,
				available: 9,
				committed: 0,
				on_hold: 0,
				stock_status: 'in-stock',
				item_status: 'inactive',
				other_info: {
					payment_type: 'subscription',
					times: '12',
					repeat_interval: 'monthly',
					trial_days: '7',
					billing_summary: 'Monthly for one year',
					manage_setup_fee: 'yes',
					signup_fee_name: 'Setup',
					signup_fee: 5,
					setup_fee_per_item: 'yes',
				},
			},
		})
		expect(result.content[0]?.text).toContain(`"private_key":"${REDACTED}"`)
	})

	it('uses safe defaults for a minimal one-time variant', async () => {
		const stub = stubClient()

		await toolNamed(stub.client, 'fluentcart_variant_create').handler({
			product_id: 2,
			title: 'Default',
			sku: '   ',
		})

		const body = stub.post.mock.calls[0]?.[1] as {
			variants: Record<string, unknown>
		}
		expect(body.variants).toMatchObject({
			item_price: 0,
			compare_price: 0,
			fulfillment_type: 'physical',
			total_stock: 0,
			available: 0,
			item_status: 'active',
			other_info: { payment_type: 'onetime' },
		})
		expect(body.variants).not.toHaveProperty('sku')
	})

	it('fetches current pricing and changes only fields the caller supplied', async () => {
		const stub = stubClient()
		stub.get.mockResolvedValue({
			data: {
				product: {
					variants: [
						{
							id: 7,
							variation_title: 'Old',
							item_price: 4000,
							compare_price: 5000,
							sku: 'OLD',
							total_stock: 3,
							available: 2,
							item_status: 'active',
							other_info: { payment_type: 'onetime' },
						},
					],
				},
			},
			status: 200,
		})

		await toolNamed(stub.client, 'fluentcart_variant_update').handler({
			product_id: 2,
			variant_id: 7,
			title: 'New',
			price: 45,
			compare_price: 55,
			sku: '  NEW  ',
			stock_quantity: 8,
			item_status: 'inactive',
			payment_type: 'subscription',
			repeat_interval: 'yearly',
		})

		expect(stub.get).toHaveBeenCalledWith('/products/2/pricing')
		expect(stub.post).toHaveBeenCalledWith('/products/variants/7', {
			variants: expect.objectContaining({
				id: 7,
				post_id: 2,
				variation_title: 'New',
				item_price: 45,
				compare_price: 55,
				sku: 'NEW',
				total_stock: 8,
				available: 8,
				item_status: 'inactive',
				other_info: expect.objectContaining({
					payment_type: 'subscription',
					repeat_interval: 'yearly',
				}),
			}),
		})
	})

	it('surfaces a failed pricing read and does not write', async () => {
		const stub = stubClient()
		stub.get.mockRejectedValue(new Error('pricing unavailable'))

		const result = await toolNamed(stub.client, 'fluentcart_variant_update').handler({
			product_id: 2,
			variant_id: 7,
			price: 45,
		})

		expect(result.isError).toBe(true)
		expect(result.content[0]?.text).toContain('pricing unavailable')
		expect(stub.post).not.toHaveBeenCalled()
	})
})

describe('remaining variant mutation routes', () => {
	it('forwards delete, media, and pricing-table fields exactly once', async () => {
		const stub = stubClient()

		await toolNamed(stub.client, 'fluentcart_variant_delete').handler({ variant_id: 7 })
		await toolNamed(stub.client, 'fluentcart_variant_set_media').handler({
			variant_id: 7,
			media_id: 12,
		})
		await toolNamed(stub.client, 'fluentcart_variant_pricing_table_update').handler({
			variant_id: 7,
			description: 'Best value',
		})

		expect(stub.del).toHaveBeenCalledWith('/products/variants/7', {})
		expect(stub.post).toHaveBeenCalledWith(
			'/products/variants/7/setMedia',
			{ media_id: 12 },
			undefined,
		)
		expect(stub.put).toHaveBeenCalledWith('/products/variants/7/pricing-table', {
			description: 'Best value',
		})
	})
})
