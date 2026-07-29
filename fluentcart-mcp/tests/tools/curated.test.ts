import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import {
	DEFAULT_TOOLSET_MODE,
	parseToolsetMode,
	TOOLSET_MODES,
	type ToolsetMode,
} from '../../src/server.js'
import type { ToolDefinition } from '../../src/tools/_factory.js'
import {
	CURATED_TOOL_NAMES,
	missingCuratedNames,
	selectCuratedTools,
} from '../../src/tools/curated.js'
import type { ToolRisk } from '../../src/tools/risk.js'

function fakeTool(name: string, risk: ToolRisk = 'read'): ToolDefinition {
	return {
		name,
		title: name,
		description: `${name} description.`,
		schema: {
			safeParse: () => ({ success: true, data: {} }),
		} as unknown as ToolDefinition['schema'],
		annotations: { readOnlyHint: risk === 'read', openWorldHint: true },
		safety: {
			risk,
			idempotency: risk === 'read' ? 'inherent' : 'unsupported',
			execution: risk === 'read' ? 'rest' : 'none',
		},
		handler: async () => ({ content: [{ type: 'text' as const, text: '{}' }] }),
	}
}

describe('toolset mode selection', () => {
	it('defaults to dynamic when no flag is given', () => {
		expect(parseToolsetMode(undefined)).toBe('dynamic')
		expect(parseToolsetMode('')).toBe('dynamic')
		expect(DEFAULT_TOOLSET_MODE).toBe('dynamic')
	})

	it('accepts each documented mode', () => {
		for (const mode of ['dynamic', 'curated', 'code', 'full'] as ToolsetMode[]) {
			expect(parseToolsetMode(mode)).toBe(mode)
		}
		expect(TOOLSET_MODES).toEqual(['dynamic', 'curated', 'code', 'full'])
	})

	it('rejects an unknown mode rather than silently choosing one', () => {
		for (const value of ['static', 'all', 'DYNAMIC', 'read-only']) {
			expect(() => parseToolsetMode(value)).toThrow(/Invalid toolset mode/)
		}
	})

	it('no longer accepts the retired static mode', () => {
		expect(() => parseToolsetMode('static')).toThrow()
	})
})

describe('curated registry', () => {
	const registry = CURATED_TOOL_NAMES.map((name) => fakeTool(name))

	it('selects curated members in a stable declared order', () => {
		const selected = selectCuratedTools([...registry].reverse())
		expect(selected.map((tool) => tool.name)).toEqual([...CURATED_TOOL_NAMES])
	})

	it('always includes store discovery', () => {
		const names = selectCuratedTools(registry).map((tool) => tool.name)
		expect(names).toContain('fluentcart_app_init')
	})

	it('omits a curated name the registry does not expose', () => {
		const withoutOrders = registry.filter((tool) => tool.name !== 'fluentcart_order_list')
		const names = selectCuratedTools(withoutOrders).map((tool) => tool.name)

		expect(names).not.toContain('fluentcart_order_list')
		expect(missingCuratedNames(withoutOrders)).toEqual(['fluentcart_order_list'])
	})

	it('never invents a tool that is absent from the filtered registry', () => {
		expect(selectCuratedTools([])).toEqual([])
	})

	it('contains no duplicate names', () => {
		expect(new Set(CURATED_TOOL_NAMES).size).toBe(CURATED_TOOL_NAMES.length)
	})

	it('stays small enough to justify its existence', () => {
		// Curated exists to keep the definition payload light; if it grows without review the
		// token budget in plan 05 will fail anyway, so catch it here first.
		expect(CURATED_TOOL_NAMES.length).toBeLessThanOrEqual(30)
	})

	it('cannot expose a policy-hidden write, because filtering happens before selection', () => {
		// A hidden write is absent from the registry handed to selectCuratedTools, so a curated
		// entry naming it simply yields nothing.
		const hiddenWrites = new Set(['fluentcart_coupon_create', 'fluentcart_coupon_update'])
		const readsOnly = registry.filter((tool) => !hiddenWrites.has(tool.name))
		const names = selectCuratedTools(readsOnly).map((tool) => tool.name)

		expect(names).not.toContain('fluentcart_coupon_create')
		expect(names).not.toContain('fluentcart_coupon_update')
	})
})

/**
 * Plan 06 Task 5. The membership is asserted by exact name, never by a count range: a range
 * would let a tool slip in or out unnoticed, which is precisely the drift this list exists to
 * prevent.
 */
const REVIEWED_CURATED_MEMBERSHIP = [
	// Discovery: what this store is and what it can do.
	'fluentcart_app_init',
	'fluentcart_dashboard_overview',
	// Find the entity the operator is asking about.
	'fluentcart_order_list',
	'fluentcart_product_list',
	'fluentcart_customer_list',
	'fluentcart_subscription_list',
	'fluentcart_coupon_list',
	'fluentcart_product_search_by_name',
	// Load one entity in detail.
	'fluentcart_order_get',
	'fluentcart_product_get',
	'fluentcart_customer_get',
	'fluentcart_subscription_get',
	'fluentcart_coupon_get',
	'fluentcart_order_transactions',
	// Answer the recurring commercial questions, via the three contract-backed reports.
	'fluentcart_report_sales_summary',
	'fluentcart_report_sales_trend',
	'fluentcart_report_top_products',
	// Writes, shown only when the write policy already permits them.
	'fluentcart_coupon_create',
	'fluentcart_coupon_update',
]

