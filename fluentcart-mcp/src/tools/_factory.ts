import type { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { redactSensitive } from '../security/redaction.js'
import type { ToolRouteMetadata } from './endpoint-types.js'
import { auditView } from './endpoints.js'
import type { ToolSafety } from './risk.js'
import { resolveToolSafety } from './risk-registry.js'
import { toolCacheDeps } from './tool-cache-runtime.js'
import {
	clientWithSignal,
	dispatchEndpointRequest,
	type HttpMethod,
	resolveEndpoint,
} from './tool-endpoint-runtime.js'
import { formatError, formatSuccess, isPageable } from './tool-response.js'

export {
	configureToolCache,
	invalidateToolCache,
	type ToolCacheDeps,
} from './tool-cache-runtime.js'
export { encodePathParameter } from './tool-endpoint-runtime.js'
export { MAX_RESPONSE_CHARS, truncateResponse } from './tool-response.js'

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
	route?:
		| { routes: { method: string; path: string }[]; composite: boolean }
		| { routes: []; unsupported: true }
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
	 * caller must receive.
	 */
	redactOutput?: boolean
	handler: (client: FluentCartClient, input: Record<string, unknown>) => Promise<unknown>
}

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
				// STORE sent. Custom `createTool` handlers compose their own output.
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
