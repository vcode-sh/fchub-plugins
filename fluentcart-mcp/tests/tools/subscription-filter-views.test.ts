// `active_view` must offer only the statuses FluentCart will actually filter on.
//
// The failure this pins is silent and expensive. `SubscriptionFilter::applyActiveViewFilter` looks
// the requested view up in `tabsMap()` and passes the result straight to `where()`. For a view the
// map does not contain, that column is null, the constraint is never applied, and the store answers
// with EVERY subscription — HTTP 200, correct-looking envelope, nothing anywhere saying the filter
// was dropped.
//
// `past_due` and `completed` are exactly that case. Both are real statuses in
// `Status::getSubscriptionStatuses()`, so an agent reading the status list has every reason to
// filter on one; neither is in `tabsMap()`. Measured live against the playground store on
// 2026-07-28: `active_view: 'past_due'` returned all four subscriptions, of which zero are past
// due. The tool's own description had listed past_due as a filter value.
//
// A closed enum turns that into a rejected argument, which a caller can recover from. `search`
// matches the status column exactly, so it remains the way to reach those two.
import { describe, expect, it, vi } from 'vitest'
import { z } from 'zod'
import type { FluentCartClient } from '../../src/api/client.js'
import { createAllTools } from '../../src/tools/index.js'

/** The nine views SubscriptionFilter::tabsMap() maps, verified against FluentCart 1.5.5. */
const MAPPED_VIEWS = [
	'active',
	'pending',
	'intended',
	'paused',
	'trialing',
	'canceled',
	'failing',
	'expiring',
	'expired',
]

/** Statuses a subscription can hold that the filter map does not contain. */
const UNMAPPED_STATUSES = ['past_due', 'completed']

function subscriptionList() {
	const client = {
		get: vi.fn().mockResolvedValue({ data: {}, status: 200 }),
	} as unknown as FluentCartClient
	const tool = createAllTools(client, {}).find(
		(candidate) => candidate.name === 'fluentcart_subscription_list',
	)
	if (!tool) throw new Error('fluentcart_subscription_list is not registered')
	return tool
}

function activeViewSchema() {
	const json = z.toJSONSchema(subscriptionList().schema) as {
		properties: { active_view?: { enum?: string[]; description?: string } }
	}
	const field = json.properties.active_view
	if (!field) throw new Error('active_view is gone from the schema')
	return field
}

describe('subscription list filter views', () => {
	it('offers exactly the nine views FluentCart maps', () => {
		expect(activeViewSchema().enum).toEqual(MAPPED_VIEWS)
	})

	it('offers no status the filter map would silently ignore', () => {
		const offered = activeViewSchema().enum ?? []
		for (const status of UNMAPPED_STATUSES) {
			expect(offered, `${status} returns every subscription unfiltered`).not.toContain(status)
		}
	})

	it('says why those two are missing, rather than leaving a caller to wonder', () => {
		const { description = '' } = activeViewSchema()
		for (const status of UNMAPPED_STATUSES) {
			expect(description).toContain(status)
		}
		expect(description).toMatch(/unfiltered|ignores/)
	})

	it('points at search, which does match the status column', () => {
		const json = z.toJSONSchema(subscriptionList().schema) as {
			properties: { search?: { description?: string } }
		}
		expect(json.properties.search?.description ?? '').toMatch(/past_due/)
	})
})