/**
 * Names admitted by the second 2026-07-27 graduation review.
 *
 * The first pass admitted nothing, because two criteria — current live schema/output coverage and
 * a recorded response size — had no evidence to check against. `tests/integration/report-semantics.test.ts`
 * now supplies both for these three, reconciling them against the order list on a seeded store.
 */
const GRADUATED_IN_THIS_REVIEW: string[] = [
	'fluentcart_report_sales_summary',
	'fluentcart_report_sales_trend',
	'fluentcart_report_top_products',
]

/** Raw report tools removed from curated in the same pass, and why. */
const DEMOTED_IN_THIS_REVIEW: string[] = [
	'fluentcart_report_overview',
	'fluentcart_report_revenue',
	'fluentcart_report_top_products_sold',
	'fluentcart_report_sales_growth',
]

describe('curated graduation review (plan 06 Task 5)', () => {
	it('holds exactly the reviewed membership, in the reviewed order', () => {
		expect([...CURATED_TOOL_NAMES]).toEqual(REVIEWED_CURATED_MEMBERSHIP)
	})

	it('admitted exactly the three reports that gained live evidence', () => {
		for (const name of GRADUATED_IN_THIS_REVIEW) {
			expect(CURATED_TOOL_NAMES).toContain(name)
		}
	})

	it('dropped the raw report tools they supersede', () => {
		// Two of these cannot answer at all on FluentCart 1.5.5: sales-growth returns HTTP 500, and
		// top-products-sold is deprecated since 1.4 and returns an empty list.
		for (const name of DEMOTED_IN_THIS_REVIEW) {
			expect(CURATED_TOOL_NAMES).not.toContain(name)
		}
	})

	it('records the evidence for the change, so it is not re-litigated from memory', () => {
		const source = readFileSync(new URL('../../src/tools/curated.ts', import.meta.url), 'utf8')
		expect(source).toMatch(/HTTP 500/)
		expect(source).toMatch(/deprecated since FluentCart 1\.4/)
		expect(source).toMatch(/report-semantics/)
	})

	it('carries only the two reviewed writes, and both are policy-gated', () => {
		const writes = ['fluentcart_coupon_create', 'fluentcart_coupon_update']
		const reads = CURATED_TOOL_NAMES.filter((name) => !writes.includes(name))

		expect(CURATED_TOOL_NAMES.filter((name) => writes.includes(name))).toEqual(writes)
		// Everything else must be a read: unavailable high-impact work never graduates by popularity.
		const hiddenWrites = new Set(writes)
		const readOnlyRegistry = CURATED_TOOL_NAMES.filter((name) => !hiddenWrites.has(name)).map(
			(name) => fakeTool(name, 'read'),
		)
		expect(selectCuratedTools(readOnlyRegistry).map((tool) => tool.name)).toEqual(reads)
	})

	it('never admits a real-money or destructive tool', () => {
		const forbidden = [
			'fluentcart_order_refund',
			'fluentcart_subscription_cancel',
			'fluentcart_order_delete',
			'fluentcart_product_delete',
			'fluentcart_role_create',
		]
		for (const name of forbidden) {
			expect(CURATED_TOOL_NAMES).not.toContain(name)
		}
	})

	it('maps every curated name onto a route the store actually serves', () => {
		// Cross-checks Task 5 against the Task 1 ledger: a curated name with no exposed route
		// would be a tool the connected store cannot answer.
		const ledger = JSON.parse(
			readFileSync(new URL('../../api-coverage.json', import.meta.url), 'utf8'),
		) as { routes: { routeDisposition: string; toolExposures: { publicName: string }[] }[] }

		const exposedNames = new Set(
			ledger.routes
				.filter((row) => row.routeDisposition === 'exposed')
				.flatMap((row) => row.toolExposures.map((exposure) => exposure.publicName)),
		)

		for (const name of CURATED_TOOL_NAMES) {
			expect(exposedNames.has(name), `${name} is curated but reaches no exposed route`).toBe(true)
		}
	})

	it('marks curated members as curated in the ledger, not merely dynamic', () => {
		const ledger = JSON.parse(
			readFileSync(new URL('../../api-coverage.json', import.meta.url), 'utf8'),
		) as { routes: { toolExposures: { publicName: string; disposition: string }[] }[] }

		const curatedNames = new Set(CURATED_TOOL_NAMES)
		for (const row of ledger.routes) {
			for (const exposure of row.toolExposures) {
				const expected = curatedNames.has(exposure.publicName) ? 'curated' : 'dynamic'
				expect(exposure.disposition).toBe(expected)
			}
		}
	})
})
