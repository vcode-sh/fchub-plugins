import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { getTool, postTool, type ToolDefinition } from './_factory.js'

export function applicationTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_app_init',
			title: 'Initialise Application',
			description:
				'Retrieve full application bootstrap data including translations, shop config, REST config, and asset URLs. ' +
				'Use to validate the connection and inspect store configuration.',
			schema: z.object({}),
			endpoint: '/app/init',
		}),

		getTool(client, {
			name: 'fluentcart_app_get_attachments',
			title: 'Get Attachments',
			description: 'Retrieve WordPress media library attachments available to FluentCart.',
			schema: z.object({}),
			endpoint: '/app/attachments',
		}),

		postTool(client, {
			name: 'fluentcart_app_upload_attachment',
			title: 'Upload Attachment',
			description:
				'Upload a media attachment to the WordPress media library via FluentCart. ' +
				'Note: the underlying API expects multipart/form-data; this tool sends JSON ' +
				'which may have limited support. For binary uploads, use the WP media uploader directly.',
			schema: z.object({
				file: z.string().describe('URL or path of the file to attach'),
			}),
			endpoint: '/app/upload-attachments',
		}),

		getTool(client, {
			name: 'fluentcart_app_get_widgets',
			title: 'Get Dashboard Widgets',
			description:
				'Retrieve dashboard widget data for the FluentCart admin panel. ' +
				'Optionally filter by widget type or request specific data.',
			schema: z.object({
				filter: z.string().optional().describe('Widget filter type'),
				data: z.string().optional().describe('Specific widget data to retrieve'),
			}),
			endpoint: '/widgets',
		}),
	]
}
