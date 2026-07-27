// Every field name a report reads must exist in the captured response fixture.
//
// This test exists because of a specific failure. The sales summary allowlist was written by
// reading the field names off the trend rows and assuming the summary block in the same response
// used them too. It does not: the summary calls gross sales `gross_sale` where a trend row calls
// it `total_sales`. Four of the eight advertised summary fields — including total sales, the
// headline number — therefore resolved to null on every call, for every store, since the tool
// shipped. Nothing caught it. The unit tests stubbed the assumed shape, so they agreed with the
// assumption, and the null fields were reported through the "the store returned no value for"
// warning, which made a broken projection look like an empty store.
//
// The fixture had the right names the whole time. So the mapping is now checked against it: if a
// contract names a field the store does not send, this fails at build time rather than resolving
// to null at a customer's.
import { describe, expect, it } from 'vitest'
import { acceptedReports } from '../../src/commerce/report-contracts.js'
import fixture from '../fixtures/rest/fluentcart-1.5.5-core-pro-1.5.4-read-contracts.json' with {
	type: 'json',
}

interface ShapeNode {
	object?: Record<string, unknown>
	array?: { object?: Record<string, unknown> } | string
}

interface FixtureContract {
	method: string
	canonicalPath: string
	responseShape?: ShapeNode
}

const contracts = fixture.contracts as unknown as FixtureContract[]

function shapeFor(path: string): ShapeNode | null {
	return (
		contracts.find((c) => c.canonicalPath === path && c.method === 'GET')?.responseShape ?? null
	)
}

/** Field names of an object node, or of the object each array element carries. */
function fieldsOf(node: unknown): Set<string> {
	if (!node || typeof node !== 'object') return new Set()
	const shape = node as ShapeNode
	if (shape.object) return new Set(Object.keys(shape.object))
	if (shape.array && typeof shape.array === 'object' && shape.array.object) {
		return new Set(Object.keys(shape.array.object))
	}
	return new Set()
}

/** Where in the response each report reads its figures from. */
const READ_LOCATIONS: Record<string, { path: string; block: string }> = {
	sales_summary: { path: '/reports/revenue', block: 'summary' },
	sales_trend: { path: '/reports/revenue', block: 'revenueReport' },
}

describe('report source fields exist in the captured response', () => {
	it('covers every accepted report that declares a source mapping', () => {
		const mapped = acceptedReports()
			.filter((report) => report.sourceFields)
			.map((report) => report.name)
		expect(mapped.length).toBeGreaterThan(0)
		for (const name of mapped) {
			expect(
				READ_LOCATIONS[name],
				`${name} maps source fields but is not located here`,
			).toBeDefined()
		}
	})

	for (const [name, location] of Object.entries(READ_LOCATIONS)) {
		it(`${name} reads only fields the store actually sends`, () => {
			const report = acceptedReports().find((r) => r.name === name)
			expect(report, `${name} is not an accepted report`).toBeDefined()
			if (!report) return

			const shape = shapeFor(location.path)
			expect(shape, `no captured contract for ${location.path}`).not.toBeNull()

			const block = (shape?.object as Record<string, unknown> | undefined)?.[location.block]
			const available = fieldsOf(block)
			expect(available.size, `${location.block} carried no fields in the fixture`).toBeGreaterThan(
				0,
			)

			const derived = new Set(report.derivedFields ?? [])
			const source = report.sourceFields ?? {}

			for (const output of report.outputProjection) {
				if (derived.has(output)) continue
				// `period` is assembled from several possible label keys, so it is checked separately.
				if (output === 'period') continue

				const actual = source[output] ?? output
				expect(
					available.has(actual),
					`${name}.${output} reads "${actual}", which ${location.path} does not send. Available: ${[...available].sort().join(', ')}`,
				).toBe(true)
			}
		})
	}

	it('never lists a derived field as something the store sends', () => {
		for (const report of acceptedReports()) {
			const location = READ_LOCATIONS[report.name]
			if (!location) continue
			const block = (shapeFor(location.path)?.object as Record<string, unknown> | undefined)?.[
				location.block
			]
			const available = fieldsOf(block)

			for (const derived of report.derivedFields ?? []) {
				// If the store starts sending it, stop computing it and read it instead.
				expect(
					available.has(derived),
					`${report.name}.${derived} is marked derived but the store now sends it`,
				).toBe(false)
			}
		}
	})

	it('gives the trend a label key the store actually sends', () => {
		const block = (shapeFor('/reports/revenue')?.object as Record<string, unknown> | undefined)
			?.revenueReport
		const available = fieldsOf(block)
		// salesTrend tries period, date, group, label in that order. At least one must exist, or
		// every bucket comes back unlabelled and the series cannot be plotted.
		const candidates = ['period', 'date', 'group', 'label']
		expect(
			candidates.some((key) => available.has(key)),
			`no usable bucket label; available: ${[...available].sort().join(', ')}`,
		).toBe(true)
	})
})
