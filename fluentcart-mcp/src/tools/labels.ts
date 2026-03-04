import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { createTool, getTool, postTool, type ToolDefinition } from './_factory.js'

export function labelTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_label_list',
			title: 'List Labels',
			description: 'Get all labels for organising orders, customers, and other entities.',
			schema: z.object({}),
			endpoint: '/labels',
		}),

		createTool(client, {
			name: 'fluentcart_label_create',
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
