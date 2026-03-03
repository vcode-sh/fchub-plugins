import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { FluentCartApiError } from '../api/errors.js'
import { createTool, deleteTool, getTool, postTool, type ToolDefinition } from './_factory.js'

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

		createTool(client, {
			name: 'fluentcart_attribute_group_update',
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

		getTool(client, {
			name: 'fluentcart_attribute_term_list',
			title: 'List Attribute Terms',
			description: 'Get all terms for an attribute group (e.g. Small, Medium, Large under Size).',
			schema: z.object({
				group_id: z.number().describe('Parent attribute group ID'),
			}),
			endpoint: '/options/attr/group/:group_id/terms',
		}),

		createTool(client, {
			name: 'fluentcart_attribute_term_create',
			title: 'Create Attribute Term',
			description:
				'Create a term within an attribute group (e.g. add "Red" to Color). ' +
				'Note: slug is required. Known FluentCart bug (<=1.3.9): term creation via API ' +
				'fails with "Information mismatch" because the server validates against the wrong ' +
				'database table. If this happens, terms must be created via the admin UI at ' +
				'/wp-admin/ > FluentCart > Settings > Product Options.',
			schema: z.object({
				group_id: z.number().describe('Parent attribute group ID'),
				title: z.string().describe('Term display name (e.g. "Red", "Large")'),
				slug: z.string().describe('URL-friendly identifier (required, e.g. "red", "large")'),
			}),
			handler: async (client, input) => {
				const groupId = input.group_id as number
				const body = { title: input.title, slug: input.slug }
				try {
					const resp = await client.post(`/options/attr/group/${groupId}/term`, body)
					return resp.data
				} catch (error) {
					if (
						error instanceof FluentCartApiError &&
						error.message.includes('Information mismatch')
					) {
						throw new FluentCartApiError(
							'SERVER_ERROR',
							`FluentCart bug: attribute term creation fails via API due to a validation ` +
								`defect in AttrTermResource::create() (queries fct_atts_terms instead of ` +
								`fct_atts_groups to validate the group). This affects FluentCart <=1.3.9. ` +
								`Workaround: create terms manually via the admin UI at ` +
								`FluentCart > Settings > Product Options, or ask the FluentCart team to fix ` +
								`AttrTermResource.php line 105.`,
						)
					}
					throw error
				}
			},
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
