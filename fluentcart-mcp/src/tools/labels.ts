import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { createTool, postTool, type ToolDefinition } from './_factory.js'
import { direct } from './endpoints.js'

export function labelTools(client: FluentCartClient): ToolDefinition[] {
	return [
		createTool(client, {
			name: 'fluentcart_label_list',
			routes: direct('GET', '/labels'),
			title: 'List Labels',
			description:
				'The labels available for organising orders, customers and other records. Pass search to ' +
				'narrow by name, or page and per_page to read a few at a time — the store returns the ' +
				'whole set at once, so both are applied here.',
			schema: z.object({
				search: z.string().optional().describe('Keep only labels whose name contains this text'),
				page: z.number().optional().describe('Page number (default: 1)'),
				per_page: z
					.number()
					.max(100)
					.optional()
					.describe('Labels per page (default: 100, max: 100). Applied here, not by the store'),
			}),
			annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: true },
			// 96 labels cost 12,581 characters, and roughly three fifths of that was `created_at` and
			// `updated_at` — 74 bytes of each 130-byte row. A label is a name you attach to something;
			// when it was first typed is not part of that. The route also took no search, no page and
			// no limit, so the only answer available was the whole set, however large it grew.
			handler: async (apiClient, input) => {
				const response = await apiClient.get('/labels')
				const body = response.data as Record<string, unknown>
				const all = Array.isArray(body?.labels) ? (body.labels as Record<string, unknown>[]) : []

				const term = String(input.search ?? '')
					.trim()
					.toLowerCase()
				const matching = term
					? all.filter((label) =>
							String(label.value ?? '')
								.toLowerCase()
								.includes(term),
						)
					: all

				const page = Math.max(1, (input.page as number) ?? 1)
				const perPage = Math.min(100, Math.max(1, (input.per_page as number) ?? 100))
				const from = (page - 1) * perPage
				const labels = matching
					.slice(from, from + perPage)
					.map((label) => ({ id: label.id, value: label.value }))

				return {
					labels,
					page,
					per_page: perPage,
					total: matching.length,
					has_more: from + labels.length < matching.length,
					...(term ? { total_in_store: all.length } : {}),
				}
			},
		}),

		createTool(client, {
			name: 'fluentcart_label_create',
			routes: direct('POST', '/labels'),
			title: 'Create Label',
			description:
				'Create a new label for tagging orders, customers, etc. ' +
				'Use `value` (preferred). `title` is accepted as a backward-compatible alias.',
			schema: z.object({
				value: z.string().optional().describe('Label text (preferred field)'),
				title: z.string().optional().describe('Deprecated alias for value'),
				color: z.string().optional().describe('Label colour as hex code (e.g. "#ff0000")'),
				bind_to_type: z
					.string()
					.optional()
					.describe('Entity type: order, customer (default: order)'),
			}),
			handler: async (c, input) => {
				const value = ((input.value as string) || (input.title as string) || '').trim()
				if (!value) {
					throw new Error('Label text is required. Provide `value` (or `title` alias).')
				}
				const body: Record<string, unknown> = { value }
				if (input.color !== undefined) body.color = input.color
				if (input.bind_to_type !== undefined) body.bind_to_type = input.bind_to_type
				const resp = await c.post('/labels', body)
				return resp.data
			},
		}),

		postTool(client, {
			name: 'fluentcart_label_update_selections',
			title: 'Update Label Selections',
			description: 'Replace all label assignments on an entity with the provided list.',
			schema: z.object({
				bind_to_type: z.string().describe('Entity type to label (e.g. "order", "customer")'),
				bind_to_id: z.number().describe('Entity ID to update labels for'),
				selectedLabels: z
					.array(z.number())
					.describe('Array of label IDs to assign (replaces existing)'),
			}),
			endpoint: '/labels/update-label-selections',
		}),
	]
}
