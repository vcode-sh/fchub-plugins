import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { deleteTool, getTool, postTool, putTool, type ToolDefinition } from './_factory.js'

export function productOptionTools(client: FluentCartClient): ToolDefinition[] {
	return [
		// ── Attribute Groups ─────────────────────────────────────────────

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

		putTool(client, {
			name: 'fluentcart_attribute_group_update',
			title: 'Update Attribute Group',
			description: 'Update an attribute group title or slug.',
			schema: z.object({
				group_id: z.number().describe('Attribute group ID'),
				title: z.string().optional().describe('Group display name'),
				slug: z.string().optional().describe('URL-friendly identifier'),
			}),
			endpoint: '/options/attr/group/:group_id',
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

		getTool(client, {
			name: 'fluentcart_attribute_term_list',
			title: 'List Attribute Terms',
			description: 'Get all terms for an attribute group (e.g. Small, Medium, Large under Size).',
			schema: z.object({
				group_id: z.number().describe('Parent attribute group ID'),
			}),
			endpoint: '/options/attr/group/:group_id/terms',
		}),

		postTool(client, {
			name: 'fluentcart_attribute_term_create',
			title: 'Create Attribute Term',
			description:
				'Create a term within an attribute group (e.g. add "Red" to Color). ' +
				'Note: slug is required. May fail on some FluentCart versions due to a known ' +
				'validation bug — if so, terms must be created via the admin UI.',
			schema: z.object({
				group_id: z.number().describe('Parent attribute group ID'),
				title: z.string().describe('Term display name (e.g. "Red", "Large")'),
				slug: z.string().describe('URL-friendly identifier (required, e.g. "red", "large")'),
			}),
			endpoint: '/options/attr/group/:group_id/term',
		}),

		postTool(client, {
			name: 'fluentcart_attribute_term_update',
			title: 'Update Attribute Term',
			description: 'Update an attribute term title or slug.',
			schema: z.object({
				group_id: z.number().describe('Parent attribute group ID'),
				term_id: z.number().describe('Attribute term ID'),
				title: z.string().optional().describe('Term display name'),
				slug: z.string().optional().describe('URL-friendly identifier'),
			}),
			endpoint: '/options/attr/group/:group_id/term/:term_id',
		}),

		deleteTool(client, {
			name: 'fluentcart_attribute_term_delete',
			title: 'Delete Attribute Term',
			description: 'Delete an attribute term. Cannot be undone.',
			schema: z.object({
				group_id: z.number().describe('Parent attribute group ID'),
				term_id: z.number().describe('Attribute term ID'),
			}),
			endpoint: '/options/attr/group/:group_id/term/:term_id',
		}),

		postTool(client, {
			name: 'fluentcart_attribute_term_reorder',
			title: 'Update Term Sort Order',
			description: 'Update sort order of an attribute term. Lower numbers appear first.',
			schema: z.object({
				group_id: z.number().describe('Parent attribute group ID'),
				term_id: z.number().describe('Attribute term ID'),
				serial: z.number().describe('New sort order position (lower = first)'),
			}),
			endpoint: '/options/attr/group/:group_id/term/:term_id/serial',
		}),
	]
}
