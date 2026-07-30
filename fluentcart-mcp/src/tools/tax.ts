import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { FluentCartApiError } from '../api/errors.js'
import {
	createTool,
	deleteTool,
	getTool,
	postTool,
	putTool,
	type ToolDefinition,
} from './_factory.js'
import { direct } from './endpoints.js'
import { taxRateViewTools } from './tax-rate-tools.js'

/** Shared with tax-classes.ts, which needs it to dig an id out of a create response. */
export function asNumber(value: unknown): number | null {
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

export function taxTools(client: FluentCartClient): ToolDefinition[] {
	return [
		// ── Tax Rates ──────────────────────────────────────────
		// The two whole-world rate views live in tax-rate-tools.ts; they share a schema and handler.
		...taxRateViewTools(client),

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
			routes: direct('POST', '/tax/country/rate'),
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
			description:
				'Save the tax identification number for a country. ' +
				'Backend stores { tax_id } in fct_meta keyed by country code.',
			schema: z.object({
				country_code: z.string().describe('ISO country code'),
				tax_id: z.string().describe('Tax identification number for this country (e.g. VAT number)'),
			}),
			endpoint: '/tax/country-tax-id/:country_code',
		}),

		// ── Shipping Tax Overrides ─────────────────────────────

		createTool(client, {
			name: 'fluentcart_tax_shipping_override_create',
			routes: direct('POST', '/tax/rates/country/override'),
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

		postTool(client, {
			name: 'fluentcart_tax_config_countries_save',
			title: 'Seed Default Tax Rates for Countries',
			// The old title and description — "Save Tax Countries", "Save the list of countries
			// configured for tax collection" — described an operation FluentCart does not have. The
			// controller calls TaxManager::generateTaxClasses($countries), which SEEDS default rates
			// for countries that have none and SKIPS every country already present. It removes
			// nothing, so it cannot restrict where tax is charged, and an agent told "only charge tax
			// in Poland" would call this with ["PL"], be told "Countries saved successfully", and have
			// changed nothing at all. Verified in FluentCart 1.5.5 at
			// app/Http/Controllers/TaxConfigurationController.php:24 and
			// app/Services/Tax/TaxManager.php:171 — the `in_array($country, $existingCountries)`
			// continue is the whole story.
			description:
				'Create default tax rates for countries that do not have any yet. This ADDS ONLY: a ' +
				'country that already has rates is skipped, and nothing is ever removed, so it cannot ' +
				'be used to restrict where tax is charged or to replace an existing list — edit ' +
				'individual rates for that. Passing an empty array seeds EVERY country in the ' +
				'ISO list, which is how a store ends up with 250 of them. To stop charging tax ' +
				'for a country, FluentCart 1.6 provides no country-wide delete route; remove its ' +
				'individual rates deliberately instead.',
			schema: z.object({
				countries: z
					.array(z.string())
					.min(1)
					.describe(
						'ISO country codes to seed default rates for, e.g. ["PL","DE"]. Must not be empty: ' +
							'an empty array seeds every country on earth, which is never what a caller means',
					),
			}),
			endpoint: '/tax/configuration/countries',
		}),

		// ── Tax Records ────────────────────────────────────────

		getTool(client, {
			name: 'fluentcart_tax_records_list',
			title: 'List Tax Records',
			description:
				'List tax records for reporting and filing. ' +
				'Note: Date filtering is not currently supported by the backend TaxFilter.',
			schema: z.object({
				page: z.number().optional().describe('Page number'),
				per_page: z.number().max(50).optional().describe('Results per page (max: 50)'),
			}),
			endpoint: '/taxes',
		}),

		postTool(client, {
			name: 'fluentcart_tax_records_mark_filed',
			title: 'Mark Tax Records Filed',
			description:
				'Mark specific tax records as filed. ' + 'Pass an array of tax record IDs to mark.',
			schema: z.object({
				ids: z.array(z.number()).describe('Tax record IDs to mark as filed'),
			}),
			endpoint: '/taxes',
		}),
	]
}
