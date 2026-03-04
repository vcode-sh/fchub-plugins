import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { createTool, getTool, postTool, type ToolDefinition } from './_factory.js'

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

		createTool(client, {
			name: 'fluentcart_coupon_update',
			title: 'Update Coupon',
			description:
				'Update a coupon using fetch-merge pattern. Fetches current state, merges your changes, submits full payload. ' +
				'Types: percentage, fixed, free_shipping.',
			annotations: { idempotentHint: true },
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
			handler: async (c, input) => {
				const id = input.coupon_id as number
				const current = await c.get(`/coupons/${id}`)
				const wrapper = current.data as Record<string, unknown>
				const coupon = (wrapper.coupon ?? wrapper) as Record<string, unknown>
				const { coupon_id: _id, ...changes } = input
				const merged = { ...coupon, ...changes } as Record<string, unknown>
				delete merged.id
				const resp = await c.put(`/coupons/${id}`, merged)
				return resp.data
			},
		}),

		createTool(client, {
			name: 'fluentcart_coupon_delete',
			title: 'Delete Coupon',
			description: 'Permanently delete a coupon. This action cannot be undone.',
			schema: z.object({
				coupon_id: z.number().describe('Coupon ID'),
			}),
			annotations: { destructiveHint: true },
			handler: async (cl, input) => {
				const id = input.coupon_id as number
				// The backend reads `id` from $request->all(). The standard deleteTool
				// only passes remaining params as query params, and the URL path param
				// may not propagate into the request bag. Sending `id` explicitly as a
				// query param ensures the controller can find it.
				const response = await cl.delete(`/coupons/${id}`, { id })
				return response.data
			},
		}),

		createTool(client, {
			name: 'fluentcart_coupon_apply',
			title: 'Apply Coupon',
			description:
				'Apply a coupon to an order. Fetches order items automatically. ' +
				'Side effect: recalculates order totals with discount.',
			schema: z.object({
				code: z.string().describe('Coupon code to apply'),
				order_id: z.number().describe('Order ID to apply the coupon to'),
			}),
			handler: async (c, input) => {
				const orderId = input.order_id as number
				const code = input.code as string
				const orderResp = await c.get(`/orders/${orderId}`)
				const orderWrapper = orderResp.data as Record<string, unknown>
				const order = (orderWrapper.order ?? orderWrapper) as Record<string, unknown>
				const items = (order.items ?? order.order_items ?? []) as Record<string, unknown>[]
				const orderItems = items.map((item) => ({
					post_id: item.post_id ?? item.product_id,
					object_id: item.object_id ?? item.variant_id ?? item.post_id ?? item.product_id,
					quantity: item.quantity ?? 1,
				}))
				const resp = await c.post('/coupons/apply', {
					coupon_code: code,
					order_id: orderId,
					order_items: orderItems,
				})
				return resp.data
			},
		}),

		createTool(client, {
			name: 'fluentcart_coupon_cancel',
			title: 'Cancel Coupon',
			description:
				'Remove an applied coupon from an order. Fetches order items automatically. ' +
				'Side effect: recalculates totals without discount.',
			schema: z.object({
				code: z.string().describe('Coupon code to remove'),
				order_id: z.number().describe('Order ID to remove the coupon from'),
			}),
			handler: async (c, input) => {
				const orderId = input.order_id as number
				const orderResp = await c.get(`/orders/${orderId}`)
				const orderWrapper = orderResp.data as Record<string, unknown>
				const order = (orderWrapper.order ?? orderWrapper) as Record<string, unknown>
				const items = (order.items ?? order.order_items ?? []) as Record<string, unknown>[]
				const orderItems = items.map((item) => ({
					post_id: item.post_id ?? item.product_id,
					object_id: item.object_id ?? item.variant_id ?? item.post_id ?? item.product_id,
					quantity: item.quantity ?? 1,
				}))
				const resp = await c.post('/coupons/cancel', {
					coupon_code: input.code,
					order_id: orderId,
					order_items: orderItems,
				})
				return resp.data
			},
		}),

		postTool(client, {
			name: 'fluentcart_coupon_reapply',
			title: 'Re-apply Coupon',
			description:
				'Re-apply a previously cancelled coupon. ' +
				'WARNING: This endpoint requires cart-session fields (order_uuid, applied_coupons array, order_items array) ' +
				'that are only available during an active checkout session. Returns empty defaults without them. ' +
				'Use coupon_apply for admin-side coupon operations on existing orders.',
			schema: z.object({
				code: z.string().optional().describe('Coupon code to re-apply'),
				order_id: z.number().optional().describe('Order ID'),
			}),
			endpoint: '/coupons/re-apply',
		}),

		postTool(client, {
			name: 'fluentcart_coupon_check_eligibility',
			title: 'Check Product Eligibility',
			description:
				'Check whether a specific product is eligible for a coupon. ' +
				'WARNING: This endpoint expects cart-context fields (appliedCoupons array, productId in camelCase) ' +
				'that are only available during an active checkout session. Not suitable for admin API usage. ' +
				'To check coupon rules, use coupon_get to inspect the conditions object instead.',
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
				show_on_checkout: z
					.boolean()
					.describe('Whether to show coupon input on checkout page (true/false)'),
			}),
			endpoint: '/coupons/storeCouponSettings',
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
