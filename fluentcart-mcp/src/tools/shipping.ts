import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { TTL } from '../cache.js'
import { deleteTool, getTool, postTool, putTool, type ToolDefinition } from './_factory.js'

export function shippingTools(client: FluentCartClient): ToolDefinition[] {
	return [
		// ── Zones ──────────────────────────────────────────────

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
		}),

		deleteTool(client, {
			name: 'fluentcart_shipping_zone_delete',
			title: 'Delete Shipping Zone',
			description: 'Delete a shipping zone. This action cannot be undone.',
			schema: z.object({
				zone_id: z.number().describe('Shipping zone ID'),
			}),
			endpoint: '/shipping/zones/:zone_id',
		}),

		postTool(client, {
			name: 'fluentcart_shipping_zone_reorder',
			title: 'Reorder Shipping Zones',
			description: 'Reorder shipping zones by priority. Lower index = higher priority.',
			schema: z.object({
				zones: z
					.array(z.number())
					.describe('Ordered array of zone IDs (first = highest priority)'),
			}),
			endpoint: '/shipping/zones/update-order',
		}),

		getTool(client, {
			name: 'fluentcart_shipping_zone_states',
			title: 'Get Zone States',
			description: 'Get available states/regions for zone configuration.',
			schema: z.object({
				country: z
					.string()
					.optional()
					.describe('ISO country code to get states for'),
			}),
			endpoint: '/shipping/zone/states',
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
				type: z
					.string()
					.describe('Method type: flat_rate, free_shipping, local_pickup'),
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
		}),

		putTool(client, {
			name: 'fluentcart_shipping_method_update',
			title: 'Update Shipping Method',
			description: 'Update a shipping method. Amount in cents.',
			schema: z.object({
				method_id: z.number().describe('Shipping method ID'),
				zone_id: z.number().optional().describe('Zone ID'),
				title: z.string().optional().describe('Display title'),
				amount: z.number().optional().describe('Shipping cost in cents'),
				min_amount: z
					.number()
					.optional()
					.describe('Minimum order amount in cents'),
				enabled: z
					.string()
					.optional()
					.describe("Method status: 'yes' or 'no'"),
				settings: z
					.record(z.string(), z.unknown())
					.optional()
					.describe('Method settings'),
			}),
			endpoint: '/shipping/methods',
		}),

		deleteTool(client, {
			name: 'fluentcart_shipping_method_delete',
			title: 'Delete Shipping Method',
			description: 'Delete a shipping method.',
			schema: z.object({
				method_id: z.number().describe('Shipping method ID'),
			}),
			endpoint: '/shipping/methods/:method_id',
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
				type: z
					.string()
					.describe('Cost type: fixed (flat amount) or percentage (required)'),
				description: z.string().optional().describe('Class description'),
			}),
			endpoint: '/shipping/classes',
		}),

		putTool(client, {
			name: 'fluentcart_shipping_class_update',
			title: 'Update Shipping Class',
			description: 'Update a shipping class name, cost, or type.',
			schema: z.object({
				class_id: z.number().describe('Shipping class ID'),
				name: z.string().optional().describe('Class name'),
				cost: z.number().optional().describe('Additional cost in cents'),
				type: z
					.string()
					.optional()
					.describe('Cost type: fixed or percentage'),
				description: z.string().optional().describe('Class description'),
			}),
			endpoint: '/shipping/classes/:class_id',
		}),

		deleteTool(client, {
			name: 'fluentcart_shipping_class_delete',
			title: 'Delete Shipping Class',
			description: 'Delete a shipping class.',
			schema: z.object({
				class_id: z.number().describe('Shipping class ID'),
			}),
			endpoint: '/shipping/classes/:class_id',
		}),
	]
}
