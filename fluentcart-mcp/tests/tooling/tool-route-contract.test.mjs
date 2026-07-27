import assert from 'node:assert/strict'
import { existsSync } from 'node:fs'
import { join } from 'node:path'
import { before, describe, it } from 'node:test'
import {
	checkCompatibility,
	checkRoutes,
	checkToolRoutes,
	PACKAGE_ROOT,
	readJson,
	SUPPORT_PATH,
	unresolvedPlaceholder,
} from '../../scripts/check-tool-routes.mjs'

const CURRENT_FIXTURE = 'tests/fixtures/routes/fluentcart-1.5.5-core-pro-1.5.4.json'

/** A minimal fixture index: two paths, one of which is served by GET only. */
const INDEX = {
	exact: new Set(['GET /orders', 'GET /orders/{param}', 'POST /orders']),
	byPath: new Map([
		['/orders', new Set(['GET', 'POST'])],
		['/orders/{param}', new Set(['GET'])],
	]),
}

function tool(route, risk = 'read') {
	return { name: 'fluentcart_probe', safety: { risk }, route }
}

function rules(result) {
	return result.failures.map((failure) => failure.rule)
}

describe('placeholder resolution', () => {
	it('accepts the canonical parameter form', () => {
		assert.equal(unresolvedPlaceholder('/orders/{param}/transactions'), null)
		assert.equal(unresolvedPlaceholder('/orders'), null)
	})

	it('rejects a named, express-style or templated placeholder', () => {
		assert.equal(unresolvedPlaceholder('/orders/{orderId}'), '{orderId}')
		assert.equal(unresolvedPlaceholder('/orders/{}'), '{}')
		assert.equal(unresolvedPlaceholder('/orders/:id'), ':id')
		// The defect under test: an endpoint string that reached the registry still carrying an
		// unsubstituted template expression.
		// biome-ignore lint/suspicious/noTemplateCurlyInString: asserting on that literal
		assert.equal(unresolvedPlaceholder('/orders/${id}'), 'template expression')
	})
})

describe('tool route contract', () => {
	it('accepts a direct tool whose single route the fixture serves', () => {
		const result = checkToolRoutes(tool({ routes: [{ method: 'GET', path: '/orders' }] }), INDEX)
		assert.deepEqual(result.failures, [])
		assert.equal(result.kind, 'direct')
	})

	it('rejects a tool with no route metadata at all', () => {
		assert.deepEqual(rules(checkToolRoutes(tool(undefined), INDEX)), ['missing-route-metadata'])
	})

	it('rejects a tool that declares neither a route nor an unsupported status', () => {
		assert.deepEqual(rules(checkToolRoutes(tool({ routes: [] }), INDEX)), ['no-route-declared'])
	})

	it('rejects a direct endpoint absent from the selected fixture', () => {
		const result = checkToolRoutes(tool({ routes: [{ method: 'GET', path: '/nowhere' }] }), INDEX)
		assert.deepEqual(rules(result), ['absent-from-fixture'])
	})

	it('rejects a correct path with the wrong method', () => {
		const result = checkToolRoutes(
			tool({ routes: [{ method: 'DELETE', path: '/orders/{param}' }] }),
			INDEX,
		)
		assert.deepEqual(rules(result), ['method-mismatch'])
		assert.match(result.failures[0].detail, /fixture has GET/)
	})

	it('rejects an unresolved endpoint placeholder before trying to match it', () => {
		const result = checkToolRoutes(
			tool({ routes: [{ method: 'GET', path: '/orders/{orderId}' }] }),
			INDEX,
		)
		assert.deepEqual(rules(result), ['unresolved-placeholder'])
	})

	it('rejects a write with more than one execution-time fallback route', () => {
		const route = {
			routes: [
				{ method: 'POST', path: '/orders' },
				{ method: 'GET', path: '/orders' },
			],
		}
		const result = checkToolRoutes(tool(route, 'reversible-write'), INDEX)
		assert.ok(rules(result).includes('write-fallback-routes'))
	})

	it('rejects a multi-route read that never declared itself composite', () => {
		const route = {
			routes: [
				{ method: 'GET', path: '/orders' },
				{ method: 'GET', path: '/orders/{param}' },
			],
		}
		assert.ok(rules(checkToolRoutes(tool(route), INDEX)).includes('undeclared-composite'))
	})

	it('accepts a composite tool that declares the flag and lists every route', () => {
		const route = {
			composite: true,
			routes: [
				{ method: 'GET', path: '/orders' },
				{ method: 'GET', path: '/orders/{param}' },
			],
		}
		const result = checkToolRoutes(tool(route), INDEX)
		assert.deepEqual(result.failures, [])
		assert.equal(result.kind, 'composite')
	})

	it('rejects a composite declaration with fewer than two routes', () => {
		const route = { composite: true, routes: [{ method: 'GET', path: '/orders' }] }
		assert.ok(rules(checkToolRoutes(tool(route), INDEX)).includes('composite-without-routes'))
	})

	it('accepts an explicitly unsupported tool but not one that still lists routes', () => {
		assert.deepEqual(checkToolRoutes(tool({ unsupported: true, routes: [] }), INDEX).failures, [])
		const contradictory = tool({ unsupported: true, routes: [{ method: 'GET', path: '/orders' }] })
		assert.deepEqual(rules(checkToolRoutes(contradictory, INDEX)), ['unsupported-with-routes'])
	})
})

