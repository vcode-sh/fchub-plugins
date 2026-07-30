import { describe, expect, it, vi } from 'vitest'
import type { FluentCartClient } from '../../src/api/client.js'
import { emailNotificationTools } from '../../src/tools/email-notifications.js'

function toolWithResponse(name: string, data: Record<string, unknown>) {
	const client = {
		get: vi.fn().mockResolvedValue({ data, status: 200 }),
	} as unknown as FluentCartClient
	const tool = emailNotificationTools(client).find((candidate) => candidate.name === name)
	if (!tool) throw new Error(`${name} is not registered`)
	return tool
}

describe('email notification read projections', () => {
	it('keeps list status and preview routing without returning every template field', async () => {
		const tool = toolWithResponse('fluentcart_email_list', {
			data: {
				order_paid_admin: {
					event: 'order_paid_done',
					group: 'order',
					group_label: 'Order Actions',
					title: 'Send mail to admin after New Order Paid',
					description: 'Long admin-facing explanation',
					recipient: 'admin',
					smartcode_groups: [],
					template_path: 'order.paid.admin',
					is_async: false,
					pre_header: 'Long pre-header copy',
					settings: {
						active: 'yes',
						subject: 'New sale',
						is_default_body: 'no',
						email_body: '<p>Large custom body</p>',
						attach_pdf_template: 'order_receipt',
					},
					name: 'order_paid_admin',
					manage_toggle: true,
					toggle_label: 'Enabled',
				},
				renewal_created_customer: {
					event: 'renewal_created',
					group: 'manual_subscription',
					group_label: 'Store-Managed Renewals',
					title: 'Send renewal order to customer',
					description: 'Long renewal explanation',
					recipient: 'customer',
					smartcode_groups: [],
					template_path: 'renewal.created.customer',
					is_async: false,
					settings: {
						active: 'no',
						subject: 'Your renewal',
						is_default_body: 'yes',
						email_body: '',
					},
					name: 'renewal_created_customer',
				},
			},
		})

		const result = await tool.handler({})

		expect(JSON.parse(result.content[0]?.text ?? '{}')).toEqual({
			data: {
				order_paid_admin: {
					title: 'Send mail to admin after New Order Paid',
					group: 'order',
					recipient: 'admin',
					event: 'order_paid_done',
					template_path: 'order.paid.admin',
					settings: {
						active: 'yes',
						subject: 'New sale',
					},
				},
				renewal_created_customer: {
					title: 'Send renewal order to customer',
					group: 'manual_subscription',
					recipient: 'customer',
					event: 'renewal_created',
					template_path: 'renewal.created.customer',
					settings: {
						active: 'no',
						subject: 'Your renewal',
					},
				},
			},
		})
	})

	it('returns only the reminder switches and timing values from the store-wide settings payload', async () => {
		const tool = toolWithResponse('fluentcart_email_reminder_settings', {
			settings: {
				reminders_enabled: 'no',
				yearly_renewal_reminders_enabled: 'yes',
				yearly_renewal_reminder_days: '30',
				trial_end_reminders_enabled: 'yes',
				trial_end_reminder_days: '3',
				monthly_renewal_reminders_enabled: 'no',
				monthly_renewal_reminder_days: '7',
				quarterly_renewal_reminders_enabled: 'no',
				quarterly_renewal_reminder_days: '14',
				half_yearly_renewal_reminders_enabled: 'no',
				half_yearly_renewal_reminder_days: '14',
				renewal_reminders_enabled: 'yes',
				renewal_reminder_overdue_days: '1,3,7',
				store_name: 'Unrelated store setting',
				subscription_management_mode: 'store_managed',
				subscription_system_charge: 'yes',
				modules_settings: [],
			},
			fields: {
				reminders: {
					type: 'section',
					schema: {},
				},
			},
		})

		const result = await tool.handler({})

		expect(JSON.parse(result.content[0]?.text ?? '{}')).toEqual({
			settings: {
				reminders_enabled: 'no',
				yearly_renewal_reminders_enabled: 'yes',
				yearly_renewal_reminder_days: '30',
				trial_end_reminders_enabled: 'yes',
				trial_end_reminder_days: '3',
				monthly_renewal_reminders_enabled: 'no',
				monthly_renewal_reminder_days: '7',
				quarterly_renewal_reminders_enabled: 'no',
				quarterly_renewal_reminder_days: '14',
				half_yearly_renewal_reminders_enabled: 'no',
				half_yearly_renewal_reminder_days: '14',
				renewal_reminders_enabled: 'yes',
				renewal_reminder_overdue_days: '1,3,7',
			},
		})
	})
})
