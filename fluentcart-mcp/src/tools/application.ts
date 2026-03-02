import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { TTL } from '../cache.js'
import { getTool, postTool, type ToolDefinition } from './_factory.js'

export function applicationTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_app_init',
			title: 'Initialise Application',
			description:
				'Get app bootstrap data: shop config, REST config, asset URLs. Translation strings excluded.',
			schema: z.object({}),
			endpoint: '/app/init',
			cache: { key: 'app_init', ttlMs: TTL.MEDIUM },
			transform: (data) => {
				if (typeof data === 'object' && data !== null) {
					const { trans, ...rest } = data as Record<string, unknown>
					return rest
				}
				return data
			},
		}),

		getTool(client, {
			name: 'fluentcart_app_get_attachments',
			title: 'Get Attachments',
			description: 'Get WordPress media library attachments available to FluentCart.',
			schema: z.object({
				page: z.number().optional().describe('Page number (default: 1)'),
				per_page: z.number().max(50).optional().describe('Results per page (max: 50)'),
			}),
			endpoint: '/app/attachments',
		}),

		postTool(client, {
			name: 'fluentcart_app_upload_attachment',
			title: 'Upload Attachment',
			description:
				'Upload media to WordPress via FluentCart. Sends JSON; for binary uploads use WP media uploader.',
			schema: z.object({
				file: z.string().describe('URL or path of the file to attach'),
			}),
			endpoint: '/app/upload-attachments',
		}),

		getTool(client, {
			name: 'fluentcart_app_get_widgets',
			title: 'Get Dashboard Widgets',
			description: 'Get dashboard widget data for the FluentCart admin panel.',
			schema: z.object({
				filter: z.string().optional().describe('Widget filter type'),
				data: z.string().optional().describe('Specific widget data to retrieve'),
			}),
			endpoint: '/widgets',
		}),
	]
}
