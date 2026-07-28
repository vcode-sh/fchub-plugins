// The subscription list projection has to actually run.
//
// `fluentcart_subscription_list` has always declared a transform that projects each row down to
// the ~25 fields a caller needs. It never ran. The guard was `Array.isArray(resp.data)`, but
// `GET /subscriptions` answers `{data: {current_page, data: [...]}}` — the rows are two levels
// down, so the check was false every time and the raw rows shipped untouched.
//
// What shipped instead, measured on the seeded store: 6,799 characters for four subscriptions,
// 1,773 of them (28%) the gateway `meta` array — Redsys subscription references, transaction
// UUIDs and intent phases. Fixing the guard took it to 2,483 characters, a 63% cut, with every
// useful field intact.
//
// `fluentcart_subscription_get` was never affected: it guards on `resp.subscription`, a key that
// genuinely exists. That asymmetry is why the bug survived — the singular tool looked fine.
import { describe, expect, it, vi } from 'vitest'
import type { FluentCartClient } from '../../src/api/client.js'
import { createAllTools } from '../../src/tools/index.js'

const RAW_ROW = {
	id: 4,
	status: 'active',
	item_name: 'Subscription Demo - Yearly',
	billing_interval: 'yearly',
	recurring_total: '99900',
	next_billing_date: '2027-04-23',
	config: { is_trial_days_simulated: 'no', currency: 'EUR' },
	// Everything below is gateway bookkeeping the caller cannot act on.
	meta: [
		{
			id: 1,
			meta_key: 'redsys_subscription_reference',
			meta_value: 'fffa2c8c82a5ca67fc9ad538b629bed3',
		},
	],
	uuid: 'fffa2c8c82a5ca67fc9ad538b629bed3',
	vendor_response: { raw: 'gateway blob' },
}

async function listWith(payload: unknown) {
	const get = vi.fn().mockResolvedValue({ data: payload, status: 200 })
	const client = { get } as unknown as FluentCartClient
	const tool = createAllTools(client, {}).find(
		(candidate) => candidate.name === 'fluentcart_subscription_list',
	)
	if (!tool) throw new Error('fluentcart_subscription_list is not registered')
	const result = (await tool.handler({} as never, {} as never)) as { content: { text: string }[] }
	return JSON.parse(result.content[0]?.text ?? '{}')
}

describe('subscription list projects its rows', () => {
	it('projects rows nested under a paginator, which is the shape the store sends', async () => {
		const out = await listWith({ data: { current_page: 1, total: 1, data: [RAW_ROW] } })
		const row = out.data.data[0]

		expect(row.meta, 'gateway meta must not reach a caller').toBeUndefined()
		expect(row.uuid).toBeUndefined()
		expect(row.vendor_response).toBeUndefined()
		expect(row.status).toBe('active')
		expect(row.billing_interval).toBe('yearly')
	})

	it('keeps the paginator around the projected rows', async () => {
		// Dropping current_page would break any caller that pages.
		const out = await listWith({ data: { current_page: 2, total: 9, data: [RAW_ROW] } })
		expect(out.data.current_page).toBe(2)
		expect(out.data.total).toBe(9)
	})

	it('also handles a flat array, so the older shape still projects', async () => {
		const out = await listWith({ data: [RAW_ROW] })
		expect(out.data[0].meta).toBeUndefined()
		expect(out.data[0].id).toBe(4)
	})

	it('surfaces the currency, which lives only inside the config blob', async () => {
		// fct_subscriptions has no currency column. Before this the whole config object shipped, or
		// the projection dropped it entirely and the currency was unavailable at all.
		const out = await listWith({ data: { data: [RAW_ROW] } })
		expect(out.data.data[0].currency).toBe('EUR')
		expect(out.data.data[0].config, 'the rest of config is bookkeeping').toBeUndefined()
	})

	it('leaves a payload it does not recognise alone', async () => {
		const out = await listWith({ unexpected: true })
		expect(out).toEqual({ unexpected: true })
	})

	it('survives a row that is missing config', async () => {
		const { config, ...noConfig } = RAW_ROW
		const out = await listWith({ data: { data: [noConfig] } })
		expect(out.data.data[0].currency).toBeUndefined()
		expect(out.data.data[0].id).toBe(4)
	})
})
