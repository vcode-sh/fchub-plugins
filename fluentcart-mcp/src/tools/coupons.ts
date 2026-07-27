import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { getTool, type ToolDefinition } from './_factory.js'

export function couponTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_coupon_list',
			title: 'List Coupons',
			description:
				'List coupons with optional search. ' +
				'Types: percentage, fixed, free_shipping. Statuses: active, inactive.',
			schema: z.object({
				page: z.number().optional().describe('Page number (default: 1)'),
				per_page: z.number().max(50).optional().describe('Results per page (default: 10, max: 50)'),
				search: z.string().optional().describe('Search by coupon code or name'),
			}),
			endpoint: '/coupons',
			transform: (data: unknown) => {
				const resp = data as Record<string, unknown>
				const wrapper = (resp?.coupons ?? resp) as Record<string, unknown>
				if (wrapper && Array.isArray(wrapper.data)) {
					wrapper.data = (wrapper.data as Record<string, unknown>[]).map((item) => ({
						id: item.id,
						title: item.title,
						code: item.code,
						type: item.type,
						amount: item.amount,
						status: item.status,
						stackable: item.stackable,
						show_on_checkout: item.show_on_checkout,
						usage_count: item.usage_count,
						created_at: item.created_at,
					}))
				}
				return resp
			},
		}),

		getTool(client, {
			name: 'fluentcart_coupon_get',
			title: 'Get Coupon',
			description: 'Get coupon details including usage stats and eligibility rules.',
			schema: z.object({
				coupon_id: z.number().describe('Coupon ID'),
			}),
			endpoint: '/coupons/:coupon_id',
		}),

		getTool(client, {
			name: 'fluentcart_coupon_settings_get',
			title: 'Get Coupon Settings',
			description:
				'Global coupon settings. WARNING: show_on_checkout always reads null on FluentCart 1.5.5, ' +
				'whatever the store has saved, so null here means "cannot tell" and never "off" — do not ' +
				'report the checkout coupon field as disabled on the strength of it. The value is stored ' +
				'correctly and the admin UI shows it; only this endpoint misreads it.',
			schema: z.object({}),
			endpoint: '/coupons/getSettings',
		}),

		getTool(client, {
			name: 'fluentcart_coupon_list_alt',
			title: 'List Coupons (Alt)',
			description:
				'Non-paginated coupon list for dropdowns and selectors. Returns a simpler format than the main listing.',
			schema: z.object({
				page: z.number().optional().describe('Page number'),
				per_page: z.number().max(50).optional().describe('Results per page (max: 50)'),
				search: z.string().optional().describe('Search coupons'),
			}),
			endpoint: '/coupons/listCoupons',
		}),
	]
}
