import type { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { deleteTool, getTool, postTool, putTool, type ToolDefinition } from './_factory.js'

interface ResourceConfig {
	/** Singular resource name, e.g. 'order' */
	resource: string
	/** Display name, e.g. 'Order' */
	displayName: string
	/** Base API path, e.g. '/orders' */
	basePath: string
	/** ID field name, e.g. 'order_id' */
	idField: string
	/** Schema for list endpoint query params */
	listSchema?: z.ZodObject<z.ZodRawShape>
	/** Schema for create endpoint body */
	createSchema?: z.ZodObject<z.ZodRawShape>
	/** Schema for update endpoint body (must include ID) */
	updateSchema?: z.ZodObject<z.ZodRawShape>
	/** Schema for get-by-id (must include ID) */
	getSchema?: z.ZodObject<z.ZodRawShape>
	/** Schema for delete (must include ID) */
	deleteSchema?: z.ZodObject<z.ZodRawShape>
	/** Additional description context for list */
	listDescription?: string
	/** Additional description context for get */
	getDescription?: string
}

export function createResourceTools(
	client: FluentCartClient,
	config: ResourceConfig,
): ToolDefinition[] {
	const tools: ToolDefinition[] = []
	const prefix = 'fluentcart'
	const { resource, displayName, basePath, idField } = config

	if (config.listSchema) {
		tools.push(
			getTool(client, {
				name: `${prefix}_${resource}_list`,
				title: `List ${displayName}s`,
				description:
					config.listDescription ??
					`Retrieve a paginated list of ${displayName.toLowerCase()}s. Monetary values are in the smallest currency unit (cents).`,
				schema: config.listSchema,
				endpoint: basePath,
			}),
		)
	}

	if (config.getSchema) {
		tools.push(
			getTool(client, {
				name: `${prefix}_${resource}_get`,
				title: `Get ${displayName}`,
				description:
					config.getDescription ??
					`Retrieve a single ${displayName.toLowerCase()} by ID. Monetary values are in the smallest currency unit (cents).`,
				schema: config.getSchema,
				endpoint: `${basePath}/:${idField}`,
			}),
		)
	}

	if (config.createSchema) {
		tools.push(
			postTool(client, {
				name: `${prefix}_${resource}_create`,
				title: `Create ${displayName}`,
				description: `Create a new ${displayName.toLowerCase()}. Monetary values must be in the smallest currency unit (cents).`,
				schema: config.createSchema,
				endpoint: basePath,
			}),
		)
	}

	if (config.updateSchema) {
		tools.push(
			putTool(client, {
				name: `${prefix}_${resource}_update`,
				title: `Update ${displayName}`,
				description: `Update an existing ${displayName.toLowerCase()} by ID.`,
				schema: config.updateSchema,
				endpoint: `${basePath}/:${idField}`,
			}),
		)
	}

	if (config.deleteSchema) {
		tools.push(
			deleteTool(client, {
				name: `${prefix}_${resource}_delete`,
				title: `Delete ${displayName}`,
				description: `Permanently delete a ${displayName.toLowerCase()} by ID. This action cannot be undone.`,
				schema: config.deleteSchema,
				endpoint: `${basePath}/:${idField}`,
			}),
		)
	}

	return tools
}
