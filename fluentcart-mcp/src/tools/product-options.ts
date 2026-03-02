import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { deleteTool, getTool, postTool, putTool, type ToolDefinition } from './_factory.js'

export function productOptionTools(client: FluentCartClient): ToolDefinition[] {
	return [
		// ── Attribute Groups ─────────────────────────────────────────────

		getTool(client, {
			name: 'fluentcart_attribute_group_list',
			title: 'List Attribute Groups',
			description:
				'Retrieve all product attribute groups (e.g. Size, Color). ' +
				'Returns group ID, title, slug, and associated terms.',
			schema: z.object({}),
			endpoint: '/options/attr/groups',
		}),

		getTool(client, {
			name: 'fluentcart_attribute_group_get',
			title: 'Get Attribute Group',
			description: 'Retrieve a specific attribute group by ID, including its terms.',
			schema: z.object({
				group_id: z.number().describe('Attribute group ID'),
			}),
			endpoint: '/options/attr/group/:group_id',
		}),

		postTool(client, {
			name: 'fluentcart_attribute_group_create',
			title: 'Create Attribute Group',
			description:
				'Create a new product attribute group. ' +
				'Groups define option categories like Size, Color, or Material.',
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
			description: 'Update an existing attribute group title or slug.',
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
			description:
				'Permanently delete an attribute group and all its terms. ' +
				'This action cannot be undone.',
			schema: z.object({
				group_id: z.number().describe('Attribute group ID'),
			}),
			endpoint: '/options/attr/group/:group_id',
		}),

		// ── Attribute Terms ──────────────────────────────────────────────

		getTool(client, {
			name: 'fluentcart_attribute_term_list',
			title: 'List Attribute Terms',
			description:
				'Retrieve all terms for a specific attribute group. ' +
				'For example, terms "Small", "Medium", "Large" under the "Size" group.',
			schema: z.object({
				group_id: z.number().describe('Parent attribute group ID'),
			}),
			endpoint: '/options/attr/group/:group_id/terms',
		}),

		postTool(client, {
			name: 'fluentcart_attribute_term_create',
			title: 'Create Attribute Term',
			description:
				'Create a new term within an attribute group. ' +
				'For example, add "Red" to the "Color" group.',
			schema: z.object({
				group_id: z.number().describe('Parent attribute group ID'),
				title: z.string().describe('Term display name (e.g. "Red", "Large")'),
				slug: z
					.string()
					.optional()
					.describe('URL-friendly identifier (auto-generated from title if omitted)'),
			}),
			endpoint: '/options/attr/group/:group_id/term',
		}),

		postTool(client, {
			name: 'fluentcart_attribute_term_update',
			title: 'Update Attribute Term',
			description: 'Update an existing attribute term title or slug.',
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
			description: 'Permanently delete an attribute term. This action cannot be undone.',
			schema: z.object({
				group_id: z.number().describe('Parent attribute group ID'),
				term_id: z.number().describe('Attribute term ID'),
			}),
			endpoint: '/options/attr/group/:group_id/term/:term_id',
		}),

		postTool(client, {
			name: 'fluentcart_attribute_term_reorder',
			title: 'Update Term Sort Order',
			description:
				'Update the sort order (serial) of an attribute term within its group. ' +
				'Lower numbers appear first.',
			schema: z.object({
				group_id: z.number().describe('Parent attribute group ID'),
				term_id: z.number().describe('Attribute term ID'),
				serial: z.number().describe('New sort order position (lower = first)'),
			}),
			endpoint: '/options/attr/group/:group_id/term/:term_id/serial',
		}),
	]
}
