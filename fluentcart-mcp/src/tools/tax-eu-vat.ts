import { z } from 'zod'
import type { ApiCapabilities } from '../api/capabilities.js'
import type { FluentCartClient } from '../api/client.js'
import type { HttpMethod } from '../api/route-normalisation.js'
import { invalidate, TTL } from '../cache.js'
import { createTool, getTool, type ToolDefinition } from './_factory.js'
import { direct } from './endpoints.js'

/**
 * The EU VAT rate table moved to an OSS-specific route after 1.3.9.
 *
 * Ordered current-first; without capability evidence the current route wins, because it is the
 * only one present on any supported FluentCart.
 */
const EU_RATE_VARIANTS: readonly { method: HttpMethod; path: string }[] = [
	{ method: 'GET', path: '/tax/configuration/settings/eu-vat/oss-rates' },
	{ method: 'GET', path: '/tax/configuration/settings/eu-vat/rates' },
]

export function taxEuVatTools(
	client: FluentCartClient,
	capabilities?: ApiCapabilities,
): ToolDefinition[] {
	const ratesPath = capabilities
		? (EU_RATE_VARIANTS.find((variant) => capabilities.has(variant.method, variant.path))?.path ??
			null)
		: (EU_RATE_VARIANTS[0]?.path ?? null)

	return [
		createTool(client, {
			name: 'fluentcart_tax_eu_vat_save',
			routes: direct('POST', '/tax/configuration/settings/eu-vat'),
			title: 'Save EU VAT Cross-Border Settings',
			description:
				'Save EU VAT cross-border registration settings. ' +
				'The backend requires action="euCrossBorderSettings" (sent automatically). ' +
				'Choose a method: "oss" (One Stop Shop — requires oss_country), ' +
				'"home" (home country — requires home_country), or "specific" (specific countries). ' +
				'Set reset_registration to "yes" to clear the current registration method.',
			schema: z.object({
				method: z.enum(['oss', 'home', 'specific']).describe('Cross-border registration type'),
				oss_country: z
					.string()
					.optional()
					.describe('ISO country code for OSS registration (required when method is "oss")'),
				home_country: z
					.string()
					.optional()
					.describe(
						'ISO country code for home country registration (required when method is "home")',
					),
				reset_registration: z
					.enum(['yes', 'no'])
					.optional()
					.describe('Set to "yes" to clear the current registration method'),
			}),
			handler: async (c, input) => {
				const euVatSettings: Record<string, string> = {
					method: input.method as string,
				}
				if (input.oss_country) euVatSettings.oss_country = input.oss_country as string
				if (input.home_country) euVatSettings.home_country = input.home_country as string

				const body: Record<string, unknown> = {
					action: 'euCrossBorderSettings',
					eu_vat_settings: euVatSettings,
				}
				if (input.reset_registration === 'yes') body.reset_registration = 'yes'

				const response = await c.post('/tax/configuration/settings/eu-vat', body)
				invalidate('tax_eu_rates')
				invalidate('tax_settings')
				return response.data
			},
		}),

		...(ratesPath
			? [
					getTool(client, {
						name: 'fluentcart_tax_eu_rates',
						title: 'Get EU VAT Rates',
						description:
							'Get EU VAT rates for all member states, as used for One Stop Shop reporting.',
						schema: z.object({}),
						endpoint: ratesPath,
						cache: { key: 'tax_eu_rates', ttlMs: TTL.LONG },
					}),
				]
			: []),
	]
}
