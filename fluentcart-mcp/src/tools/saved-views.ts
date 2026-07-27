import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { FluentCartApiError } from '../api/errors.js'
import { assertWithinEmergencyCap } from '../commerce/response-budget.js'
import { createTool, type ToolDefinition } from './_factory.js'
import { composite, direct } from './endpoints.js'

/**
 * Saved table views — the filter/sort presets the admin tables remember.
 *
 * `object_type` is required on every route. Omitting it returns 403, not 422, which reads like a
 * permission wall and is really a missing argument: SavedViewsPolicy maps each object type to a
 * capability and denies anything it cannot map, including the empty string. The enum below is
 * that map's key set, so an unknown table fails here rather than as a misleading 403.
 */
const OBJECT_TYPES = [
	'product_table',
	'order_table',
	'customers',
	'coupon_table',
	'subscriptions',
	'licenses',
	'order_bump_table',
	'log_table',
	'taxes_table',
	'shipping_zone_table',
	'shipping_class_table',
] as const

const objectType = z
	.enum(OBJECT_TYPES)
	.describe(
		`Which admin table the view belongs to. One of: ${OBJECT_TYPES.join(', ')}. Required — the store answers 403, not 422, when it is missing.`,
	)

interface SavedViewRow {
	id: number | null
	name: string | null
	objectType: string | null
	isPublic: boolean | null
	description: string | null
}

function projectView(row: unknown): SavedViewRow {
	const record = (row ?? {}) as Record<string, unknown>
	const id = Number(record.id)
	return {
		id: Number.isInteger(id) ? id : null,
		name: typeof record.name === 'string' ? record.name : null,
		objectType: typeof record.object_type === 'string' ? record.object_type : null,
		isPublic: typeof record.is_public === 'boolean' ? record.is_public : null,
		description: typeof record.description === 'string' ? record.description : null,
	}
}

function readViews(payload: unknown): unknown[] {
	const record = (payload ?? {}) as Record<string, unknown>
	return Array.isArray(record.views) ? record.views : []
}

export function savedViewTools(client: FluentCartClient): ToolDefinition[] {
	return [
		createTool(client, {
			name: 'fluentcart_saved_view_list',
			title: 'List Saved Table Views',
			description:
				'List the saved filter/sort presets for one admin table. Returns id, name, object type, ' +
				'visibility and description per view — the stored query itself is not returned, because ' +
				'it is an admin-table blob with no stable public contract. Requires `object_type`.',
			schema: z.object({ object_type: objectType }),
			annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: true },
			routes: direct('GET', '/saved-views'),
			handler: async (c, input) => {
				const response = await c.get('/saved-views', { object_type: input.object_type })
				const views = readViews(response.data).map(projectView)
				assertWithinEmergencyCap(views, 'fluentcart_saved_view_list')
				return { object_type: input.object_type, views, total: views.length }
			},
		}),

		createTool(client, {
			name: 'fluentcart_saved_view_create',
			title: 'Create Saved Table View',
			description:
				'Create a saved filter preset for an admin table. Reversible: the created view has a ' +
				'stable id and can be removed again. Returns the created view, re-read from the store ' +
				'rather than echoed, so a silent server-side rename is visible.',
			schema: z.object({
				object_type: objectType,
				name: z.string().min(1).describe('Display name for the view; must be unique per table'),
				description: z.string().optional().describe('Optional description'),
				is_public: z
					.boolean()
					.optional()
					.describe('Whether every admin sees the view (default: false, private to you)'),
			}),
			annotations: { openWorldHint: true },
			// Composite: the create is followed by a read-back on the same object type, which is how
			// the tool reports what was actually stored rather than what it asked for.
			routes: composite(
				{ method: 'POST', path: '/saved-views' },
				{ method: 'GET', path: '/saved-views' },
			),
			handler: async (c, input) => {
				const body: Record<string, unknown> = {
					object_type: input.object_type,
					name: input.name,
				}
				if (input.description !== undefined) body.description = input.description
				if (input.is_public !== undefined) body.is_public = input.is_public

				const created = await c.post('/saved-views', body)
				const createdId = projectView((created.data as Record<string, unknown>)?.view).id

				const listed = await c.get('/saved-views', { object_type: input.object_type })
				const views = readViews(listed.data).map(projectView)
				const stored =
					views.find((view) => view.id === createdId) ??
					views.find((view) => view.name === input.name) ??
					null

				if (!stored) {
					throw new FluentCartApiError(
						'SERVER_ERROR',
						'The store accepted the saved view but it is absent from the list read back afterwards. ' +
							'Nothing was returned to identify it, so no id can be reported for later removal.',
					)
				}

				return { created: stored, reversal: 'DELETE /saved-views/{id} removes this view.' }
			},
		}),
	]
}
