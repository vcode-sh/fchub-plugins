import { z } from 'zod'
import type { ApiCapabilities } from '../api/capabilities.js'
import type { FluentCartClient } from '../api/client.js'
import { FluentCartApiError } from '../api/errors.js'
import { createTool, deleteTool, getTool, postTool, type ToolDefinition } from './_factory.js'
import { direct } from './endpoints.js'

const TERMS_BULK = '/options/attr/group/{param}/terms'
const TERMS_LEGACY = '/options/attr/group/{param}/term'
const REORDER_BULK = '/options/attr/group/{param}/terms/reorder'
const REORDER_LEGACY = '/options/attr/group/{param}/term/{param}/serial'
const MAX_TERMS = 10
const MAX_TITLE = 50
const HEX_COLOUR = /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/

/**
 * Choose the route this store exposes, current first.
 *
 * Without capability evidence the current route wins: it is the only one present on any
 * supported FluentCart, so defaulting to the retired route would guarantee a 404.
 */
function pick(
	capabilities: ApiCapabilities | undefined,
	current: string,
	legacy: string,
): string | null {
	if (!capabilities) return current
	return [current, legacy].find((path) => capabilities.has('POST', path)) ?? null
}

function invalid(message: string): never {
	throw new FluentCartApiError('VALIDATION_ERROR', `Validation error: ${message}`, 422)
}

/**
 * Build the `terms` payload for the current bulk endpoint.
 *
 * Settings are checked here so a bad hex or a relative image fails before the round trip. Which
 * of them a group *requires* stays with the server: that depends on the group's own type, and
 * the server is the only party that already knows it.
 */
