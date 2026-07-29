import { describe, expect, it, vi } from 'vitest'
import type { FluentCartClient } from '../../src/api/client.js'
import { FluentCartApiError } from '../../src/api/errors.js'
import { REDACTED } from '../../src/security/redaction.js'
import { couponWriteTools } from '../../src/tools/coupons-writes.js'

function stubClient() {
	const get = vi.fn()
	const post = vi.fn().mockResolvedValue({ data: { ok: true }, status: 200 })
	const put = vi.fn().mockResolvedValue({ data: { ok: true }, status: 200 })
	const del = vi.fn().mockResolvedValue({ data: { ok: true }, status: 200 })
	const request = vi.fn().mockResolvedValue({ data: { ok: true }, status: 200 })
	return {
		client: { get, post, put, delete: del, request } as unknown as FluentCartClient,
		get,
		post,
		put,
		del,
	}
}

function toolNamed(client: FluentCartClient, name: string) {
	const tool = couponWriteTools(client).find((candidate) => candidate.name === name)
	if (!tool) throw new Error(`${name} is not registered`)
	return tool
}

function output(result: Awaited<ReturnType<ReturnType<typeof toolNamed>['handler']>>) {
	return JSON.parse(result.content[0]?.text ?? '{}') as Record<string, unknown>
}

describe('coupon reversible writes', () => {
	it('creates through the declared route, preserves the payload, and redacts the response', async () => {
		const stub = stubClient()
		stub.post.mockResolvedValue({
			data: { coupon: { id: 9, code: 'SAFE', api_token: 'must-not-leak' } },
			status: 200,
		})
		const input = {
			title: 'Summer',
			code: 'SAFE',
			type: 'percentage',
			amount: 15,
			status: 'active',
			stackable: 'no',
			show_on_checkout: 'yes',
			conditions: { max_uses: 20 },
		}

		const result = await toolNamed(stub.client, 'fluentcart_coupon_create').handler(input)

		expect(stub.post).toHaveBeenCalledWith('/coupons', input, undefined)
		expect(output(result)).toMatchObject({
			coupon: { id: 9, code: 'SAFE', api_token: REDACTED },
		})
	})

	it('fetches, merges, and replaces a coupon without sending either id field', async () => {
		const stub = stubClient()
		stub.get.mockResolvedValue({
			data: {
				coupon: {
					id: 7,
					title: 'Old',
					code: 'SAVE',
					type: 'fixed',
					amount: 500,
					status: 'active',
					stackable: 'no',
				},
			},
			status: 200,
		})

		await toolNamed(stub.client, 'fluentcart_coupon_update').handler({
			coupon_id: 7,
			title: 'New',
			amount: 750,
		})

		expect(stub.get).toHaveBeenCalledWith('/coupons/7')
		expect(stub.put).toHaveBeenCalledWith('/coupons/7', {
			title: 'New',
			code: 'SAVE',
			type: 'fixed',
			amount: 750,
			status: 'active',
			stackable: 'no',
		})
	})

	it('also accepts the unwrapped coupon shape returned by older stores', async () => {
		const stub = stubClient()
		stub.get.mockResolvedValue({
			data: { id: 7, title: 'Old', code: 'SAVE', status: 'active' },
			status: 200,
		})

		await toolNamed(stub.client, 'fluentcart_coupon_update').handler({
			coupon_id: 7,
			title: 'New',
		})

		expect(stub.put).toHaveBeenCalledWith('/coupons/7', {
			title: 'New',
			code: 'SAVE',
			status: 'active',
		})
	})

	it('returns an explicit, redacted MCP error when the store rejects a write', async () => {
		const stub = stubClient()
		stub.post.mockRejectedValue(
			new FluentCartApiError('VALIDATION_ERROR', 'Bad Bearer abcdefgh123', 422, {
				api_token: 'must-not-leak',
			}),
		)

		const result = await toolNamed(stub.client, 'fluentcart_coupon_create').handler({
			title: 'Broken',
			code: 'BROKEN',
			type: 'fixed',
			amount: 1,
			status: 'active',
			stackable: 'no',
			show_on_checkout: 'no',
		})

		expect(result.isError).toBe(true)
		expect(result.content[0]?.text).toContain(`Bearer ${REDACTED}`)
		expect(result.content[0]?.text).toContain(`"api_token":"${REDACTED}"`)
		expect(result.content[0]?.text).not.toContain('must-not-leak')
	})
})

describe('coupon mutation payload contracts', () => {
	it('sends the coupon id explicitly when deleting', async () => {
		const stub = stubClient()
		await toolNamed(stub.client, 'fluentcart_coupon_delete').handler({ coupon_id: 4 })
		expect(stub.del).toHaveBeenCalledWith('/coupons/4', { id: 4 })
	})

	it('derives order item identifiers for apply and cancel', async () => {
		const stub = stubClient()
		stub.get
			.mockResolvedValueOnce({
				data: {
					order: {
						items: [
							{ post_id: 10, object_id: 11, quantity: 2 },
							{ product_id: 20, variant_id: 21 },
						],
					},
				},
				status: 200,
			})
			.mockResolvedValueOnce({
				data: {
					order_items: [{ product_id: 30 }],
				},
				status: 200,
			})

		await toolNamed(stub.client, 'fluentcart_coupon_apply').handler({
			code: 'SAVE',
			order_id: 3,
		})
		await toolNamed(stub.client, 'fluentcart_coupon_cancel').handler({
			code: 'SAVE',
			order_id: 3,
		})

		expect(stub.post).toHaveBeenNthCalledWith(1, '/coupons/apply', {
			coupon_code: 'SAVE',
			order_id: 3,
			order_items: [
				{ post_id: 10, object_id: 11, quantity: 2 },
				{ post_id: 20, object_id: 21, quantity: 1 },
			],
		})
		expect(stub.post).toHaveBeenNthCalledWith(2, '/coupons/cancel', {
			coupon_code: 'SAVE',
			order_id: 3,
			order_items: [{ post_id: 30, object_id: 30, quantity: 1 }],
		})
	})

	it('forwards the three endpoint-factory coupon operations unchanged', async () => {
		const stub = stubClient()

		await toolNamed(stub.client, 'fluentcart_coupon_reapply').handler({
			code: 'SAVE',
			order_id: 3,
		})
		await toolNamed(stub.client, 'fluentcart_coupon_check_eligibility').handler({
			coupon_id: 4,
			product_id: 8,
		})
		await toolNamed(stub.client, 'fluentcart_coupon_settings_save').handler({
			show_on_checkout: true,
		})

		expect(stub.post.mock.calls).toEqual([
			['/coupons/re-apply', { code: 'SAVE', order_id: 3 }, undefined],
			['/coupons/checkProductEligibility', { coupon_id: 4, product_id: 8 }, undefined],
			['/coupons/storeCouponSettings', { show_on_checkout: true }, undefined],
		])
	})
})
