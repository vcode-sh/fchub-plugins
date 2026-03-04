import type { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { FluentCartApiError } from '../api/errors.js'
import { cached } from '../cache.js'

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
	isPublic?: boolean
	transform?: (data: unknown) => unknown
	cache?: { key: string; ttlMs: number }
}

interface CustomToolConfig extends BaseToolConfig {
	handler: (client: FluentCartClient, input: Record<string, unknown>) => Promise<unknown>
}

export const MAX_RESPONSE_CHARS = 80_000

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

export function truncateResponse(data: unknown): unknown {
	const json = JSON.stringify(data)
	if (json.length <= MAX_RESPONSE_CHARS) return data

	// Array response — slice to fit
	if (Array.isArray(data) && data.length > 0) {
		const avgItemSize = json.length / data.length
		const targetCount = Math.max(1, Math.floor((MAX_RESPONSE_CHARS * 0.85) / avgItemSize))
		const sliced = data.slice(0, targetCount)
		return { _truncated: true, _total: data.length, _showing: sliced.length, items: sliced }
	}

	// Object with data/items array (paginated response)
	if (typeof data === 'object' && data !== null) {
		const obj = data as Record<string, unknown>
		const arrayKey =
			'data' in obj && Array.isArray(obj.data)
				? 'data'
				: 'items' in obj && Array.isArray(obj.items)
					? 'items'
					: null
		if (arrayKey) {
			const arr = obj[arrayKey] as unknown[]
			if (arr.length > 0) {
				const arrJson = JSON.stringify(arr)
				const overhead = json.length - arrJson.length
				const available = MAX_RESPONSE_CHARS * 0.85 - overhead
				const avgItemSize = arrJson.length / arr.length
				const targetCount = Math.max(1, Math.floor(available / avgItemSize))
				const sliced = arr.slice(0, targetCount)
				return {
					...obj,
					[arrayKey]: sliced,
					_truncated: true,
					_total: arr.length,
					_showing: sliced.length,
				}
			}
		}
	}

	// Non-array large response — return notice
	return {
		_truncated: true,
		_chars: json.length,
		_message:
			'Response too large. Use filters, pagination, or more specific queries to reduce size.',
	}
}

function formatSuccess(data: unknown) {
	const truncated = truncateResponse(data)
	return {
		content: [{ type: 'text' as const, text: JSON.stringify(truncated) }],
	}
}

function formatError(error: unknown) {
	if (error instanceof FluentCartApiError) {
		let text = `Error [${error.code}]: ${error.message}`
		if (error.detail !== undefined) {
			const detailStr =
				typeof error.detail === 'string' ? error.detail : JSON.stringify(error.detail)
			text += `: ${detailStr}`
		}
		return {
			content: [{ type: 'text' as const, text }],
			isError: true,
		}
	}
	const message = error instanceof Error ? error.message : String(error)
	return {
		content: [{ type: 'text' as const, text: `Error: ${message}` }],
		isError: true,
	}
}

export function createTool(client: FluentCartClient, config: CustomToolConfig): ToolDefinition {
	return {
		name: config.name,
		title: config.title,
		description: config.description,
		schema: config.schema,
		annotations: {
			openWorldHint: true,
			...config.annotations,
		},
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
	return {
		name: config.name,
		title: config.title,
		description: config.description,
		schema: config.schema,
		annotations: {
			...METHOD_ANNOTATIONS[method],
			...config.annotations,
		},
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
