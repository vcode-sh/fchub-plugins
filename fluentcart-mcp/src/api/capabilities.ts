/**
 * Runtime capability discovery.
 *
 * The registry a store actually exposes is the only trustworthy answer to "does this route
 * exist". Version strings are provenance labels, not routing logic: a store may run a patched
 * build, may have Pro deactivated, or may sit behind a plugin that removes controllers.
 *
 * Failure to discover is an actionable startup error, never a licence to guess. A store whose
 * REST index is hidden gets a clear refusal instead of a registry of routes that may not exist.
 */

import { z } from 'zod'
import { FluentCartApiError } from './errors.js'
import {
	canonicaliseRoute,
	FLUENT_CART_NAMESPACE,
	type HttpMethod,
	isFluentCartRoute,
	isHttpMethod,
	isNamespaceRoot,
	operationKey,
} from './route-normalisation.js'

export const CAPABILITY_DISCOVERY_ERROR = 'CAPABILITY_DISCOVERY_ERROR'

/**
 * Raised when the REST index cannot be turned into trustworthy capability evidence.
 *
 * Deliberately distinct from `FluentCartApiError`: "I could not reach the store" and "I reached
 * the store but it will not say what it supports" need different operator responses.
 */
export class CapabilityDiscoveryError extends Error {
	readonly code: typeof CAPABILITY_DISCOVERY_ERROR = CAPABILITY_DISCOVERY_ERROR

	constructor(
		message: string,
		readonly detail?: unknown,
	) {
		super(message)
		this.name = 'CapabilityDiscoveryError'
	}
}

export interface ApiCapabilities {
	has(method: HttpMethod, path: string): boolean
	operations: ReadonlySet<string>
	source: 'live-rest-index'
}

const DISCOVERY_TIMEOUT_MS = 10_000
const MAX_REDIRECTS = 5
const REDIRECT_STATUSES: ReadonlySet<number> = new Set([301, 302, 303, 307, 308])

/**
 * Only the fields discovery is allowed to act on. Zod validates the envelope before any `routes`
 * traversal and strips the rest, so `args`, `callback` and `_links` cannot reach a fixture.
 */
const endpointSchema = z.object({
	methods: z.array(z.string()).optional(),
})

const routeSchema = z.object({
	endpoints: z.array(endpointSchema).optional(),
})

const restRootSchema = z.object({
	namespaces: z.array(z.string()).optional(),
	routes: z.record(z.string(), routeSchema),
})

/**
 * The namespace index, not the whole WordPress REST index.
 *
 * `/wp-json/` describes every namespace a site registers, and this server routes on exactly
 * one. Measured on the development store: the root index is 527,327 characters across 987
 * routes, of which 386 operations survive the FluentCart filter; `/wp-json/fluent-cart/v2` is
 * 117,444 characters and yields the identical 386 operations. Verified by diffing the derived
 * operation sets rather than by counting routes — same set, nothing only in one, nothing only
 * in the other. Every startup paid 4.5x for bytes that were then thrown away.
 *
 * The narrower URL also sharpens the failure: WordPress answers 404 `rest_no_route` for a
 * namespace nobody registered, which is a direct statement that FluentCart is not serving here.
 */
function namespaceIndexUrl(storeUrl: string): URL {
	let parsed: URL
	try {
		parsed = new URL(storeUrl)
	} catch {
		throw new CapabilityDiscoveryError(`Store URL is not a valid absolute URL: ${storeUrl}`)
	}

	if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
		throw new CapabilityDiscoveryError(
			`Store URL must use http or https, received: ${parsed.protocol}`,
		)
	}

	parsed.search = ''
	parsed.hash = ''

	// Append rather than resolve against the origin: a WordPress install in a subdirectory keeps
	// its path segment, and `new URL('/wp-json/', base)` would silently discard it.
	const base = parsed.toString().replace(/\/+$/, '')
	return new URL(`${base}/wp-json/${FLUENT_CART_NAMESPACE}`)
}

function nextRedirectTarget(current: URL, response: Response, origin: string): URL {
	const location = response.headers?.get('location')
	if (!location) {
		throw new CapabilityDiscoveryError(
			`REST index returned ${response.status} without a Location header`,
		)
	}

	let target: URL
	try {
		target = new URL(location, current)
	} catch {
		throw new CapabilityDiscoveryError(`REST index redirect target is not a valid URL: ${location}`)
	}

	if (target.origin !== origin) {
		throw new CapabilityDiscoveryError(
			`REST index redirected to a different origin (${target.origin}); refusing to follow`,
		)
	}

	return target
}

/**
 * Fetch the namespace index with an unconditional 10-second budget and manual redirect handling.
 *
 * Redirects are followed by hand so a hop to another origin can be refused; automatic following
 * would let a hijacked redirect define what this server believes the store supports.
 */
async function fetchRestRoot(rootUrl: URL): Promise<Response> {
	const controller = new AbortController()
	const timer = setTimeout(() => controller.abort(), DISCOVERY_TIMEOUT_MS)
	const origin = rootUrl.origin

	try {
		let current = rootUrl

		for (let hop = 0; hop <= MAX_REDIRECTS; hop += 1) {
			const response = await fetch(current.toString(), {
				method: 'GET',
				// The REST index is public. This header set is complete rather than conditional:
				// there is no Authorization branch here to get wrong, so an administrator
				// credential cannot leak to a route that never needs one.
				headers: { Accept: 'application/json' },
				redirect: 'manual',
				signal: controller.signal,
			})

			if (!REDIRECT_STATUSES.has(response.status)) return response
			current = nextRedirectTarget(current, response, origin)
		}

		throw new CapabilityDiscoveryError(
			`REST index exceeded ${MAX_REDIRECTS} redirects without returning a document`,
		)
	} finally {
		clearTimeout(timer)
	}
}

