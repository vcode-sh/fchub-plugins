import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { TTL } from '../cache.js'
import { getTool, type ToolDefinition } from './_factory.js'

export function miscTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_misc_countries',
			title: 'Get Countries List',
			description: 'Get all countries as ISO 3166-1 alpha-2 code/name pairs.',
			schema: z.object({}),
			endpoint: '/address-info/countries',
			cache: { key: 'countries', ttlMs: TTL.LONG },
		}),

		getTool(client, {
			name: 'fluentcart_misc_country_info',
			title: 'Get Country Info',
			description:
				'Get country details including states/provinces and address locale configuration.',
			schema: z.object({
				country_code: z
					.string()
					.describe('ISO 3166-1 alpha-2 country code (e.g. "US", "PL", "GB")'),
				timezone: z.string().optional().describe('Timezone identifier (e.g. "Europe/London")'),
			}),
			endpoint: '/address-info/get-country-info',
		}),

		getTool(client, {
			name: 'fluentcart_misc_filter_options',
			title: 'Get Filter Options',
			description:
				'Get available filter options for advanced filtering on orders, customers, and other entities.',
			schema: z.object({}),
			endpoint: '/advance_filter/get-filter-options',
			cache: { key: 'filter_options', ttlMs: TTL.MEDIUM },
		}),

		getTool(client, {
			name: 'fluentcart_misc_form_search_options',
			title: 'Search Form Options',
			description: 'Get search/autocomplete options for dynamic form field population.',
			schema: z.object({}),
			endpoint: '/forms/search_options',
		}),
	]
}
