import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { createTool, encodePathParameter, type ToolDefinition } from './_factory.js'
import { direct } from './endpoints.js'

function record(value: unknown): Record<string, unknown> {
	return value !== null && typeof value === 'object' && !Array.isArray(value)
		? (value as Record<string, unknown>)
		: {}
}

function optionalText(value: unknown): string | null {
	return typeof value === 'string' && value.trim() !== '' ? value : null
}

function projectOverride(value: unknown): Record<string, unknown> {
	const override = record(value)
	const meta = record(override.meta_value)
	return {
		id: override.id ?? null,
		category_id: meta.category_id ?? null,
		category_name: optionalText(meta.category_name),
		state: optionalText(meta.state),
		city: optionalText(meta.city),
		postcode: optionalText(meta.postcode),
		tax_label: optionalText(meta.tax_label),
		rate: meta.rate ?? null,
		override_state_tax: meta.override_state_tax === 'yes' || meta.override_state_tax === true,
		class_id: override.class_id ?? meta.class_id ?? null,
		class_label: optionalText(override.class_label),
	}
}

export function taxProductOverrideTools(client: FluentCartClient): ToolDefinition[] {
	return [
		createTool(client, {
			name: 'fluentcart_tax_product_overrides',
			title: 'Get Product Tax Overrides',
			description:
				'Read product-category tax overrides for one ISO country code. The response projects ' +
				'only the applicable category, location, rate and tax-class fields and omits FluentCart ' +
				'metadata storage internals.',
			schema: z.object({
				country_code: z
					.string()
					.trim()
					.regex(/^[A-Za-z]{2}$/)
					.describe('ISO 3166-1 alpha-2 country code, e.g. "PL"'),
			}),
			annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: true },
			routes: direct('GET', '/tax/product-overrides/{param}'),
			handler: async (c, input) => {
				const countryCode = String(input.country_code).trim().toUpperCase()
				const encoded = encodePathParameter(
					'/tax/product-overrides/{param}',
					'country_code',
					countryCode,
				)
				const response = await c.get(`/tax/product-overrides/${encoded}`)
				const body = record(response.data)
				const overrides = Array.isArray(body.overrides) ? body.overrides.map(projectOverride) : []
				return { country_code: countryCode, overrides, total: overrides.length }
			},
		}),
	]
}
