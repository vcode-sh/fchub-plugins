import assert from 'node:assert/strict'
import { existsSync, readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { describe, it } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	buildLedger,
	CORE_FIXTURE,
	CURRENT_FIXTURE,
	compareRoutes,
	extractRiskRegistry,
	extractTools,
	OUTPUT_FILE,
	REVIEWED_ORPHAN_TOOL_ROUTES,
	safetyFor,
	TOOL_ROUTE_OVERRIDES,
	validateLedger,
} from '../../scripts/build-api-coverage.mjs'

const PACKAGE_ROOT = dirname(dirname(dirname(fileURLToPath(import.meta.url))))
const readJson = (relative) => JSON.parse(readFileSync(join(PACKAGE_ROOT, relative), 'utf8'))

const ledger = buildLedger()
const fixture = readJson(CURRENT_FIXTURE)
const coreFixture = readJson(CORE_FIXTURE)
const key = (operation) => `${operation.method} ${operation.path}`
const coreOperations = new Set(coreFixture.operations.map(key))

/** Deep clone so a negative test can corrupt a row without poisoning the shared ledger. */
const clone = (value) => JSON.parse(JSON.stringify(value))

describe('ledger completeness', () => {
	it('passes its own contract with no failures', () => {
		assert.deepEqual(validateLedger(ledger), [])
	})

	it('covers every fixture operation exactly once under its component profile', () => {
		const counts = new Map()
		for (const row of ledger.routes) {
			const id = `${row.component.slug} ${row.method} ${row.path}`
			counts.set(id, (counts.get(id) ?? 0) + 1)
		}

		for (const operation of fixture.operations) {
			const component = coreOperations.has(key(operation)) ? 'fluent-cart' : 'fluent-cart-pro'
			const id = `${component} ${key(operation)}`
			assert.equal(counts.get(id), 1, `${id} must appear exactly once`)
		}
		assert.equal(ledger.routes.length, fixture.operations.length)
	})

	it('records the canonical operation count the fixture claims', () => {
		assert.equal(ledger.routes.length, fixture.counts.applicationCanonicalPairs)
		assert.equal(ledger.routes.length, 386)
	})

	it('attributes the isolated 355-operation Core set and 31-operation Pro delta', () => {
		assert.equal(ledger.routes.filter((row) => row.component.slug === 'fluent-cart').length, 355)
		assert.equal(ledger.routes.filter((row) => row.component.slug === 'fluent-cart-pro').length, 31)
		for (const row of ledger.routes) {
			assert.equal(
				row.component.evidenceFixture,
				row.component.slug === 'fluent-cart' ? CORE_FIXTURE : CURRENT_FIXTURE,
			)
			assert.ok(existsSync(join(PACKAGE_ROOT, row.component.evidenceFixture)))
		}
	})

	it('states the isolated fixture-delta attribution', () => {
		assert.match(ledger.attribution, /355/)
		assert.match(ledger.attribution, /31/)
		assert.doesNotMatch(ledger.attribution, /NOT evidenced/)
	})

	it('separates the 1.3.9 contract from the current-runtime delta', () => {
		const origins = new Set(ledger.routes.map((row) => row.contractOrigin))
		assert.deepEqual([...origins].sort(), ['current-runtime', 'legacy-docs'])
		assert.equal(ledger.counts.deltaSince139, 61)
	})

	it('is sorted by component, then path, then method', () => {
		// Uses the generator's own exported comparator rather than a copy: a second implementation
		// here is exactly how the ledger and its contract drifted apart before.
		for (let index = 1; index < ledger.routes.length; index++) {
			const previous = ledger.routes[index - 1]
			const current = ledger.routes[index]
			assert.ok(
				compareRoutes(previous, current) < 0,
				`out of order: ${key(previous)} precedes ${key(current)}`,
			)
		}

		// And the whole array is already at its sorted fixed point.
		const resorted = [...ledger.routes].sort(compareRoutes)
		assert.deepEqual(
			resorted.map((row) => key(row)),
			ledger.routes.map((row) => key(row)),
		)
	})
})

