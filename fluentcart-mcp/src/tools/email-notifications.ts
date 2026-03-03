import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { TTL } from '../cache.js'
import { createTool, getTool, postTool, putTool, type ToolDefinition } from './_factory.js'

export function emailNotificationTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_email_list',
			title: 'List Email Notifications',
			description: 'List all email notification templates with status.',
			schema: z.object({}),
			endpoint: '/email-notification',
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

		postTool(client, {
			name: 'fluentcart_email_toggle',
			title: 'Toggle Email Notification',
			description: 'Enable or disable an email notification.',
			schema: z.object({
				name: z.string().describe('Notification key/name'),
				active: z.string().optional().describe("Active status: 'yes' or 'no'"),
			}),
			endpoint: '/email-notification/enable-notification/:name',
		}),

		getTool(client, {
			name: 'fluentcart_email_shortcodes',
			title: 'Get Email Shortcodes',
			description: 'Get available shortcodes for email templates.',
			schema: z.object({}),
			endpoint: '/email-notification/get-short-codes',
			cache: { key: 'email_shortcodes', ttlMs: TTL.LONG },
		}),

		postTool(client, {
			name: 'fluentcart_email_template_preview',
			title: 'Preview Email Template',
			description: 'Preview rendered email template with sample data.',
			schema: z.object({
				notification: z.string().optional().describe('Notification key to preview'),
				body: z.string().optional().describe('Custom body to render'),
			}),
			endpoint: '/email-notification/get-template',
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
