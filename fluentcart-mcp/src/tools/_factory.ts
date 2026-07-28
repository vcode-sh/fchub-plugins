import type { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { FluentCartApiError } from '../api/errors.js'
import type { CacheScope } from '../commerce/cache.js'
import { PrincipalScopedCache } from '../commerce/cache.js'
import {
	assertToolResponseBudget,
	assertWithinEmergencyCap,
	ResponseTooLargeError,
} from '../commerce/response-budget.js'
import { redactSensitive } from '../security/redaction.js'
import type { ToolRouteMetadata } from './endpoint-types.js'
import { auditView } from './endpoints.js'
import type { ToolSafety } from './risk.js'
import { resolveToolSafety } from './risk-registry.js'

export interface ToolAnnotations {
	readOnlyHint?: boolean
	destructiveHint?: boolean
	idempotentHint?: boolean
	openWorldHint?: boolean
}

export interface ToolDefinition {
	name: string
	title: string
	description: string
	schema: z.ZodObject<z.ZodRawShape>
	annotations: ToolAnnotations
	/**
	 * Reviewed business-risk metadata. Resolved from the risk registry rather than inferred
	 * from the HTTP verb, and required: an unclassified write resolves to `unreviewed-write`,
	 * which the exposure policy hides.
	 */
	safety: ToolSafety
	/**
	 * The REST operations this tool may reach, for capability filtering and CI auditing.
	 *
	 * Endpoint-factory tools get this for free from the method and endpoint they already
	 * declare, so it cannot drift from what they call. Custom tools must state it: nobody can
	 * infer the route list of a hand-written handler without running it.
	 */
	routes?: ToolRouteMetadata
	/**
	 * The same declaration in the shape scripts/check-tool-routes.mjs reads. Derived from
	 * `routes`, never authored by hand, so the audit view and the view the server routes on
	 * cannot disagree.
	 */
	route?: { routes: { method: string; path: string }[]; composite: boolean }
	handler: (
		input: Record<string, unknown>,
		execution?: ToolExecutionContext,
	) => Promise<{
		content: { type: 'text'; text: string }[]
		isError?: boolean
	}>
}

export interface ToolExecutionContext {
	/** Cancels upstream work when the owning execution or transport no longer needs the result. */
	signal?: AbortSignal
}

interface BaseToolConfig {
	name: string
	title: string
	description: string
	schema: z.ZodObject<z.ZodRawShape>
	annotations?: Partial<ToolAnnotations>
}

interface EndpointToolConfig extends BaseToolConfig {
	endpoint: string
	/** Ordered fallbacks for a store that serves a retired route instead of the current one. */
	routes?: ToolRouteMetadata
	isPublic?: boolean
	/**
	 * Rewrite the query object before dispatch.
	 *
	 * For routes whose parameters are not simply the tool's inputs — a relation the caller should
	 * not have to know to ask for, or a key shape the client cannot express from a schema field.
	 */
	query?: (input: Record<string, unknown>) => Record<string, unknown>
	transform?: (data: unknown) => unknown
	cache?: { key: string; ttlMs: number }
	/** Cache keys to invalidate on successful write (POST/PUT/DELETE). */
	invalidates?: string[]
}

interface CustomToolConfig extends BaseToolConfig {
	/** Required in practice; the migration test names any tool that omits it. */
	routes?: ToolRouteMetadata
	/**
	 * Successful custom-handler output is redacted by default because it may contain upstream
	 * data. Disable only when the handler returns a locally generated protocol secret that the
	 * caller must receive, such as a short-lived guarded-action confirmation token.
	 */
	redactOutput?: boolean
	handler: (client: FluentCartClient, input: Record<string, unknown>) => Promise<unknown>
}

export interface ToolCacheDeps {
	cache: PrincipalScopedCache
	scope: CacheScope
}

/**
 * Bind endpoint caches to the client context that owns their authorisation.
 *
 * A WeakMap prevents a module-global response cache from crossing stores or principals. Production
 * supplies the store/principal/route scope; direct factory callers get a cache private to their
 * client object, which is safe even when no identity metadata is available.
 */
const CACHE_BY_CLIENT = new WeakMap<FluentCartClient, ToolCacheDeps>()

function toolCacheDeps(client: FluentCartClient): ToolCacheDeps {
	const existing = CACHE_BY_CLIENT.get(client)
	if (existing) return existing

	const isolated = {
		cache: new PrincipalScopedCache(),
		scope: {
			origin: 'client-local',
			principal: 'client-local',
			routeProfile: 'undiscovered',
		},
	}
	CACHE_BY_CLIENT.set(client, isolated)
	return isolated
}

export function configureToolCache(client: FluentCartClient, deps?: ToolCacheDeps): void {
	if (deps) CACHE_BY_CLIENT.set(client, deps)
	else toolCacheDeps(client)
}

export function invalidateToolCache(client: FluentCartClient, operation: string): number {
	const deps = toolCacheDeps(client)
	return deps.cache.invalidate(deps.scope, operation)
}

function clientWithSignal(
	client: FluentCartClient,
	signal: AbortSignal | undefined,
): FluentCartClient {
	if (!signal) return client
	return {
		request: (method, path, options = {}) => client.request(method, path, { ...options, signal }),
		get: (path, params, isPublic) => client.get(path, params, isPublic, signal),
		post: (path, body, isPublic) => client.post(path, body, isPublic, signal),
		put: (path, body) => client.put(path, body, signal),
		delete: (path, params) => client.delete(path, params, signal),
	} as FluentCartClient
}

/**
 * Retained for compatibility with callers that imported it. The real limits now live in
 * src/commerce/response-budget.ts, where they are enforced rather than approximated.
 */
export const MAX_RESPONSE_CHARS = 80_000

/**
 * Look up the reviewed safety row for a tool.
 *
 * The verb-derived annotation is consulted only to pick the default for an unlisted name: a
 * read stays a read, and an unlisted write becomes `unreviewed-write` and is hidden. The verb
 * never grants a tool more permission than the registry gave it.
 */
function resolveSafety(name: string, verbAnnotations: ToolAnnotations): ToolSafety {
	return resolveToolSafety(name, verbAnnotations.readOnlyHint === true)
}

/**
 * Annotations describe business semantics, not the HTTP verb.
 *
 * FluentCart serves several pure reads over POST and several irreversible edits over PUT, so
 * deriving `readOnlyHint` from the method would mislabel both. The reviewed risk row decides.
 */
function annotationsFor(safety: ToolSafety): ToolAnnotations {
	if (safety.risk === 'read') {
		return { readOnlyHint: true, idempotentHint: true, destructiveHint: false, openWorldHint: true }
	}

	const destructive = safety.risk !== 'reversible-write'
	return {
		readOnlyHint: false,
		destructiveHint: destructive,
		idempotentHint: safety.idempotency === 'inherent',
		openWorldHint: true,
	}
}

/**
 * Filter keys every FluentCart report controller reads from a nested `params` object.
 *
 * Mostly `ReportHelper::sanitizeParams` (app/Services/Report/ReportHelper.php:169-189), the
 * allowlist every report route runs its input through. `paymentStatus` is the exception: it is
 * absent from that list but read straight off the request at
 * `ReportingController.php:167` — `$request->get('params.paymentStatus')` — so a tool that sent it
 * flat would have it silently ignored, which is the same failure this nesting exists to prevent.
 */
const REPORT_PARAM_KEYS: ReadonlySet<string> = new Set([
	'startDate',
	'endDate',
	'compareType',
	'compareDate',
	'groupKey',
	'currency',
	'filterMode',
	'storeMode',
	'subscriptionType',
	'paymentStatus',
	'variation_ids',
	'orderStatus',
	// The snake_case twin is a different key, not a spelling variant: `/reports/search-repeat-customer`
	// reads `order_status`, and without the nesting it was dropped and the report could only ever
	// answer with nobody. See fluentcart_report_repeat_customers.
	'order_status',
	'orderTypes',
])

/**
 * Move report filters into the `params` object the controller actually reads.
 *
 * Every report controller starts with `ReportHelper::processParams($request->get('params'), …)`,
 * so a flat `?startDate=…` is simply never seen. The endpoint does not reject it — it falls back
 * to defaults and answers HTTP 200 with zeros, an empty array, or in one case a 500. Measured on
 * a 34-order store, flat versus nested for the same range:
 *
 *   /reports/revenue                  399 B, every figure 0   →  2,284 B with real totals
 *   /reports/fetch-top-sold-products   22 B, no rows          →  2,273 B with real rows
 *   /reports/subscription-retention   389 B, one row          →  4,429 B, full MRR series
 *   /reports/top-products-sold        HTTP 500                →    185 B deprecation notice
 *
 * This is applied by route rather than declared per tool, because the failure is silent: a report
 * added later that forgot the flag would look like an empty store rather than a bug. Only keys in
 * the allowlist move, so paging and anything the controller reads at the top level are untouched.
 */
function nestReportParams(
	endpoint: string,
	rest: Record<string, unknown>,
): Record<string, unknown> {
	if (!endpoint.startsWith('/reports/')) return rest

	const nested: Record<string, unknown> = {}
	for (const [key, value] of Object.entries(rest)) {
		nested[REPORT_PARAM_KEYS.has(key) ? `params[${key}]` : key] = value
	}
	return nested
}

/**
 * A path parameter is one segment, and only ever one segment.
 *
 * Interpolating it raw let a caller leave the route entirely. `fluentcart_email_get` declares
 * `GET /email-notification/{param}`, and `{notification: '../../../wp/v2/users'}` resolved to
 * `https://store.example/wp-json/wp/v2/users` — WordPress core's user list, requested with the
 * store administrator's credentials attached. Every containment mechanism this server has (the
 * `routes` declarations, `scripts/check-tool-routes.mjs`, capability pruning) describes routes a
 * tool may reach, and all of them were bypassed by a string.
 *
 * Percent-encoding closes four variants at once, because each depends on a character surviving
 * into the URL: `/` for traversal, `?` for smuggled query parameters, `#` for truncating the path
 * so a different record is fetched and reported as the requested one, and a newline, which the URL
 * parser silently deletes so `x\ny` and `xy` address the same record invisibly.
 *
 * `.` and `..` are rejected outright rather than encoded: they are never a real identifier, and a
 * caller sending one has misunderstood something that should be corrected loudly.
 */
export function encodePathParameter(endpoint: string, key: string, value: unknown): string {
	const raw = String(value ?? '')
	if (raw === '.' || raw === '..') {
		throw new Error(
			`Path parameter "${key}" in ${endpoint} cannot be "${raw}"; it must identify a single record.`,
		)
	}
	return encodeURIComponent(raw)
}

function resolveEndpoint(
	endpoint: string,
	input: Record<string, unknown>,
): { path: string; rest: Record<string, unknown> } {
	const rest = { ...input }
	const path = endpoint.replace(/:(\w+)/g, (_, key: string) => {
		const value = rest[key]
		delete rest[key]
		return encodePathParameter(endpoint, key, value)
	})
	if (path.includes('//') || path.endsWith('/')) {
		throw new Error(`Missing required path parameter in ${endpoint}`)
	}
	return { path, rest: nestReportParams(endpoint, rest) }
}

/**
 * Reject an over-budget payload instead of quietly shortening it.
 *
 * The previous implementation sliced arrays to roughly 85% of the cap and returned the result
 * as a success marked `_truncated`. That had two failure modes worth remembering: a single
 * record larger than the cap could not be shrunk at all and was returned whole (measured at
 * 200,065 characters against an 80,000 cap, still flagged as success), and a shortened page
 * paired with an advancing page number silently skipped the rows it had dropped.
 *
 * A caller cannot distinguish a truncated list from a short one, so truncation is not a
 * kindness. Bound the request, then return the page whole or say why you cannot.
 */
export function truncateResponse(data: unknown): unknown {
	assertWithinEmergencyCap(data, 'this request')
	return data
}

/**
 * Whether the caller has a page size to shrink.
 *
 * Read off the schema, because the schema is the contract the caller sees. A tool without
 * `per_page` in it cannot be asked for a smaller answer, so it is held to the emergency cap
 * and never told to try — that advice was the whole defect on `fluentcart_variant_list_all`.
 *
 * A tool that advertises `per_page` and ignores it would get advice that cannot work, which is
 * a bug in that tool rather than in this rule: an advertised parameter that does nothing is
 * already a lie to the caller, and `tests/tools/payload-and-cache.test.ts` exists to catch it.
 */
function isPageable(schema: z.ZodObject<z.ZodRawShape>): boolean {
	return Object.hasOwn(schema.shape, 'per_page')
}

/**
 * The Laravel paginator keys that address pages by URL, all of which a tool caller pages past
 * by number instead. `path` additionally publishes the store's internal REST URL in every list
 * response, which is not the tool's to give away.
 */
const PAGINATOR_LINK_KEYS: readonly string[] = [
	'links',
	'first_page_url',
	'last_page_url',
	'next_page_url',
	'prev_page_url',
	'path',
]

/**
 * A Laravel LengthAwarePaginator, and nothing that merely resembles one.
 *
 * Both conditions matter. `path` and `links` are ordinary words — an attachment row, a file
 * record and a template all legitimately carry one — so removing them by name alone would
 * delete real data. Requiring a `data` array beside a `current_page` is the shape FluentCart
 * actually serves on every paginated route, checked against eleven of them live.
 */
function isPaginator(record: Record<string, unknown>): boolean {
	return Array.isArray(record.data) && Object.hasOwn(record, 'current_page')
}

/**
 * Drop the URL half of the pagination envelope, keeping the facts a caller pages on.
 *
 * The envelope is perverse under paging: `links[]` carries one object per page, so a SMALLER
 * per_page produces MORE of them. Measured live, `fluentcart_order_list` at per_page 1 spent
 * 1,708 of 2,086 characters — 82% — on links to pages nobody will ever dereference, and
 * `fluentcart_shipping_class_list` 518 of 627. Asking for less data cost more tokens.
 *
 * `current_page`, `last_page`, `per_page`, `total`, `from` and `to` are left exactly as the
 * store sent them: they are how a caller knows where it is and whether to ask again.
 *
 * Bounded at six levels because every paginator observed sits at depth one or two, and an
 * unbounded walk would recurse as deep as a hostile body cares to nest. Objects that lose
 * nothing are returned by reference, so a payload with no envelope is not copied at all.
 */
function stripPaginationLinks(value: unknown, depth = 0): unknown {
	if (depth > 6 || value === null || typeof value !== 'object') return value

	if (Array.isArray(value)) {
		let changed = false
		const mapped = value.map((entry) => {
			const next = stripPaginationLinks(entry, depth + 1)
			if (next !== entry) changed = true
			return next
		})
		return changed ? mapped : value
	}

	const record = value as Record<string, unknown>
	const dropping = isPaginator(record)
	let changed = false
	const result: Record<string, unknown> = {}

	for (const [key, inner] of Object.entries(record)) {
		if (dropping && PAGINATOR_LINK_KEYS.includes(key)) {
			changed = true
			continue
		}
		const next = stripPaginationLinks(inner, depth + 1)
		if (next !== inner) changed = true
		result[key] = next
	}

	return changed ? result : value
}

/**
 * Reduce the response, bound it, then serialise it.
 *
 * Before this, every tool outside `search` and `reference_data` was bounded solely by the
 * 40,000-character emergency cap, so a paged read could return 24,749 characters (measured:
 * `fluentcart_customer_list` at per_page 100) with nothing objecting. The budget that applies
 * is the one whose remedy exists; see assertToolResponseBudget.
 *
 * The envelope is stripped before the budget is checked, so the limit judges what the caller
 * actually receives rather than what the store happened to wrap it in.
 */
function formatSuccess(data: unknown, budget: { context: string; pageable: boolean }) {
	const reduced = stripPaginationLinks(data)
	assertToolResponseBudget(reduced, budget.context, { pageable: budget.pageable })
	return {
		content: [{ type: 'text' as const, text: JSON.stringify(reduced) }],
	}
}

function formatError(error: unknown) {
	if (error instanceof ResponseTooLargeError) {
		return {
			content: [{ type: 'text' as const, text: `Error [${error.code}]: ${error.message}` }],
			isError: true,
		}
	}

	// Upstream error payloads routinely echo the request, so redact before the text is built.
	if (error instanceof FluentCartApiError) {
		let text = `Error [${error.code}]: ${redactSensitive(error.message) as string}`
		if (error.detail !== undefined) {
			const redactedDetail = redactSensitive(error.detail)
			const detailStr =
				typeof redactedDetail === 'string' ? redactedDetail : JSON.stringify(redactedDetail)
			text += `: ${detailStr}`
		}
		return {
			content: [{ type: 'text' as const, text }],
			isError: true,
		}
	}
	const message = error instanceof Error ? error.message : String(error)
	return {
		content: [{ type: 'text' as const, text: `Error: ${redactSensitive(message) as string}` }],
		isError: true,
	}
}

export function createTool(client: FluentCartClient, config: CustomToolConfig): ToolDefinition {
	return {
		name: config.name,
		title: config.title,
		description: config.description,
		schema: config.schema,
		annotations: annotationsFor(
			resolveSafety(config.name, { openWorldHint: true, ...config.annotations }),
		),
		safety: resolveSafety(config.name, { openWorldHint: true, ...config.annotations }),
		routes: config.routes,
		route: auditView(config.routes),
		handler: async (input, execution) => {
			try {
				const result = await config.handler(clientWithSignal(client, execution?.signal), input)
				const output = config.redactOutput === false ? result : redactSensitive(result)
				return formatSuccess(output, {
					context: config.name,
					pageable: isPageable(config.schema),
				})
			} catch (error) {
				return formatError(error)
			}
		},
	}
}

type HttpMethod = 'GET' | 'POST' | 'PUT' | 'DELETE'

const METHOD_ANNOTATIONS: Record<HttpMethod, ToolAnnotations> = {
	GET: { readOnlyHint: true, idempotentHint: true, openWorldHint: true },
	POST: { openWorldHint: true },
	PUT: { idempotentHint: true, openWorldHint: true },
	DELETE: { destructiveHint: true, openWorldHint: true },
}

async function dispatchEndpointRequest(
	client: FluentCartClient,
	method: HttpMethod,
	path: string,
	payload: Record<string, unknown>,
	isPublic: boolean | undefined,
	signal: AbortSignal | undefined,
): Promise<unknown> {
	const scopedClient = clientWithSignal(client, signal)
	switch (method) {
		case 'GET':
			return (await scopedClient.get(path, payload, isPublic)).data
		case 'POST':
			return (await scopedClient.post(path, payload, isPublic)).data
		case 'PUT':
			return (await scopedClient.put(path, payload)).data
		case 'DELETE':
			return (await scopedClient.delete(path, payload)).data
	}
}

function createEndpointTool(
	client: FluentCartClient,
	method: HttpMethod,
	config: EndpointToolConfig,
): ToolDefinition {
	const cacheDeps = toolCacheDeps(client)
	const derived: ToolRouteMetadata = config.routes ?? {
		kind: 'direct',
		variants: [{ method, path: config.endpoint }],
	}

	return {
		name: config.name,
		title: config.title,
		description: config.description,
		schema: config.schema,
		annotations: annotationsFor(
			resolveSafety(config.name, { ...METHOD_ANNOTATIONS[method], ...config.annotations }),
		),
		safety: resolveSafety(config.name, { ...METHOD_ANNOTATIONS[method], ...config.annotations }),
		routes: derived,
		route: auditView(derived),
		handler: async (input, execution) => {
			try {
				const resolved = resolveEndpoint(config.endpoint, input)
				const path = resolved.path
				const rest = config.query ? config.query(resolved.rest) : resolved.rest
				const fetcher = async () => {
					const response = await dispatchEndpointRequest(
						client,
						method,
						path,
						rest,
						config.isPublic,
						execution?.signal,
					)
					return config.transform ? config.transform(response) : response
				}
				const data =
					config.cache && !execution?.signal
						? await cacheDeps.cache.getOrLoad(
								cacheDeps.scope,
								config.cache.key,
								{ path, query: rest },
								config.cache.ttlMs,
								fetcher,
							)
						: await fetcher()
				if (config.invalidates) {
					for (const key of config.invalidates) {
						cacheDeps.cache.invalidate(cacheDeps.scope, key)
					}
				}
				// Redaction ran on every failure path and on logs, but never here — so a store that
				// returned a secret in a SUCCESSFUL response handed it straight to the caller, while
				// the same secret in an error body was scrubbed. `fluentcart_payment_get_settings` and
				// `fluentcart_integration_get_global_settings` are plain reads, exposed in every write
				// mode including `disabled`, and returned `secret_key`, `webhook_secret` and
				// `api_token` verbatim. A read-only deployment leaked its own gateway credentials.
				//
				// Applied here rather than inside `formatSuccess` because this path returns what the
				// STORE sent. Custom `createTool` handlers compose their own output, and one of them —
				// the guarded-action preview — must return a `confirm_token` for the protocol to work
				// at all; redacting that would break the mechanism rather than protect anything.
				return formatSuccess(redactSensitive(data), {
					context: config.name,
					pageable: isPageable(config.schema),
				})
			} catch (error) {
				return formatError(error)
			}
		},
	}
}

export const getTool = (client: FluentCartClient, config: EndpointToolConfig): ToolDefinition =>
	createEndpointTool(client, 'GET', config)

export const postTool = (client: FluentCartClient, config: EndpointToolConfig): ToolDefinition =>
	createEndpointTool(client, 'POST', config)

export const putTool = (client: FluentCartClient, config: EndpointToolConfig): ToolDefinition =>
	createEndpointTool(client, 'PUT', config)

export const deleteTool = (client: FluentCartClient, config: EndpointToolConfig): ToolDefinition =>
	createEndpointTool(client, 'DELETE', config)