describe('disposition contract', () => {
	it('gives every row a non-empty reason', () => {
		for (const row of ledger.routes) {
			assert.ok(row.reason.trim().length > 0, `${key(row)} has an empty reason`)
		}
	})

	it('leaves excluded routes with no tool exposure at all', () => {
		for (const row of ledger.routes.filter((r) => r.routeDisposition === 'excluded')) {
			assert.deepEqual(row.toolExposures, [], `${key(row)} is excluded but exposes tools`)
		}
	})

	it('gives every exposed route at least one tool exposure', () => {
		for (const row of ledger.routes.filter((r) => r.routeDisposition === 'exposed')) {
			assert.ok(row.toolExposures.length > 0, `${key(row)} is exposed with no tool`)
		}
	})

	it('uses only the two reviewed dispositions on a route', () => {
		for (const row of ledger.routes) {
			assert.ok(['exposed', 'excluded'].includes(row.routeDisposition))
		}
	})

	it('marks each tool exposure curated or dynamic, never excluded', () => {
		for (const row of ledger.routes) {
			for (const exposure of row.toolExposures) {
				assert.ok(['curated', 'dynamic'].includes(exposure.disposition))
			}
		}
	})
})

describe('evidence contract', () => {
	const exposed = ledger.routes.filter((row) => row.routeDisposition === 'exposed')

	it('gives every exposed route schema, permission and response evidence', () => {
		for (const row of exposed) {
			assert.ok(row.schemaEvidence.length > 0, `${key(row)} lacks schema evidence`)
			assert.ok(row.permissionEvidence.length > 0, `${key(row)} lacks permission evidence`)
			assert.ok(row.responseEvidence.length > 0, `${key(row)} lacks response evidence`)
		}
	})

	it('points every evidence path at a file that exists', () => {
		for (const row of exposed) {
			for (const entry of [
				...row.schemaEvidence,
				...row.permissionEvidence,
				...row.responseEvidence,
			]) {
				const [path] = entry.split('#')
				assert.ok(
					existsSync(join(PACKAGE_ROOT, path)),
					`${key(row)}: missing evidence file ${path}`,
				)
			}
		}
	})

	it('anchors schema evidence at the tool that declares it', () => {
		for (const row of exposed) {
			for (const entry of row.schemaEvidence) {
				const [path, anchor] = entry.split('#')
				assert.ok(anchor, `${key(row)}: schema evidence ${entry} has no anchor`)
				assert.match(readFileSync(join(PACKAGE_ROOT, path), 'utf8'), new RegExp(anchor))
			}
		}
	})

	it('anchors permission evidence at a route the fixture actually serves', () => {
		for (const row of exposed) {
			const anchored = row.permissionEvidence.find((entry) => entry.includes('#'))
			assert.ok(anchored, `${key(row)} has no anchored permission evidence`)
			const [, anchor] = anchored.split('#')
			assert.ok(fixture.operations.some((operation) => key(operation) === anchor))
		}
	})

	it('never cites the discontinued Stream plugin as evidence', () => {
		for (const row of ledger.routes) {
			for (const entry of [
				...row.schemaEvidence,
				...row.permissionEvidence,
				...row.responseEvidence,
			]) {
				assert.ok(!entry.includes('fchub-stream'), `${key(row)} cites Stream: ${entry}`)
			}
		}
	})
})

