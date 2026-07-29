import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { after, describe, it } from 'node:test'
import { fileURLToPath } from 'node:url'
import { assertAllowedLiveTarget, fetchTargetIdentity } from '../../scripts/live-target-policy.mjs'

const PACKAGE_ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..')

describe('assertAllowedLiveTarget', () => {
	it('accepts loopback hostnames without any opt-in', () => {
		assert.equal(assertAllowedLiveTarget('http://localhost:9081', {}).hostname, 'localhost')
		assert.equal(assertAllowedLiveTarget('http://127.0.0.1:9081', {}).hostname, '127.0.0.1')
		assert.equal(assertAllowedLiveTarget('http://[::1]:9081', {}).hostname, '[::1]')
	})

	it('rejects a remote target without the explicit remote opt-in', () => {
		assert.throws(
			() => assertAllowedLiveTarget('https://store.example.com', {}),
			/FLUENTCART_INTEGRATION_ALLOW_REMOTE=yes/,
		)
	})

	it('rejects a remote opt-in that names no exact origin', () => {
		assert.throws(
			() =>
				assertAllowedLiveTarget('https://store.example.com', {
					FLUENTCART_INTEGRATION_ALLOW_REMOTE: 'yes',
				}),
			/FLUENTCART_INTEGRATION_REMOTE_ORIGIN/,
		)
	})

	it('accepts a remote target whose exact origin and fingerprint are declared', () => {
		assert.equal(
			assertAllowedLiveTarget('https://store.example.com', {
				FLUENTCART_INTEGRATION_ALLOW_REMOTE: 'yes',
				FLUENTCART_INTEGRATION_REMOTE_ORIGIN: 'https://store.example.com',
				FLUENTCART_INTEGRATION_TARGET_FINGERPRINT: 'fixture-store-uuid',
			}).origin,
			'https://store.example.com',
		)
	})

	it('rejects an origin allowlist entry that does not match the target exactly', () => {
		assert.throws(
			() =>
				assertAllowedLiveTarget('https://store.example.com', {
					FLUENTCART_INTEGRATION_ALLOW_REMOTE: 'yes',
					FLUENTCART_INTEGRATION_REMOTE_ORIGIN: 'https://other.example.com',
					FLUENTCART_INTEGRATION_TARGET_FINGERPRINT: 'fixture-store-uuid',
				}),
			/does not match/,
		)
	})

	it('rejects a remote opt-in without a declared target fingerprint', () => {
		assert.throws(
			() =>
				assertAllowedLiveTarget('https://store.example.com', {
					FLUENTCART_INTEGRATION_ALLOW_REMOTE: 'yes',
					FLUENTCART_INTEGRATION_REMOTE_ORIGIN: 'https://store.example.com',
				}),
			/FLUENTCART_INTEGRATION_TARGET_FINGERPRINT/,
		)
	})

	it('rejects a non-http scheme', () => {
		assert.throws(() => assertAllowedLiveTarget('file:///tmp/store', {}), /http or https/)
	})

	it('rejects an unparseable target', () => {
		assert.throws(() => assertAllowedLiveTarget('not-a-url', {}), /not a valid URL/)
	})

	it('rejects loopback lookalike hostnames', () => {
		assert.throws(
			() => assertAllowedLiveTarget('http://localhost.example.com', {}),
			/FLUENTCART_INTEGRATION_ALLOW_REMOTE=yes/,
		)
		assert.throws(
			() => assertAllowedLiveTarget('http://127.0.0.1.example.com', {}),
			/FLUENTCART_INTEGRATION_ALLOW_REMOTE=yes/,
		)
	})

	it('only accepts the exact string yes for the remote opt-in', () => {
		for (const value of ['YES', 'true', '1', 'yes ']) {
			assert.throws(
				() =>
					assertAllowedLiveTarget('https://store.example.com', {
						FLUENTCART_INTEGRATION_ALLOW_REMOTE: value,
						FLUENTCART_INTEGRATION_REMOTE_ORIGIN: 'https://store.example.com',
						FLUENTCART_INTEGRATION_TARGET_FINGERPRINT: 'fixture-store-uuid',
					}),
				/FLUENTCART_INTEGRATION_ALLOW_REMOTE=yes/,
			)
		}
	})
})

const originalFetch = globalThis.fetch

after(() => {
	globalThis.fetch = originalFetch
})

function stubFetch(handler) {
	globalThis.fetch = handler
}

function jsonResponse(body, init = {}) {
	return {
		ok: init.status === undefined || (init.status >= 200 && init.status < 300),
		status: init.status ?? 200,
		url: init.url ?? 'http://localhost:9081/wp-json/',
		redirected: init.redirected ?? false,
		async json() {
			if (typeof body === 'string') throw new SyntaxError('Unexpected token')
			return body
		},
	}
}

const validRoot = {
	url: 'http://localhost:9081/wp-json/',
	home: 'http://localhost:9081',
	namespaces: ['wp/v2', 'fluent-cart/v2'],
	name: 'Store',
}

