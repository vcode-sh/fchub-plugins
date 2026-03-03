import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { getTool, postTool, type ToolDefinition } from './_factory.js'

export function labelTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_label_list',
			title: 'List Labels',
			description: 'Get all labels for organising orders, customers, and other entities.',
			schema: z.object({}),
			endpoint: '/labels',
		}),

		postTool(client, {
			name: 'fluentcart_label_create',
			title: 'Create Label',
			description: 'Create a new label for tagging orders, customers, etc.',
			schema: z.object({
				title: z.string().describe('Label text (required)'),
				color: z.string().optional().describe('Label colour as hex code (e.g. "#ff0000")'),
				bind_to_type: z
					.string()
					.optional()
					.describe('Entity type: order, customer (default: order)'),
			}),
			endpoint: '/labels',
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
