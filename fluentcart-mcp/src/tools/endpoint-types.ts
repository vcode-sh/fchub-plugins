import type { HttpMethod } from '../api/route-normalisation.js'

export type { HttpMethod }

/**
 * One REST operation a tool may be bound to.
 *
 * `path` is written in whichever form its module already uses — `/orders/:order_id` from the
 * endpoint factories, or `/orders/{param}` from a hand-written handler. Both canonicalise to
 * the same identity before a capability is checked, so a module never has to restate its route
 * in a second dialect just to be auditable.
 *
 * `mapInput` exists for the case where a variant needs a different request shape from its
 * siblings: the 1.3.9 route that took one term per call and the current one that takes a batch
 * are the same tool, not two.
 */
export interface EndpointVariant {
	method: HttpMethod
	path: string
	mapInput?: (input: Record<string, unknown>) => {
		path: string
		body?: Record<string, unknown>
		params?: Record<string, unknown>
	}
}

/**
 * The routes a tool is allowed to reach.
 *
 * `direct` means one REST call, chosen once at registration from the ordered variants. A
 * `composite` tool may reach several operations in one execution or across input-selected
 * branches, and must list every operation it may issue. Every listed route must exist before the
 * tool is advertised, so every valid input has the capability evidence it needs.
 */
export interface ToolRouteMetadata {
	kind: 'direct' | 'composite'
	variants: readonly EndpointVariant[]
}