describe('registry route report', () => {
	let report

	before(async () => {
		report = await checkRoutes(CURRENT_FIXTURE)
	})

	it('reports registered, omitted, direct, composite and unsupported counts with names', () => {
		for (const key of ['registered', 'omitted', 'direct', 'composite', 'unsupported']) {
			assert.equal(typeof report.counts[key], 'number', `missing count: ${key}`)
			assert.ok(Array.isArray(report.tools[key]), `missing name list: ${key}`)
			assert.equal(
				report.counts[key],
				report.tools[key].length,
				`${key} count disagrees with its names`,
			)
		}
		assert.ok(report.counts.registered > 0, 'no tool was registered at all')
		assert.deepEqual(report.tools.registered, [...report.tools.registered].sort())
	})

	it('names the fixture it judged the registry against', () => {
		assert.equal(report.fixture, CURRENT_FIXTURE)
		assert.equal(report.evidenceKind, 'live-rest-index')
		assert.equal(report.fixtureOperations, 386)
	})

	/**
	 * RED until the ToolRouteMetadata migration lands. Every tool currently fails with
	 * `missing-route-metadata`, which is the correct reading of the contract: a registry that
	 * records no routes has not proven a single endpoint, and pretending otherwise is the exact
	 * failure this checker exists to prevent.
	 */
	it('holds every source tool to a declared, fixture-backed route', () => {
		const byRule = new Map()
		for (const failure of report.failures) {
			byRule.set(failure.rule, (byRule.get(failure.rule) ?? 0) + 1)
		}
		const summary = [...byRule].map(([rule, count]) => `${rule}=${count}`).join(', ')
		assert.deepEqual(report.failures, [], `route contract violations: ${summary}`)
	})
})

describe('compatibility support gate', () => {
	let result
	let support

	before(() => {
		support = readJson(SUPPORT_PATH)
		result = checkCompatibility()
	})

	it('records the legacy runtime as an explicit, reviewed status', () => {
		assert.ok(['tested', 'docs-contract-only'].includes(support.legacyRuntime))
	})

	it('always judges the docs contract, core-only, core+Pro and all-active profiles', () => {
		const judged = result.profiles
			.filter((profile) => profile.status !== 'SKIPPED')
			.map((profile) => profile.id)

		for (const id of [
			'docs-contract-1.3.9',
			'current-core-only-1.5.5',
			'isolated-core-pro-1.5.5-1.5.4',
			'all-active-local',
		]) {
			assert.ok(judged.includes(id), `${id} was not judged`)
		}
	})

	it('checks the legacy runtime fixture only when the status is tested', () => {
		const legacy = result.profiles.find((profile) => profile.id === 'legacy-runtime-1.3.9')
		if (support.legacyRuntime === 'tested') {
			assert.notEqual(legacy.status, 'SKIPPED')
			return
		}
		assert.equal(legacy.status, 'SKIPPED')
		assert.deepEqual(result.legacyClaims, [], 'legacy runtime support is claimed but never proven')
	})

	it('blocks a profile whose fixture was never captured, rather than assuming it', () => {
		const missing = support.profiles.filter(
			(profile) =>
				profile.checkedWhen === undefined && !existsSync(join(PACKAGE_ROOT, profile.fixture)),
		)
		for (const profile of missing) {
			const judged = result.profiles.find((entry) => entry.id === profile.id)
			assert.equal(judged.status, 'BLOCKED', `${profile.id} has no fixture but was not blocked`)
			assert.ok(judged.remediation, `${profile.id} is blocked without telling anyone how to fix it`)
		}
		assert.equal(result.ok, missing.length === 0 && result.legacyClaims.length === 0)
	})

	it('recomputes the specification delta rather than trusting the recorded numbers', () => {
		assert.equal(result.specificationDelta.status, 'MEASURED')
		assert.equal(result.specificationDelta.retained, 325)
		assert.equal(result.specificationDelta.stale, 17)
		assert.equal(result.specificationDelta.currentAbsentFromSpecification, 61)
		assert.deepEqual(result.specificationDelta.problems, [])
	})
})
