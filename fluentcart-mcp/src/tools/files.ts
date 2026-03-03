import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { deleteTool, getTool, postTool, type ToolDefinition } from './_factory.js'

export function fileTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_file_list',
			title: 'List Files',
			description: 'List uploaded files with optional search.',
			schema: z.object({
				page: z.number().optional().describe('Page number'),
				per_page: z.number().max(50).optional().describe('Results per page (max: 50)'),
				search: z.string().optional().describe('Search files by name'),
			}),
			endpoint: '/files',
		}),

		postTool(client, {
			name: 'fluentcart_file_upload',
			title: 'Upload File',
			description: 'Upload a file (provide URL or base64 content).',
			schema: z.object({
				file_url: z.string().optional().describe('URL to download file from'),
				file_name: z.string().optional().describe('File name'),
				bucket: z.string().optional().describe('Storage bucket name'),
			}),
			endpoint: '/files/upload',
		}),

		getTool(client, {
			name: 'fluentcart_file_bucket_list',
			title: 'List File Buckets',
			description: 'List available file storage buckets.',
			schema: z.object({}),
			endpoint: '/files/bucket-list',
		}),

		deleteTool(client, {
			name: 'fluentcart_file_delete',
			title: 'Delete File',
			description: 'Delete a file. This action cannot be undone.',
			schema: z.object({
				file_id: z.number().optional().describe('File ID to delete'),
				file_ids: z.array(z.number()).optional().describe('Array of file IDs to delete'),
			}),
			endpoint: '/files/delete',
		}),
	]
}
