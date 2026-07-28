// Scenarios: what a shopper is shown, and the reference lists a shop leans on to show it.
//
// The other half of the store-configuration sweep — see scenarios-config.test.ts for the money,
// mail and access questions, and support/scenario.ts for why discovery, answer and cost are all
// scored. Ground truth is the store's own tables, never a second endpoint.
import { beforeAll, describe, it } from 'vitest'
import type { ToolDefinition } from '../../src/tools/_factory.js'
import {
	buildConfigTools,
	count,
	must,
	SQL,
	STORE_OPTION,
	sweep,
} from './support/config-fixture.js'
import { getLiveClient } from './support/live-client.js'
import type { Scenario } from './support/scenario.js'

let tools: ToolDefinition[]

beforeAll(() => {
	tools = buildConfigTools(getLiveClient())
})

const SCENARIOS: Scenario[] = [
	{
		id: 'storefront/browse',
		question: 'What does a shopper see on the shop page?',
		discovery: { query: 'storefront shop page', expect: 'fluentcart_public_product_views' },
		budget: 800,
		run: async (ctx) => {
			const first = await ctx.call('fluentcart_public_product_views')
			must(!first.isError, `public_product_views failed: ${first.text.slice(0, 120)}`)
			const published = count(ctx, SQL.publishedProducts)
			must(
				Number(first.body.total) === published,
				`the shop page reports ${first.body.total} products, the store publishes ${published}`,
			)
			must(
				first.body.cards_rendered === first.body.to,
				`claims rows ${first.body.from}-${first.body.to} but renders ${first.body.cards_rendered} cards`,
			)
			const second = await ctx.call('fluentcart_public_product_views', { page: 2, per_page: 5 })
			must(second.body.from === 6, `page 2 of 5 should start at row 6, got ${second.body.from}`)
			ctx.note(
				`${published} products; page one holds ${first.body.markup_characters} characters of markup, answered in ${first.chars}`,
			)
		},
	},
	{
		id: 'storefront/search',
		question: 'If a shopper searches for "hoodie", what comes up?',
		discovery: { query: 'storefront product search', expect: 'fluentcart_public_product_search' },
		budget: 900,
		run: async (ctx) => {
			const { isError, body } = await ctx.call('fluentcart_public_product_search', {
				search: 'hoodie',
			})
			must(!isError, 'public_product_search failed')
			const titles = (body.titles ?? []) as string[]
			must(titles.length > 0, 'searching for a product the shop sells returned nothing')
			must(
				body.results === titles.length,
				`${body.results} results against ${titles.length} titles`,
			)
			const known = ctx
				.db(
					"select post_title from wp_posts where post_type='fluent-products' and post_status='publish' and post_title like '%hoodie%';",
				)
				.map(([title]) => title)
			for (const title of titles)
				must(known.includes(title), `"${title}" is no hoodie the shop sells`)
			const miss = await ctx.call('fluentcart_public_product_search', { search: 'zzzznothing' })
			must(miss.body.results === 0, `a search for nothing returned ${miss.body.results} results`)
			ctx.note(`"hoodie" matches ${titles.length} of the ${known.length} published hoodies`)
		},
	},
	{
		id: 'storefront/checkout-countries',
		question: 'What countries can a customer pick at checkout?',
		discovery: { query: 'countries at checkout', expect: 'fluentcart_misc_countries' },
		budget: 9_200,
		run: async (ctx) => {
			const { isError, body, chars } = await ctx.call('fluentcart_misc_countries')
			must(!isError, 'misc_countries failed')
			const countries = (body.data ?? []) as { value: string }[]
			must(countries.length > 150, `only ${countries.length} countries are offered`)
			const home = count(
				ctx,
				`select option_value like '%s:13:"store_country";s:2:"%' from wp_options where option_name='${STORE_OPTION}';`,
			)
			must(home === 1, 'the store has no country of its own')
			ctx.note(
				`${countries.length} countries for ${chars} characters, with no search and no paging`,
			)
		},
	},
	{
		id: 'storefront/unknown-country',
		question: 'What address fields apply in country "ZZ"?',
		discovery: {
			query: 'country states and postcode rules',
			expect: 'fluentcart_misc_country_info',
		},
		budget: 600,
		run: async (ctx) => {
			const real = await ctx.call('fluentcart_misc_country_info', { country_code: 'PL' })
			must(!real.isError, 'misc_country_info failed for a real country')
			must(real.body.country_name === 'Poland', `PL resolved to ${real.body.country_name}`)
			const fake = await ctx.call('fluentcart_misc_country_info', { country_code: 'ZZ' })
			must(!fake.isError, 'an unknown code is rejected now; update this scenario')
			must(fake.body.country_name === 'ZZ', `ZZ resolved to ${fake.body.country_name}`)
			ctx.note('an unknown code comes back as a country with no states, so a typo looks valid')
		},
	},
	{
		id: 'storefront/activity-today',
		question: 'What has been changing on the store today?',
		discovery: { query: 'activity log', expect: 'fluentcart_activity_list' },
		budget: 2_600,
		run: async (ctx) => {
			const { isError, body } = await ctx.call('fluentcart_activity_list', { per_page: 5 })
			must(!isError, 'activity_list failed')
			const page = (body.activities ?? {}) as { data?: { id: number }[]; total?: number }
			const rows = page.data ?? []
			must(rows.length > 0, 'the activity log is empty on a store that is being written to')
			// Other work is writing to this log right now, so prove the rows are real and ordered
			// rather than pinning a count that moves under the test.
			const ids = rows.map((row) => row.id)
			const real = count(
				ctx,
				`select count(*) from wp_fct_activity where id in (${ids.join(',')});`,
			)
			must(real === ids.length, `${ids.length - real} reported entries do not exist`)
			must(
				ids.every((id, index) => index === 0 || id < ids[index - 1]),
				`the log is not newest-first: ${ids.join(', ')}`,
			)
			ctx.note(`${page.total} entries logged, newest first`)
		},
	},
	{
		id: 'storefront/labels',
		question: 'What labels can I put on an order?',
		discovery: { query: 'labels on orders', expect: 'fluentcart_label_list' },
		budget: 14_000,
		run: async (ctx) => {
			const { isError, body, chars } = await ctx.call('fluentcart_label_list')
			must(!isError, 'label_list failed')
			const labels = (body.labels ?? []) as unknown[]
			const held = count(ctx, SQL.labels)
			must(labels.length === held, `answer lists ${labels.length} labels, the store holds ${held}`)
			ctx.note(
				`${held} labels for ${chars} characters: the list takes no page, no search and no limit, so it only grows`,
			)
		},
	},
	{
		id: 'storefront/filter-values',
		question: 'Which label values can I filter the order list by?',
		discovery: { query: 'filter options for labels', expect: 'fluentcart_misc_filter_options' },
		budget: 6_000,
		run: async (ctx) => {
			const blank = await ctx.call('fluentcart_misc_filter_options')
			must(!blank.isError, 'misc_filter_options failed')
			const nothing = (blank.body.options ?? []) as Record<string, unknown>
			must(Object.keys(nothing).length === 0, 'an unkeyed call returns options now; update this')
			const keyed = await ctx.call('fluentcart_misc_filter_options', { remote_data_key: 'labels' })
			const options = Object.keys((keyed.body.options ?? {}) as Record<string, string>)
			const held = count(ctx, SQL.labels)
			must(
				options.length === held,
				`filter offers ${options.length} labels, the store holds ${held}`,
			)
			ctx.note('without remote_data_key this route answers 200 with nothing, for every caller')
		},
	},
]

describe('storefront and reference-data scenarios', () => {
	it('answers every one', async () => {
		await sweep(tools, SCENARIOS)
	}, 300_000)
})
