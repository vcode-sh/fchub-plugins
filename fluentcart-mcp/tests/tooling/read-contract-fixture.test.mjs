import assert from 'node:assert/strict'
import { existsSync, readFileSync } from 'node:fs'
import { dirname, join, resolve, sep } from 'node:path'
import { describe, it } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	ENDPOINTS,
	mergeShapes,
	SHAPE_TOKENS,
	toShape,
} from '../../scripts/capture-read-contracts.mjs'

const PACKAGE_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..')
const FIXTURE_PATH = join(
	PACKAGE_ROOT,
	'tests/fixtures/rest/fluentcart-1.5.5-all-active-read-contracts.json',
)

const raw = readFileSync(FIXTURE_PATH, 'utf8')
const fixture = JSON.parse(raw)
const tokens = new Set(SHAPE_TOKENS)

/** Every leaf a shape may legally contain, so anything else is a value that escaped. */
function leafTokens(shape, found = new Set()) {
	if (typeof shape === 'string') found.add(shape)
	else if (shape?.object) for (const child of Object.values(shape.object)) leafTokens(child, found)
	else if (shape?.array) leafTokens(shape.array, found)
	return found
}

function everyKey(shape, keys = new Set()) {
	if (shape?.object) {
		for (const [key, child] of Object.entries(shape.object)) {
			keys.add(key)
			everyKey(child, keys)
		}
	} else if (shape?.array) {
		everyKey(shape.array, keys)
	}
	return keys
}

const allShapes = fixture.contracts.map((contract) => contract.responseShape)
const allKeys = new Set(allShapes.flatMap((shape) => [...everyKey(shape)]))

describe('read contract fixture provenance', () => {
	it('declares its schema version, generator and route fixture', () => {
		assert.equal(fixture.schemaVersion, 1)
		assert.equal(fixture.generatedBy, 'scripts/capture-read-contracts.mjs')
		assert.equal(fixture.evidenceScope, 'all-active-compatibility')
		assert.equal(fixture.routeFixture, 'tests/fixtures/routes/fluentcart-1.5.5-core-pro-1.5.4.json')
		assert.ok(existsSync(join(PACKAGE_ROOT, fixture.routeFixture.split('/').join(sep))))
	})

	it('binds every contract to the same runtime profile digest', () => {
		assert.match(fixture.profileDigest, /^sha256:[0-9a-f]{64}$/)
		for (const contract of fixture.contracts) {
			assert.equal(contract.profileDigest, fixture.profileDigest, contract.canonicalPath)
		}
	})

	it('records the runtime it was captured against', () => {
		assert.equal(fixture.profile.wordpress, '7.0.2')
		const versions = new Map(
			fixture.profile.activeComponents.map((component) => [component.slug, component.version]),
		)
		assert.equal(versions.get('fluent-cart'), '1.5.5')
		assert.equal(versions.get('fluent-cart-pro'), '1.5.4')
	})

	it('keeps Core, Core plus Pro, and all-active evidence scopes distinct', () => {
		assert.deepEqual(
			fixture.compatibilityScopes.map(({ scope, status }) => ({ scope, status })),
			[
				{ scope: 'core', status: 'BLOCKED' },
				{ scope: 'core-pro', status: 'BLOCKED' },
				{ scope: 'all-active', status: 'CAPTURED' },
			],
		)
		for (const row of fixture.compatibilityScopes.filter((entry) => entry.status === 'BLOCKED')) {
			assert.match(row.reason, /\S/, row.scope)
		}
	})

	it('covers exactly the allowlisted endpoints, sorted and deduplicated', () => {
		const paths = fixture.contracts.map((contract) => contract.canonicalPath)
		assert.deepEqual(paths, [...ENDPOINTS.map((entry) => entry.path)].sort())
		assert.equal(new Set(paths).size, paths.length)
		assert.ok(paths.length >= 30, `expected broad safe-read evidence, found ${paths.length}`)
	})

	it('cites a controller file and method for every contract', () => {
		for (const contract of fixture.contracts) {
			const { evidence } = contract
			assert.match(
				evidence.controllerFile,
				/^app\/.+\/Controllers\/.+\.php$/,
				contract.canonicalPath,
			)
			assert.match(evidence.controllerMethod, /^[a-zA-Z]+$/, contract.canonicalPath)
			assert.match(evidence.capturedAt, /^\d{4}-\d{2}-\d{2}T[\d:.]+Z$/, contract.canonicalPath)
		}
	})

	it('records only successful GET reads', () => {
		for (const contract of fixture.contracts) {
			assert.equal(contract.method, 'GET', contract.canonicalPath)
			assert.equal(contract.status, 200, contract.canonicalPath)
		}
	})
})

/**
 * The point of this block. The fixture is committed, so "it holds no customer data" has to be a
 * property anybody can check by reading the file, not a claim resting on the capture script having
 * behaved. Restricting every leaf to a closed token set makes it exactly that: there is nowhere in
 * the structure for a value to hide.
 */
