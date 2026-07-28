import { afterAll, beforeAll, describe, it } from 'vitest'
import type { ToolDefinition } from '../../src/tools/_factory.js'
import { createAllTools } from '../../src/tools/index.js'
import { facts, must, pairs, type Row, sweep, variantsOf } from './product-scenario-kit.js'
import { CleanupLedger } from './support/cleanup-ledger.js'
import { getLiveClient, removeProduct, verifyProductMissing } from './support/live-client.js'
import { getLiveRun } from './support/live-run.js'
import type { Scenario } from './support/scenario.js'

const run = getLiveRun()
const ledger = new CleanupLedger()
const VARIANTS = 'wp_fct_product_variations'
let tools: ToolDefinition[]

beforeAll(() => {
	tools = createAllTools(getLiveClient(), {})
})

afterAll(async () => {
	await ledger.cleanup()
})

const SCENARIOS: Scenario[] = [
	{
		id: 'stock/about-to-run-out',
		question: 'What am I about to run out of?',
		discovery: { query: 'what am I about to run out of', expect: 'fluentcart_variant_list_all' },
		budget: 2_500,
		run: async (ctx) => {
			const low = await ctx.call('fluentcart_variant_list_all', { stock: 'low' })
			must(!low.isError, 'variant_list_all failed')
			const [atRisk, untracked, tracked] = facts(
				ctx,
				`select sum(manage_stock=1 and available<5), sum(manage_stock=0), sum(manage_stock=1) from ${VARIANTS};`,
			)
			must(atRisk === '0', 'the store now has low stock; this scenario needs new data')
			must(low.body.total === 0, `nothing is low, yet ${low.body.total} were reported`)
			// The filter must be able to match, or "nothing is low" proves only that it is broken.
			const proof = await ctx.call('fluentcart_variant_list_all', {
				stock: 'low',
				low_below: 101,
				per_page: 3,
			})
			must(
				String(proof.body.total) === tracked,
				`threshold 101 matched ${proof.body.total}/${tracked}`,
			)
			ctx.note(
				`nothing below 5: ${tracked} tracked variants sit at 100, ${untracked} count nothing`,
			)
		},
	},
	{
		id: 'stock/sold-out',
		question: 'What has sold out?',
		discovery: { query: 'what has sold out', expect: 'fluentcart_variant_list_all' },
		budget: 800,
		run: async (ctx) => {
			const { isError, body } = await ctx.call('fluentcart_variant_list_all', { stock: 'out' })
			must(!isError, 'variant_list_all failed')
			const [out, inert] = facts(
				ctx,
				`select sum(manage_stock=1 and available<=0), sum(manage_stock=0 and available<=0) from ${VARIANTS};`,
			)
			must(String(body.total) === out, `reported ${body.total} sold out, the store has ${out}`)
			// The whole point: a variant that counts nothing has no stock level, so its zero is inert.
			must(Number(inert) > 0, 'no untracked zeroes left to misread; this scenario needs new data')
			ctx.note(
				`${inert} variants sit at available 0 without tracking stock; none is called sold out`,
			)
		},
	},
	{
		id: 'stock/tshirt-by-colour',
		question: 'How many t-shirts do I have left in each colour?',
		discovery: { query: 'find a product by name', expect: 'fluentcart_product_search_by_name' },
		budget: 3_000,
		run: async (ctx) => {
			const found = await ctx.call('fluentcart_product_search_by_name', { name: 'T-Shirt' })
			const row = ((found.body.products as Row).data as Row[])[0]
			must(row, 'no t-shirt in the catalogue')
			const listed = await ctx.call('fluentcart_variant_list', { product_id: Number(row.ID) })
			must(!listed.isError, 'variant_list failed')
			const answer = pairs(variantsOf(listed.body).map((v) => [v.variation_title, v.available]))
			const truth = pairs(
				ctx.db(
					`select variation_title, available from ${VARIANTS} where post_id=${row.ID} order by id;`,
				),
			)
			must(answer === truth, `answered ${answer}, store says ${truth}`)
			ctx.note(`${row.post_title}: ${answer}`)
		},
	},
	{
		id: 'stock/every-variant-price',
		question: "What does the Basic Men's T-Shirt cost, in every variant?",
		discovery: {
			query: 'variations of one product with prices',
			expect: 'fluentcart_variant_list',
		},
		budget: 2_000,
		run: async (ctx) => {
			const { isError, body } = await ctx.call('fluentcart_variant_list', { product_id: 28 })
			must(!isError, 'variant_list failed')
			const answer = pairs(variantsOf(body).map((v) => [v.variation_title, v.item_price]))
			const rows = ctx.db(
				`select variation_title, item_price from ${VARIANTS} where post_id=28 order by id;`,
			)
			must(answer === pairs(rows), `answered ${answer}, store says ${pairs(rows)}`)
			must(body.total === rows.length, `total says ${body.total} for ${rows.length} variants`)
			ctx.note(`prices in minor units: ${answer}`)
		},
	},
	{
		id: 'stock/untracked-variant',
		question: 'How many of the name-your-price demo do I have left?',
		budget: 800,
		run: async (ctx) => {
			// Reached from a product id, so no discovery step. This is the reading that must not
			// happen: total_stock and available are absent, not zero, when nothing is counted.
			const { isError, body, text } = await ctx.call('fluentcart_variant_list', { product_id: 570 })
			must(!isError, 'variant_list failed')
			const variant = variantsOf(body)[0]
			must(variant, 'the name-your-price demo has no variants')
			const [manages, status] = facts(
				ctx,
				`select manage_stock, stock_status from ${VARIANTS} where post_id=570;`,
			)
			must(manages === '0', 'this variant now tracks stock; this scenario needs another one')
			must(
				variant.manage_stock === false,
				`manage_stock is ${JSON.stringify(variant.manage_stock)}`,
			)
			must(
				variant.stock_status === status,
				`stock_status ${variant.stock_status} against ${status}`,
			)
			must(
				!('available' in variant || 'total_stock' in variant),
				`a stock level was invented: ${text}`,
			)
		},
	},
	{
		id: 'stock/no-skus',
		question: 'Which of my products have no SKU set?',
		discovery: { query: 'variants missing a sku', expect: 'fluentcart_variant_list_all' },
		// Twenty thousand characters for a yes/no answer. That IS the finding: nothing in the
		// registry filters on SKU, so establishing it means reading every variant in the store.
		budget: 21_000,
		run: async (ctx) => {
			const rows: Row[] = []
			for (let page = 1; page <= 4; page += 1) {
				const call = await ctx.call('fluentcart_variant_list_all', { page, per_page: 50 })
				must(!call.isError, `page ${page} failed`)
				rows.push(...variantsOf(call.body))
				if (!call.body.has_more) break
			}
			const [total, blank] = facts(
				ctx,
				`select count(*), sum(sku is null or sku='') from ${VARIANTS};`,
			)
			must(String(rows.length) === total, `read ${rows.length} variants, store holds ${total}`)
			const missing = rows.filter((v) => v.sku === null || v.sku === '').length
			must(String(missing) === blank, `${missing} without a SKU, database says ${blank}`)
			ctx.note(`all ${total} variants lack a SKU, and it cost ${ctx.spent()} characters to say so`)
		},
	},
	{
		id: 'stock/subscription-variants',
		question: 'Which variants are subscriptions rather than one-off purchases?',
		discovery: {
			query: 'which variants are subscriptions',
			expect: 'fluentcart_product_find_subscription_variants',
		},
		budget: 1_200,
		run: async (ctx) => {
			const all = await ctx.call('fluentcart_product_find_subscription_variants')
			must(!all.isError, 'find_subscription_variants failed')
			const rows = all.body as unknown as Row[]
			const [total, yearly] = facts(
				ctx,
				`select count(*), sum(variation_title like '%Yearly%') from ${VARIANTS} where payment_type='subscription';`,
			)
			must(
				String(rows.length) === total,
				`${rows.length} subscription variants, store has ${total}`,
			)
			// The filter used to be read by nothing: `search` was sent, `name` was expected.
			const few = (
				await ctx.call('fluentcart_product_find_subscription_variants', { name: 'Yearly' })
			).body as unknown as Row[]
			must(String(few.length) === yearly, `"Yearly" gave ${few.length}, store has ${yearly}`)
			ctx.note(`${total} subscription variants, ${yearly} of them yearly`)
		},
	},
	{
		id: 'stock/variants-per-product',
		question: 'How many variants does each product have?',
		discovery: {
			query: 'how many variants does each product have',
			expect: 'fluentcart_product_search_variant_by_name',
		},
		budget: 4_500,
		run: async (ctx) => {
			const { isError, body } = await ctx.call('fluentcart_product_search_variant_by_name')
			must(!isError, 'search_variant_by_name failed')
			const tree = body as unknown as { value: number; children: unknown[] }[]
			const answer = tree
				.map((node) => `${node.value}=${node.children.length}`)
				.sort()
				.join(',')
			const truth = ctx
				.db(`select post_id, count(*) from ${VARIANTS} group by post_id;`)
				.map(([id, count]) => `${id}=${count}`)
				.sort()
				.join(',')
			must(answer === truth, `answered ${answer}, store says ${truth}`)
			ctx.note(`${tree.length} products and their variant counts for ${ctx.spent()} characters`)
		},
	},
	{
		id: 'stock/product-without-variants',
		question: 'A product with no variants — what does the tool say?',
		budget: 3_000,
		run: async (ctx) => {
			// No product in this store has zero variants, so one is created, read, removed, and
			// proven gone from the database rather than from another endpoint.
			const created = await ctx.call('fluentcart_product_create', {
				post_title: `${run.prefix}-novariants`,
				variation_type: 'simple_variations',
			})
			must(!created.isError, `product_create failed: ${created.text}`)
			const id = Number((created.body.data as Row).ID)
			must(Number.isInteger(id) && id > 0, 'create returned no usable id')
			// removeProduct, not a bare DELETE: the scenario deletes the fixture itself and this is
			// the net for a run that throws first, so absence is checked before deleting again.
			ledger.track({
				type: 'product',
				id,
				remove: removeProduct,
				verifyMissing: verifyProductMissing,
			})

			try {
				const listed = await ctx.call('fluentcart_variant_list', { product_id: id })
				must(!listed.isError, 'a product with no variants must answer, not fail')
				must(variantsOf(listed.body).length === 0, 'variants appeared from nowhere')
				must(listed.body.total === 0, `total says ${listed.body.total} for a product with none`)
				const [held] = facts(ctx, `select count(*) from ${VARIANTS} where post_id=${id};`)
				must(held === '0', `the store made ${held} variants for a simple_variations product`)
			} finally {
				await ctx.call('fluentcart_product_delete', { product_id: id })
			}
			const [left] = facts(ctx, `select count(*) from wp_posts where ID=${id};`)
			must(left === '0', `fixture ${id} survived deletion`)
			ctx.note(`fixture ${id} created, read and deleted; the database confirms it is gone`)
		},
	},
]

describe('product stock scenarios', () => {
	it('answers every one', () => sweep(tools, SCENARIOS), 180_000)
})
