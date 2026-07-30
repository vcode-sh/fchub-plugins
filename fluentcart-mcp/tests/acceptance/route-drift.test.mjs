// Live route drift against the checked fixture.
//
// The fixture is evidence, not a target. This lane captures the store's REST index again right
// now, compares both the exact and the canonical sets, and names every added or removed pair. It
// never writes the fixture back: a drift detector that repairs its own baseline detects nothing.

import assert from 'node:assert/strict'
import { createHash } from 'node:crypto'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { before, describe, it } from 'node:test'
import { pathToFileURL } from 'node:url'
import { PACKAGE_ROOT, resolveFixture } from '../../scripts/acceptance/evidence-writer.mjs'
import { assertAllowedLiveTarget } from '../../scripts/live-target-policy.mjs'

const DEFAULT_FIXTURE = 'tests/fixtures/routes/fluentcart-1.6.0-core-pro-1.6.0.json'
const FIXTURE_PATH = resolveFixture(process.env.FLUENTCART_ACCEPTANCE_FIXTURE ?? DEFAULT_FIXTURE)
const FETCH_TIMEOUT_MS = 15_000

/**
 * Drift accepted by a reviewer, each entry naming the component that owns the pair. Empty means
 * every pair in the live store is already in the fixture and vice versa. An unexplained addition
 * or removal fails this lane; it does not quietly land here.
 */
const ACCEPTED_DRIFT = []

const fixture = JSON.parse(readFileSync(FIXTURE_PATH, 'utf8'))
const fixtureDigestBefore = createHash('sha256').update(readFileSync(FIXTURE_PATH)).digest('hex')

let live

/**
 * Resolve the target for the public route index.
 *
 * This lane needs a store URL and nothing else — `/wp-json/` is a public document. It therefore
 * opens no credential file at all: scripts/run-live-tests.mjs is the single credential-loading
 * entry point in this repository, and a second reader would quietly become a second one.
 * The URL arrives through the environment (the launcher exports it). There is deliberately no
 * loopback default: the deterministic acceptance lane must not become a live test merely because
 * a developer happens to have WordPress running.
 */
function liveTargetUrl() {
	return assertAllowedLiveTarget(process.env.FLUENTCART_URL, process.env)
}

const liveTarget = process.env.FLUENTCART_URL ? liveTargetUrl() : null

/** The REST root index is a public document, so this request carries no credential. */
async function fetchRestIndex(target) {
	const url = new URL('/wp-json/', target)
	const response = await fetch(url, {
		headers: { Accept: 'application/json' },
		redirect: 'error',
		signal: AbortSignal.timeout(FETCH_TIMEOUT_MS),
	})
	assert.ok(response.ok, `${target.origin} returned HTTP ${response.status} for /wp-json/`)
	return response.json()
}

/**
 * Reduce a REST index exactly as `scripts/capture-route-fixture.mjs` does, through the same
 * canonicaliser the running server uses, so a difference here is a store difference and never a
 * disagreement about what a route is called.
 */
function methodsOf(document, path, isHttpMethod) {
	const methods = []
	for (const endpoint of document.routes[path]?.endpoints ?? []) {
		for (const method of endpoint?.methods ?? []) {
			if (isHttpMethod(method)) methods.push(method)
		}
	}
	return methods
}

function reduceIndex(document, normaliser) {
	const { canonicaliseRoute, isFluentCartRoute, isHttpMethod, isNamespaceRoot, sortOperations } =
		normaliser
	const prefixed = Object.keys(document.routes ?? {}).filter(isFluentCartRoute)
	assert.ok(prefixed.length > 0, 'the live store exposes no fluent-cart/v2 routes')

	const exactInclusive = new Set()
	const applicationExact = new Set()
	const byCanonical = new Map()

	for (const path of prefixed) {
		for (const method of methodsOf(document, path, isHttpMethod)) {
			exactInclusive.add(`${method} ${path}`)
			if (isNamespaceRoot(path)) continue
			applicationExact.add(`${method} ${path}`)

			const canonical = `${method} ${canonicaliseRoute(path)}`
			if (!byCanonical.has(canonical)) {
				byCanonical.set(canonical, { method, path: canonicaliseRoute(path), exact: new Set() })
			}
			byCanonical.get(canonical).exact.add(`${method} ${path}`)
		}
	}

	const operations = sortOperations(
		[...byCanonical.values()].map(({ method, path }) => ({ method, path })),
	)

	return {
		operations,
		applicationExact,
		canonicalCollapses: [...byCanonical.entries()]
			.filter(([, entry]) => entry.exact.size > 1)
			.map(([canonical, entry]) => ({ canonical, exact: [...entry.exact].sort() }))
			.sort((left, right) => (left.canonical < right.canonical ? -1 : 1)),
		counts: {
			prefixedPathsInclusive: prefixed.length,
			exactPairsInclusive: exactInclusive.size,
			applicationPaths: prefixed.filter((path) => !isNamespaceRoot(path)).length,
			applicationExactPairs: applicationExact.size,
			applicationCanonicalPairs: operations.length,
		},
	}
}

before(async () => {
	if (!liveTarget) return
	const normaliser = await import(
		pathToFileURL(resolve(PACKAGE_ROOT, 'dist/api/route-normalisation.js')).href
	)
	live = reduceIndex(await fetchRestIndex(liveTarget), normaliser)
})

const key = (operation) => `${operation.method} ${operation.path}`

