import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { deleteTool, getTool, postTool, putTool, type ToolDefinition } from './_factory.js'

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
		}),

		postTool(client, {
			name: 'fluentcart_coupon_create',
			title: 'Create Coupon',
			description:
				'Create a new coupon. For fixed type, amount is in cents. ' +
				'For percentage, amount is the percent value (e.g. 15 = 15% off).',
			schema: z.object({
				title: z.string().describe('Coupon display name (required)'),
				code: z.string().describe('Coupon code — unique identifier (required)'),
				type: z.string().describe('Coupon type: percentage, fixed, free_shipping (required)'),
				amount: z
					.number()
					.describe('Discount value — percentage or amount in cents for fixed type (required)'),
				status: z.string().describe('Status: active, inactive, scheduled (required)'),
				stackable: z
					.string()
					.describe("Allow stacking with other coupons: 'yes' or 'no' (required)"),
				show_on_checkout: z.string().describe("Show on checkout page: 'yes' or 'no' (required)"),
				notes: z.string().optional().describe('Internal notes (defaults to empty string)'),
				start_date: z.string().optional().describe('Start date (ISO 8601)'),
				end_date: z.string().optional().describe('End date (ISO 8601)'),
				conditions: z
					.object({
						max_uses: z.number().optional().describe('Max number of uses (null for unlimited)'),
						min_purchase_amount: z.number().optional().describe('Minimum order amount in cents'),
						max_discount_amount: z.number().optional().describe('Maximum discount amount in cents'),
						included_products: z
							.array(z.number())
							.optional()
							.describe('Product IDs this coupon applies to'),
						included_categories: z
							.array(z.number())
							.optional()
							.describe('Category IDs this coupon applies to'),
					})
					.optional()
					.describe('Coupon conditions and restrictions'),
			}),
			endpoint: '/coupons',
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

		putTool(client, {
			name: 'fluentcart_coupon_update',
			title: 'Update Coupon',
			description:
				'Update an existing coupon. All required fields must be sent (no partial updates). ' +
				'Types: percentage, fixed, free_shipping.',
			schema: z.object({
				coupon_id: z.number().describe('Coupon ID'),
				title: z.string().optional().describe('Coupon display name'),
				code: z.string().optional().describe('Coupon code'),
				type: z.string().optional().describe('Coupon type: percentage, fixed, free_shipping'),
				amount: z.number().optional().describe('Discount value — percentage or amount in cents'),
				status: z.string().optional().describe('Status: active, inactive, scheduled'),
				stackable: z.string().optional().describe("Allow stacking: 'yes' or 'no'"),
				show_on_checkout: z.string().optional().describe("Show on checkout: 'yes' or 'no'"),
				notes: z.string().optional().describe('Internal notes'),
				start_date: z.string().optional().describe('Start date (ISO 8601)'),
				end_date: z.string().optional().describe('End date (ISO 8601)'),
				conditions: z
					.object({
						max_uses: z.number().optional().describe('Max number of uses'),
						min_purchase_amount: z.number().optional().describe('Minimum order amount in cents'),
						max_discount_amount: z.number().optional().describe('Maximum discount amount in cents'),
						included_products: z
							.array(z.number())
							.optional()
							.describe('Product IDs this coupon applies to'),
						included_categories: z
							.array(z.number())
							.optional()
							.describe('Category IDs this coupon applies to'),
					})
					.optional()
					.describe('Coupon conditions and restrictions'),
			}),
			endpoint: '/coupons/:coupon_id',
		}),

		deleteTool(client, {
			name: 'fluentcart_coupon_delete',
			title: 'Delete Coupon',
			description: 'Permanently delete a coupon. This action cannot be undone.',
			schema: z.object({
				coupon_id: z.number().describe('Coupon ID'),
			}),
			endpoint: '/coupons/:coupon_id',
		}),

		postTool(client, {
			name: 'fluentcart_coupon_apply',
			title: 'Apply Coupon',
			description:
				'Apply a coupon to an order. Side effect: recalculates order totals with discount.',
			schema: z.object({
				code: z.string().describe('Coupon code to apply'),
				order_id: z.number().describe('Order ID to apply the coupon to'),
			}),
			endpoint: '/coupons/apply',
		}),

		postTool(client, {
			name: 'fluentcart_coupon_cancel',
			title: 'Cancel Coupon',
			description:
				'Remove an applied coupon from an order. Side effect: recalculates totals without discount.',
			schema: z.object({
				code: z.string().describe('Coupon code to remove'),
				order_id: z.number().describe('Order ID to remove the coupon from'),
			}),
			endpoint: '/coupons/cancel',
		}),

		postTool(client, {
			name: 'fluentcart_coupon_reapply',
			title: 'Re-apply Coupon',
			description:
				'Re-apply a previously cancelled coupon. Side effect: recalculates totals with discount.',
			schema: z.object({
				code: z.string().optional().describe('Coupon code to re-apply'),
				order_id: z.number().optional().describe('Order ID'),
			}),
			endpoint: '/coupons/re-apply',
		}),

		postTool(client, {
			name: 'fluentcart_coupon_check_eligibility',
			title: 'Check Product Eligibility',
			description: 'Check whether a specific product is eligible for a coupon.',
			schema: z.object({
				coupon_id: z.number().describe('Coupon ID'),
				product_id: z.number().describe('Product ID to check eligibility for'),
			}),
			endpoint: '/coupons/checkProductEligibility',
		}),

		getTool(client, {
			name: 'fluentcart_coupon_settings_get',
			title: 'Get Coupon Settings',
			description: 'Retrieve global coupon settings for the store.',
			schema: z.object({}),
			endpoint: '/coupons/getSettings',
		}),

		postTool(client, {
			name: 'fluentcart_coupon_settings_save',
			title: 'Save Coupon Settings',
			description: 'Save global coupon settings for the store.',
			schema: z.object({
				settings: z.record(z.string(), z.unknown()).optional().describe('Coupon settings object'),
			}),
			endpoint: '/coupons/storeCouponSettings',
		}),

		getTool(client, {
			name: 'fluentcart_coupon_list_alt',
			title: 'List Coupons (Alt)',
			description: 'Alternative coupon listing endpoint with different response format.',
			schema: z.object({
				page: z.number().optional().describe('Page number'),
				per_page: z.number().max(50).optional().describe('Results per page (max: 50)'),
				search: z.string().optional().describe('Search coupons'),
			}),
			endpoint: '/coupons/listCoupons',
		}),
	]
}