function buildTerms(
	input: Record<string, unknown>,
): { title: string; settings?: Record<string, string> }[] {
	const rows = Array.isArray(input.terms)
		? (input.terms as Record<string, unknown>[])
		: input.title !== undefined
			? [{ title: input.title }]
			: []

	if (rows.length === 0) invalid('provide `terms` (1-10 rows) or a single `title`.')
	if (rows.length > MAX_TERMS) invalid(`cannot create more than ${MAX_TERMS} terms at once.`)

	return rows.map((row) => {
		const title = typeof row.title === 'string' ? row.title.trim() : ''
		if (!title) invalid('each term needs a title.')
		if (title.length > MAX_TITLE) invalid(`each title must be ${MAX_TITLE} characters or fewer.`)

		const settings: Record<string, string> = {}
		if (row.color !== undefined) {
			const colour = String(row.color)
			if (!HEX_COLOUR.test(colour)) invalid(`color must be hex like "#ff0000", got "${colour}".`)
			settings.color = colour
		}
		if (row.image !== undefined) {
			const image = String(row.image)
			if (!/^https?:\/\//i.test(image)) invalid(`image must be an absolute URL, got "${image}".`)
			settings.image = image
		}

		return Object.keys(settings).length > 0 ? { title, settings } : { title }
	})
}

/**
 * Attribute terms, including the routes that moved between 1.3.9 and the current release.
 *
 * Split from product-options.ts: the capability-gated route selection and the payload builder
 * below serve terms alone, so they travel with them.
 */
export function productOptionTermTools(
	client: FluentCartClient,
	capabilities?: ApiCapabilities,
): ToolDefinition[] {
	const termCreatePath = pick(capabilities, TERMS_BULK, TERMS_LEGACY)
	const termReorderPath = pick(capabilities, REORDER_BULK, REORDER_LEGACY)
	const bulkCreate = termCreatePath === TERMS_BULK
	const bulkReorder = termReorderPath === REORDER_BULK

	return [
		getTool(client, {
			name: 'fluentcart_attribute_term_list',
			title: 'List Attribute Terms',
			description: 'Get all terms for an attribute group (e.g. Small, Medium, Large under Size).',
			schema: z.object({
				group_id: z.number().describe('Parent attribute group ID'),
			}),
			endpoint: '/options/attr/group/:group_id/terms',
		}),

		...(termCreatePath
			? [
					createTool(client, {
						name: 'fluentcart_attribute_term_create',
						routes: direct('POST', termCreatePath),
						title: 'Create Attribute Term',
						description: bulkCreate
							? 'Create up to 10 terms in an attribute group in one call (e.g. add "Red" and ' +
								'"Blue" to Color). Pass `terms`, or `title` alone for a single plain term. ' +
								'Slugs are derived by the server from the title and cannot be chosen. A "color" ' +
								'group requires `color` (hex) on every term and an "image" group requires ' +
								'`image` (absolute URL); read the type with fluentcart_attribute_group_get.'
							: 'Create a single term in an attribute group (e.g. add "Red" to Color). This ' +
								'store exposes the legacy per-term route, where `slug` is required. Known ' +
								'defect (<=1.3.9): may fail with "Information mismatch".',
						schema: z.object({
							group_id: z.number().describe('Parent attribute group ID'),
							title: z
								.string()
								.optional()
								.describe('Display name, to create one term with no settings'),
							terms: z
								.array(
									z.object({
										title: z.string().describe('Term display name (max 50 characters)'),
										color: z.string().optional().describe('Hex colour for a "color" group'),
										image: z
											.string()
											.optional()
											.describe('Absolute image URL for an "image" group'),
									}),
								)
								.optional()
								.describe('Up to 10 terms to create in one call'),
							slug: z
								.string()
								.optional()
								.describe('Legacy route only. Rejected on current stores, which derive the slug.'),
						}),
						handler: async (client, input) => {
							const groupId = input.group_id as number
							const path = termCreatePath.replace('{param}', String(groupId))

							if (!bulkCreate) {
								try {
									return (await client.post(path, { title: input.title, slug: input.slug })).data
								} catch (error) {
									const mismatch =
										error instanceof FluentCartApiError &&
										error.message.includes('Information mismatch')
									if (!mismatch) throw error
									throw new FluentCartApiError(
										'SERVER_ERROR',
										'FluentCart defect: term creation fails on this version because ' +
											'AttrTermResource::create() validates the group against the terms table. ' +
											'Create the term via FluentCart > Settings > Product Options, or upgrade to ' +
											'a release exposing /options/attr/group/{id}/terms.',
									)
								}
							}

							// Refuse rather than drop it: the current controller strips a per-term slug
							// before the resource runs, so forwarding it would change nothing while
							// reading as though it had been honoured.
							if (input.slug !== undefined) {
								invalid(
									'`slug` is not accepted on this FluentCart version. The server derives each ' +
										'slug from the title and suffixes it for uniqueness. Remove `slug`, then ' +
										'rename with fluentcart_attribute_term_update if needed.',
								)
							}

							return (await client.post(path, { terms: buildTerms(input) })).data
						},
					}),
				]
			: []),

		postTool(client, {
			name: 'fluentcart_attribute_term_update',
			title: 'Update Attribute Term',
			description: 'Update an attribute term title or slug.',
			schema: z.object({
				group_id: z.number().describe('Parent attribute group ID'),
				term_id: z.number().describe('Attribute term ID'),
				title: z.string().optional().describe('Term display name'),
				slug: z.string().optional().describe('URL-friendly identifier'),
			}),
			endpoint: '/options/attr/group/:group_id/term/:term_id',
		}),

		deleteTool(client, {
			name: 'fluentcart_attribute_term_delete',
			title: 'Delete Attribute Term',
			description: 'Delete an attribute term. Cannot be undone.',
			schema: z.object({
				group_id: z.number().describe('Parent attribute group ID'),
				term_id: z.number().describe('Attribute term ID'),
			}),
			endpoint: '/options/attr/group/:group_id/term/:term_id',
		}),

		...(termReorderPath
			? [
					createTool(client, {
						name: 'fluentcart_attribute_term_reorder',
						routes: direct('POST', termReorderPath),
						title: 'Reorder Attribute Terms',
						description: bulkReorder
							? 'Set the display order of an attribute group’s terms. Pass `ids` as the ' +
								'complete list of term IDs in the order you want; position in the array becomes ' +
								'the sort order. Every ID must belong to the group or the call is rejected.'
							: 'Set the sort order of one attribute term. Lower numbers appear first. ' +
								'This store exposes the legacy per-term route, which takes `term_id` and `serial`.',
						schema: z.object({
							group_id: z.number().describe('Parent attribute group ID'),
							ids: z
								.array(z.union([z.number(), z.string()]))
								.optional()
								.describe('Ordered term IDs — the full list for the group, first is position 1'),
							term_id: z.number().optional().describe('Legacy per-term route: term to move'),
							serial: z
								.number()
								.optional()
								.describe('Legacy per-term route: sort position (lower = first)'),
						}),
						handler: async (client, input) => {
							const groupId = String(input.group_id)

							if (!bulkReorder) {
								if (input.term_id === undefined || input.serial === undefined) {
									invalid(
										'this store exposes the legacy route, which needs `term_id` and `serial`.',
									)
								}
								// One resolved route, bound at registration; only the parameters differ.
								const path = termReorderPath
									.replace('{param}/serial', `${input.term_id}/serial`)
									.replace('{param}', groupId)
								return (await client.post(path, { serial: input.serial })).data
							}

							const ids = Array.isArray(input.ids) ? input.ids : []
							if (ids.length === 0) {
								invalid(
									'`ids` must list the group’s term IDs in the wanted order. A partial list is ' +
										'rejected by the server, because every ID must belong to the group.',
								)
							}

							const path = termReorderPath.replace('{param}', groupId)
							return (await client.post(path, { ids })).data
						},
					}),
				]
			: []),
	]
}
