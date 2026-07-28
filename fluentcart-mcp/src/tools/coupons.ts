import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { getTool, type ToolDefinition } from './_factory.js'

/**
 * The redemption cap, which lives inside the `conditions` blob rather than beside `use_count`.
 *
 * Lifting one number out keeps "has this coupon hit its limit" answerable from the list. The rest
 * of `conditions` — product and category restrictions, purchase minimums, email allowlists — is
 * roughly 380 characters per row and belongs to `fluentcart_coupon_get`, which is where a caller
 * goes once it has a coupon in hand.
 */
function usageCap(value: unknown): unknown {
	if (value === null || typeof value !== 'object') return undefined
	return (value as Record<string, unknown>).max_uses
}

export function couponTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_coupon_list',
			title: 'List Coupons',
			description:
				'List coupons with optional search. Rows carry use_count against the max_uses cap, plus ' +
				'start_date and end_date, so expiry and redemption limits are answerable without fetching ' +
				'every coupon. status is derived rather than merely stored: a coupon past its end_date reads ' +
				'expired. Statuses: active, inactive, expired, scheduled. ' +
				'Types: percentage, fixed, free_shipping. amount is a percent for percentage, minor units for fixed.',
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
						// FluentCart names this `use_count`. The projection asked for `usage_count`, which
						// no coupon payload has ever carried, so the key was dropped from every row by
						// JSON.stringify and no caller could see a redemption count at all.
						use_count: item.use_count,
						max_uses: usageCap(item.conditions),
						start_date: item.start_date,
						end_date: item.end_date,
						created_at: item.created_at,
					}))
				}
				return resp
			},
		}),

		getTool(client, {
			name: 'fluentcart_coupon_get',
			title: 'Get Coupon',
			description:
				'Get one coupon: use_count, start and end dates, and the conditions object that decides ' +
				'eligibility — included_products, included_categories, max_uses, min_purchase_amount. ' +
				'An unknown coupon id answers HTTP 200 with coupon:null rather than an error, so a null ' +
				'coupon means no such coupon, never a coupon without details.',
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
				'ACTIVE coupons only, in a compact form for pickers. Expired and disabled coupons are filtered out upstream, so an empty result does not mean the store has no coupons — use fluentcart_coupon_list to see every coupon whatever its status.',
			schema: z.object({
				page: z.number().optional().describe('Page number'),
				per_page: z.number().max(50).optional().describe('Results per page (max: 50)'),
				search: z.string().optional().describe('Search coupons'),
			}),
			endpoint: '/coupons/listCoupons',
		}),
	]
}
