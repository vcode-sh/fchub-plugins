import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { getTool, postTool, type ToolDefinition } from './_factory.js'

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

		postTool(client, {
			name: 'fluentcart_settings_save_store',
			title: 'Save Store Settings',
			description:
				'Update store settings. Only provided keys are updated; omitted keys remain unchanged.',
			schema: z.object({
				settings: z
					.record(z.string(), z.unknown())
					.describe(
						'Settings key-value pairs to update (e.g. {currency: "USD", order_mode: "live"})',
					),
			}),
			endpoint: '/settings/store',
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
	]
}
