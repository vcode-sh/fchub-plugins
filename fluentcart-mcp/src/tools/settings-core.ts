import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { getTool, postTool, type ToolDefinition } from './_factory.js'

export function settingsCoreTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_settings_get_store',
			title: 'Get Store Settings',
			description:
				'Retrieve store configuration settings including currency, address, checkout options, ' +
				'page assignments (checkout, cart, receipt, shop), button texts, and order mode (test/live).',
			schema: z.object({
				settings_name: z.string().optional().describe('Retrieve a specific settings group by name'),
			}),
			endpoint: '/settings/store',
		}),

		postTool(client, {
			name: 'fluentcart_settings_save_store',
			title: 'Save Store Settings',
			description:
				'Update store configuration settings. Accepts any key-value pairs from getStoreSettings ' +
				'(e.g. store_name, currency, order_mode, checkout page IDs, button texts). ' +
				'Only provided keys are updated; omitted keys remain unchanged.',
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
			description:
				'Retrieve all available payment methods with their status and configuration. ' +
				'Returns both active and inactive gateways (Stripe, PayPal, manual, etc.).',
			schema: z.object({}),
			endpoint: '/settings/payment-methods/all',
		}),

		getTool(client, {
			name: 'fluentcart_payment_get_settings',
			title: 'Get Payment Method Settings',
			description:
				'Retrieve settings for a specific payment method by its key. ' +
				'Returns method-specific configuration fields and current values.',
			schema: z.object({
				method: z.string().describe('Payment method key (e.g. "stripe", "paypal", "przelewy24")'),
			}),
			endpoint: '/settings/payment-methods',
		}),

		getTool(client, {
			name: 'fluentcart_settings_get_modules',
			title: 'Get Module Settings',
			description:
				'Retrieve module configuration including available modules (Turnstile, Stock Management, ' +
				'Licensing, Order Bump) and their activation status.',
			schema: z.object({}),
			endpoint: '/settings/modules',
		}),

		getTool(client, {
			name: 'fluentcart_settings_get_permissions',
			title: 'Get Permissions',
			description:
				'Retrieve available WordPress roles and currently configured capability permissions ' +
				'for FluentCart access control. Returns role names, keys, and capability flags.',
			schema: z.object({}),
			endpoint: '/settings/permissions',
		}),

		postTool(client, {
			name: 'fluentcart_settings_save_permissions',
			title: 'Save Permissions',
			description:
				'Update capability-based permission assignments for WordPress roles. ' +
				'Replaces the full capability list; omitted capabilities are removed.',
			schema: z.object({
				capability: z.array(z.string()).describe('Array of capability strings to assign to roles'),
			}),
			endpoint: '/settings/permissions',
		}),

		getTool(client, {
			name: 'fluentcart_settings_get_confirmation_shortcodes',
			title: 'Get Confirmation Shortcodes',
			description:
				'Retrieve available shortcodes for use in the order confirmation page template. ' +
				'Returns placeholder tokens that can be inserted into confirmation content.',
			schema: z.object({}),
			endpoint: '/settings/confirmation/shortcode',
		}),
	]
}
