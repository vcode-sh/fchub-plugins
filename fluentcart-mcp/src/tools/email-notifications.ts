import { z } from 'zod'
import type { ApiCapabilities } from '../api/capabilities.js'
import type { FluentCartClient } from '../api/client.js'
import { FluentCartApiError } from '../api/errors.js'
import type { HttpMethod } from '../api/route-normalisation.js'
import { TTL } from '../cache.js'
import { createTool, getTool, putTool, type ToolDefinition } from './_factory.js'
import { direct } from './endpoints.js'

/**
 * Template preview moved route between 1.3.9 and the current release.
 *
 * Ordered current-first. Without capability evidence the current route wins: it is the only one
 * present on any supported FluentCart, and defaulting to the retired route would guarantee a 404.
 */
const PREVIEW_VARIANTS: readonly { method: HttpMethod; path: string }[] = [
	{ method: 'POST', path: '/email-notification/preview-default-template' },
	{ method: 'POST', path: '/email-notification/get-template' },
]

const REMINDER_SETTING_KEYS = [
	'reminders_enabled',
	'yearly_renewal_reminders_enabled',
	'yearly_renewal_reminder_days',
	'trial_end_reminders_enabled',
	'trial_end_reminder_days',
	'monthly_renewal_reminders_enabled',
	'monthly_renewal_reminder_days',
	'quarterly_renewal_reminders_enabled',
	'quarterly_renewal_reminder_days',
	'half_yearly_renewal_reminders_enabled',
	'half_yearly_renewal_reminder_days',
	'renewal_reminders_enabled',
	'renewal_reminder_overdue_days',
] as const

function asRecord(value: unknown): Record<string, unknown> {
	return value && typeof value === 'object' && !Array.isArray(value)
		? (value as Record<string, unknown>)
		: {}
}

function projectEmailNotifications(value: unknown): Record<string, unknown> {
	const rows = asRecord(asRecord(value).data)
	const data: Record<string, unknown> = {}

	for (const [name, value] of Object.entries(rows)) {
		const row = asRecord(value)
		const settings = asRecord(row.settings)
		data[name] = {
			title: row.title,
			group: row.group,
			recipient: row.recipient,
			event: row.event,
			template_path: row.template_path,
			settings: {
				active: settings.active,
				subject: settings.subject,
			},
		}
	}

	return { data }
}

function projectReminderSettings(value: unknown): Record<string, unknown> {
	const source = asRecord(asRecord(value).settings)
	const settings: Record<string, unknown> = {}

	for (const key of REMINDER_SETTING_KEYS) {
		if (Object.hasOwn(source, key)) settings[key] = source[key]
	}

	return { settings }
}

function selectPath(
	capabilities: ApiCapabilities | undefined,
	variants: readonly { method: HttpMethod; path: string }[],
): string | null {
	if (!capabilities) return variants[0]?.path ?? null
	return variants.find((variant) => capabilities.has(variant.method, variant.path))?.path ?? null
}

