import type { ApiCapabilities } from '../api/capabilities.js'
import { canonicaliseRoute } from '../api/route-normalisation.js'
import type { EndpointVariant, HttpMethod, ToolRouteMetadata } from './endpoint-types.js'

/**
 * Reduce a declared path to the identity a capability check uses.
 *
 * The endpoint factories write parameters as `:order_id`; the REST index publishes them as
 * named regex groups; hand-written handlers use `{param}`. All three mean the same operation,
 * so they are flattened to one form before anything is compared.
 */
export function toCanonicalPath(path: string): string {
	return canonicaliseRoute(path.replace(/:[A-Za-z_][A-Za-z0-9_]*/g, '{param}'))
}

/**
 * Choose the first variant the store actually serves.
 *
 * Order is significance: variants are declared current-route-first, so a store running both the
 * current and a retired route binds to the current one. A path that matches with the wrong
 * method is not a match — route support is a `(path, method)` pair, and a POST tool pointed at
 * a GET-only route would fail at the worst possible moment.
 *
 * Returns `null` when nothing matches, which is the signal to omit the tool entirely rather
 * than register something that cannot work.
 */
export function selectEndpoint(
	capabilities: ApiCapabilities,
	variants: readonly EndpointVariant[],
): EndpointVariant | null {
	for (const variant of variants) {
		if (capabilities.has(variant.method, toCanonicalPath(variant.path))) return variant
	}
	return null
}

/** Declare a tool that issues exactly one REST call, with optional ordered fallbacks. */
export function direct(
	method: HttpMethod,
	path: string,
	...fallbacks: readonly EndpointVariant[]
): ToolRouteMetadata {
	return { kind: 'direct', variants: [{ method, path }, ...fallbacks] }
}

/** Declare every route a tool may issue across a sequence or input-selected branches. */
export function composite(...variants: readonly EndpointVariant[]): ToolRouteMetadata {
	return { kind: 'composite', variants }
}

/** Shorthand for a single variant inside a `direct` fallback chain or a `composite` list. */
export function op(method: HttpMethod, path: string): EndpointVariant {
	return { method, path }
}

/**
 * Decide whether a store can support a tool at all.
 *
 * A direct tool needs one of its variants. A composite needs all of them: it either runs a
 * sequence or selects a route from valid input. Missing a sequence leg can leave a partial write;
 * missing an input branch advertises a valid request that can only fail. Refusing registration is
 * the honest contract in both cases.
 */
export function isSupported(capabilities: ApiCapabilities, routes: ToolRouteMetadata): boolean {
	if (routes.variants.length === 0) return false
	if (routes.kind === 'direct') return selectEndpoint(capabilities, routes.variants) !== null
	return routes.variants.every((variant) =>
		capabilities.has(variant.method, toCanonicalPath(variant.path)),
	)
}

/**
 * Project `ToolRouteMetadata` into the checker's shape. Paths are canonicalised because the
 * factory writes `:order_id` while the fixture speaks `{param}`, and `composite` reports whether
 * the tool may issue more than one call — the safety property the checker cares about.
 */
export function auditView(
	routes: ToolRouteMetadata | undefined,
): { routes: { method: string; path: string }[]; composite: boolean } | undefined {
	if (!routes) return undefined
	return {
		routes: routes.variants.map((variant) => ({
			method: variant.method,
			path: toCanonicalPath(variant.path),
		})),
		composite: routes.kind === 'composite' || routes.variants.length > 1,
	}
}