/**
 * Turn a transport failure into something a person can act on.
 *
 * Discovery runs before any transport is connected, so this message is frequently the only thing
 * a user ever sees from the server — in Claude Desktop it is the whole of the crash log. Node
 * renders an unreachable host as the bare string "fetch failed", which names neither the store
 * being contacted nor anything to change, so the URL and the likely cause are added here.
 */
function toDiscoveryFailure(error: unknown, rootUrl: URL | string): never {
	if (error instanceof CapabilityDiscoveryError) throw error
	if (error instanceof FluentCartApiError) throw error

	const isAbort =
		(error instanceof DOMException && error.name === 'AbortError') ||
		(error instanceof Error && error.name === 'AbortError')

	if (isAbort) {
		throw new FluentCartApiError(
			'TIMEOUT',
			`Timed out after ${DISCOVERY_TIMEOUT_MS}ms reading the FluentCart REST index at ${rootUrl}. The store is reachable but slow to answer, or a firewall is holding the connection open.`,
		)
	}

	const detail = error instanceof Error ? error.message : String(error)
	throw new FluentCartApiError(
		'CONNECTION_ERROR',
		`Could not reach the FluentCart REST index at ${rootUrl} (${detail}). Check that FLUENTCART_URL points at the site root, that the site is running, and that /wp-json/ is not blocked.`,
	)
}

/** Check the transport outcome and decode the body. Shape validation belongs to the builder. */
async function readRootDocument(response: Response, url: URL | string): Promise<unknown> {
	// WordPress answers 404 for a namespace that is not registered, and for a REST API that has
	// been switched off wholesale. Both mean the same thing to this server — there is no evidence
	// that FluentCart serves anything here — so the message names both rather than guessing.
	if (response.status === 404) {
		throw new CapabilityDiscoveryError(
			`No ${FLUENT_CART_NAMESPACE} routes are registered at ${url} (HTTP 404). Either FluentCart is not active on this store, or the WordPress REST API is disabled. Check that the plugin is active and that /wp-json/ is reachable.`,
			{ status: 404 },
		)
	}

	if (!response.ok) {
		throw new CapabilityDiscoveryError(
			`REST index request failed with status ${response.status} at ${url}; cannot determine store compatibility`,
			{ status: response.status },
		)
	}

	try {
		return JSON.parse(await response.text())
	} catch {
		throw new CapabilityDiscoveryError(
			`REST index at ${url} did not return valid JSON; a security plugin may be hiding or rewriting it`,
		)
	}
}

/**
 * Build the capability set from a REST index document, validating its shape before reading
 * `routes`. Exported so a caller holding a captured index reaches the identical result as a
 * live discovery, with no second implementation to drift.
 */
export function capabilitiesFromRestIndex(document: unknown): ApiCapabilities {
	const validated = restRootSchema.safeParse(document)
	if (!validated.success) {
		throw new CapabilityDiscoveryError(
			'REST index document did not match the expected WordPress shape',
			validated.error.issues,
		)
	}

	const root = validated.data
	const namespaceListed = root.namespaces?.includes(FLUENT_CART_NAMESPACE) ?? false
	const routePaths = Object.keys(root.routes).filter(isFluentCartRoute)

	if (routePaths.length === 0) {
		throw new CapabilityDiscoveryError(
			namespaceListed
				? `Namespace ${FLUENT_CART_NAMESPACE} is registered but exposes no routes`
				: `Namespace ${FLUENT_CART_NAMESPACE} is not present in the REST index; FluentCart may be inactive`,
		)
	}

	const operations = new Set<string>()

	for (const path of routePaths) {
		// The namespace index itself describes the namespace; it is not an application operation.
		if (isNamespaceRoot(path)) continue

		const route = root.routes[path]
		for (const endpoint of route?.endpoints ?? []) {
			for (const method of endpoint.methods ?? []) {
				if (!isHttpMethod(method)) continue
				operations.add(operationKey(method, path))
			}
		}
	}

	// A namespace document listing only its own root passes the check above — one path, zero
	// operations — and would hand back an empty registry that prunes every tool without ever
	// saying why. Fail closed here too: no operations is not a store this server can serve.
	if (operations.size === 0) {
		throw new CapabilityDiscoveryError(
			`Namespace ${FLUENT_CART_NAMESPACE} is registered but exposes no operations`,
		)
	}

	return {
		has: (method: HttpMethod, path: string) =>
			operations.has(`${method} ${canonicaliseRoute(path)}`),
		operations,
		source: 'live-rest-index',
	}
}

/**
 * Read the store's public REST index and derive the operations it exposes.
 *
 * @throws {CapabilityDiscoveryError} when the store answers but the answer is unusable —
 *   non-2xx, malformed JSON, unexpected shape, cross-origin redirect or a missing namespace.
 * @throws {FluentCartApiError} with `TIMEOUT` or `CONNECTION_ERROR` when the store cannot be
 *   reached at all.
 */
export async function discoverApiCapabilities(storeUrl: string): Promise<ApiCapabilities> {
	const rootUrl = namespaceIndexUrl(storeUrl)

	let response: Response
	try {
		response = await fetchRestRoot(rootUrl)
	} catch (error) {
		toDiscoveryFailure(error, rootUrl)
	}

	let document: unknown
	try {
		document = await readRootDocument(response, rootUrl)
	} catch (error) {
		toDiscoveryFailure(error, rootUrl)
	}

	return capabilitiesFromRestIndex(document)
}
