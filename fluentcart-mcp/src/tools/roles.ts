import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { TTL } from '../cache.js'
import { deleteTool, getTool, postTool, type ToolDefinition } from './_factory.js'

export function roleTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_role_list',
			title: 'List Roles',
			description: 'List all FluentCart roles with capabilities.',
			schema: z.object({}),
			endpoint: '/roles',
			cache: { key: 'roles', ttlMs: TTL.MEDIUM },
		}),

		getTool(client, {
			name: 'fluentcart_role_get',
			title: 'Get Role',
			description: 'Get a specific role by key.',
			schema: z.object({
				key: z.string().describe('Role key (e.g. "admin", "shop_manager")'),
			}),
			endpoint: '/roles/:key',
		}),

		postTool(client, {
			name: 'fluentcart_role_create',
			title: 'Create Role',
			description:
				'Create a custom FluentCart role. Note: may return 422 if role creation is restricted to the admin UI.',
			schema: z.object({
				title: z.string().describe('Role display name (required)'),
				slug: z.string().describe('Role key/slug (required)'),
				capabilities: z
					.record(z.string(), z.boolean())
					.optional()
					.describe('Capability map (e.g. {"manage_orders": true})'),
			}),
			endpoint: '/roles',
		}),

		postTool(client, {
			name: 'fluentcart_role_update',
			title: 'Update Role',
			description: 'Update a role (NOTE: POST not PUT per API).',
			schema: z.object({
				key: z.string().describe('Role key'),
				name: z.string().optional().describe('Role display name'),
				capabilities: z.array(z.string()).optional().describe('Array of capability strings'),
			}),
			endpoint: '/roles/:key',
		}),

		deleteTool(client, {
			name: 'fluentcart_role_delete',
			title: 'Delete Role',
			description: 'Delete a custom role. Built-in roles cannot be deleted.',
			schema: z.object({
				key: z.string().describe('Role key to delete'),
			}),
			endpoint: '/roles/:key',
		}),

		getTool(client, {
			name: 'fluentcart_role_managers',
			title: 'List Managers',
			description: 'List users with FluentCart management roles.',
			schema: z.object({}),
			endpoint: '/roles/managers',
		}),

		getTool(client, {
			name: 'fluentcart_role_user_list',
			title: 'List Role Users',
			description: 'List all users with their assigned FluentCart roles.',
			schema: z.object({
				search: z.string().optional().describe('Search users by name or email'),
				page: z.number().optional().describe('Page number'),
				per_page: z.number().max(50).optional().describe('Results per page (max: 50)'),
			}),
			endpoint: '/roles/user-list',
		}),
	]
}
