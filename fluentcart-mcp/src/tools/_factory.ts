import type { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { FluentCartApiError } from '../api/errors.js'
import { cached, invalidate } from '../cache.js'
import { assertWithinEmergencyCap, ResponseTooLargeError } from '../commerce/response-budget.js'
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
	handler: (input: Record<string, unknown>) => Promise<{
		content: { type: 'text'; text: string }[]
		isError?: boolean
	}>
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
	transform?: (data: unknown) => unknown
	cache?: { key: string; ttlMs: number }
	/** Cache keys to invalidate on successful write (POST/PUT/DELETE). */
	invalidates?: string[]
}

interface CustomToolConfig extends BaseToolConfig {
	/** Required in practice; the migration test names any tool that omits it. */
	routes?: ToolRouteMetadata
	handler: (client: FluentCartClient, input: Record<string, unknown>) => Promise<unknown>
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

function resolveEndpoint(
	endpoint: string,
	input: Record<string, unknown>,
): { path: string; rest: Record<string, unknown> } {
	const rest = { ...input }
	const path = endpoint.replace(/:(\w+)/g, (_, key: string) => {
		const value = rest[key]
		delete rest[key]
		return String(value ?? '')
	})
	if (path.includes('//') || path.endsWith('/')) {
		throw new Error(`Missing required path parameter in ${endpoint}`)
	}
	return { path, rest }
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

function formatSuccess(data: unknown) {
	const truncated = truncateResponse(data)
	return {
		content: [{ type: 'text' as const, text: JSON.stringify(truncated) }],
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
		handler: async (input) => {
			try {
				const result = await config.handler(client, input)
				return formatSuccess(result)
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

function createEndpointTool(
	client: FluentCartClient,
	method: HttpMethod,
	config: EndpointToolConfig,
): ToolDefinition {
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
		handler: async (input) => {
			try {
				const { path, rest } = resolveEndpoint(config.endpoint, input)
				const fetcher = async () => {
					let response: { data: unknown }
					switch (method) {
						case 'GET':
							response = await client.get(path, rest, config.isPublic)
							break
						case 'POST':
							response = await client.post(path, rest, config.isPublic)
							break
						case 'PUT':
							response = await client.put(path, rest)
							break
						case 'DELETE':
							response = await client.delete(path, rest)
							break
					}
					return config.transform ? config.transform(response.data) : response.data
				}
				const data = config.cache
					? await cached(config.cache.key, config.cache.ttlMs, fetcher)
					: await fetcher()
				if (config.invalidates) {
					for (const key of config.invalidates) invalidate(key)
				}
				return formatSuccess(data)
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
