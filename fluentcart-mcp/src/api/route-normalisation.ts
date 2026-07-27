/**
 * Canonicalisation of WordPress REST route patterns.
 *
 * The REST index publishes routes as PHP regular expressions with named groups, for example
 * `/fluent-cart/v2/orders/(?P<id>[^\s(?!/)]+)`. Those patterns are not stable identities: the
 * same logical operation is registered with different group names and different character
 * classes across controllers, and a store may expose two variants of one route. Capability
 * checks therefore compare a canonical form in which every path parameter is the literal
 * `{param}` and the FluentCart namespace prefix is removed.
 *
 * A canonical route is only half of an identity. Route support is a `(canonical path, method)`
 * pair; a matching path with the wrong method is unsupported.
 */

export type HttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'

export const HTTP_METHODS: readonly HttpMethod[] = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']

const HTTP_METHOD_SET: ReadonlySet<string> = new Set<string>(HTTP_METHODS)

export function isHttpMethod(value: string): value is HttpMethod {
	return HTTP_METHOD_SET.has(value)
}

export const FLUENT_CART_NAMESPACE = 'fluent-cart/v2'
export const FLUENT_CART_ROUTE_PREFIX = `/${FLUENT_CART_NAMESPACE}`

/** The canonical placeholder every path parameter collapses to. */
export const ROUTE_PARAM = '{param}'

export interface RouteOperation {
	readonly method: HttpMethod
	readonly path: string
}

const NAMED_GROUP_OPEN = '(?P<'

/**
 * Return the index just past the group opened at `start`.
 *
 * WordPress group bodies routinely contain both nested parentheses and slashes — the common
 * FluentCart pattern `[^\s(?!/)]+` has each — so neither a lazy `\)` match nor a slash split
 * can find the true end. Scan with an explicit depth counter, treating a character class as
 * opaque and honouring backslash escapes, so that parentheses inside `[...]` never move the
 * depth.
 */
function skipNamedGroup(pattern: string, start: number): number {
	let depth = 0
	let inCharClass = false

	for (let index = start; index < pattern.length; index += 1) {
		const char = pattern.charAt(index)

		if (char === '\\') {
			index += 1
			continue
		}
		if (inCharClass) {
			if (char === ']') inCharClass = false
			continue
		}
		if (char === '[') {
			inCharClass = true
			continue
		}
		if (char === '(') {
			depth += 1
			continue
		}
		if (char === ')') {
			depth -= 1
			if (depth === 0) return index + 1
		}
	}

	// An unterminated group consumes the remainder rather than leaking regex into the identity.
	return pattern.length
}

function replaceNamedGroups(pattern: string): string {
	let result = ''
	let index = 0

	while (index < pattern.length) {
		if (pattern.startsWith(NAMED_GROUP_OPEN, index)) {
			result += ROUTE_PARAM
			index = skipNamedGroup(pattern, index)
			continue
		}
		result += pattern.charAt(index)
		index += 1
	}

	return result
}

/**
 * Reduce a route pattern to its canonical identity.
 *
 * Accepts WordPress regex routes, OpenAPI-style `{name}` templates and already-canonical
 * paths, with or without the `/wp-json` and `/fluent-cart/v2` prefixes. The operation is
 * idempotent: canonicalising a canonical route returns it unchanged.
 */
export function canonicaliseRoute(path: string): string {
	let candidate = path.trim()
	if (candidate === '') return '/'

	candidate = candidate.replace(/^\/?wp-json(?=\/|$)/, '')
	if (!candidate.startsWith('/')) candidate = `/${candidate}`

	if (
		candidate === FLUENT_CART_ROUTE_PREFIX ||
		candidate.startsWith(`${FLUENT_CART_ROUTE_PREFIX}/`)
	) {
		candidate = candidate.slice(FLUENT_CART_ROUTE_PREFIX.length)
	}

	candidate = replaceNamedGroups(candidate)
	candidate = candidate.replace(/\{[^}]*\}/g, ROUTE_PARAM)
	candidate = candidate.replace(/\/{2,}/g, '/')

	if (candidate.length > 1 && candidate.endsWith('/')) candidate = candidate.slice(0, -1)
	if (candidate === '') candidate = '/'

	return candidate
}

/** True for the namespace index itself, which is discovery metadata, not an operation. */
export function isNamespaceRoot(path: string): boolean {
	return canonicaliseRoute(path) === '/'
}

/** True for any route registered under the FluentCart v2 namespace, including its root. */
export function isFluentCartRoute(path: string): boolean {
	const trimmed = path.trim().replace(/^\/?wp-json(?=\/|$)/, '')
	const normalised = trimmed.startsWith('/') ? trimmed : `/${trimmed}`
	return (
		normalised === FLUENT_CART_ROUTE_PREFIX || normalised.startsWith(`${FLUENT_CART_ROUTE_PREFIX}/`)
	)
}

/** The string identity of an operation: canonical path qualified by method. */
export function operationKey(method: HttpMethod, path: string): string {
	return `${method} ${canonicaliseRoute(path)}`
}

/**
 * Order operations by path then method.
 *
 * Sorting is by explicit comparison rather than locale-aware collation so that a fixture
 * generated on one machine byte-matches a fixture generated on another.
 */
export function compareOperations(left: RouteOperation, right: RouteOperation): number {
	if (left.path !== right.path) return left.path < right.path ? -1 : 1
	if (left.method !== right.method) return left.method < right.method ? -1 : 1
	return 0
}

export function sortOperations(operations: readonly RouteOperation[]): RouteOperation[] {
	return [...operations].sort(compareOperations)
}

/**
 * Canonicalise every operation and drop repeats, preserving first-seen order.
 *
 * This is where genuinely distinct registrations merge: a store that registers both
 * `customers/(?P<customerId>[0-9]+)` and `customers/(?P<customerId>[^\s(?!/)]+)` exposes one
 * `GET /customers/{param}` operation, not two.
 */
export function dedupeOperations(operations: readonly RouteOperation[]): RouteOperation[] {
	const seen = new Set<string>()
	const unique: RouteOperation[] = []

	for (const operation of operations) {
		const key = operationKey(operation.method, operation.path)
		if (seen.has(key)) continue
		seen.add(key)
		unique.push({ method: operation.method, path: canonicaliseRoute(operation.path) })
	}

	return unique
}

/** Canonicalise, deduplicate and sort in one step — the form written to fixtures. */
export function normaliseOperations(operations: readonly RouteOperation[]): RouteOperation[] {
	return sortOperations(dedupeOperations(operations))
}