export function emailNotificationTools(
	client: FluentCartClient,
	capabilities?: ApiCapabilities,
): ToolDefinition[] {
	const previewPath = selectPath(capabilities, PREVIEW_VARIANTS)

	return [
		createTool(client, {
			name: 'fluentcart_email_list',
			routes: direct('GET', '/email-notification'),
			title: 'List Email Notifications',
			description:
				'List every email notification as a compact status summary. The response is an object ' +
				'keyed by notification name, not a paginated array. Each row keeps the title, group, ' +
				'recipient, event, template path, active state and subject; use fluentcart_email_get ' +
				'for the full template body and editing fields.',
			schema: z.object({}),
			annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: true },
			handler: async (c) => {
				const response = await c.get('/email-notification')
				return projectEmailNotifications(response.data)
			},
		}),

		getTool(client, {
			name: 'fluentcart_email_get',
			title: 'Get Email Notification',
			description: 'Get a specific email notification template.',
			schema: z.object({
				notification: z.string().describe('Notification key/name'),
			}),
			endpoint: '/email-notification/:notification',
		}),

		putTool(client, {
			name: 'fluentcart_email_update',
			title: 'Update Email Notification',
			description: 'Update an email notification template (subject, body, etc.).',
			schema: z.object({
				notification: z.string().describe('Notification key/name'),
				subject: z.string().optional().describe('Email subject line'),
				body: z.string().optional().describe('Email body (HTML)'),
				settings: z
					.record(z.string(), z.unknown())
					.optional()
					.describe('Additional template settings'),
			}),
			endpoint: '/email-notification/:notification',
		}),

		createTool(client, {
			name: 'fluentcart_email_toggle',
			routes: direct('POST', '/email-notification/enable-notification/{param}'),
			title: 'Toggle Email Notification',
			description:
				'Enable or disable an email notification. Use `active` (`yes`/`no`); `status` is accepted as a legacy alias.',
			schema: z.object({
				name: z.string().describe('Notification key/name'),
				active: z.string().optional().describe("Active status: 'yes' or 'no'"),
				status: z.string().optional().describe('Legacy alias for active'),
			}),
			handler: async (c, input) => {
				const name = input.name as string
				const active = (input.active as string | undefined) || (input.status as string | undefined)
				const body: Record<string, unknown> = {}
				if (active !== undefined) body.active = active
				const response = await c.post(`/email-notification/enable-notification/${name}`, body)
				return response.data
			},
		}),

		getTool(client, {
			name: 'fluentcart_email_shortcodes',
			title: 'Get Email Shortcodes',
			description: 'Get available shortcodes for email templates.',
			schema: z.object({}),
			endpoint: '/email-notification/get-short-codes',
			cache: { key: 'email_shortcodes', ttlMs: TTL.LONG },
		}),

		...(previewPath
			? [
					createTool(client, {
						name: 'fluentcart_email_template_preview',
						// The variant is already resolved above, so declare the one route this
						// registration will call. Declaring the alternative too would claim a route the
						// connected store does not serve.
						routes: direct('POST', previewPath),
						title: 'Preview Email Template',
						description:
							'Preview a stored email template rendered with sample order data. ' +
							'Returns the rendered HTML. `template` is the notification template key ' +
							'(e.g. "order.paid.admin"); read the available keys from fluentcart_email_list. ' +
							'Rendering arbitrary HTML is not supported: the endpoint always renders the ' +
							'stored template selected by `template`.',
						schema: z.object({
							template: z
								.string()
								.describe('Notification template key to preview (e.g. "order.paid.admin")'),
							body: z
								.string()
								.optional()
								.describe('Not supported — rejected. The endpoint renders the stored template.'),
						}),
						annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: true },
						handler: async (c, input) => {
							// Neither the 1.3.9 contract nor the current controller reads a custom body;
							// forwarding it would render the default template and quietly ignore the caller.
							if (input.body !== undefined) {
								throw new FluentCartApiError(
									'VALIDATION_ERROR',
									'Validation error: `body` is not supported. This endpoint renders the stored ' +
										'template named by `template`; a custom body would be silently ignored.',
									422,
								)
							}
							const response = await c.post(previewPath, { template: input.template })
							return response.data
						},
					}),
				]
			: []),

		getTool(client, {
			name: 'fluentcart_email_digest_settings',
			title: 'Get Email Digest Settings',
			description:
				'Read the staff digest schedule: whether digests are enabled, who receives them, ' +
				'whether an empty period still sends, and the daily/weekly/monthly cadence with send ' +
				'hour and day of week.',
			schema: z.object({}),
			endpoint: '/email-notification/digest-settings',
		}),

		createTool(client, {
			name: 'fluentcart_email_reminder_settings',
			title: 'Get Email Reminder Settings',
			description:
				'Read the customer reminder schedule: which renewal and trial-end reminders are on, ' +
				'and how many days ahead each fires. The store returns these settings wrapped in the ' +
				'admin form schema; only the settings are returned here, because the rest is HTML for ' +
				'rendering the settings page and describes no store behaviour.',
			schema: z.object({}),
			annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: true },
			routes: direct('GET', '/email-notification/reminders'),
			handler: async (c) => {
				const response = await c.get('/email-notification/reminders')
				return projectReminderSettings(response.data)
			},
		}),

		getTool(client, {
			name: 'fluentcart_email_settings_get',
			title: 'Get Email Settings',
			description: 'Get global email notification settings (from address, logo, etc.).',
			schema: z.object({}),
			endpoint: '/email-notification/get-settings',
		}),

		createTool(client, {
			name: 'fluentcart_email_settings_save',
			routes: direct('POST', '/email-notification/save-settings'),
			title: 'Save Email Settings',
			description:
				'Save global email notification settings. Pass fields at top level: from_name, from_email, admin_email, logo_url, footer_text.',
			schema: z.object({
				from_name: z.string().optional().describe('Sender display name'),
				from_email: z.string().optional().describe('Sender email address'),
				admin_email: z.string().optional().describe('Admin notification email'),
				logo_url: z.string().optional().describe('Logo URL for email header'),
				footer_text: z.string().optional().describe('Email footer text'),
			}),
			handler: async (c, input) => {
				const resp = await c.post('/email-notification/save-settings', input)
				return resp.data
			},
		}),
	]
}
