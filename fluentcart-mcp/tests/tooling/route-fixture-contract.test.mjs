import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { describe, it } from 'node:test'
import { fileURLToPath } from 'node:url'

const packageRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..')
const fixturePath = resolve(
	packageRoot,
	'tests/fixtures/routes/fluentcart-1.6.0-core-pro-1.6.0.json',
)
const fixture = JSON.parse(readFileSync(fixturePath, 'utf8'))
const serialised = JSON.stringify(fixture)

const ALLOWED_METHODS = new Set(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'])

describe('route fixture provenance', () => {
	it('declares its schema version, evidence kind and source', () => {
		assert.equal(fixture.schemaVersion, 1)
		assert.equal(fixture.evidenceKind, 'live-rest-index')
		assert.equal(fixture.source, 'sanitised-local-runtime')
	})

	it('records when it was captured', () => {
		assert.match(fixture.capturedAt, /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/)
		assert.ok(Number.isFinite(Date.parse(fixture.capturedAt)))
	})

	it('records the WordPress version and both FluentCart components', () => {
		assert.equal(fixture.profile.wordpress, '7.0.2')

		const versions = new Map(
			fixture.profile.activeComponents.map((component) => [component.slug, component.version]),
		)
		assert.equal(versions.get('fluent-cart'), '1.6.0')
		assert.equal(versions.get('fluent-cart-pro'), '1.6.0')
	})

	it('records every active component with a version, and none twice', () => {
		const slugs = fixture.profile.activeComponents.map((component) => component.slug)

		assert.ok(slugs.length > 0, 'a runtime fixture must name what was running')
		assert.equal(new Set(slugs).size, slugs.length, 'a component may appear only once')

		// This fixture is now an ISOLATED capture: an ephemeral project running FluentCart core
		// and Pro and nothing else. That is the point of it — the previous capture ran on the
		// development site with nine unrelated plugins active, so no route in it was provable
		// as core+Pro. Pinning the exact pair keeps the isolation honest: a recapture that
		// quietly picks up another plugin fails here rather than silently inflating the counts.
		assert.deepEqual(
			slugs.slice().sort(),
			['fluent-cart', 'fluent-cart-pro'],
			`an isolated core+Pro capture must record exactly those two, found: ${slugs.join(', ')}`,
		)

		for (const component of fixture.profile.activeComponents) {
			assert.match(component.version, /^\d+(\.\d+)*(-[0-9A-Za-z.-]+)?$/, component.slug)
		}
	})

	it('is a core+Pro capture, not core-only and not a documentation contract', () => {
		const slugs = fixture.profile.activeComponents.map((component) => component.slug)

		assert.ok(slugs.includes('fluent-cart'))
		assert.ok(slugs.includes('fluent-cart-pro'))
		assert.notEqual(fixture.evidenceKind, 'docs-contract')
	})
})

describe('route fixture headline counts', () => {
	// Measured directly from the live REST index of the captured runtime. These are captured
	// evidence: if a recapture moves them, the store changed and the delta needs explaining.
	it('counts 339 FluentCart paths including the namespace root', () => {
		assert.equal(fixture.counts.prefixedPathsInclusive, 339)
	})

	it('counts 398 exact method/path pairs including the namespace root', () => {
		assert.equal(fixture.counts.exactPairsInclusive, 398)
	})

	it('counts 338 application paths once the namespace root is excluded', () => {
		assert.equal(fixture.counts.applicationPaths, 338)
		assert.equal(fixture.counts.prefixedPathsInclusive - fixture.counts.applicationPaths, 1)
	})

	it('counts 397 exact application pairs', () => {
		assert.equal(fixture.counts.applicationExactPairs, 397)
		assert.equal(fixture.counts.exactPairsInclusive - fixture.counts.applicationExactPairs, 1)
	})

	/**
	 * The planning document asserts 387 canonical pairs alongside 387 exact pairs. That cannot
	 * hold at the same time as the deduplication rule the same document mandates: the store
	 * registers `customers/(?P<customerId>[0-9]+)` and `customers/(?P<customerId>[^\s(?!/)]+)`
	 * as two patterns, and both must canonicalise to one `GET /customers/{param}` operation.
	 * One collapse, so 397 exact pairs necessarily yield 396 canonical ones.
	 *
	 * The next test pins the identity of that single collapse, so this number is evidence
	 * rather than an adjustment made to get a passing run.
	 */
	it('counts 396 canonical application pairs after the mandated deduplication', () => {
		assert.equal(fixture.counts.applicationCanonicalPairs, 396)
		assert.equal(fixture.operations.length, 396)
	})

	it('collapses exactly one pair, and it is the documented customers pair', () => {
		assert.equal(fixture.canonicalCollapses.length, 1)

		const [collapse] = fixture.canonicalCollapses
		assert.equal(collapse.canonical, 'GET /customers/{param}')
		assert.deepEqual(collapse.exact, [
			'GET /fluent-cart/v2/customers/(?P<customerId>[0-9]+)',
			'GET /fluent-cart/v2/customers/(?P<customerId>[^\\s(?!/)]+)',
		])
	})

	it('reconciles the exact and canonical counts through that collapse alone', () => {
		const collapsed = fixture.canonicalCollapses.reduce(
			(total, entry) => total + entry.exact.length - 1,
			0,
		)

		assert.equal(
			fixture.counts.applicationExactPairs - collapsed,
			fixture.counts.applicationCanonicalPairs,
		)
	})
})

describe('route fixture operations', () => {
	it('uses only the five discoverable methods', () => {
		for (const operation of fixture.operations) {
			assert.ok(ALLOWED_METHODS.has(operation.method), `unexpected method ${operation.method}`)
		}
	})

	it('carries nothing beyond a method and a canonical path', () => {
		for (const operation of fixture.operations) {
			assert.deepEqual(Object.keys(operation).sort(), ['method', 'path'])
		}
	})

	it('contains no unresolved regular expression syntax', () => {
		for (const { path } of fixture.operations) {
			assert.ok(path.startsWith('/'), `path is not rooted: ${path}`)
			assert.doesNotMatch(path, /\(\?P</, path)
			assert.doesNotMatch(path, /[()\\[\]$^+]/, path)
		}
	})

	it('names every path parameter {param}', () => {
		for (const { path } of fixture.operations) {
			for (const placeholder of path.match(/\{[^}]*\}/g) ?? []) {
				assert.equal(placeholder, '{param}')
			}
		}
	})

	it('excludes the namespace root, which is not an application operation', () => {
		for (const { path } of fixture.operations) {
			assert.notEqual(path, '/')
			assert.doesNotMatch(path, /^\/fluent-cart\/v2/, 'the namespace prefix must be stripped')
		}
	})

	it('is deduplicated', () => {
		const keys = fixture.operations.map((operation) => `${operation.method} ${operation.path}`)
		assert.equal(new Set(keys).size, keys.length)
	})

	it('is sorted by path then method, so a recapture produces a readable diff', () => {
		const sorted = [...fixture.operations].sort((left, right) => {
			if (left.path !== right.path) return left.path < right.path ? -1 : 1
			if (left.method !== right.method) return left.method < right.method ? -1 : 1
			return 0
		})

		assert.deepEqual(fixture.operations, sorted)
	})
})

describe('route fixture sanitisation', () => {
	it('carries no credential or authentication material', () => {
		for (const marker of [/basic /i, /password/i, /nonce/i, /authorization/i, /cookie/i]) {
			assert.doesNotMatch(serialised, marker, `fixture must not contain ${marker}`)
		}
	})

	it('carries no callback names, argument schemas or link metadata', () => {
		for (const marker of [/"callback"/, /"args"/, /"_links"/, /permission_callback/]) {
			assert.doesNotMatch(serialised, marker, `fixture must not contain ${marker}`)
		}
	})

	it('carries no host-specific or site data', () => {
		for (const marker of [/https?:\/\//, /localhost/, /\b\d{1,3}(\.\d{1,3}){3}\b/, /wp-admin/]) {
			assert.doesNotMatch(serialised, marker, `fixture must not contain ${marker}`)
		}
	})

	it('contains only the keys a fixture is allowed to have', () => {
		assert.deepEqual(Object.keys(fixture).sort(), [
			'canonicalCollapses',
			'capturedAt',
			'counts',
			'evidenceKind',
			'operations',
			'profile',
			'schemaVersion',
			'source',
		])

		for (const component of fixture.profile.activeComponents) {
			assert.deepEqual(Object.keys(component).sort(), ['slug', 'version'])
		}
	})
})
