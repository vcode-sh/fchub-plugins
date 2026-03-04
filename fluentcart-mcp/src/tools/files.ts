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
			description:
				'Upload a file. ' +
				'WARNING: This endpoint requires multipart/form-data file upload which is not supported via JSON API. ' +
				'The backend reads files from $request->files(), not from JSON body fields. ' +
				'This tool will not work until FluentCart adds URL-based file import support.',
			schema: z.object({
				file_url: z
					.string()
					.optional()
					.describe('URL to download file from (NOT SUPPORTED by backend)'),
				file_name: z.string().optional().describe('File name (backend validates as "name")'),
				bucket: z.string().optional().describe('Storage bucket name'),
			}),
			endpoint: '/files/upload',
		}),

		getTool(client, {
			name: 'fluentcart_file_bucket_list',
			title: 'List File Buckets',
			description:
				'List available file storage buckets. ' +
				'driver is required — use "local" for WordPress uploads. ' +
				'WARNING: Some driver values may trigger an "Invalid driver" error in the backend.',
			schema: z.object({
				driver: z
					.string()
					.describe('Storage driver name (required — use "local" for WordPress uploads)'),
			}),
			endpoint: '/files/bucket-list',
		}),

		deleteTool(client, {
			name: 'fluentcart_file_delete',
			title: 'Delete File',
			description:
				'Delete one or more files. Use file_id for a single file, or file_ids for bulk deletion. This action cannot be undone.',
			schema: z.object({
				file_id: z.number().optional().describe('Single file ID to delete (use this OR file_ids)'),
				file_ids: z
					.array(z.number())
					.optional()
					.describe('Array of file IDs for bulk deletion (use this OR file_id)'),
			}),
			endpoint: '/files/delete',
		}),
	]
}
