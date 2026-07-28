// Customer PII and payment-session identifiers must not reach a tool result.
//
// Found by measuring live payloads rather than by reading code. `fluentcart_order_get` returned
// the customer's IP address five times in a single response, because FluentCart embeds a complete
// copy of the order inside every address it returns — `order_addresses[].order`,
// `billing_address.order`, `shipping_address.order` — each with its own `ip_address`, `uuid` and
// a `config` blob holding `p24_session_id`. `fluentcart_subscription_get` leaked the same three
// through `related_orders[]`.
//
// The size was the symptom that led there: one order was 14,476 characters, of which the three
// address fields were 11,220. Stripping the back-reference and the identifiers took it to 7,140,
// and the subscription from 4,103 to 1,435.
//
// An IP address is personal data, and a payment-session id is a live handle to a transaction.
// Neither is something an agent can act on, so there is no trade to weigh here.
import { describe, expect, it, vi } from 'vitest'
import type { FluentCartClient } from '../../src/api/client.js'
import { createAllTools } from '../../src/tools/index.js'

/** An order as FluentCart really returns it, back-reference and all. */
function rawOrder(): Record<string, unknown> {
	const order: Record<string, unknown> = {
		id: 1,
		status: 'completed',
		payment_status: 'paid',
		total_amount: 13000,
		currency: 'EUR',
		created_at: '2026-05-05',
		ip_address: '148.251.46.45',
		uuid: '4f78c851b1de0672ba4362eaba2d6bb5',
		config: { user_tz: 'Europe/Warsaw', p24_session_id: 'd203f04efb238669bb2d51654952f5d4' },
		customer: { id: 7, full_name: 'A Customer', email: 'buyer@example.invalid' },
		transactions: [{ id: 3, total: 13000, uuid: 'tx-uuid', meta: { gateway: 'noise' } }],
	}
	// The address rows each carry the whole order back again.
	const address = { id: 1, city: 'Warsaw', order }
	order.order_addresses = [address, address]
	order.billing_address = address
	order.shipping_address = address
	return order
}

const FORBIDDEN = ['148.251.46.45', 'ip_address', 'p24_session_id']

async function resultOf(name: string, payload: unknown, input: Record<string, unknown>) {
	const get = vi.fn().mockResolvedValue({ data: payload, status: 200 })
	const client = { get } as unknown as FluentCartClient
	const tool = createAllTools(client, {}).find((candidate) => candidate.name === name)
	if (!tool) throw new Error(`${name} is not registered`)
	const result = (await tool.handler(input as never, {} as never)) as {
		content: { text: string }[]
	}
	return result.content[0]?.text ?? ''
}

describe('order details carry no personal or session identifiers', () => {
	it('strips the IP address, the uuid and the payment-session config', async () => {
		const text = await resultOf('fluentcart_order_get', { order: rawOrder() }, { order_id: 1 })

		for (const forbidden of FORBIDDEN) {
			expect(text, `${forbidden} reached the caller`).not.toContain(forbidden)
		}
		expect(text).not.toContain('4f78c851b1de0672ba4362eaba2d6bb5')
	})

	it('drops the order copy embedded in every address', async () => {
		const text = await resultOf('fluentcart_order_get', { order: rawOrder() }, { order_id: 1 })
		const parsed = JSON.parse(text) as { order: Record<string, unknown> }

		// `order_addresses` held the same two records as billing_address and shipping_address, so it
		// is now dropped outright rather than merely stripped of its back-reference. That is the
		// stronger guarantee — a copy that does not exist cannot leak — so this asserts absence, and
		// then asserts the surviving pair carries neither the nested order nor a lost address.
		expect(parsed.order.order_addresses).toBeUndefined()

		const billing = parsed.order.billing_address as Record<string, unknown>
		const shipping = parsed.order.shipping_address as Record<string, unknown>
		expect(billing.order).toBeUndefined()
		expect(shipping.order).toBeUndefined()

		// The address itself must survive — only the duplication goes.
		expect(billing.city).toBe('Warsaw')
	})

	it('keeps everything a caller actually needs', async () => {
		const text = await resultOf('fluentcart_order_get', { order: rawOrder() }, { order_id: 1 })
		for (const kept of ['completed', 'paid', '13000', 'buyer@example.invalid']) {
			expect(text).toContain(kept)
		}
	})
})

describe('subscription details carry no personal or session identifiers', () => {
	it('summarises related orders instead of restating them', async () => {
		const text = await resultOf(
			'fluentcart_subscription_get',
			{ subscription: { id: 4, status: 'active', related_orders: [rawOrder()] } },
			{ subscription_id: 4 },
		)

		for (const forbidden of FORBIDDEN) {
			expect(text, `${forbidden} reached the caller`).not.toContain(forbidden)
		}

		const parsed = JSON.parse(text) as {
			subscription: { related_orders: Record<string, unknown>[] }
		}
		const related = parsed.subscription.related_orders[0]
		// Enough to recognise the order and go and fetch it.
		expect(related?.id).toBe(1)
		expect(related?.payment_status).toBe('paid')
		expect(related?.order_addresses).toBeUndefined()
	})

	it('leaves a related_orders payload that is not an array alone', async () => {
		const text = await resultOf(
			'fluentcart_subscription_get',
			{ subscription: { id: 4, related_orders: null } },
			{ subscription_id: 4 },
		)
		expect(JSON.parse(text).subscription.related_orders).toBeNull()
	})
})

describe('subscription lists carry no gateway bookkeeping', () => {
	it('drops meta, uuid and vendor_response from every row', async () => {
		const text = await resultOf(
			'fluentcart_subscription_list',
			{
				data: {
					current_page: 1,
					data: [
						{
							id: 4,
							status: 'active',
							uuid: 'sub-uuid',
							vendor_response: { gateway: 'blob' },
							meta: [{ meta_key: 'redsys_subscription_reference', meta_value: 'secret-ref' }],
						},
					],
				},
			},
			{},
		)

		expect(text).not.toContain('redsys_subscription_reference')
		expect(text).not.toContain('secret-ref')
		expect(text).not.toContain('sub-uuid')
		expect(text).toContain('active')
	})
})
