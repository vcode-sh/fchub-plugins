import type { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'

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
		structuredContent?: Record<string, unknown>
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
}

interface CustomToolConfig extends BaseToolConfig {
	handler: (client: FluentCartClient, input: Record<string, unknown>) => Promise<unknown>
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

function formatSuccess(data: unknown) {
	const text = JSON.stringify(data, null, 2)
	const structured = Array.isArray(data) ? { items: data } : data
	return {
		content: [{ type: 'text' as const, text }],
		structuredContent: structured as Record<string, unknown>,
	}
}

function formatError(error: unknown) {
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

export function getTool(client: FluentCartClient, config: EndpointToolConfig): ToolDefinition {
	return {
		name: config.name,
		title: config.title,
		description: config.description,
		schema: config.schema,
		annotations: {
			readOnlyHint: true,
			idempotentHint: true,
			openWorldHint: true,
			...config.annotations,
		},
		handler: async (input) => {
			try {
				const { path, rest } = resolveEndpoint(config.endpoint, input)
				const response = await client.get(path, rest, config.isPublic)
				return formatSuccess(response.data)
			} catch (error) {
				return formatError(error)
			}
		},
	}
}

export function postTool(client: FluentCartClient, config: EndpointToolConfig): ToolDefinition {
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
				const { path, rest } = resolveEndpoint(config.endpoint, input)
				const response = await client.post(path, rest, config.isPublic)
				return formatSuccess(response.data)
			} catch (error) {
				return formatError(error)
			}
		},
	}
}

export function putTool(client: FluentCartClient, config: EndpointToolConfig): ToolDefinition {
	return {
		name: config.name,
		title: config.title,
		description: config.description,
		schema: config.schema,
		annotations: {
			idempotentHint: true,
			openWorldHint: true,
			...config.annotations,
		},
		handler: async (input) => {
			try {
				const { path, rest } = resolveEndpoint(config.endpoint, input)
				const response = await client.put(path, rest)
				return formatSuccess(response.data)
			} catch (error) {
				return formatError(error)
			}
		},
	}
}

export function deleteTool(client: FluentCartClient, config: EndpointToolConfig): ToolDefinition {
	return {
		name: config.name,
		title: config.title,
		description: config.description,
		schema: config.schema,
		annotations: {
			destructiveHint: true,
			openWorldHint: true,
			...config.annotations,
		},
		handler: async (input) => {
			try {
				const { path, rest } = resolveEndpoint(config.endpoint, input)
				const response = await client.delete(path, rest)
				return formatSuccess(response.data)
			} catch (error) {
				return formatError(error)
			}
		},
	}
}
