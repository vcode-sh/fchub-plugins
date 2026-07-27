import type { ToolDefinition } from './_factory.js'

/**
 * Curated mode: a small, reviewed set of tools chosen by merchant intent rather than by REST
 * route coverage. Membership is by exact public name — nothing graduates here automatically,
 * because "popular route" is not the same as "worth spending a caller's context on".
 *
 * Every name here is still subject to capability discovery and the write-exposure policy, so
 * an entry that the connected store does not support, or that the current write mode hides,
 * simply does not appear.
 *
 * ## Graduation criteria
 *
 * A tool may join this list only when it serves a frequent workflow, its schema and output have
 * passed current live tests, its response is under 8,000 characters on the seeded store, its risk
 * is `read`, it adds at most 600 measured `o200k_base` definition tokens, and it does not
 * duplicate a more semantic commerce tool.
 *
 * ## Review, 2026-07-27, first pass: nothing graduated
 *
 * No candidate was admitted, because two criteria had no evidence to check against:
 * `tests/integration/api-readonly.test.ts` exercises no named tool, so nothing had current live
 * schema/output coverage, and no response measurement was recorded anywhere.
 *
 * ## Review, 2026-07-27, second pass: the three reports graduated, four raw ones left
 *
 * `tests/integration/report-semantics.test.ts` now supplies exactly the missing evidence for the
 * contract-backed reports, and measuring the raw ones it replaced showed two of them could not
 * answer at all. See the ANALYTICS block below for the per-tool findings.
 */

/**
 * Orientation: what this store is and what it can do.
 *
 * `fluentcart_dashboard_stats` used to be listed here and was removed on 2026-07-27: no tool has
 * ever carried that name, so `selectCuratedTools` silently dropped it and curated shipped 20
 * entries while claiming 21. The `/dashboard/stats` endpoint it was reaching for is already
 * served by `fluentcart_dashboard_overview`, so nothing is lost. If a real tool later takes that
 * name it re-enters through the graduation review, with evidence, like anything else.
 */
const DISCOVERY = ['fluentcart_app_init', 'fluentcart_dashboard_overview']

/** Find an entity the operator is asking about. */
const FIND = [
	'fluentcart_order_list',
	'fluentcart_product_list',
	'fluentcart_customer_list',
	'fluentcart_subscription_list',
	'fluentcart_coupon_list',
	'fluentcart_product_search_by_name',
]

/** Load the detail of one entity once it has been found. */
const LOAD = [
	'fluentcart_order_get',
	'fluentcart_product_get',
	'fluentcart_customer_get',
	'fluentcart_subscription_get',
	'fluentcart_coupon_get',
	'fluentcart_order_transactions',
]

/**
 * Answer the recurring commercial questions.
 *
 * ## Graduation review, 2026-07-27: the three contract-backed reports replace four raw ones
 *
 * The previous list was four raw REST passthroughs. Measured against the seeded store, two of
 * them could not answer anything at all:
 *
 * - `fluentcart_report_sales_growth` answers HTTP 500, `Class "" not found`, on FluentCart 1.5.5.
 * - `fluentcart_report_top_products_sold` is deprecated since FluentCart 1.4 and returns an empty
 *   list; its own response body points to `fetch-top-sold-products`, which is the endpoint
 *   `fluentcart_report_top_products` already uses and which returns real rows.
 *
 * The other two, `report_overview` and `report_revenue`, work but hand back the raw payload —
 * 2,464 and 2,200 characters — with no period, currency or payment scope attached, so a caller
 * cannot tell what the number covers.
 *
 * The three admitted here now satisfy the graduation criteria that previously had no evidence to
 * check against. `tests/integration/report-semantics.test.ts` reconciles order counts, money
 * totals, date-range filtering and currency scoping against the order list, so schema and output
 * have current live coverage; measured payloads are 976, 1,656 and 1,792 characters, well inside
 * the 8,000 ceiling; all three are risk `read`; and each states its period, currency and payment
 * scope and carries its own warnings. They supersede rather than duplicate the raw tools, which
 * remain reachable in full mode for anyone who wants the unreduced payload.
 */
const ANALYTICS = [
	'fluentcart_report_sales_summary',
	'fluentcart_report_sales_trend',
	'fluentcart_report_top_products',
]

/**
 * Writes that may appear in curated mode when the write policy already permits them.
 * Deliberately tiny: curated exists to keep the definition payload small.
 */
const WRITES = ['fluentcart_coupon_create', 'fluentcart_coupon_update']

export const CURATED_TOOL_NAMES: readonly string[] = [
	...DISCOVERY,
	...FIND,
	...LOAD,
	...ANALYTICS,
	...WRITES,
]

/**
 * Select the curated members present in an already capability- and policy-filtered registry.
 *
 * Order follows CURATED_TOOL_NAMES so the definition payload is byte-stable between runs,
 * which is what makes the token measurement reproducible.
 */
export function selectCuratedTools(tools: readonly ToolDefinition[]): ToolDefinition[] {
	const available = new Map(tools.map((tool) => [tool.name, tool]))
	const selected: ToolDefinition[] = []

	for (const name of CURATED_TOOL_NAMES) {
		const tool = available.get(name)
		if (tool) selected.push(tool)
	}

	return selected
}

/** Names listed as curated but absent from the supplied registry, for contract tests. */
export function missingCuratedNames(tools: readonly ToolDefinition[]): string[] {
	const available = new Set(tools.map((tool) => tool.name))
	return CURATED_TOOL_NAMES.filter((name) => !available.has(name))
}