describe('risk contract', () => {
	const registry = extractRiskRegistry()

	it('never labels an accepted state-changing route as a read', () => {
		const unregistered = new Set(ledger.unregisteredReads.map((entry) => entry.publicName))
		for (const row of ledger.routes.filter((r) => r.routeDisposition === 'exposed')) {
			if (row.method === 'GET' || row.risk !== 'read') continue
			for (const exposure of row.toolExposures) {
				const reviewed = registry.get(exposure.publicName)?.risk
				assert.ok(
					reviewed === 'read' || unregistered.has(exposure.publicName),
					`${key(row)}: ${exposure.publicName} is exposed as a read with no reviewed read row`,
				)
			}
		}
	})

	it('names every POST-shaped read that relies on its own annotation instead of the registry', () => {
		for (const entry of ledger.unregisteredReads) {
			assert.ok(entry.reason.trim().length > 0)
			assert.ok(entry.owner)
			assert.ok(fixture.operations.some((operation) => key(operation) === entry.route))
		}
	})

	it('keeps real-money exposure to the two reviewed guarded actions', () => {
		const guarded = new Set(['fluentcart_order_refund', 'fluentcart_subscription_cancel'])
		for (const row of ledger.routes.filter((r) => r.routeDisposition === 'exposed')) {
			if (row.risk !== 'real-money') continue
			for (const exposure of row.toolExposures) {
				assert.ok(
					guarded.has(exposure.publicName),
					`${key(row)} exposes real-money tool ${exposure.publicName}`,
				)
			}
		}
	})
})

describe('tool and route integrity', () => {
	const tools = extractTools()

	it('extracts a REST route for every tool', () => {
		const routeless = tools.filter((tool) => tool.routes.length === 0).map((tool) => tool.name)
		assert.deepEqual(routeless, [], 'every tool must resolve to at least one route')
	})

	it('has no duplicate tool names', () => {
		const names = tools.map((tool) => tool.name)
		assert.deepEqual(
			names.filter((name, index) => names.indexOf(name) !== index),
			[],
		)
	})

	it('reviews every orphan tool route and says which kind it is', () => {
		for (const orphan of ledger.orphanToolRoutes) {
			assert.ok(
				REVIEWED_ORPHAN_TOOL_ROUTES[orphan.route],
				`unreviewed orphan tool route: ${orphan.route}`,
			)
			assert.ok(['compatibility-fallback', 'removed-route-defect'].includes(orphan.kind))
			assert.ok(orphan.reason.trim().length > 0)
			assert.ok(orphan.tools.length > 0)
		}
	})

	it('keeps a served preferred variant behind every compatibility fallback', () => {
		const served = new Set(fixture.operations.map(key))
		for (const orphan of ledger.orphanToolRoutes.filter(
			(o) => o.kind === 'compatibility-fallback',
		)) {
			assert.ok(
				served.has(orphan.preferred),
				`${orphan.route} falls back with no served preferred route`,
			)
		}
	})

	it('requires a complete served route list and guard evidence for guarded execution', () => {
		const registry = extractRiskRegistry()
		const served = new Set(fixture.operations.map(key))
		const guardedTools = tools.filter(
			(tool) => safetyFor(tool, registry).execution === 'guarded-rest',
		)

		// 2.0.0 ships guarded execution withdrawn, so this set is legitimately empty. The rule
		// must keep working for the release that restores it, so it is exercised against a
		// synthetic tool as well: a validator that stops being tested the moment nothing matches
		// is a validator that quietly rots until the day it is needed.
		const synthetic = {
			name: 'synthetic_guarded_probe',
			routes: [{ method: 'POST', path: '/orders/{param}/refund' }],
		}
		for (const route of synthetic.routes) {
			assert.ok(
				served.has(key(route)),
				`the synthetic guarded probe must claim a served route, got ${key(route)}`,
			)
		}
		const unserved = { method: 'POST', path: '/orders/{param}/no-such-route' }
		assert.equal(served.has(key(unserved)), false, 'an unserved route must not validate')

		for (const tool of guardedTools) {
			assert.ok(tool.routes.length > 0, `${tool.name} has no route list`)
			for (const route of tool.routes) {
				assert.ok(served.has(key(route)), `${tool.name} claims unserved route ${key(route)}`)
			}
		}
	})
})