describe('read contract fixture carries no store data', () => {
	it('uses only the declared shape tokens as leaves', () => {
		for (const contract of fixture.contracts) {
			for (const leaf of leafTokens(contract.responseShape)) {
				assert.ok(tokens.has(leaf), `${contract.canonicalPath} leaked leaf "${leaf}"`)
			}
		}
	})

	it('records no personal or secret-looking key', () => {
		const forbidden =
			/email|phone|mobile|password|secret|token|nonce|authorization|cookie|iban|cvv|ssn|last4|first_name|last_name|full_name|display_name|user_login|username|address_[12]|postcode|postal_code|ip_address|api_key|access_key|private_key|vat_number|tax_id/i
		for (const key of allKeys) {
			assert.doesNotMatch(key, forbidden, `fixture records a personal or secret key: ${key}`)
		}
	})

	it('carries no host, credential or identifier material anywhere in the file', () => {
		for (const marker of [
			/https?:\/\//,
			/localhost/,
			/\b\d{1,3}(\.\d{1,3}){3}\b/,
			/wp-admin/,
			/basic /i,
			/@[a-z0-9-]+\.[a-z]{2,}/i,
			/\bsk_(live|test)_/,
			/eyJ[A-Za-z0-9_-]{10,}\./,
		]) {
			assert.doesNotMatch(raw, marker, `fixture must not contain ${marker}`)
		}
	})

	/**
	 * Numbers are the other way a value escapes: a count, a total, a price. Pagination is the one
	 * place a number is legitimate, and only because `per_page` describes the API rather than the
	 * store's contents.
	 */
	it('contains no number outside pagination and the schema version', () => {
		const allowed = new Set(['schemaVersion', 'status', 'observedDefault', 'observedMaximum'])
		JSON.parse(raw, (key, value) => {
			if (typeof value === 'number') {
				assert.ok(allowed.has(key), `fixture records a bare number under "${key}"`)
			}
			return value
		})
	})
})

describe('read contract pagination', () => {
	const paginated = ENDPOINTS.filter((entry) => entry.paginated).map((entry) => entry.path)

	it('records pagination for exactly the endpoints declared paginated', () => {
		for (const contract of fixture.contracts) {
			const expected = paginated.includes(contract.canonicalPath)
			assert.equal(contract.pagination !== undefined, expected, contract.canonicalPath)
		}
	})

	it('names only request fields the store was observed to honour', () => {
		for (const contract of fixture.contracts) {
			if (!contract.pagination) continue
			for (const field of contract.pagination.requestFields) {
				assert.ok(['page', 'per_page'].includes(field), `${contract.canonicalPath}: ${field}`)
			}
		}
	})

	/**
	 * A maximum without `per_page` in `requestFields` would be a cap nobody can request, which is
	 * how an ignored parameter gets mistaken for a page-size limit.
	 */
	it('reports a maximum only where per_page is honoured', () => {
		for (const contract of fixture.contracts) {
			const pagination = contract.pagination
			if (!pagination || pagination.observedMaximum === null) continue
			assert.ok(pagination.requestFields.includes('per_page'), contract.canonicalPath)
			assert.ok(pagination.observedMaximum > 0, contract.canonicalPath)
		}
	})

	it('records the products page-size cap this store actually applies', () => {
		const products = fixture.contracts.find((entry) => entry.canonicalPath === '/products')
		assert.deepEqual(products.pagination.requestFields, ['page', 'per_page'])
		assert.equal(products.pagination.observedDefault, 10)
		assert.equal(products.pagination.observedMaximum, 10)
		assert.ok(products.pagination.responseFields.includes('current_page'))
		assert.ok(products.pagination.responseFields.includes('total'))
	})
})

describe('shape reduction', () => {
	it('reduces scalars to their type and never their value', () => {
		assert.equal(toShape('a customer name'), 'string')
		assert.equal(toShape(42), 'number')
		assert.equal(toShape(true), 'boolean')
		assert.equal(toShape(null), 'null')
	})

	it('reduces objects to sorted keys with shaped values', () => {
		assert.deepEqual(toShape({ b: 1, a: 'x' }), { object: { a: 'string', b: 'number' } })
	})

	it('marks an empty array rather than inventing an item shape', () => {
		assert.deepEqual(toShape([]), { array: 'empty' })
	})

	it('unions the keys of heterogeneous array items', () => {
		const shape = toShape([{ a: 1 }, { b: 'x' }])
		assert.deepEqual(shape, { array: { object: { a: 'number', b: 'string' } } })
	})

	it('refuses to guess when two shapes genuinely disagree', () => {
		assert.equal(mergeShapes('string', 'number'), 'mixed')
	})

	it('treats null as unknown rather than as a conflicting type', () => {
		assert.equal(mergeShapes('null', 'string'), 'string')
		assert.equal(mergeShapes('string', 'null'), 'string')
	})

	it('throws nothing on a personal key it never sees, but the allowlist has none', () => {
		for (const entry of ENDPOINTS) {
			assert.notEqual(entry.path, '/coupons', 'coupons carry email restrictions')
			assert.notEqual(entry.path, '/customers')
			assert.notEqual(entry.path, '/orders')
		}
	})
})