describe('checked fixture counts', () => {
	it('uses the exact fixture selected by the acceptance harness', () => {
		const selected = process.env.FLUENTCART_ACCEPTANCE_FIXTURE
		const expected = selected ? resolveFixture(selected) : resolve(PACKAGE_ROOT, DEFAULT_FIXTURE)
		assert.equal(FIXTURE_PATH, expected)
	})

	it('records 339 paths and 398 exact pairs including the namespace root', () => {
		assert.equal(fixture.counts.prefixedPathsInclusive, 339)
		assert.equal(fixture.counts.exactPairsInclusive, 398)
	})

	it('records 338 application paths and 397 exact application pairs', () => {
		assert.equal(fixture.counts.applicationPaths, 338)
		assert.equal(fixture.counts.applicationExactPairs, 397)
	})

	/**
	 * The previous fixture's planning prose says 387 canonical pairs. It cannot: the store registers the customer
	 * detail route under both a numeric and a catch-all pattern, and the same plan mandates that
	 * both canonicalise to one operation. One collapse turns 397 exact pairs into 396 canonical
	 * ones, and the fixture names that collapse rather than adjusting a number to fit.
	 */
	it('records 396 canonical pairs, reconciled by exactly one mandated collapse', () => {
		assert.equal(fixture.counts.applicationCanonicalPairs, 396)
		assert.equal(fixture.operations.length, 396)
		assert.equal(fixture.canonicalCollapses.length, 1)
		assert.equal(fixture.canonicalCollapses[0].canonical, 'GET /customers/{param}')
		assert.deepEqual(fixture.canonicalCollapses[0].exact, [
			'GET /fluent-cart/v2/customers/(?P<customerId>[0-9]+)',
			'GET /fluent-cart/v2/customers/(?P<customerId>[^\\s(?!/)]+)',
		])
		assert.equal(
			fixture.counts.applicationExactPairs - fixture.canonicalCollapses[0].exact.length + 1,
			fixture.counts.applicationCanonicalPairs,
		)
	})

	it('names the runtime it was captured from', () => {
		const versions = new Map(
			fixture.profile.activeComponents.map((component) => [component.slug, component.version]),
		)
		assert.equal(fixture.profile.wordpress, '7.0.2')
		assert.equal(versions.get('fluent-cart'), '1.6.0')
		assert.equal(versions.get('fluent-cart-pro'), '1.6.0')
		assert.equal(fixture.evidenceKind, 'live-rest-index')
	})
})

describe('live capture', { skip: liveTarget ? false : 'BLOCKED: FLUENTCART_URL' }, () => {
	it('reproduces the inclusive and application-only counts', (t) => {
		t.diagnostic(
			`live: ${live.counts.prefixedPathsInclusive} paths, ${live.counts.exactPairsInclusive} inclusive pairs, ${live.counts.applicationExactPairs} exact, ${live.counts.applicationCanonicalPairs} canonical`,
		)
		assert.deepEqual(live.counts, fixture.counts)
	})

	it('collapses the same single pair as the fixture', () => {
		assert.deepEqual(live.canonicalCollapses, fixture.canonicalCollapses)
	})

	it('excludes the namespace root from the application counts', () => {
		assert.equal(live.counts.prefixedPathsInclusive - live.counts.applicationPaths, 1)
		assert.equal(live.counts.exactPairsInclusive - live.counts.applicationExactPairs, 1)
	})
})

describe('drift', { skip: liveTarget ? false : 'BLOCKED: FLUENTCART_URL' }, () => {
	it('adds no canonical operation without an accepted explanation', () => {
		const known = new Set(fixture.operations.map(key))
		const added = live.operations.map(key).filter((pair) => !known.has(pair))
		const unexplained = added.filter((pair) => !ACCEPTED_DRIFT.includes(pair))
		assert.deepEqual(unexplained, [], 'unexplained added routes; assign each an owning component')
	})

	it('removes no canonical operation without an accepted explanation', () => {
		const present = new Set(live.operations.map(key))
		const removed = fixture.operations.map(key).filter((pair) => !present.has(pair))
		const unexplained = removed.filter((pair) => !ACCEPTED_DRIFT.includes(pair))
		assert.deepEqual(unexplained, [], 'unexplained removed routes; the fixture may be stale')
	})

	it('reconciles the exact set too, not merely the canonical one', () => {
		// A canonical path cannot be turned back into the regular expression it came from, so the
		// exact set is reconciled by count and by the collapse that explains the difference.
		assert.equal(live.applicationExact.size, fixture.counts.applicationExactPairs)
		const collapsed = live.canonicalCollapses.reduce(
			(total, entry) => total + entry.exact.length - 1,
			0,
		)
		assert.equal(
			live.counts.applicationExactPairs - collapsed,
			live.counts.applicationCanonicalPairs,
		)
		assert.deepEqual(
			live.canonicalCollapses.flatMap((entry) => entry.exact),
			fixture.canonicalCollapses.flatMap((entry) => entry.exact),
		)
	})

	it('carries no accepted drift today, so the fixture is current', () => {
		assert.deepEqual(ACCEPTED_DRIFT, [])
	})
})

describe('fixture immutability', () => {
	it('leaves the checked fixture byte-identical', () => {
		const after = createHash('sha256').update(readFileSync(FIXTURE_PATH)).digest('hex')
		assert.equal(after, fixtureDigestBefore, 'this lane must never rewrite its own baseline')
	})
})
