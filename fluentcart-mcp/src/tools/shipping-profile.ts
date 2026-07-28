import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { getTool, type ToolDefinition } from './_factory.js'

function record(value: unknown): Record<string, unknown> {
	return value !== null && typeof value === 'object' && !Array.isArray(value)
		? (value as Record<string, unknown>)
		: {}
}

function projectMethod(value: unknown): Record<string, unknown> {
	const method = record(value)
	const projected: Record<string, unknown> = {
		id: method.id ?? null,
		title: method.title ?? null,
		type: method.type ?? null,
		amount: method.amount ?? null,
		enabled: method.enabled ?? null,
	}
	if (method.min_amount !== undefined) projected.min_amount = method.min_amount
	return projected
}

function projectZone(value: unknown): Record<string, unknown> {
	const zone = record(value)
	return {
		id: zone.id ?? null,
		name: zone.name ?? null,
		region: zone.region ?? null,
		order: zone.order ?? null,
		methods: Array.isArray(zone.methods) ? zone.methods.map(projectMethod) : [],
	}
}

export function shippingProfileTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_shipping_class_profile',
			title: 'Get Shipping Class Profile',
			description:
				'Read one shipping class together with its configured zones and delivery methods. ' +
				'The response keeps operational pricing and availability fields while omitting model ' +
				'timestamps and other persistence metadata.',
			schema: z.object({
				class_id: z.number().int().positive().describe('Shipping class ID'),
			}),
			endpoint: '/shipping/classes/:class_id/profile',
			routes: {
				kind: 'direct',
				variants: [{ method: 'GET', path: '/shipping/classes/{param}/profile' }],
			},
			transform: (data) => {
				const body = record(data)
				const shippingClass = record(body.shipping_class)
				return {
					shipping_class: {
						id: shippingClass.id ?? null,
						name: shippingClass.name ?? null,
						description: shippingClass.description ?? null,
						cost: shippingClass.cost ?? null,
						type: shippingClass.type ?? null,
						zones: Array.isArray(shippingClass.zones) ? shippingClass.zones.map(projectZone) : [],
					},
				}
			},
		}),
	]
}