describe('fetchTargetIdentity', () => {
	it('documents the exact copyable fingerprint format emitted by the launcher', () => {
		const example = readFileSync(join(PACKAGE_ROOT, '.env.test.local.example'), 'utf8')
		const placeholder = example.match(
			/^# FLUENTCART_INTEGRATION_TARGET_FINGERPRINT="([^"]+)"$/m,
		)?.[1]
		assert.equal(placeholder, '<64 lowercase hexadecimal characters>')
		assert.doesNotMatch(example, /TARGET_FINGERPRINT="sha256:/)
	})

	it('produces a stable canonical document and lowercase sha-256 fingerprint', async () => {
		stubFetch(async () => jsonResponse(validRoot))
		const first = await fetchTargetIdentity('http://localhost:9081')

		assert.match(first.fingerprint, /^[0-9a-f]{64}$/)
		assert.equal(
			first.canonical,
			JSON.stringify({
				hasFluentCartV2: true,
				home: 'http://localhost:9081',
				url: 'http://localhost:9081/wp-json',
			}),
		)

		// Trailing slashes, default ports and letter case must not change the fingerprint.
		stubFetch(async () =>
			jsonResponse({
				...validRoot,
				url: 'HTTP://LOCALHOST:9081/wp-json',
				home: 'http://localhost:9081/',
			}),
		)
		const second = await fetchTargetIdentity('http://localhost:9081')
		assert.equal(second.fingerprint, first.fingerprint)
	})

	it('removes default ports when canonicalising', async () => {
		stubFetch(async () =>
			jsonResponse({
				url: 'https://store.example.com:443/wp-json/',
				home: 'https://store.example.com:443',
				namespaces: ['fluent-cart/v2'],
			}),
		)
		const identity = await fetchTargetIdentity('https://store.example.com')
		assert.equal(
			identity.canonical,
			JSON.stringify({
				hasFluentCartV2: true,
				home: 'https://store.example.com',
				url: 'https://store.example.com/wp-json',
			}),
		)
	})

	it('produces a different fingerprint for a different store', async () => {
		stubFetch(async () => jsonResponse(validRoot))
		const a = await fetchTargetIdentity('http://localhost:9081')
		stubFetch(async () =>
			jsonResponse({
				...validRoot,
				home: 'http://localhost:9090',
				url: 'http://localhost:9090/wp-json/',
			}),
		)
		const b = await fetchTargetIdentity('http://localhost:9090')
		assert.notEqual(a.fingerprint, b.fingerprint)
	})

	it('never sends an Authorization header to the public root index', async () => {
		let seenInit
		stubFetch(async (_url, init) => {
			seenInit = init
			return jsonResponse(validRoot)
		})
		await fetchTargetIdentity('http://localhost:9081')
		const headers = new Headers(seenInit?.headers ?? {})
		assert.equal(headers.get('authorization'), null)
		assert.equal(seenInit?.redirect, 'manual')
	})

	it('rejects a malformed root document', async () => {
		stubFetch(async () => jsonResponse({ url: 'http://localhost:9081/wp-json/' }))
		await assert.rejects(fetchTargetIdentity('http://localhost:9081'), /root document/)
	})

	it('rejects invalid JSON', async () => {
		stubFetch(async () => jsonResponse('<html>'))
		await assert.rejects(fetchTargetIdentity('http://localhost:9081'), /root document/)
	})

	it('rejects a missing fluent-cart/v2 namespace', async () => {
		stubFetch(async () => jsonResponse({ ...validRoot, namespaces: ['wp/v2'] }))
		await assert.rejects(fetchTargetIdentity('http://localhost:9081'), /fluent-cart\/v2/)
	})

	it('rejects a non-200 status', async () => {
		stubFetch(async () => jsonResponse(validRoot, { status: 403 }))
		await assert.rejects(fetchTargetIdentity('http://localhost:9081'), /status 403/)
	})

	it('rejects a cross-origin redirect', async () => {
		stubFetch(async () => ({
			ok: false,
			status: 302,
			redirected: true,
			url: 'https://elsewhere.example.com/wp-json/',
			async json() {
				return validRoot
			},
		}))
		await assert.rejects(fetchTargetIdentity('http://localhost:9081'), /redirect/i)
	})

	it('rejects a network failure with a clear message', async () => {
		stubFetch(async () => {
			throw new TypeError('fetch failed')
		})
		await assert.rejects(fetchTargetIdentity('http://localhost:9081'), /could not be reached/)
	})

	it('aborts after the ten second budget', async () => {
		stubFetch(async (_url, init) => {
			const error = new Error('The operation was aborted')
			error.name = 'AbortError'
			assert.ok(init?.signal, 'expected an abort signal')
			throw error
		})
		await assert.rejects(fetchTargetIdentity('http://localhost:9081'), /timed out after 10s/)
	})
})
