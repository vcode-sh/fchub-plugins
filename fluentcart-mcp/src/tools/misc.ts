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
				'Get country details including states/provinces and address locale configuration. ' +
				'An unrecognised code is not rejected: the store echoes it back as a country of that ' +
				'name with no states and no locale rules, so check the code against ' +
				'fluentcart_misc_countries rather than trusting a successful response.',
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
				'Get the selectable values behind one advanced-filter field on the orders, customers ' +
				'and product surfaces. remote_data_key names the field: "labels" returns every order ' +
				'and customer label, "product_variations" returns products with their variants nested. ' +
				'Any other key is offered to plugins and returns an empty list if nothing answers. ' +
				'Calling this without remote_data_key always returns an empty list — it is not a ' +
				'catalogue of the available filters.',
			schema: z.object({
				remote_data_key: z
					.string()
					.optional()
					.describe(
						'Which filter field to load values for: "labels", "product_variations", or a key a plugin registers. Omitting it returns nothing.',
					),
				search: z.string().optional().describe('Filter the returned options by keyword'),
				limit: z.number().optional().describe('Maximum number of options to return'),
				parent_id: z.number().optional().describe('Parent option ID for nested lookups'),
			}),
			endpoint: '/advance_filter/get-filter-options',
			cache: { key: 'filter_options', ttlMs: TTL.MEDIUM },
		}),

		getTool(client, {
			name: 'fluentcart_misc_form_search_options',
			title: 'Search Form Options',
			description:
				'Get autocomplete values for one settings-page field, named by search_for. The route is ' +
				'purely an extension point: FluentCart core registers no field of its own, so a store ' +
				'with no plugin answering that name returns an empty list however the call is phrased.',
			schema: z.object({
				search_for: z
					.string()
					.optional()
					.describe('Which field to load values for. Omitting it always returns an empty list.'),
				search_by: z.string().optional().describe('Keyword to filter the returned values by'),
			}),
			endpoint: '/forms/search_options',
		}),
	]
}
