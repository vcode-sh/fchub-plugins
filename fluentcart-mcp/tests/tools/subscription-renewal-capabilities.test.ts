import { describe, expect, it, vi } from 'vitest'
import type { FluentCartClient } from '../../src/api/client.js'
import { canExposeTool } from '../../src/security/write-policy.js'
import { createAllTools } from '../../src/tools/index.js'
import { reviewedRisk } from '../../src/tools/risk-registry.js'
import { settingsCoreTools } from '../../src/tools/settings-core.js'

function stubClient() {
	const get = vi.fn().mockResolvedValue({ data: {}, status: 200 })
	const put = vi.fn().mockResolvedValue({ data: {}, status: 200 })
	const post = vi.fn().mockResolvedValue({ data: {}, status: 200 })
	return { client: { get, put, post } as unknown as FluentCartClient, get, put, post }
}

function toolNamed(client: FluentCartClient, name: string) {
	const tool = createAllTools(client, {}).find((candidate) => candidate.name === name)
	if (!tool) throw new Error(`${name} is not registered`)
	return tool
}

describe('FluentCart 1.6 renewal reads', () => {
	it('registers bounded renewal list and detail reads', () => {
		const { client } = stubClient()
		const list = toolNamed(client, 'fluentcart_renewal_list')
		const detail = toolNamed(client, 'fluentcart_renewal_get')

		expect(list.safety.risk).toBe('read')
		expect(detail.safety.risk).toBe('read')
		expect(list.schema.safeParse({ per_page: 51 }).success).toBe(false)
		expect(
			list.schema.safeParse({ page: 1, per_page: 50, payment_status: 'pending' }).success,
		).toBe(true)
		expect(detail.schema.safeParse({ renewal_id: 17 }).success).toBe(true)
	})

	it('uses the exact FluentCart 1.6 routes', async () => {
		const { client, get } = stubClient()
		await toolNamed(client, 'fluentcart_renewal_list').handler({
			page: 2,
			per_page: 25,
			parent_order_id: 7,
			customer_id: 4,
			payment_status: 'pending',
		})
		await toolNamed(client, 'fluentcart_renewal_get').handler({ renewal_id: 17 })

		expect(get).toHaveBeenNthCalledWith(
			1,
			'/renewals',
			{
				page: 2,
				per_page: 25,
				parent_id: 7,
				customer_id: 4,
				payment_status: 'pending',
			},
			undefined,
		)
		expect(get).toHaveBeenNthCalledWith(2, '/renewals/17', {}, undefined)
	})

	it('projects renewal lists without gateway and internal order data', async () => {
		const { client, get } = stubClient()
		get.mockResolvedValue({
			data: {
				data: {
					invoices: {
						current_page: 1,
						data: [
							{
								id: 14,
								receipt_number: 'R-14',
								status: 'processing',
								payment_status: 'pending',
								payment_method_title: 'Invoice',
								currency: 'EUR',
								total_amount: 1599,
								parent_id: 7,
								created_at: '2026-07-30 10:00:00',
								customer: {
									id: 4,
									full_name: 'Ada Lovelace',
									email: 'ada@example.test',
									ip_address: '127.0.0.1',
								},
								config: { payment_session_id: 'secret' },
								uuid: 'internal',
								meta: [{ meta_value: 'gateway-state' }],
							},
						],
					},
				},
			},
			status: 200,
		})

		const result = await toolNamed(client, 'fluentcart_renewal_list').handler({})
		const body = JSON.parse(result.content[0]?.text ?? '{}')
		const row = body.data.invoices.data[0]

		expect(row).toEqual({
			id: 14,
			receipt_number: 'R-14',
			status: 'processing',
			payment_status: 'pending',
			payment_method_title: 'Invoice',
			currency: 'EUR',
			total_amount: 1599,
			parent_order_id: 7,
			customer: { id: 4, full_name: 'Ada Lovelace', email: 'ada@example.test' },
			created_at: '2026-07-30 10:00:00',
		})
	})

	it.each(['direct', 'wrapped'] as const)(
		'projects renewal detail for the %s response envelope',
		async (envelope) => {
			const { client, get } = stubClient()
			const rawInvoice = {
				id: 17,
				customer: { id: 4, full_name: 'Ada Lovelace', email: 'ada@example.test' },
				order_items: [
					{
						id: 2,
						title: 'Monthly plan',
						quantity: 1,
						line_total: 1599,
						line_meta: { secret: true },
					},
				],
				transactions: [
					{ id: 9, status: 'pending', total: 1599, uuid: 'internal', meta: { gateway: 'secret' } },
				],
				config: { payment_session_id: 'secret' },
				ip_address: '127.0.0.1',
				uuid: 'internal',
				meta: [{ meta_value: 'gateway-state' }],
				vendor_response: { private: true },
			}
			get.mockResolvedValue({
				data: envelope === 'direct' ? { invoice: rawInvoice } : { data: { invoice: rawInvoice } },
				status: 200,
			})

			const result = await toolNamed(client, 'fluentcart_renewal_get').handler({ renewal_id: 17 })
			const body = JSON.parse(result.content[0]?.text ?? '{}')
			const invoice = body.data?.invoice ?? body.invoice

			expect(invoice.order_items).toEqual([
				{ id: 2, title: 'Monthly plan', quantity: 1, line_total: 1599 },
			])
			expect(invoice.transactions).toEqual([{ id: 9, status: 'pending', total: 1599 }])
			for (const key of ['config', 'ip_address', 'uuid', 'meta', 'vendor_response']) {
				expect(invoice[key]).toBeUndefined()
			}
		},
	)
})

