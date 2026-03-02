import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { postTool, type ToolDefinition } from './_factory.js'

export function noteTools(client: FluentCartClient): ToolDefinition[] {
	return [
		postTool(client, {
			name: 'fluentcart_note_attach',
			title: 'Attach Note to Order',
			description: 'Attach or update a note on an order (admin comments, memos, status updates).',
			schema: z.object({
				order_id: z.number().describe('Order ID to attach the note to'),
				note: z.string().describe('Note content'),
			}),
			endpoint: '/notes/attach',
		}),
	]
}
