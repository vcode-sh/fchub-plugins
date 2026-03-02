import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { deleteTool, getTool, putTool, type ToolDefinition } from './_factory.js'

export function activityTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_activity_list',
			title: 'List Activities',
			description:
				'Retrieve a paginated list of activity log entries. ' +
				'Supports filtering by log type, module name, and module ID. ' +
				'Returns audit trail of actions performed in the store.',
			schema: z.object({
				page: z.number().optional().describe('Page number (default: 1)'),
				per_page: z.number().max(50).optional().describe('Results per page (default: 10, max: 50)'),
				search: z.string().optional().describe('Search activity logs by keyword'),
				log_type: z.string().optional().describe('Filter by log type'),
				module_name: z.string().optional().describe('Filter by module name (e.g. "Order")'),
				module_id: z.number().optional().describe('Filter by module ID'),
			}),
			endpoint: '/activity',
		}),

		deleteTool(client, {
			name: 'fluentcart_activity_delete',
			title: 'Delete Activity',
			description: 'Delete a specific activity log entry. This action is irreversible.',
			schema: z.object({
				activity_id: z.number().describe('Activity log entry ID to delete'),
			}),
			endpoint: '/activity/:activity_id',
		}),

		putTool(client, {
			name: 'fluentcart_activity_mark_read',
			title: 'Mark Activity Read',
			description:
				'Update the read status of an activity log entry. ' + 'Can mark as either read or unread.',
			schema: z.object({
				activity_id: z.number().describe('Activity log entry ID'),
				status: z.enum(['read', 'unread']).describe('New read status: "read" or "unread"'),
			}),
			endpoint: '/activity/:activity_id/mark-read',
		}),
	]
}
