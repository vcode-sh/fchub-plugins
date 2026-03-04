import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { FluentCartApiError } from '../api/errors.js'
import { TTL } from '../cache.js'
import { createTool, getTool, postTool, type ToolDefinition } from './_factory.js'

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

		createTool(client, {
			name: 'fluentcart_role_create',
			title: 'Create Role',
			description:
				'Assign a FluentCart role to a WordPress user. ' +
				'Despite historical naming, backend expects user_id + role_key (not role CRUD payload).',
			schema: z.object({
				user_id: z.number().optional().describe('WordPress user ID to assign role to'),
				role_key: z.string().optional().describe('Role key (e.g. manager, worker, accountant)'),
				slug: z.string().optional().describe('Legacy alias for role_key'),
				key: z.string().optional().describe('Legacy alias for role_key'),
			}),
			handler: async (c, input) => {
				const userId = input.user_id as number | undefined
				const roleKey =
					(input.role_key as string | undefined) ||
					(input.slug as string | undefined) ||
					(input.key as string | undefined)

				if (!userId || !roleKey) {
					throw new FluentCartApiError(
						'VALIDATION_ERROR',
						'Validation error: user_id and role_key are required',
						422,
					)
				}

				const resp = await c.post('/roles', { user_id: userId, role_key: roleKey })
				return resp.data
			},
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

		createTool(client, {
			name: 'fluentcart_role_delete',
			title: 'Delete Role',
			description:
				'Remove a FluentCart role assignment from a user. ' +
				'Backend requires role key in path and user_id in request.',
			schema: z.object({
				key: z.string().describe('Role key to remove'),
				user_id: z.number().describe('WordPress user ID'),
			}),
			handler: async (c, input) => {
				const key = input.key as string
				const userId = input.user_id as number
				const resp = await c.delete(`/roles/${key}`, { user_id: userId })
				return resp.data
			},
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