describe('FluentCart 1.6 subscription mutation', () => {
	it('keeps gateway fetch out of every write mode because it can sync state and contact a gateway', () => {
		const { client } = stubClient()
		const fetch = toolNamed(client, 'fluentcart_subscription_fetch')

		expect(fetch.safety).toEqual({
			risk: 'external-side-effect',
			idempotency: 'unsupported',
			execution: 'none',
		})
		expect(canExposeTool(fetch.safety, { writeMode: 'disabled' })).toBe(false)
		expect(canExposeTool(fetch.safety, { writeMode: 'reversible' })).toBe(false)
	})

	it('exposes only the narrow, reversible bill-times update', () => {
		const { client } = stubClient()
		const tools = createAllTools(client, {})
		const update = toolNamed(client, 'fluentcart_subscription_update')

		expect(update.safety).toEqual({
			risk: 'reversible-write',
			idempotency: 'inherent',
			execution: 'rest',
		})
		for (const unavailable of [
			'fluentcart_subscription_pause',
			'fluentcart_subscription_resume',
			'fluentcart_subscription_reactivate',
			'fluentcart_subscription_charge_now',
			'fluentcart_subscription_create_renewal',
			'fluentcart_subscription_skip_renewal',
		]) {
			expect(tools.some((tool) => tool.name === unavailable)).toBe(false)
		}
	})

	it('guards, writes, then reads back the bill-times update before reporting success', async () => {
		const { client, get, put } = stubClient()
		get
			.mockResolvedValueOnce({
				data: {
					subscription: {
						status: 'active',
						collection_method: 'manual',
						bill_times: 3,
						bill_count: 1,
					},
				},
				status: 200,
			})
			.mockResolvedValueOnce({
				data: {
					subscription: {
						status: 'active',
						collection_method: 'manual',
						bill_times: 0,
						bill_count: 1,
					},
				},
				status: 200,
			})
		const result = await toolNamed(client, 'fluentcart_subscription_update').handler({
			order_id: 7,
			subscription_id: 11,
			expected_bill_times: 3,
			expected_bill_count: 1,
			bill_times: 0,
		})

		expect(get).toHaveBeenNthCalledWith(1, '/subscriptions/11', {})
		expect(put).toHaveBeenCalledWith('/orders/7/subscriptions/11/update', {
			data: {
				bill_times: 0,
				status: 'active',
			},
		})
		expect(get).toHaveBeenNthCalledWith(2, '/subscriptions/11', {})
		expect(JSON.parse(result.content[0]?.text ?? '{}')).toEqual({
			message: 'Subscription bill times updated and verified.',
			order_id: 7,
			previous_bill_times: 3,
			subscription: {
				id: 11,
				status: 'active',
				collection_method: 'manual',
				bill_times: 0,
				bill_count: 1,
			},
		})
	})

	it('reports an ambiguous post-write read failure without inviting a blind retry', async () => {
		const { client, get, put } = stubClient()
		get
			.mockResolvedValueOnce({
				data: {
					subscription: {
						status: 'active',
						collection_method: 'system',
						bill_times: 5,
						bill_count: 2,
					},
				},
				status: 200,
			})
			.mockRejectedValueOnce(new Error('readback unavailable'))

		const result = await toolNamed(client, 'fluentcart_subscription_update').handler({
			order_id: 7,
			subscription_id: 11,
			expected_bill_times: 5,
			expected_bill_count: 2,
			bill_times: 6,
		})

		expect(put).toHaveBeenCalledOnce()
		expect(result.isError).toBe(true)
		expect(result.content[0]?.text).toContain('"mutation_may_have_applied":true')
		expect(result.content[0]?.text).toContain('"previous_bill_times":5')
		expect(result.content[0]?.text).toContain('"requested_bill_times":6')
		expect(result.content[0]?.text).toContain('Do not retry blindly')
	})

	it('reports an ambiguous PUT failure because the server may have written before the client failed', async () => {
		const { client, get, put } = stubClient()
		get.mockResolvedValueOnce({
			data: {
				subscription: {
					status: 'active',
					collection_method: 'manual',
					bill_times: 5,
					bill_count: 2,
				},
			},
			status: 200,
		})
		put.mockRejectedValueOnce(new Error('connection closed'))

		const result = await toolNamed(client, 'fluentcart_subscription_update').handler({
			order_id: 7,
			subscription_id: 11,
			expected_bill_times: 5,
			expected_bill_count: 2,
			bill_times: 6,
		})

		expect(result.isError).toBe(true)
		expect(result.content[0]?.text).toContain('"mutation_may_have_applied":true')
		expect(result.content[0]?.text).toContain('Fetch fluentcart_subscription_get before deciding')
	})

	it('refuses a licensed subscription before PUT because FluentCart Pro may change licence state', async () => {
		const { client, get, put } = stubClient()
		get.mockResolvedValueOnce({
			data: {
				subscription: {
					status: 'active',
					collection_method: 'manual',
					bill_times: 5,
					bill_count: 2,
					licenses: [{ id: 91, status: 'expired' }],
				},
			},
			status: 200,
		})

		const result = await toolNamed(client, 'fluentcart_subscription_update').handler({
			order_id: 7,
			subscription_id: 11,
			expected_bill_times: 5,
			expected_bill_count: 2,
			bill_times: 6,
		})

		expect(result.isError).toBe(true)
		expect(result.content[0]?.text).toMatch(/linked licences/i)
		expect(put).not.toHaveBeenCalled()
	})

	it('requires a fresh bill-times guard and refuses wider financial or scheduling changes', () => {
		const { client } = stubClient()
		const update = toolNamed(client, 'fluentcart_subscription_update')
		expect(
			update.schema.safeParse({
				order_id: 7,
				subscription_id: 11,
				expected_bill_times: 3,
				expected_bill_count: 1,
				bill_times: 4,
			}).success,
		).toBe(true)
		expect(
			update.schema.safeParse({
				order_id: 7,
				subscription_id: 11,
				expected_bill_times: 3,
				expected_bill_count: 1,
				bill_times: 3,
			}).success,
		).toBe(false)
		expect(
			update.schema.safeParse({
				order_id: 7,
				subscription_id: 11,
				expected_bill_times: 3,
				expected_bill_count: 1,
				bill_times: -1,
			}).success,
		).toBe(false)
		expect(
			update.schema.safeParse({
				order_id: 7,
				subscription_id: 11,
				expected_bill_times: 3,
				expected_bill_count: 1,
				bill_times: 4,
				recurring_total: 19.99,
			}).success,
		).toBe(false)
	})

	it('fails closed when the current subscription is stale or cannot carry its own status through the update', async () => {
		const { client, get, put } = stubClient()
		get.mockResolvedValue({
			data: {
				subscription: {
					status: 'active',
					collection_method: 'manual',
					bill_times: 2,
					bill_count: 1,
				},
			},
			status: 200,
		})
		const stale = await toolNamed(client, 'fluentcart_subscription_update').handler({
			order_id: 7,
			subscription_id: 11,
			expected_bill_times: 3,
			expected_bill_count: 1,
			bill_times: 4,
		})
		expect(stale.isError).toBe(true)
		expect(stale.content[0]?.text).toMatch(/changed since it was read/i)
		expect(put).not.toHaveBeenCalled()

		get.mockResolvedValue({
			data: {
				subscription: {
					status: 'failing',
					collection_method: 'manual',
					bill_times: 3,
					bill_count: 1,
				},
			},
			status: 200,
		})
		const unsupported = await toolNamed(client, 'fluentcart_subscription_update').handler({
			order_id: 7,
			subscription_id: 11,
			expected_bill_times: 3,
			expected_bill_count: 1,
			bill_times: 4,
		})
		expect(unsupported.isError).toBe(true)
		expect(unsupported.content[0]?.text).toMatch(/cannot safely carry/i)
		expect(put).not.toHaveBeenCalled()
	})

	it('refuses automatic gateway subscriptions before writing', async () => {
		const { client, get, put } = stubClient()
		get.mockResolvedValue({
			data: {
				subscription: {
					status: 'active',
					collection_method: 'automatic',
					bill_times: 3,
					bill_count: 1,
				},
			},
			status: 200,
		})

		const result = await toolNamed(client, 'fluentcart_subscription_update').handler({
			order_id: 7,
			subscription_id: 11,
			expected_bill_times: 3,
			expected_bill_count: 1,
			bill_times: 4,
		})

		expect(result.isError).toBe(true)
		expect(result.content[0]?.text).toMatch(/store-billed/i)
		expect(put).not.toHaveBeenCalled()
	})

	it('refuses an end-of-term bill-times update and a terminal current status before writing', async () => {
		const { client, get, put } = stubClient()
		get.mockResolvedValue({
			data: {
				subscription: {
					status: 'active',
					collection_method: 'manual',
					bill_times: 5,
					bill_count: 3,
				},
			},
			status: 200,
		})
		const atEnd = await toolNamed(client, 'fluentcart_subscription_update').handler({
			order_id: 7,
			subscription_id: 11,
			expected_bill_times: 5,
			expected_bill_count: 3,
			bill_times: 3,
		})
		expect(atEnd.isError).toBe(true)
		expect(atEnd.content[0]?.text).toMatch(/strictly greater than bill_count/i)
		expect(put).not.toHaveBeenCalled()

		get.mockResolvedValue({
			data: {
				subscription: {
					status: 'completed',
					collection_method: 'manual',
					bill_times: 5,
					bill_count: 3,
				},
			},
			status: 200,
		})
		const terminal = await toolNamed(client, 'fluentcart_subscription_update').handler({
			order_id: 7,
			subscription_id: 11,
			expected_bill_times: 5,
			expected_bill_count: 3,
			bill_times: 6,
		})
		expect(terminal.isError).toBe(true)
		expect(terminal.content[0]?.text).toMatch(/cannot safely carry/i)
		expect(put).not.toHaveBeenCalled()
	})

	it('reports an error when the required post-write read-back differs', async () => {
		const { client, get, put } = stubClient()
		get
			.mockResolvedValueOnce({
				data: {
					subscription: {
						status: 'active',
						collection_method: 'manual',
						bill_times: 3,
						bill_count: 1,
					},
				},
				status: 200,
			})
			.mockResolvedValueOnce({
				data: {
					subscription: {
						status: 'active',
						collection_method: 'manual',
						bill_times: 4,
						bill_count: 2,
					},
				},
				status: 200,
			})
		const result = await toolNamed(client, 'fluentcart_subscription_update').handler({
			order_id: 7,
			subscription_id: 11,
			expected_bill_times: 3,
			expected_bill_count: 1,
			bill_times: 4,
		})

		expect(put).toHaveBeenCalledOnce()
		expect(get).toHaveBeenCalledTimes(2)
		expect(result.isError).toBe(true)
		expect(result.content[0]?.text).toContain('"mutation_may_have_applied":true')
		expect(result.content[0]?.text).toContain('Do not retry blindly')
	})
})

describe('store settings cannot change 1.6 billing controls generically', () => {
	it('rejects subscription management and system-charge settings before making a request', async () => {
		const { client, post } = stubClient()
		const save = settingsCoreTools(client).find(
			(candidate) => candidate.name === 'fluentcart_settings_save_store',
		)
		if (!save) throw new Error('fluentcart_settings_save_store is not registered')

		const result = await save.handler({ settings: { subscription_management_mode: 'system' } })

		expect(result.isError).toBe(true)
		expect(result.content[0]?.text).toMatch(/dedicated guarded flow/i)
		expect(post).not.toHaveBeenCalled()
	})
})

describe('removed FluentCart routes leave no registry residue', () => {
	it('does not classify the 1.5.5 tax-country delete ghost as an executable tool', () => {
		expect(reviewedRisk('fluentcart_tax_country_delete_all')).toBeNull()
	})
})