describe('the checked-in ledger is current', () => {
	it('matches a fresh generation byte for byte', () => {
		const onDisk = readFileSync(join(PACKAGE_ROOT, OUTPUT_FILE), 'utf8')
		assert.equal(onDisk, `${JSON.stringify(ledger, null, '\t')}\n`)
	})
})

describe('the validator actually rejects bad ledgers', () => {
	it('rejects an excluded route that still exposes a tool', () => {
		const broken = clone(ledger)
		const row = broken.routes.find((r) => r.routeDisposition === 'excluded')
		row.toolExposures = [{ publicName: 'fluentcart_order_list', disposition: 'dynamic' }]
		assert.ok(validateLedger(broken).some((f) => f.includes('empty toolExposures')))
	})

	it('rejects an exposed route with no tool exposure', () => {
		const broken = clone(ledger)
		broken.routes.find((r) => r.routeDisposition === 'exposed').toolExposures = []
		assert.ok(validateLedger(broken).some((f) => f.includes('at least one tool exposure')))
	})

	it('rejects an empty reason', () => {
		const broken = clone(ledger)
		broken.routes[0].reason = '   '
		assert.ok(validateLedger(broken).some((f) => f.includes('empty reason')))
	})

	it('rejects evidence pointing into the Stream plugin', () => {
		const broken = clone(ledger)
		const row = broken.routes.find((r) => r.routeDisposition === 'exposed')
		row.schemaEvidence = ['../fchub-stream/app/Http/Controllers/StreamController.php']
		assert.ok(validateLedger(broken).some((f) => f.includes('Stream plugin')))
	})

	it('rejects evidence that does not exist on disk', () => {
		const broken = clone(ledger)
		broken.routes.find((r) => r.routeDisposition === 'exposed').schemaEvidence = [
			'src/tools/imaginary.ts',
		]
		assert.ok(validateLedger(broken).some((f) => f.includes('does not exist')))
	})

	it('rejects a permission anchor the fixture does not serve', () => {
		const broken = clone(ledger)
		const row = broken.routes.find((r) => r.routeDisposition === 'exposed')
		row.permissionEvidence = [`${CURRENT_FIXTURE}#GET /invented-route`]
		assert.ok(validateLedger(broken).some((f) => f.includes('not present in')))
	})

	it('rejects a missing fixture operation', () => {
		const broken = clone(ledger)
		broken.routes.shift()
		assert.ok(validateLedger(broken).some((f) => f.includes('missing from ledger')))
	})

	it('rejects a duplicate ledger row', () => {
		const broken = clone(ledger)
		broken.routes.push(clone(broken.routes[0]))
		assert.ok(validateLedger(broken).some((f) => f.includes('duplicate ledger row')))
	})

	it('rejects an unreviewed orphan tool route', () => {
		const broken = clone(ledger)
		broken.orphanToolRoutes.push({
			route: 'GET /not-reviewed',
			tools: ['fluentcart_made_up'],
			kind: 'unreviewed',
		})
		assert.ok(validateLedger(broken).some((f) => f.includes('unreviewed orphan')))
	})

	it('rejects a duplicate tool name', () => {
		const tools = extractTools()
		const failures = validateLedger(ledger, { tools: [...tools, tools[0]] })
		assert.ok(failures.some((f) => f.includes('duplicate tool name')))
	})

	it('rejects a tool that resolves to no route', () => {
		const tools = extractTools()
		const failures = validateLedger(ledger, {
			tools: [
				...tools,
				{
					name: 'fluentcart_ghost',
					sourceFile: 'src/tools/ghost.ts',
					routes: [],
					readOnlyHint: true,
				},
			],
		})
		assert.ok(failures.some((f) => f.includes('yields no REST route')))
	})

	it('rejects a guarded tool with no guard evidence', () => {
		const registry = extractRiskRegistry()
		registry.set('fluentcart_unguarded', {
			risk: 'real-money',
			idempotency: 'guard-required',
			execution: 'guarded-rest',
		})
		const failures = validateLedger(ledger, {
			registry,
			tools: [
				...extractTools(),
				{
					name: 'fluentcart_unguarded',
					sourceFile: 'src/tools/ghost.ts',
					readOnlyHint: false,
					routes: [{ method: 'POST', path: '/orders/{param}/refund' }],
				},
			],
		})
		assert.ok(failures.some((f) => f.includes('no guard evidence')))
	})

	it('rejects a guarded tool claiming a route the store does not serve', () => {
		// 2.0.0 withdraws guarded execution, so no real tool carries `guarded-rest` any more. The
		// rule still has to work for the release that restores it, so the registry is overridden
		// here to make refund guarded again for the duration of this assertion. Testing the rule
		// only while a matching tool happens to exist would retire the check silently.
		const registry = new Map(extractRiskRegistry())
		registry.set('fluentcart_order_refund', {
			risk: 'real-money',
			idempotency: 'guard-required',
			execution: 'guarded-rest',
		})
		const tools = extractTools().map((tool) =>
			tool.name === 'fluentcart_order_refund'
				? {
						...tool,
						routes: [...tool.routes, { method: 'POST', path: '/orders/{param}/unserved' }],
					}
				: tool,
		)
		assert.ok(validateLedger(ledger, { registry, tools }).some((f) => f.includes('unserved route')))
	})
})

