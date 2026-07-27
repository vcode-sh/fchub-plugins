import { z } from 'zod'
import type { ApiCapabilities } from '../api/capabilities.js'
import type { FluentCartClient } from '../api/client.js'
import { FluentCartApiError } from '../api/errors.js'
import { invalidate, TTL } from '../cache.js'
import { createTool, deleteTool, getTool, putTool, type ToolDefinition } from './_factory.js'
import { composite, op } from './endpoints.js'
import { asNumber } from './tax.js'

/**
 * Pull a class id out of whichever envelope the store used.
 *
 * Create responses vary by release: some return the row, some a bare success message. The
 * caller needs an id either way, so look through the shapes that have been observed rather
 * than assuming one.
 */
function extractId(data: unknown): number | null {
	const wrappers = ['data', 'class', 'tax_class']
	const keys = ['class_id', 'id']
	if (!data || typeof data !== 'object') return null

	const obj = data as Record<string, unknown>
	for (const key of keys) {
		const maybe = asNumber(obj[key])
		if (maybe != null) return maybe
	}

	for (const wrapper of wrappers) {
		const nested = obj[wrapper]
		if (!nested || typeof nested !== 'object') continue
		const nestedObj = nested as Record<string, unknown>
		for (const key of keys) {
			const maybe = asNumber(nestedObj[key])
			if (maybe != null) return maybe
		}
	}

	return null
}

export function taxClassTools(
	client: FluentCartClient,
	capabilities?: ApiCapabilities,
): ToolDefinition[] {
	// The current registry serves GET and POST on /tax/classes and DELETE on /tax/classes/{id},
	// but no update verb. Registering an update against a path that only accepts DELETE would
	// offer an edit that cannot happen — and on a store where DELETE is the sole verb, an
	// optimistic retry is the one outcome nobody wants.
	const canUpdate = capabilities?.has('PUT', '/tax/classes/{param}') === true

	return [
		getTool(client, {
			name: 'fluentcart_tax_class_list',
			title: 'List Tax Classes',
			description: 'List all tax classes configured in the store.',
			schema: z.object({}),
			endpoint: '/tax/classes',
			cache: { key: 'tax_classes', ttlMs: TTL.MEDIUM },
		}),

		createTool(client, {
			name: 'fluentcart_tax_class_create',
			routes: composite(op('POST', '/tax/classes'), op('GET', '/tax/classes')),
			title: 'Create Tax Class',
			description:
				'Create a new tax class for categorising products with different tax rates. ' +
				'Accepts `title` (preferred) and `name` (legacy alias).',
			schema: z.object({
				title: z.string().optional().describe('Tax class title (required)'),
				name: z.string().optional().describe('Legacy alias for title'),
				description: z.string().optional().describe('Description'),
			}),
			handler: async (c, input) => {
				const title = (input.title as string | undefined) || (input.name as string | undefined)
				if (!title) {
					throw new FluentCartApiError(
						'VALIDATION_ERROR',
						'Validation error: title is required',
						422,
					)
				}

				const body: Record<string, unknown> = { title }
				if (input.description !== undefined) body.description = input.description

				const created = await c.post('/tax/classes', body)
				invalidate('tax_classes')
				const directId = extractId(created.data)
				if (directId != null) return created.data

				// Some runtimes only return a success message; enrich with class_id via list lookup.
				const list = await c.get('/tax/classes')
				const classes = ((list.data as Record<string, unknown>).tax_classes ?? []) as Array<
					Record<string, unknown>
				>
				const matched = classes.find((taxClass) => taxClass.title === title)
				const classId = matched ? asNumber(matched.id) : null
				if (classId == null) return created.data

				return {
					...(created.data as Record<string, unknown>),
					class_id: classId,
					class: matched,
					_enriched: true,
				}
			},
		}),

		...(canUpdate
			? [
					putTool(client, {
						name: 'fluentcart_tax_class_update',
						title: 'Update Tax Class',
						description:
							'Update a tax class title or description. ' +
							'Withdrawn after FluentCart 1.3.9 — available only on stores that still serve it.',
						schema: z.object({
							class_id: z.number().describe('Tax class ID'),
							title: z.string().optional().describe('Tax class title'),
							description: z.string().optional().describe('Description'),
						}),
						endpoint: '/tax/classes/:class_id',
						invalidates: ['tax_classes'],
					}),
				]
			: []),

		deleteTool(client, {
			name: 'fluentcart_tax_class_delete',
			title: 'Delete Tax Class',
			description: 'Delete a tax class. This action cannot be undone.',
			schema: z.object({
				class_id: z.number().describe('Tax class ID'),
			}),
			endpoint: '/tax/classes/:class_id',
			invalidates: ['tax_classes'],
		}),
	]
}
