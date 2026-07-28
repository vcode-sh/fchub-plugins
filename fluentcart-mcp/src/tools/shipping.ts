import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { TTL } from '../cache.js'
import { deleteTool, getTool, postTool, putTool, type ToolDefinition } from './_factory.js'

export function shippingTools(client: FluentCartClient): ToolDefinition[] {
	return [
		// ── Zones ──────────────────────────────────────────────

		getTool(client, {
			name: 'fluentcart_shipping_package_list',
			title: 'List Shipping Packages',
			description:
				'List the parcel presets configured for shipping rate calculation, with their ' +
				'dimensions and weight.',
			schema: z.object({}),
			endpoint: '/shipping/packages',
		}),

		getTool(client, {
			name: 'fluentcart_shipping_zone_countries',
			title: 'List Shipping Zone Countries',
			description:
				'List every country a shipping zone may cover, grouped by continent, with ISO codes. ' +
				'Use it to resolve the exact codes a zone expects instead of guessing them.',
			schema: z.object({}),
			endpoint: '/shipping/zone/countries',
			cache: { key: 'shipping_zone_countries', ttlMs: TTL.LONG },
		}),

		getTool(client, {
			name: 'fluentcart_shipping_zone_list',
			title: 'List Shipping Zones',
			description: 'List all shipping zones with their methods and regions.',
			schema: z.object({}),
			endpoint: '/shipping/zones',
			cache: { key: 'shipping_zones', ttlMs: TTL.MEDIUM },
		}),

		getTool(client, {
			name: 'fluentcart_shipping_zone_get',
			title: 'Get Shipping Zone',
			description: 'Get a specific shipping zone with its methods.',
			schema: z.object({
				zone_id: z.number().describe('Shipping zone ID'),
			}),
			endpoint: '/shipping/zones/:zone_id',
		}),

		postTool(client, {
			name: 'fluentcart_shipping_zone_create',
			title: 'Create Shipping Zone',
			description:
				'Create a new shipping zone. Zones determine which shipping methods are available ' +
				"based on the customer's location.",
			schema: z.object({
				name: z.string().describe('Zone name (required)'),
				region: z
					.array(z.string())
					.optional()
					.describe('Region codes — ISO country codes or state codes (CC:STATE format)'),
			}),
			endpoint: '/shipping/zones',
			invalidates: ['shipping_zones'],
		}),

		putTool(client, {
			name: 'fluentcart_shipping_zone_update',
			title: 'Update Shipping Zone',
			description: 'Update an existing shipping zone name or regions.',
			schema: z.object({
				zone_id: z.number().describe('Shipping zone ID'),
				name: z.string().optional().describe('Zone name'),
				region: z
					.array(z.string())
					.optional()
					.describe('Region codes — ISO country codes or state codes (CC:STATE format)'),
			}),
			endpoint: '/shipping/zones/:zone_id',
			invalidates: ['shipping_zones'],
		}),

		deleteTool(client, {
			name: 'fluentcart_shipping_zone_delete',
			title: 'Delete Shipping Zone',
			description: 'Delete a shipping zone. This action cannot be undone.',
			schema: z.object({
				zone_id: z.number().describe('Shipping zone ID'),
			}),
			endpoint: '/shipping/zones/:zone_id',
			invalidates: ['shipping_zones'],
		}),

		postTool(client, {
			name: 'fluentcart_shipping_zone_reorder',
			title: 'Reorder Shipping Zones',
			description: 'Reorder shipping zones by priority. Lower index = higher priority.',
			schema: z.object({
				zones: z.array(z.number()).describe('Ordered array of zone IDs (first = highest priority)'),
			}),
			endpoint: '/shipping/zones/update-order',
			invalidates: ['shipping_zones'],
		}),

		getTool(client, {
			name: 'fluentcart_shipping_zone_states',
			title: 'Get Zone States',
			description:
				'The states, provinces or regions of one country, for building a shipping zone narrower ' +
				'than the whole country. A country with no subdivisions returns an empty list, which is ' +
				'an answer rather than a failure.',
			schema: z.object({
				country_code: z.string().min(2).describe('ISO 3166-1 alpha-2 country code, e.g. "US"'),
				country: z.string().optional().describe('Alias for country_code'),
			}),
			// The tool's parameter was `country`; the route reads `country_code`. Nothing rejected the
			// wrong name, so nothing ever failed — `/shipping/zone/states?country=US` answers HTTP 200
			// with `{country_code: "", states: [], address_locale: []}`. Every country returned that
			// same empty body, so a merchant building state-level shipping for the US was told their
			// country has no states. Verified live against the raw route: `country_code=US` returns
			// all fifty, `country=US` returns none.
			query: (input) => ({
				country_code: String(input.country_code ?? input.country ?? '')
					.trim()
					.toUpperCase(),
			}),
			endpoint: '/shipping/zone/states',
			// An unrecognised code is not rejected: "ZZ" answers with an empty list, exactly as Poland
			// does, because Poland genuinely has no subdivisions here. So an empty `states` cannot on
			// its own tell a typo from a real answer — and a typo that looks like a valid country is
			// how a shipping zone ends up silently covering nothing.
			//
			// The payload does distinguish them, though nothing says so: a country FluentCart knows
			// carries an `address_locale` OBJECT describing its postcode and state fields, while an
			// unknown code carries an empty ARRAY. Verified across twelve codes — ZZ, QQ, XX and the
			// empty string all gave `[]`; PL, AT, BE, DK, CZ, GB, DE and US all gave an object,
			// including the five that have no subdivisions at all. So the answer can be definite
			// rather than a hedge, and it costs no second request.
			transform: (data: unknown) => {
				const body = data as Record<string, unknown> | null
				const inner = body?.data as Record<string, unknown> | undefined
				if (!(inner && Array.isArray(inner.states)) || inner.states.length > 0) return data

				const locale = inner.address_locale
				const known = locale !== null && typeof locale === 'object' && !Array.isArray(locale)
				return {
					...body,
					data: {
						...inner,
						note: known
							? 'This country is recognised and has no subdivisions in FluentCart, so a zone here covers the whole country.'
							: 'This country code is NOT recognised — it is not a country without subdivisions, it is not a country. Check the code with fluentcart_shipping_zone_countries.',
					},
				}
			},
			cache: { key: 'shipping_zone_states', ttlMs: TTL.LONG },
		}),

		// ── Methods ────────────────────────────────────────────

		postTool(client, {
			name: 'fluentcart_shipping_method_create',
			title: 'Create Shipping Method',
			description:
				'Add a shipping method to a zone. Types: flat_rate, free_shipping, local_pickup. ' +
				'Amount in cents.',
			schema: z.object({
				zone_id: z.number().describe('Zone ID to add the method to'),
				type: z.string().describe('Method type: flat_rate, free_shipping, local_pickup'),
				title: z.string().describe('Display title (required)'),
				amount: z.number().optional().describe('Shipping cost in cents (for flat_rate)'),
				min_amount: z
					.number()
					.optional()
					.describe('Minimum order amount in cents (for free_shipping)'),
				settings: z
					.record(z.string(), z.unknown())
					.optional()
					.describe('Additional method settings'),
			}),
			endpoint: '/shipping/methods',
			invalidates: ['shipping_zones'],
		}),

		putTool(client, {
			name: 'fluentcart_shipping_method_update',
			title: 'Update Shipping Method',
			description:
				'Update a shipping method. Amount in cents. ' +
				'Backend validates zone_id, title, and type as required even on update.',
			schema: z.object({
				method_id: z.number().describe('Shipping method ID'),
				zone_id: z.number().describe('Zone ID (required by backend validation)'),
				title: z.string().describe('Display title (required by backend validation)'),
				type: z
					.string()
					.describe(
						'Method type: flat_rate, free_shipping, local_pickup (required by backend validation)',
					),
				amount: z.number().optional().describe('Shipping cost in cents'),
				min_amount: z.number().optional().describe('Minimum order amount in cents'),
				enabled: z.string().optional().describe("Method status: 'yes' or 'no'"),
				settings: z.record(z.string(), z.unknown()).optional().describe('Method settings'),
			}),
			endpoint: '/shipping/methods',
			invalidates: ['shipping_zones'],
		}),

		deleteTool(client, {
			name: 'fluentcart_shipping_method_delete',
			title: 'Delete Shipping Method',
			description: 'Delete a shipping method.',
			schema: z.object({
				method_id: z.number().describe('Shipping method ID'),
			}),
			endpoint: '/shipping/methods/:method_id',
			invalidates: ['shipping_zones'],
		}),

		// ── Classes ────────────────────────────────────────────

		getTool(client, {
			name: 'fluentcart_shipping_class_list',
			title: 'List Shipping Classes',
			description:
				'List all shipping classes. Classes group products for different shipping rate calculations.',
			schema: z.object({}),
			endpoint: '/shipping/classes',
			cache: { key: 'shipping_classes', ttlMs: TTL.MEDIUM },
		}),

		getTool(client, {
			name: 'fluentcart_shipping_class_get',
			title: 'Get Shipping Class',
			description: 'Get a specific shipping class.',
			schema: z.object({
				class_id: z.number().describe('Shipping class ID'),
			}),
			endpoint: '/shipping/classes/:class_id',
		}),

		postTool(client, {
			name: 'fluentcart_shipping_class_create',
			title: 'Create Shipping Class',
			description: 'Create a shipping class for grouping products with similar shipping needs.',
			schema: z.object({
				name: z.string().describe('Class name (required)'),
				cost: z.number().describe('Additional cost in cents (required)'),
				type: z.string().describe('Cost type: fixed (flat amount) or percentage (required)'),
				description: z.string().optional().describe('Class description'),
			}),
			endpoint: '/shipping/classes',
			invalidates: ['shipping_classes'],
		}),

		putTool(client, {
			name: 'fluentcart_shipping_class_update',
			title: 'Update Shipping Class',
			description: 'Update a shipping class name, cost, or type.',
			schema: z.object({
				class_id: z.number().describe('Shipping class ID'),
				name: z.string().optional().describe('Class name'),
				cost: z.number().optional().describe('Additional cost in cents'),
				type: z.string().optional().describe('Cost type: fixed or percentage'),
				description: z.string().optional().describe('Class description'),
			}),
			endpoint: '/shipping/classes/:class_id',
			invalidates: ['shipping_classes'],
		}),

		deleteTool(client, {
			name: 'fluentcart_shipping_class_delete',
			title: 'Delete Shipping Class',
			description: 'Delete a shipping class.',
			schema: z.object({
				class_id: z.number().describe('Shipping class ID'),
			}),
			endpoint: '/shipping/classes/:class_id',
			invalidates: ['shipping_classes'],
		}),
	]
}
