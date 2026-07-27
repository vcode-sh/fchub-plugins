import { createHash } from 'node:crypto'

const LOOPBACK_HOSTNAMES = new Set(['localhost', '127.0.0.1', '[::1]', '::1'])
const IDENTITY_TIMEOUT_MS = 10_000

/**
 * Decide whether a live integration target may be contacted at all.
 *
 * Loopback needs no opt-in. Anything else needs the exact opt-in string, an exact origin
 * allowlist entry and a declared store fingerprint, so that a stray FLUENTCART_URL cannot
 * quietly point a mutating test suite at a production shop.
 *
 * @param {string} rawUrl
 * @param {Record<string, string | undefined>} policy
 * @returns {URL}
 */
export function assertAllowedLiveTarget(rawUrl, policy) {
	let url
	try {
		url = new URL(rawUrl)
	} catch {
		throw new Error(`FLUENTCART_URL is not a valid URL: ${rawUrl}`)
	}

	if (url.protocol !== 'http:' && url.protocol !== 'https:') {
		throw new Error(`Live target must use http or https, received ${url.protocol}`)
	}

	if (isLoopbackHostname(url.hostname)) {
		return url
	}

	if (policy.FLUENTCART_INTEGRATION_ALLOW_REMOTE !== 'yes') {
		throw new Error(
			`Live target ${url.origin} is not loopback. Set FLUENTCART_INTEGRATION_ALLOW_REMOTE=yes to allow a remote store.`,
		)
	}

	const allowedOrigin = policy.FLUENTCART_INTEGRATION_REMOTE_ORIGIN
	if (!allowedOrigin) {
		throw new Error(
			'A remote live target requires FLUENTCART_INTEGRATION_REMOTE_ORIGIN naming the exact allowed origin.',
		)
	}

	let allowed
	try {
		allowed = new URL(allowedOrigin)
	} catch {
		throw new Error(
			`FLUENTCART_INTEGRATION_REMOTE_ORIGIN is not a valid URL: ${allowedOrigin}`,
		)
	}

	if (allowed.origin !== url.origin) {
		throw new Error(
			`Live target ${url.origin} does not match the allowlisted origin ${allowed.origin}.`,
		)
	}

	if (!policy.FLUENTCART_INTEGRATION_TARGET_FINGERPRINT) {
		throw new Error(
			'A remote live target requires FLUENTCART_INTEGRATION_TARGET_FINGERPRINT to pin the exact store identity.',
		)
	}

	return url
}

function isLoopbackHostname(hostname) {
	return LOOPBACK_HOSTNAMES.has(hostname.toLowerCase())
}

/**
 * Read the unauthenticated WordPress REST root and reduce it to a stable identity.
 *
 * No credentials are sent: this is a public document and the launcher runs before any
 * authenticated call. The canonical form is stable JSON so the fingerprint survives
 * cosmetic differences in scheme case, default ports and trailing slashes.
 *
 * @param {string} rawUrl
 * @returns {Promise<{ canonical: string; fingerprint: string }>}
 */
export async function fetchTargetIdentity(rawUrl) {
	const base = new URL(rawUrl)
	const rootUrl = new URL('/wp-json/', base)

	let response
	try {
		response = await fetch(rootUrl, {
			method: 'GET',
			redirect: 'manual',
			headers: { accept: 'application/json' },
			signal: AbortSignal.timeout(IDENTITY_TIMEOUT_MS),
		})
	} catch (error) {
		if (error instanceof Error && (error.name === 'AbortError' || error.name === 'TimeoutError')) {
			throw new Error(`Live target ${base.origin} timed out after 10s while reading /wp-json/.`)
		}
		throw new Error(`Live target ${base.origin} could not be reached while reading /wp-json/.`)
	}

	if (response.redirected || (response.status >= 300 && response.status < 400)) {
		const destination = safeOrigin(response.url)
		if (destination !== base.origin) {
			throw new Error(
				`Live target ${base.origin} issued a cross-origin redirect while reading /wp-json/.`,
			)
		}
	}

	if (!response.ok) {
		throw new Error(`Live target ${base.origin} returned status ${response.status} for /wp-json/.`)
	}

	let document
	try {
		document = await response.json()
	} catch {
		throw new Error(`Live target ${base.origin} returned an unreadable REST root document.`)
	}

	if (
		document === null ||
		typeof document !== 'object' ||
		typeof document.url !== 'string' ||
		typeof document.home !== 'string' ||
		!Array.isArray(document.namespaces) ||
		document.namespaces.some((entry) => typeof entry !== 'string')
	) {
		throw new Error(
			`Live target ${base.origin} returned an invalid REST root document (expected url, home and namespaces).`,
		)
	}

	const hasFluentCartV2 = document.namespaces.includes('fluent-cart/v2')
	if (!hasFluentCartV2) {
		throw new Error(
			`Live target ${base.origin} does not expose the fluent-cart/v2 namespace; it is not a FluentCart store.`,
		)
	}

	// Keys are written in sorted order so the canonical document is byte-stable.
	const canonical = JSON.stringify({
		hasFluentCartV2,
		home: canonicaliseUrl(document.home),
		url: canonicaliseUrl(document.url),
	})

	return {
		canonical,
		fingerprint: createHash('sha256').update(canonical, 'utf8').digest('hex'),
	}
}

function canonicaliseUrl(value) {
	let url
	try {
		url = new URL(value)
	} catch {
		return value.trim().replace(/\/+$/, '')
	}

	const scheme = url.protocol.toLowerCase()
	const host = url.hostname.toLowerCase()
	const isDefaultPort =
		url.port === '' || (scheme === 'http:' && url.port === '80') || (scheme === 'https:' && url.port === '443')
	const authority = isDefaultPort ? host : `${host}:${url.port}`
	const path = url.pathname.replace(/\/+$/, '')

	return `${scheme}//${authority}${path}`
}

function safeOrigin(value) {
	try {
		return new URL(value).origin
	} catch {
		return null
	}
}
