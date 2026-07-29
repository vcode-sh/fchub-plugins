import { describe, expect, it, vi } from 'vitest'
import type { FluentCartClient } from '../../src/api/client.js'
import { REDACTED } from '../../src/security/redaction.js'
import { customerWriteTools } from '../../src/tools/customers-writes.js'

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
		request,
	}
}

function toolNamed(client: FluentCartClient, name: string) {
	const tool = customerWriteTools(client).find((candidate) => candidate.name === name)
	if (!tool) throw new Error(`${name} is not registered`)
	return tool
}

describe('customer reversible writes', () => {
	it('creates through the declared route and redacts secrets in a successful response', async () => {
		const stub = stubClient()
		stub.post.mockResolvedValue({
			data: { customer: { id: 2, email: 'buyer@example.com', auth_token: 'secret' } },
			status: 200,
		})
		const input = { email: 'buyer@example.com', first_name: 'Ada' }

		const result = await toolNamed(stub.client, 'fluentcart_customer_create').handler(input)
		const body = JSON.parse(result.content[0]?.text ?? '{}')

		expect(stub.post).toHaveBeenCalledWith('/customers', input, undefined)
		expect(body.customer.auth_token).toBe(REDACTED)
	})

	it('fetches and merges required fields before updating', async () => {
		const stub = stubClient()
		stub.get.mockResolvedValue({
			data: {
				customer: {
					id: 6,
					first_name: 'Ada',
					last_name: 'Lovelace',
					full_name: 'Ada Lovelace',
					email: 'old@example.com',
					status: 'active',
					ignored: 'not-part-of-the-write-contract',
				},
			},
			status: 200,
		})

		await toolNamed(stub.client, 'fluentcart_customer_update').handler({
			customer_id: 6,
			email: 'new@example.com',
			notes: 'Verified',
		})

		expect(stub.get).toHaveBeenCalledWith('/customers/6')
		expect(stub.put).toHaveBeenCalledWith('/customers/6', {
			id: 6,
			first_name: 'Ada',
			last_name: 'Lovelace',
			full_name: 'Ada Lovelace',
			email: 'new@example.com',
			status: 'active',
			notes: 'Verified',
		})
	})

	it('also accepts an unwrapped customer response', async () => {
		const stub = stubClient()
		stub.get.mockResolvedValue({
			data: {
				first_name: 'Ada',
				last_name: 'Lovelace',
				full_name: 'Ada Lovelace',
				email: 'ada@example.com',
				status: 'active',
			},
			status: 200,
		})

		await toolNamed(stub.client, 'fluentcart_customer_update').handler({
			customer_id: 6,
			status: 'inactive',
		})

		expect(stub.put).toHaveBeenCalledWith(
			'/customers/6',
			expect.objectContaining({
				id: 6,
				email: 'ada@example.com',
				status: 'inactive',
			}),
		)
	})

	it('propagates an upstream failure as an MCP error', async () => {
		const stub = stubClient()
		stub.get.mockRejectedValue(new Error('store unavailable'))

		const result = await toolNamed(stub.client, 'fluentcart_customer_update').handler({
			customer_id: 6,
			first_name: 'Grace',
		})

		expect(result.isError).toBe(true)
		expect(result.content[0]?.text).toContain('store unavailable')
		expect(stub.put).not.toHaveBeenCalled()
	})
})

describe('customer mutation payload contracts', () => {
	it('maps labels and address writes to the shapes FluentCart reads', async () => {
		const stub = stubClient()

		await toolNamed(stub.client, 'fluentcart_customer_update_additional_info').handler({
			customer_id: 2,
			labels: [4, 5],
		})
		await toolNamed(stub.client, 'fluentcart_customer_address_update').handler({
			customer_id: 2,
			address_id: 8,
			name: 'Ada',
			email: 'ada@example.com',
			country: 'GB',
		})
		await toolNamed(stub.client, 'fluentcart_customer_address_delete').handler({
			customer_id: 2,
			address_id: 8,
		})
		await toolNamed(stub.client, 'fluentcart_customer_address_make_primary').handler({
			customer_id: 2,
			address_id: 8,
			type: 'billing',
		})

		expect(stub.put).toHaveBeenNthCalledWith(1, '/customers/2/additional-info', {
			labels: [4, 5],
		})
		expect(stub.put).toHaveBeenNthCalledWith(2, '/customers/2/address', {
			id: 8,
			name: 'Ada',
			email: 'ada@example.com',
			country: 'GB',
		})
		expect(stub.request).toHaveBeenCalledWith('DELETE', '/customers/2/address', {
			body: { address: { id: 8 } },
		})
		expect(stub.post).toHaveBeenCalledWith('/customers/2/address/make-primary', {
			addressId: 8,
			type: 'billing',
		})
	})

	it('forwards every endpoint-factory customer mutation without renaming fields', async () => {
		const stub = stubClient()

		await toolNamed(stub.client, 'fluentcart_customer_recalculate_ltv').handler({ customer_id: 2 })
		await toolNamed(stub.client, 'fluentcart_customer_address_create').handler({
			customer_id: 2,
			name: 'Ada',
			email: 'ada@example.com',
			label: 'Home',
		})
		await toolNamed(stub.client, 'fluentcart_customer_attach_user').handler({
			customer_id: 2,
			user_id: 9,
		})
		await toolNamed(stub.client, 'fluentcart_customer_detach_user').handler({ customer_id: 2 })
		await toolNamed(stub.client, 'fluentcart_customer_bulk_action').handler({
			action: 'delete_customers',
			customer_ids: [2, 3],
		})

		expect(stub.post.mock.calls).toEqual([
			['/customers/2/recalculate-ltv', {}, undefined],
			['/customers/2/address', { name: 'Ada', email: 'ada@example.com', label: 'Home' }, undefined],
			['/customers/2/attachable-user', { user_id: 9 }, undefined],
			['/customers/2/detach-user', {}, undefined],
			[
				'/customers/do-bulk-action',
				{ action: 'delete_customers', customer_ids: [2, 3] },
				undefined,
			],
		])
	})
})
