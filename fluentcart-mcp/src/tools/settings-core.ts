import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { createTool, getTool, postTool, putTool, type ToolDefinition } from './_factory.js'
import { direct } from './endpoints.js'

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
			routes: direct('POST', '/settings/store'),
			title: 'Save Store Settings',
			description:
				'Update store settings. Backend reads top-level keys directly (e.g. store_name, currency, order_mode). ' +
				'Use get_store first to discover current values. Pass only the keys you want to change.',
			schema: z.object({
				settings: z
					.record(z.string(), z.unknown())
					.describe(
						'Store settings as key-value pairs at top level (e.g. {currency: "USD", store_name: "My Shop", order_mode: "live"})',
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
				modules: z.record(z.string(), z.unknown()).describe('Module settings to save'),
			}),
			endpoint: '/settings/modules',
		}),

		createTool(client, {
			name: 'fluentcart_settings_save_confirmation',
			routes: direct('POST', '/settings/confirmation'),
			title: 'Save Confirmation Settings',
			description:
				'Update order confirmation page settings and template. Pass settings at top level.',
			schema: z.object({
				settings: z.record(z.string(), z.unknown()).describe('Confirmation page settings'),
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
				settings: z.record(z.string(), z.unknown()).describe('Payment method settings'),
			}),
			endpoint: '/settings/payment-methods',
		}),

		postTool(client, {
			name: 'fluentcart_settings_reorder_payment_methods',
			title: 'Reorder Payment Methods',
			description: 'Set the display order of payment methods on checkout.',
			schema: z.object({
				methods: z.array(z.string()).describe('Ordered array of payment method keys'),
			}),
			endpoint: '/settings/payment-methods/reorder',
		}),

		createTool(client, {
			name: 'fluentcart_settings_print_templates_get',
			routes: direct('GET', '/templates/print-templates'),
			title: 'Get Print Templates',
			description:
				'Which print templates exist — invoice, packing slip and so on — with their key, title and ' +
				'the size of each body. The HTML itself is omitted unless you ask: pass include_content ' +
				'true when you actually need the markup, remembering three templates came to 38,319 ' +
				'characters of it.',
			schema: z.object({
				include_content: z
					.boolean()
					.optional()
					.describe("Return each template's raw HTML body as well (large; default false)"),
			}),
			annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: true },
			// 99% of this response was `templates[].content` — 22,703 characters of markup across three
			// templates, 38,669 in total against a 40,000 cap, so a fourth template would have broken
			// the tool outright. The question it answers, "which templates are configured", needs about
			// 190 characters. The bodies stay reachable behind the flag rather than being removed,
			// because editing one legitimately needs them.
			handler: async (apiClient, input) => {
				const response = await apiClient.get('/templates/print-templates')
				const body = response.data as Record<string, unknown> | null
				const templates = body?.templates

				if (input.include_content === true || !Array.isArray(templates)) return response.data

				return {
					...body,
					templates: templates.map((entry) => {
						if (entry === null || typeof entry !== 'object') return entry
						const { content, ...rest } = entry as Record<string, unknown>
						return {
							...rest,
							content_characters: typeof content === 'string' ? content.length : 0,
						}
					}),
					content_omitted: 'Pass include_content true to receive the HTML bodies.',
				}
			},
		}),

		putTool(client, {
			name: 'fluentcart_settings_print_templates_save',
			title: 'Save Print Templates',
			description: 'Update print templates for invoices, packing slips, etc.',
			schema: z.object({
				templates: z.record(z.string(), z.unknown()).describe('Template settings to save'),
			}),
			endpoint: '/templates/print-templates',
		}),
	]
}
