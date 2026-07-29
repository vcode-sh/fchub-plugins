import type { FluentCartClient } from '../api/client.js'

export type HttpMethod = 'GET' | 'POST' | 'PUT' | 'DELETE'

/**
 * Filter keys every FluentCart report controller reads from a nested `params` object.
 *
 * `paymentStatus` is absent from ReportHelper's allowlist but read directly by
 * ReportingController. The snake-case `order_status` is separately required by the repeat
 * customer report.
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
	'order_status',
	'orderTypes',
])

/**
 * Move report filters into the nested query object FluentCart actually reads.
 *
 * Flat report filters are silently ignored: the upstream route returns HTTP 200 with defaults,
 * zeros, or empty rows. This route-level rule therefore protects newly registered report tools
 * as well as today's definitions.
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
 * A path parameter is exactly one encoded segment.
 *
 * Raw interpolation previously let a value such as `../../../wp/v2/users` leave the declared
 * FluentCart route while retaining administrator credentials. Dots are rejected outright because
 * neither is a valid record identifier.
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

export function resolveEndpoint(
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

/** Scope every API call to the owning MCP execution's cancellation signal. */
export function clientWithSignal(
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

export async function dispatchEndpointRequest(
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
