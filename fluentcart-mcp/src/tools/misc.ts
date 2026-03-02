import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { getTool, type ToolDefinition } from './_factory.js'

export function miscTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_misc_countries',
			title: 'Get Countries List',
			description:
				'Retrieve a list of all available countries as value/name pairs. ' +
				'Each entry contains an ISO 3166-1 alpha-2 code and the country name.',
			schema: z.object({}),
			endpoint: '/address-info/countries',
		}),

		getTool(client, {
			name: 'fluentcart_misc_country_info',
			title: 'Get Country Info',
			description:
				'Retrieve detailed information about a specific country including ' +
				'states/provinces and address locale configuration.',
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
				'Retrieve available filter options for advanced filtering on orders, customers, ' +
				'and other entities. Returns field definitions usable in advanced filter queries.',
			schema: z.object({}),
			endpoint: '/advance_filter/get-filter-options',
		}),

		getTool(client, {
			name: 'fluentcart_misc_form_search_options',
			title: 'Search Form Options',
			description:
				'Retrieve search/autocomplete options for form fields. ' +
				'Returns available options for dynamic form field population.',
			schema: z.object({}),
			endpoint: '/forms/search_options',
		}),
	]
}