/**
 * An override restates routes the source reader cannot evaluate, which means it is a copy — and
 * a copy of a declaration is only safe while something proves the two still agree. Each entry
 * names its `source` as `path/to/file.ts#CONSTANT`; this reads that constant back out of the
 * source and compares it with the override, so an edit to either side fails here rather than
 * silently teaching the ledger a route the tool no longer calls.
 */
describe('reviewed route overrides match their source constants', () => {
	// An override restates routes the source reader cannot evaluate, so it is a copy — and a copy
	// of a declaration is safe only while something proves the two still agree. Every symbol named
	// in `source` must exist in the file it names, which catches a moved or renamed constant. When
	// a symbol is a literal array of route objects the contents are compared too; a symbol built
	// from an expression cannot be read statically, and is checked for existence alone rather than
	// pretended to be verified.
	const routesInConstant = (text, symbol) => {
		const declared = new RegExp(`\\b${symbol}\\b[^=\\n]*=\\s*\\[`).exec(text)
		if (!declared) return null

		const open = declared.index + declared[0].length - 1
		let depth = 0
		let end = open
		for (; end < text.length; end++) {
			if (text[end] === '[') depth++
			else if (text[end] === ']' && --depth === 0) break
		}

		return [
			...text
				.slice(open, end + 1)
				.matchAll(/method:\s*'(GET|POST|PUT|PATCH|DELETE)'\s*,\s*path:\s*'([^']+)'/g),
		].map((match) => `${match[1]} ${match[2]}`)
	}

	for (const [tool, override] of Object.entries(TOOL_ROUTE_OVERRIDES)) {
		it(`${tool} still points at a live source`, () => {
			const [file, symbolList] = override.source.split('#')
			assert.ok(existsSync(join(PACKAGE_ROOT, file)), `${override.source} names a missing file`)

			const text = readFileSync(join(PACKAGE_ROOT, file), 'utf8')
			const symbols = (symbolList ?? '').split(',').filter(Boolean)
			assert.ok(symbols.length > 0, `${tool} override names no symbol`)

			const collected = []
			for (const symbol of symbols) {
				assert.match(
					text,
					new RegExp(`\\b${symbol}\\b`),
					`${symbol} is absent from ${file}; the override points at a constant that moved`,
				)
				const routes = routesInConstant(text, symbol)
				if (routes) collected.push(...routes)
			}

			// Only assert equality when every named symbol was readable as a literal route array.
			if (collected.length === 0) return

			const fromOverride = override.routes.map((route) => `${route.method} ${route.path}`)
			assert.deepEqual(
				[...collected].sort(),
				[...fromOverride].sort(),
				`${tool} override has drifted from ${override.source}`,
			)
		})
	}
})
