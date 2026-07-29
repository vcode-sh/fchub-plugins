import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { assertWithinEmergencyCap } from '../commerce/response-budget.js'
import { createTool, encodePathParameter, getTool, type ToolDefinition } from './_factory.js'
import { composite, direct, op } from './endpoints.js'

/**
 * Receipt and invoice PDF template configuration.
 *
 * Three routes return the same `templates` map from different sources — the shipped defaults,
 * the active set and the saved overrides — at roughly 12 kB each. Listing them whole would spend
 * half the response budget on `pdf_settings` blobs an agent cannot act on, so the list tools
 * project to identity and the per-template tool returns one template in full.
 *
 * The seller-details route contains banking, identity, tax and contact values. Its tool returns
 * readiness booleans only, never those values.
 */
const SOURCES = {
	active: '/settings/pdf-templates/receipt',
	saved: '/settings/pdf-templates/saved',
	'factory-default': '/settings/pdf-templates/factory-default',
} as const

type TemplateSource = keyof typeof SOURCES

interface TemplateRow {
	name: string | null
	title: string | null
	description: string | null
	isDefault: boolean | null
	settingsCount: number | null
}

function projectTemplate(key: string, value: unknown): TemplateRow {
	const record = (value ?? {}) as Record<string, unknown>
	return {
		name: typeof record.name === 'string' ? record.name : key,
		title: typeof record.title === 'string' ? record.title : null,
		description: typeof record.description === 'string' ? record.description : null,
		isDefault: typeof record.is_default === 'boolean' ? record.is_default : null,
		settingsCount: Array.isArray(record.pdf_settings) ? record.pdf_settings.length : null,
	}
}

function projectMap(payload: unknown): TemplateRow[] {
	const templates = ((payload ?? {}) as Record<string, unknown>).templates
	if (!templates || typeof templates !== 'object') return []
	return Object.entries(templates as Record<string, unknown>).map(([key, value]) =>
		projectTemplate(key, value),
	)
}

export function pdfTemplateTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_pdf_seller_details_status',
			title: 'Get PDF Seller Details Status',
			description:
				'Report whether the seller identity, contact, bank and tax fields needed by invoice ' +
				'templates are configured. This deliberately returns booleans only and never exposes ' +
				'the seller values or the private WordPress administration URL. `e_invoice_profile` ' +
				'is the configured ZUGFeRD/Factur-X profile name, not a seller identity profile.',
			schema: z.object({}),
			endpoint: '/settings/pdf-templates/seller-details',
			transform: (data) => {
				const body = (data ?? {}) as Record<string, unknown>
				const seller = (body.seller_details ?? {}) as Record<string, unknown>
				const configured = (key: string) =>
					typeof seller[key] === 'string' && seller[key].trim() !== ''
				return {
					e_invoice_enabled: seller.zugferd_enabled === '1' || seller.zugferd_enabled === true,
					e_invoice_profile:
						typeof seller.zugferd_profile === 'string' ? seller.zugferd_profile : null,
					store_country_configured: body.store_country_set === true,
					configured: {
						contact_name: configured('seller_contact_name'),
						contact_email: configured('seller_contact_email'),
						contact_phone: configured('seller_contact_phone'),
						bank_iban: configured('seller_bank_iban'),
						bank_bic: configured('seller_bank_bic'),
						bank_account_name: configured('seller_bank_account_name'),
						electronic_address: configured('seller_electronic_address'),
						vat_id: configured('seller_vat_id'),
						tax_id: configured('seller_tax_id'),
						legal_name: configured('seller_legal_name'),
						legal_registration_id: configured('seller_legal_registration_id'),
					},
				}
			},
		}),

		createTool(client, {
			name: 'fluentcart_pdf_template_status',
			title: 'Get PDF Template Availability',
			description:
				'Report whether the PDF add-on that renders receipts and invoices is installed and ' +
				'active. Check this before reading or changing template configuration: without the ' +
				'add-on the templates exist as settings but nothing renders them.',
			schema: z.object({}),
			annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: true },
			routes: direct('GET', '/settings/pdf-templates/status'),
			handler: async (c) => {
				const response = await c.get('/settings/pdf-templates/status')
				const record = (response.data ?? {}) as Record<string, unknown>
				const addon = (record.addon_info ?? {}) as Record<string, unknown>
				return {
					renderer_available: record.has_fluent_pdf === true,
					addon: {
						slug: addon.plugin_slug ?? null,
						installed: addon.is_installed ?? null,
						active: addon.is_active ?? null,
						source: addon.source_type ?? null,
					},
				}
			},
		}),

		createTool(client, {
			name: 'fluentcart_pdf_template_list',
			title: 'List PDF Templates',
			description:
				'List the receipt and invoice PDF templates by name, title, description and whether ' +
				'each is the default. `source` selects which set to read: "active" is what the store ' +
				'uses now, "saved" the stored overrides, "factory-default" what FluentCart shipped. ' +
				'Settings bodies are summarised as a count — read one template in full with ' +
				'fluentcart_pdf_template_get.',
			schema: z.object({
				source: z
					.enum(['active', 'saved', 'factory-default'])
					.optional()
					.describe('Which template set to read (default: active)'),
			}),
			annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: true },
			// One route is selected by `source`, but every documented input must work before the
			// tool is advertised. Composite availability therefore requires all three branches.
			routes: composite(
				op('GET', '/settings/pdf-templates/receipt'),
				op('GET', '/settings/pdf-templates/saved'),
				op('GET', '/settings/pdf-templates/factory-default'),
			),
			handler: async (c, input) => {
				const source = (input.source as TemplateSource | undefined) ?? 'active'
				const response = await c.get(SOURCES[source])
				const templates = projectMap(response.data)
				assertWithinEmergencyCap(templates, 'fluentcart_pdf_template_list')
				return { source, templates, total: templates.length }
			},
		}),

		createTool(client, {
			name: 'fluentcart_pdf_template_get',
			title: 'Get PDF Template',
			description:
				'Read one receipt or invoice PDF template in full, including its settings body. Use ' +
				'fluentcart_pdf_template_list first to find the template name, e.g. "order_receipt".',
			schema: z.object({
				template: z
					.string()
					.min(1)
					.describe('Template name, e.g. "order_receipt", "renewal_receipt", "refund_notice"'),
			}),
			annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: true },
			routes: direct('GET', '/settings/pdf-templates/receipt/{param}'),
			handler: async (c, input) => {
				const template = encodePathParameter(
					'/settings/pdf-templates/receipt/{param}',
					'template',
					input.template,
				)
				const response = await c.get(`/settings/pdf-templates/receipt/${template}`)
				assertWithinEmergencyCap(response.data, 'fluentcart_pdf_template_get')
				return response.data
			},
		}),
	]
}
