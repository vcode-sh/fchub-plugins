import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { createTool, deleteTool, getTool, postTool, type ToolDefinition } from './_factory.js'
import { composite, op } from './endpoints.js'

/** Attribute groups (Size, Colour, Material). Terms live in product-options-terms.ts. */
export function productOptionTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_attribute_group_list',
			title: 'List Attribute Groups',
			description: 'Get all product attribute groups (e.g. Size, Color) with their terms.',
			schema: z.object({}),
			endpoint: '/options/attr/groups',
		}),

		getTool(client, {
			name: 'fluentcart_attribute_group_get',
			title: 'Get Attribute Group',
			description: 'Get a specific attribute group by ID, including its terms.',
			schema: z.object({
				group_id: z.number().describe('Attribute group ID'),
			}),
			endpoint: '/options/attr/group/:group_id',
		}),

		postTool(client, {
			name: 'fluentcart_attribute_group_create',
			title: 'Create Attribute Group',
			description: 'Create an attribute group (e.g. Size, Color, Material).',
			schema: z.object({
				title: z.string().describe('Group display name (e.g. "Size", "Color")'),
				slug: z.string().describe('URL-friendly identifier (required, must be unique)'),
				description: z.string().optional().describe('Group description'),
			}),
			endpoint: '/options/attr/group',
		}),

		createTool(client, {
			name: 'fluentcart_attribute_group_update',
			routes: composite(
				op('GET', '/options/attr/group/{param}'),
				op('PUT', '/options/attr/group/{param}'),
			),
			title: 'Update Attribute Group',
			description: 'Update an attribute group title or slug.',
			schema: z.object({
				group_id: z.number().describe('Attribute group ID'),
				title: z.string().optional().describe('Group display name'),
				slug: z.string().optional().describe('URL-friendly identifier'),
			}),
			annotations: { idempotentHint: true, openWorldHint: true },
			handler: async (client, input) => {
				const groupId = input.group_id as number
				// API requires slug on every update — fetch current if not provided
				if (!input.slug) {
					const current = await client.get(`/options/attr/group/${groupId}`)
					const group = (current.data as Record<string, unknown>).group as Record<string, unknown>
					input.slug = group?.slug ?? ''
				}
				const body: Record<string, unknown> = {}
				if (input.title !== undefined) body.title = input.title
				if (input.slug !== undefined) body.slug = input.slug
				const response = await client.put(`/options/attr/group/${groupId}`, body)
				return response.data
			},
		}),

		deleteTool(client, {
			name: 'fluentcart_attribute_group_delete',
			title: 'Delete Attribute Group',
			description: 'Delete an attribute group and all its terms. Cannot be undone.',
			schema: z.object({
				group_id: z.number().describe('Attribute group ID'),
			}),
			endpoint: '/options/attr/group/:group_id',
		}),

		// ── Attribute Terms ──────────────────────────────────────────────
	]
}
