import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { createTool, getTool, postTool, putTool, type ToolDefinition } from './_factory.js'

export function settingsCoreTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_settings_get_store',
			title: 'Get Store Settings',
			description:
				'Get store config: currency, address, checkout options, page assignments, button texts, and order mode.',
			schema: z.object({
				settings_name: z.string().optional().describe('Retrieve a specific settings group by name'),
			}),
			endpoint: '/settings/store',
		}),

		createTool(client, {
			name: 'fluentcart_settings_save_store',
			title: 'Save Store Settings',
			description:
				'Update store settings. Pass key-value pairs at top level (e.g. currency, order_mode, address). Only provided keys are updated.',
			schema: z.object({
				settings: z
					.record(z.string(), z.unknown())
					.describe(
						'Settings key-value pairs to update (e.g. {currency: "USD", order_mode: "live"})',
					),
			}),
			handler: async (c, input) => {
				const settings = (input.settings ?? {}) as Record<string, unknown>
				const resp = await c.post('/settings/store', settings)
				return resp.data
			},
		}),

		getTool(client, {
			name: 'fluentcart_payment_get_all',
			title: 'Get All Payment Methods',
			description: 'Get all payment methods with status and configuration (active and inactive).',
			schema: z.object({}),
			endpoint: '/settings/payment-methods/all',
		}),

		getTool(client, {
			name: 'fluentcart_payment_get_settings',
			title: 'Get Payment Method Settings',
			description: 'Get settings for a specific payment method by key.',
			schema: z.object({
				method: z.string().describe('Payment method key (e.g. "stripe", "paypal", "przelewy24")'),
			}),
			endpoint: '/settings/payment-methods',
		}),

		getTool(client, {
			name: 'fluentcart_settings_get_modules',
			title: 'Get Module Settings',
			description:
				'Get module config: Turnstile, Stock Management, Licensing, Order Bump and their activation status.',
			schema: z.object({}),
			endpoint: '/settings/modules',
		}),

		getTool(client, {
			name: 'fluentcart_settings_get_permissions',
			title: 'Get Permissions',
			description: 'Get WordPress roles and FluentCart capability permissions for access control.',
			schema: z.object({}),
			endpoint: '/settings/permissions',
		}),

		postTool(client, {
			name: 'fluentcart_settings_save_permissions',
			title: 'Save Permissions',
			description:
				'Update capability permissions for WordPress roles. Replaces full list; omitted capabilities are removed.',
			schema: z.object({
				capability: z.array(z.string()).describe('Array of capability strings to assign to roles'),
			}),
			endpoint: '/settings/permissions',
		}),

		getTool(client, {
			name: 'fluentcart_settings_get_confirmation_shortcodes',
			title: 'Get Confirmation Shortcodes',
			description: 'Get available shortcodes for the order confirmation page template.',
			schema: z.object({}),
			endpoint: '/settings/confirmation/shortcode',
		}),

		postTool(client, {
			name: 'fluentcart_settings_save_modules',
			title: 'Save Module Settings',
			description:
				'Update module activation and configuration (Turnstile, Stock, Licensing, Order Bump).',
			schema: z.object({
				modules: z
					.record(z.string(), z.unknown())
					.describe('Module settings to save'),
			}),
			endpoint: '/settings/modules',
		}),

		createTool(client, {
			name: 'fluentcart_settings_save_confirmation',
			title: 'Save Confirmation Settings',
			description: 'Update order confirmation page settings and template. Pass settings at top level.',
			schema: z.object({
				settings: z
					.record(z.string(), z.unknown())
					.describe('Confirmation page settings'),
			}),
			handler: async (c, input) => {
				const settings = (input.settings ?? {}) as Record<string, unknown>
				const resp = await c.post('/settings/confirmation', settings)
				return resp.data ?? { success: true }
			},
		}),

		postTool(client, {
			name: 'fluentcart_settings_save_payment_method',
			title: 'Save Payment Method Settings',
			description: 'Save settings for a specific payment method.',
			schema: z.object({
				method: z.string().describe('Payment method key'),
				settings: z
					.record(z.string(), z.unknown())
					.describe('Payment method settings'),
			}),
			endpoint: '/settings/payment-methods',
		}),

		postTool(client, {
			name: 'fluentcart_settings_reorder_payment_methods',
			title: 'Reorder Payment Methods',
			description: 'Set the display order of payment methods on checkout.',
			schema: z.object({
				methods: z
					.array(z.string())
					.describe('Ordered array of payment method keys'),
			}),
			endpoint: '/settings/payment-methods/reorder',
		}),

		getTool(client, {
			name: 'fluentcart_settings_print_templates_get',
			title: 'Get Print Templates',
			description: 'Get print templates for invoices, packing slips, etc.',
			schema: z.object({}),
			endpoint: '/templates/print-templates',
		}),

		putTool(client, {
			name: 'fluentcart_settings_print_templates_save',
			title: 'Save Print Templates',
			description: 'Update print templates for invoices, packing slips, etc.',
			schema: z.object({
				templates: z
					.record(z.string(), z.unknown())
					.describe('Template settings to save'),
			}),
			endpoint: '/templates/print-templates',
		}),
	]
}
