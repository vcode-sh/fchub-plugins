// Scenarios: the questions a merchant asks about the catalogue — what it holds, what it costs.
//
// Scored on discovery, answer and cost together; see support/scenario.ts for why a green HTTP 200
// proves none of the three. Inventory questions live in scenarios-products-stock.test.ts.
import { beforeAll, describe, it } from 'vitest'
import type { ToolDefinition } from '../../src/tools/_factory.js'
import { createAllTools } from '../../src/tools/index.js'
import { must, type Row, rowsOf, sweep } from './product-scenario-kit.js'
import { getLiveClient } from './support/live-client.js'
import type { Scenario } from './support/scenario.js'

let tools: ToolDefinition[]

beforeAll(() => {
	tools = createAllTools(getLiveClient(), {})
})

const SCENARIOS: Scenario[] = [
	{
		id: 'products/catalogue-size',
		question: 'How many products do I have, and how many are actually published?',
		discovery: { query: 'list my products', expect: 'fluentcart_product_list' },
		budget: 5_000,
		run: async (ctx) => {
			const { isError, body } = await ctx.call('fluentcart_product_list', { per_page: 50 })
			must(!isError, 'product_list failed')
			const rows = rowsOf(body)
			const [[all, published]] = ctx.db(
				"select count(*), sum(post_status='publish') from wp_posts where post_type='fluent-products';",
			)
			must(String(rows.length) === all, `listed ${rows.length} products, database holds ${all}`)
			const live = rows.filter((row) => row.post_status === 'publish').length
			must(
				String(live) === published,
				`${live} published in the payload, ${published} in the store`,
			)
			ctx.note(`${all} products, ${published} published — the list shows drafts, and says which`)
		},
	},
	{
		id: 'products/digital-vs-physical',
		question: 'Which products are digital and which need shipping?',
		discovery: { query: 'digital or physical products', expect: 'fluentcart_product_list' },
		budget: 5_000,
		run: async (ctx) => {
			const digital = await ctx.call('fluentcart_product_list', {
				active_view: 'digital',
				per_page: 50,
			})
			const physical = await ctx.call('fluentcart_product_list', {
				active_view: 'physical',
				per_page: 50,
			})
			must(!(digital.isError || physical.isError), 'an active_view filter failed')
			const counts = new Map(
				ctx.db(
					"select d.fulfillment_type, count(*) from wp_posts p join wp_fct_product_details d on d.post_id=p.ID where p.post_type='fluent-products' group by d.fulfillment_type;",
				) as [string, string][],
			)
			for (const [name, call] of [['digital', digital] as const, ['physical', physical] as const]) {
				const rows = rowsOf(call.body)
				must(
					String(rows.length) === counts.get(name),
					`${name}: ${rows.length} vs ${counts.get(name)}`,
				)
				// Every row states its own fulfilment, so the filter is checkable from the payload.
				must(
					rows.every((row) => row.fulfillment_type === name),
					`a ${name} row does not say so`,
				)
			}
			ctx.note(`${counts.get('digital')} digital, ${counts.get('physical')} physical`)
		},
	},
	{
		id: 'products/category-contents',
		question: 'Show me everything in the Menswear category.',
		discovery: {
			query: 'list the products filed under a category',
			expect: 'fluentcart_product_search_by_name',
		},
		budget: 3_500,
		run: async (ctx) => {
			const terms = await ctx.call('fluentcart_product_terms')
			must(!terms.isError, 'product_terms failed')
			const taxonomies = terms.body.taxonomies as Record<string, Row>
			const list = (taxonomies['product-categories']?.terms ?? []) as Row[]
			const menswear = list.find((term) => term.label === 'Menswear')
			must(menswear, 'the category tree no longer names Menswear')
			const found = await ctx.call('fluentcart_product_search_by_name', {
				category_id: Number(menswear.value),
			})
			must(!found.isError, 'search_by_name rejected a category filter')
			const rows = rowsOf(found.body)
			const [[expected]] = ctx.db(
				"select count(*) from wp_term_relationships tr join wp_posts p on p.ID=tr.object_id where tr.term_taxonomy_id=2 and p.post_status='publish';",
			)
			must(String(rows.length) === expected, `${rows.length} products returned, ${expected} filed`)
			const [[assigned]] = ctx.db(
				'select count(*) from wp_term_relationships where term_taxonomy_id=2;',
			)
			ctx.note(
				`${expected} of ${assigned} Menswear products published; the route serves published only`,
			)
		},
	},
	{
		id: 'products/empty-category',
		question: 'What is in the Widgets category?',
		budget: 800,
		run: async (ctx) => {
			// Reached from the category tree rather than by searching, so no discovery step.
			const { isError, body } = await ctx.call('fluentcart_product_search_by_name', {
				category_id: 40,
			})
			must(!isError, 'an empty category must answer, not fail')
			const rows = rowsOf(body)
			const [[filed]] = ctx.db(
				'select count(*) from wp_term_relationships where term_taxonomy_id=40;',
			)
			must(filed === '0', 'Widgets is no longer empty; the scenario needs another term')
			must(rows.length === 0, `an empty category returned ${rows.length} products`)
		},
	},
	{
		id: 'products/search-no-match',
		question: 'Do I sell anything called kombucha?',
		discovery: { query: 'find a product by name', expect: 'fluentcart_product_search_by_name' },
		budget: 600,
		run: async (ctx) => {
			const { isError, body } = await ctx.call('fluentcart_product_search_by_name', {
				name: 'kombucha',
			})
			must(!isError, 'a term matching nothing must answer, not fail')
			const rows = rowsOf(body)
			const [[hits]] = ctx.db(
				"select count(*) from wp_posts where post_type='fluent-products' and post_title like '%kombucha%';",
			)
			must(hits === '0', 'the store now sells kombucha; the scenario needs another word')
			// The failure worth catching: a term nothing matches quietly returning the catalogue.
			must(rows.length === 0, `no match returned ${rows.length} products`)
		},
	},
	{
		id: 'products/most-expensive',
		question: 'What is the most expensive thing I sell?',
		discovery: {
			query: 'dearest product in the catalogue',
			expect: 'fluentcart_product_search_by_name',
		},
		budget: 4_000,
		run: async (ctx) => {
			// Paged deliberately: the store fixes this route at ten rows and reports no total, so a
			// single call answers with the priciest product on page one and sounds just as certain.
			const rows: Row[] = []
			for (let page = 1; page <= 5; page += 1) {
				const { isError, body } = await ctx.call('fluentcart_product_search_by_name', { page })
				must(!isError, `page ${page} failed`)
				rows.push(...rowsOf(body))
				const inner = body.products as Record<string, unknown>
				if (!inner.has_more) break
			}
			const dearest = rows.reduce((best, row) =>
				Number(row.max_price) > Number(best.max_price) ? row : best,
			)
			const [[id, title, price]] = ctx.db(
				"select p.ID, p.post_title, max(v.item_price) m from wp_posts p join wp_fct_product_variations v on v.post_id=p.ID where p.post_type='fluent-products' and p.post_status='publish' group by p.ID order by m desc limit 1;",
			)
			must(
				String(dearest.ID) === id && String(dearest.max_price) === price,
				`answered ${dearest.post_title} at ${dearest.max_price}, the store's dearest is ${title} at ${price}`,
			)
			ctx.note(`${rows.length} published products read over ${ctx.calls()} pages; dearest ${title}`)
		},
	},
	{
		id: 'products/price-range-truth',
		question: 'What is the cheapest and dearest Dual-Tone Hoodie?',
		discovery: { query: 'find a product by name', expect: 'fluentcart_product_search_by_name' },
		budget: 1_500,
		run: async (ctx) => {
			const { isError, body } = await ctx.call('fluentcart_product_search_by_name', {
				name: 'Dual-Tone',
			})
			must(!isError, 'search_by_name failed')
			const row = rowsOf(body)[0]
			must(row, 'Dual-Tone Hoodie was not found by name')
			const [[low, high]] = ctx.db(
				'select min(item_price), max(item_price) from wp_fct_product_variations where post_id=47;',
			)
			must(
				String(row.min_price) === low && String(row.max_price) === high,
				`quoted ${row.min_price}–${row.max_price}, the variants run ${low}–${high}`,
			)
			const [[storedLow, storedHigh]] = ctx.db(
				'select min_price, max_price from wp_fct_product_details where post_id=47;',
			)
			ctx.note(
				`variants run ${low}–${high}; fct_product_details still stores ${storedLow}–${storedHigh}, and the API recomputes rather than trusting it`,
			)
		},
	},
	{
		id: 'products/green-shirt',
		question: 'Find the product a customer described as "the green shirt".',
		discovery: { query: 'list products matching a colour', expect: 'fluentcart_product_list' },
		budget: 3_500,
		run: async (ctx) => {
			// A shopper names a colour that exists only as a variant title. product_search_by_name
			// matches product titles, so it is the wrong door — and now says so.
			const byTitle = await ctx.call('fluentcart_product_search_by_name', { name: 'green' })
			must(rowsOf(byTitle.body).length === 0, 'a title search now matches colours; retune this')

			const listed = await ctx.call('fluentcart_product_list', { search: 'Green', per_page: 50 })
			must(!listed.isError, 'product_list failed')
			const rows = rowsOf(listed.body)
			const [[expected]] = ctx.db(
				"select count(distinct p.ID) from wp_posts p join wp_fct_product_variations v on v.post_id=p.ID where p.post_type='fluent-products' and (p.post_title like '%green%' or v.variation_title like '%green%');",
			)
			must(
				String(rows.length) === expected,
				`${rows.length} products for "Green", store has ${expected}`,
			)

			const shirt = rows.find((row) => String(row.post_title).includes('T-Shirt'))
			must(shirt, 'no shirt among the green products')
			const variants = await ctx.call('fluentcart_variant_list', { product_id: Number(shirt.ID) })
			const green = (variants.body.variants as Row[]).find((v) =>
				String(v.variation_title).toLowerCase().includes('green'),
			)
			const [[title]] = ctx.db(
				"select variation_title from wp_fct_product_variations where post_id=28 and variation_title like '%Green%' limit 1;",
			)
			must(green?.variation_title === title, `named ${green?.variation_title}, store says ${title}`)
			ctx.note(`"the green shirt" is ${shirt.post_title} / ${title}`)
		},
	},
	{
		id: 'products/unknown-id',
		question: 'Tell me about product 999999.',
		discovery: { query: 'product details for one product', expect: 'fluentcart_product_get' },
		budget: 800,
		run: async (ctx) => {
			const { isError, text } = await ctx.call('fluentcart_product_get', { product_id: 999_999 })
			const [[exists]] = ctx.db('select count(*) from wp_posts where ID=999999;')
			must(exists === '0', 'product 999999 now exists; pick another id')
			must(isError, 'a product that does not exist came back as a success')
			// The distinction a caller has to be able to draw: a bad id, not a bad password.
			must(
				/no query results|not found|does not exist/i.test(text),
				`the failure does not say the product is missing: ${text.slice(0, 120)}`,
			)
			// Quoted from the payload, not asserted as a fixed phrase: the error projection is being
			// worked on elsewhere, and a note that restates a wording rather than reading it goes
			// stale silently — which is the same failure this whole file exists to catch.
			ctx.note(`${text.length} characters, reading: ${text.slice(0, 100)}`)
		},
	},
]

describe('product catalogue scenarios', () => {
	it('answers every one', () => sweep(tools, SCENARIOS), 180_000)
})
