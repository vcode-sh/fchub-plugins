import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { FluentCartApiError } from '../api/errors.js'
import { TTL } from '../cache.js'
import {
	createTool,
	deleteTool,
	getTool,
	postTool,
	putTool,
	type ToolDefinition,
} from './_factory.js'

function asNumber(value: unknown): number | null {
	if (typeof value === 'number' && Number.isFinite(value)) return value
	if (typeof value === 'string' && value.trim() !== '' && !Number.isNaN(Number(value))) {
		return Number(value)
	}
	return null
}

function asFlag(value: unknown): number | undefined {
	if (value === undefined) return undefined
	if (typeof value === 'boolean') return value ? 1 : 0
	const numeric = asNumber(value)
	return numeric == null ? undefined : numeric
}

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

export function taxTools(client: FluentCartClient): ToolDefinition[] {
	return [
		// ── Tax Classes ────────────────────────────────────────

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

		putTool(client, {
			name: 'fluentcart_tax_class_update',
			title: 'Update Tax Class',
			description: 'Update a tax class title or description.',
			schema: z.object({
				class_id: z.number().describe('Tax class ID'),
				title: z.string().optional().describe('Tax class title'),
				description: z.string().optional().describe('Description'),
			}),
			endpoint: '/tax/classes/:class_id',
		}),

		deleteTool(client, {
			name: 'fluentcart_tax_class_delete',
			title: 'Delete Tax Class',
			description: 'Delete a tax class. This action cannot be undone.',
			schema: z.object({
				class_id: z.number().describe('Tax class ID'),
			}),
			endpoint: '/tax/classes/:class_id',
		}),

		// ── Tax Rates ──────────────────────────────────────────

		getTool(client, {
			name: 'fluentcart_tax_rate_list',
			title: 'List Tax Rates',
			description: 'List all tax rates across countries.',
			schema: z.object({}),
			endpoint: '/tax/rates',
		}),

		getTool(client, {
			name: 'fluentcart_tax_rate_country',
			title: 'Get Country Tax Rates',
			description: 'Get tax rates for a specific country.',
			schema: z.object({
				country_code: z
					.string()
					.describe('ISO 3166-1 alpha-2 country code (e.g. "PL", "US", "GB")'),
			}),
			endpoint: '/tax/rates/country/rates/:country_code',
		}),

		createTool(client, {
			name: 'fluentcart_tax_rate_create',
			title: 'Create Tax Rate',
			description:
				'Create a tax rate for a country. Rate is a percentage value (e.g. 23 for 23%). ' +
				'Supports aliases: country_code->country, tax_class_id->class_id, compound->is_compound, shipping->for_shipping.',
			schema: z.object({
				country: z.string().optional().describe('ISO country code (e.g. "PL", "US", "GB")'),
				country_code: z.string().optional().describe('Legacy alias for country'),
				rate: z.number().describe('Tax rate percentage (e.g. 23 for 23%)'),
				name: z.string().optional().describe('Rate name (e.g. "VAT", "GST")'),
				class_id: z.number().optional().describe('Tax class ID (required)'),
				tax_class_id: z.number().optional().describe('Legacy alias for class_id'),
				priority: z.number().optional().describe('Rate priority'),
				is_compound: z.number().optional().describe('Whether rate is compound (0 or 1)'),
				compound: z.union([z.number(), z.boolean()]).optional().describe('Legacy alias'),
				for_shipping: z.number().optional().describe('Whether rate applies to shipping (0 or 1)'),
				shipping: z.union([z.number(), z.boolean()]).optional().describe('Legacy alias'),
			}),
			handler: async (c, input) => {
				const country =
					(input.country as string | undefined) || (input.country_code as string | undefined)
				const classId = asNumber(input.class_id) ?? asNumber(input.tax_class_id)

				if (!country) {
					throw new FluentCartApiError(
						'VALIDATION_ERROR',
						'Validation error: country is required',
						422,
					)
				}
				if (classId == null) {
					throw new FluentCartApiError(
						'VALIDATION_ERROR',
						'Validation error: class_id is required',
						422,
					)
				}

				const body: Record<string, unknown> = {
					country,
					rate: input.rate,
					name: (input.name as string | undefined) || 'VAT',
					class_id: classId,
				}
				if (input.priority !== undefined) body.priority = input.priority

				const isCompound = asFlag(input.is_compound ?? input.compound)
				if (isCompound !== undefined) body.is_compound = isCompound

				const forShipping = asFlag(input.for_shipping ?? input.shipping)
				if (forShipping !== undefined) body.for_shipping = forShipping

				const response = await c.post('/tax/country/rate', body)
				return response.data
			},
		}),

		putTool(client, {
			name: 'fluentcart_tax_rate_update',
			title: 'Update Tax Rate',
			description: 'Update a tax rate. Rate is a percentage value.',
			schema: z.object({
				rate_id: z.number().describe('Tax rate ID'),
				country: z.string().optional().describe('ISO country code'),
				rate: z.number().optional().describe('Tax rate percentage'),
				name: z.string().optional().describe('Rate name'),
				class_id: z.number().optional().describe('Tax class ID'),
				priority: z.number().optional().describe('Rate priority'),
				is_compound: z.number().optional().describe('Whether rate is compound (0 or 1)'),
				for_shipping: z.number().optional().describe('Whether rate applies to shipping (0 or 1)'),
			}),
			endpoint: '/tax/country/rate/:rate_id',
		}),

		deleteTool(client, {
			name: 'fluentcart_tax_rate_delete',
			title: 'Delete Tax Rate',
			description: 'Delete a tax rate. This action cannot be undone.',
			schema: z.object({
				rate_id: z.number().describe('Tax rate ID'),
			}),
			endpoint: '/tax/country/rate/:rate_id',
		}),

		deleteTool(client, {
			name: 'fluentcart_tax_country_delete_all',
			title: 'Delete All Country Tax Rates',
			description: 'Delete all tax rates for a country. This action cannot be undone.',
			schema: z.object({
				country_code: z.string().describe('ISO country code'),
			}),
			endpoint: '/tax/country/:country_code',
		}),

		// ── Country Tax ID ─────────────────────────────────────

		getTool(client, {
			name: 'fluentcart_tax_country_id_get',
			title: 'Get Country Tax ID Settings',
			description: 'Get country tax identification settings (e.g. VAT number requirements).',
			schema: z.object({
				country_code: z.string().describe('ISO country code'),
			}),
			endpoint: '/tax/country-tax-id/:country_code',
		}),

		postTool(client, {
			name: 'fluentcart_tax_country_id_save',
			title: 'Save Country Tax ID Settings',
			description: 'Save country tax identification settings.',
			schema: z.object({
				country_code: z.string().describe('ISO country code'),
				tax_id_label: z.string().optional().describe('Label for tax ID field'),
				tax_id_required: z.boolean().optional().describe('Whether tax ID is required'),
				settings: z
					.record(z.string(), z.unknown())
					.optional()
					.describe('Additional tax ID settings'),
			}),
			endpoint: '/tax/country-tax-id/:country_code',
		}),

		// ── Shipping Tax Overrides ─────────────────────────────

		createTool(client, {
			name: 'fluentcart_tax_shipping_override_create',
			title: 'Create Shipping Tax Override',
			description:
				'Add a shipping tax override to an existing tax rate. ' +
				'Pass the existing tax rate ID and the override rate percentage. ' +
				'This sets the for_shipping flag on the rate with a custom override rate.',
			schema: z.object({
				id: z.number().describe('Existing tax rate ID to add shipping override to'),
				override_tax_rate: z.number().describe('Override tax rate percentage for shipping'),
			}),
			handler: async (c, input) => {
				const response = await c.post('/tax/rates/country/override', {
					id: input.id,
					override_tax_rate: input.override_tax_rate,
				})
				return response.data
			},
		}),

		deleteTool(client, {
			name: 'fluentcart_tax_shipping_override_delete',
			title: 'Delete Shipping Tax Override',
			description: 'Delete a shipping tax override.',
			schema: z.object({
				override_id: z.number().describe('Override ID'),
			}),
			endpoint: '/tax/rates/country/override/:override_id',
		}),

		// ── Configuration ──────────────────────────────────────

		getTool(client, {
			name: 'fluentcart_tax_config_rates',
			title: 'Get Tax Configuration Rates',
			description: 'Get tax configuration with all rate overviews.',
			schema: z.object({}),
			endpoint: '/tax/configuration/rates',
		}),

		postTool(client, {
			name: 'fluentcart_tax_config_countries_save',
			title: 'Save Tax Countries',
			description: 'Save the list of countries configured for tax collection.',
			schema: z.object({
				countries: z.array(z.string()).describe('Array of ISO country codes to configure for tax'),
			}),
			endpoint: '/tax/configuration/countries',
		}),

		getTool(client, {
			name: 'fluentcart_tax_settings_get',
			title: 'Get Tax Settings',
			description:
				'Get global tax settings including tax-inclusive pricing, display options, and rounding.',
			schema: z.object({}),
			endpoint: '/tax/configuration/settings',
			cache: { key: 'tax_settings', ttlMs: TTL.MEDIUM },
		}),

		postTool(client, {
			name: 'fluentcart_tax_settings_save',
			title: 'Save Tax Settings',
			description: 'Save global tax settings.',
			schema: z.object({
				settings: z.record(z.string(), z.unknown()).describe('Tax settings to save'),
			}),
			endpoint: '/tax/configuration/settings',
		}),

		// ── EU VAT ─────────────────────────────────────────────

		postTool(client, {
			name: 'fluentcart_tax_eu_vat_save',
			title: 'Save EU VAT Settings',
			description: 'Save EU VAT configuration settings.',
			schema: z.object({
				enabled: z.boolean().optional().describe('Enable EU VAT'),
				settings: z.record(z.string(), z.unknown()).optional().describe('EU VAT configuration'),
			}),
			endpoint: '/tax/configuration/settings/eu-vat',
		}),

		getTool(client, {
			name: 'fluentcart_tax_eu_rates',
			title: 'Get EU VAT Rates',
			description: 'Get EU VAT rates for all member states.',
			schema: z.object({}),
			endpoint: '/tax/configuration/settings/eu-vat/rates',
			cache: { key: 'tax_eu_rates', ttlMs: TTL.LONG },
		}),

		// ── Tax Records ────────────────────────────────────────

		getTool(client, {
			name: 'fluentcart_tax_records_list',
			title: 'List Tax Records',
			description: 'List tax records for reporting and filing.',
			schema: z.object({
				page: z.number().optional().describe('Page number'),
				per_page: z.number().max(50).optional().describe('Results per page (max: 50)'),
				startDate: z.string().optional().describe('Start date (YYYY-MM-DD)'),
				endDate: z.string().optional().describe('End date (YYYY-MM-DD)'),
			}),
			endpoint: '/taxes',
		}),

		postTool(client, {
			name: 'fluentcart_tax_records_mark_filed',
			title: 'Mark Tax Records Filed',
			description:
				'Mark specific tax records as filed. ' +
				'Pass an array of tax record IDs to mark.',
			schema: z.object({
				ids: z.array(z.number()).describe('Tax record IDs to mark as filed'),
			}),
			endpoint: '/taxes',
		}),
	]
}
