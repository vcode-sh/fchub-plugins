import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { TTL } from '../cache.js'
import { deleteTool, getTool, postTool, putTool, type ToolDefinition } from './_factory.js'

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

		postTool(client, {
			name: 'fluentcart_tax_class_create',
			title: 'Create Tax Class',
			description: 'Create a new tax class for categorising products with different tax rates.',
			schema: z.object({
				title: z.string().describe('Tax class title (required)'),
				description: z.string().optional().describe('Description'),
			}),
			endpoint: '/tax/classes',
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

		postTool(client, {
			name: 'fluentcart_tax_rate_create',
			title: 'Create Tax Rate',
			description: 'Create a tax rate for a country. Rate is a percentage value (e.g. 23 for 23%).',
			schema: z.object({
				country: z.string().describe('ISO country code (e.g. "PL", "US", "GB")'),
				rate: z.number().describe('Tax rate percentage (e.g. 23 for 23%)'),
				name: z.string().describe('Rate name (e.g. "VAT", "GST")'),
				class_id: z.number().describe('Tax class ID (required)'),
				priority: z.number().optional().describe('Rate priority'),
				is_compound: z.number().optional().describe('Whether rate is compound (0 or 1)'),
				for_shipping: z.number().optional().describe('Whether rate applies to shipping (0 or 1)'),
			}),
			endpoint: '/tax/country/rate',
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

		postTool(client, {
			name: 'fluentcart_tax_shipping_override_create',
			title: 'Create Shipping Tax Override',
			description: 'Create a shipping tax override for a country. ' + 'Rate is a percentage value.',
			schema: z.object({
				country_code: z.string().describe('ISO country code'),
				rate: z.number().describe('Override tax rate percentage'),
				name: z.string().optional().describe('Override name'),
			}),
			endpoint: '/tax/rates/country/override',
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
			description: 'Mark tax records as filed for a given period or set of IDs.',
			schema: z.object({
				tax_ids: z.array(z.number()).optional().describe('Tax record IDs to mark as filed'),
				startDate: z.string().optional().describe('Start date filter'),
				endDate: z.string().optional().describe('End date filter'),
			}),
			endpoint: '/taxes',
		}),
	]
}
