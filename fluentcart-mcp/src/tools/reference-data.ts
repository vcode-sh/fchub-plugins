import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import type { CacheScope, PrincipalScopedCache } from '../commerce/cache.js'
import {
	fetchReferenceData,
	REFERENCE_DESCRIPTORS,
	REFERENCE_KINDS,
	type ReferenceKind,
} from '../commerce/reference-data.js'
import { createTool, type ToolDefinition } from './_factory.js'
import type { EndpointVariant } from './endpoint-types.js'

/**
 * Every route the tool may reach. Exactly one is issued per call — whichever `kind` selects.
 *
 * Declared `composite` because that is the only accurate statement available: across
 * invocations this tool really can call all six, so `direct` — which claims one fixed route —
 * would be false. It is a dispatcher, not a sequence, and the two are not the same shape.
 *
 * That distinction has a cost worth knowing about. Capability pruning registers a composite only
 * when every declared route is served, which is right for a sequence — a refund that dies on its
 * third call is worse than one never offered — but wrong here: the six kinds are independent, so
 * a store missing only `/labels` would lose countries and tax classes along with it. Today the
 * cost is theoretical: all six are core routes, present in the core+Pro, core-only and 1.3.9
 * fixtures alike. It also fails safe, withdrawing the tool rather than calling a route that is
 * not there.
 *
 * The proper fix is a third kind — `branch`: one route per call, chosen by input, registered
 * when at least one variant is served, with the handler refusing a kind whose route is absent.
 * That needs a change to endpoint-types.ts and to the pruning rule, neither of which belongs to
 * this module.
 *
 * Written out as literals rather than mapped from the descriptors so a static auditor can read
 * the routes straight out of this file without evaluating it. The duplication is held honest by
 * a test asserting this list equals the descriptor routes exactly, so the two cannot drift.
 */
const VARIANTS: readonly EndpointVariant[] = [
	{ method: 'GET', path: '/settings/payment-methods/all' },
	{ method: 'GET', path: '/tax/classes' },
	{ method: 'GET', path: '/shipping/zones' },
	{ method: 'GET', path: '/address-info/countries' },
	{ method: 'GET', path: '/labels' },
	{ method: 'GET', path: '/products/fetch-term' },
]

function describeKinds(): string {
	return REFERENCE_KINDS.map((kind) => `${kind} (${REFERENCE_DESCRIPTORS[kind].route})`).join(', ')
}

const schema = z.object({
	kind: z
		.enum(REFERENCE_KINDS as [ReferenceKind, ...ReferenceKind[]])
		.describe(
			`Which reference list to read. One of: ${describeKinds()}. An unrecognised kind is rejected before any request is made.`,
		),
	search: z
		.string()
		.optional()
		.describe(
			'Case-insensitive filter applied to the label, code and identifier of every row. Filtering happens across the whole list, so the returned total reflects the matches rather than the page.',
		),
	page: z.number().int().min(1).optional().describe('Page number, starting at 1 (default: 1).'),
	per_page: z
		.number()
		.int()
		.min(1)
		.optional()
		.describe(
			'Rows per page (default: 50, maximum: 100). A larger value is rejected rather than silently reduced, so a page always means what it says.',
		),
})

export interface ReferenceDataToolDeps {
	cache: PrincipalScopedCache
	scope: CacheScope
}

/**
 * The single public entry point for safe reference lists.
 *
 * It calls the same fetcher the MCP resources call, so a list read through a resource and the
 * same list read through this tool cannot disagree, and the second of the two is served from
 * the shared principal-scoped cache rather than costing the merchant another request.
 */
export function referenceDataTools(
	client: FluentCartClient,
	deps: ReferenceDataToolDeps,
): ToolDefinition[] {
	return [
		createTool(client, {
			name: 'fluentcart_list_reference_data',
			title: 'List Store Reference Data',
			description:
				'Read a store reference list: payment methods, tax classes, shipping zones, countries, labels or product categories. ' +
				'Returns compact rows of id, label and — where the store provides them — code and status. ' +
				'Use it to resolve the identifiers other tools expect, instead of guessing a slug. ' +
				'Results are paginated with an exact total, and permission failures are reported as errors rather than as an empty list.',
			annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: true },
			schema,
			routes: { kind: 'composite', variants: VARIANTS },
			handler: async (activeClient, input) =>
				fetchReferenceData(
					{ client: activeClient, cache: deps.cache, scope: deps.scope },
					{
						kind: input.kind,
						search: input.search,
						page: input.page,
						per_page: input.per_page,
					},
				),
		}),
	]
}
