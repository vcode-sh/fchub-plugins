import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { FluentCartApiError } from '../api/errors.js'
import { createTool, postTool, putTool, type ToolDefinition } from './_factory.js'
import { composite, direct, op } from './endpoints.js'

/**
 * Order lifecycle: payment marking, status transitions and post-purchase artefacts.
 *
 * Split from orders-core.ts, which now holds only the order record's own CRUD.
 */
async function executeStatusOperations(
	c: FluentCartClient,
	orderId: number,
	operations: { key: string; body: Record<string, unknown> }[],
): Promise<Array<Record<string, unknown>>> {
	const results: Array<Record<string, unknown>> = []
	for (const op of operations) {
		try {
			const resp = await c.put(`/orders/${orderId}/statuses`, op.body)
			results.push({ field: op.key, ok: true, data: resp.data })
		} catch (error) {
			if (error instanceof FluentCartApiError) {
				results.push({
					field: op.key,
					ok: false,
					error: { code: error.code, message: error.message, detail: error.detail },
				})
				continue
			}
			throw error
		}
	}
	return results
}

export function orderLifecycleTools(client: FluentCartClient): ToolDefinition[] {
	return [
		createTool(client, {
			name: 'fluentcart_order_mark_paid',
			routes: direct('POST', '/orders/{param}/mark-as-paid'),
			title: 'Mark Order as Paid',
			description:
				'Mark an order as paid manually. Maps `note` to backend field `mark_paid_note`. ' +
				'Side effect: triggers order paid hooks and integration feeds.',
			schema: z.object({
				order_id: z.number().describe('Order ID'),
				payment_method: z.string().optional().describe('Payment method used'),
				transaction_id: z.string().optional().describe('External transaction ID'),
				note: z.string().optional().describe('Payment note (mapped to mark_paid_note)'),
			}),
			handler: async (c, input) => {
				const orderId = input.order_id as number
				const body: Record<string, unknown> = {}
				if (input.payment_method) body.payment_method = input.payment_method
				if (input.transaction_id) body.transaction_id = input.transaction_id
				if (input.note) body.mark_paid_note = input.note

				const resp = await c.post(`/orders/${orderId}/mark-as-paid`, body)
				return resp.data
			},
		}),

		createTool(client, {
			name: 'fluentcart_order_update_statuses',
			routes: composite(op('GET', '/orders/{param}'), op('PUT', '/orders/{param}/statuses')),
			title: 'Update Order Statuses',
			description:
				'Update payment, shipping, and order statuses independently using backend action+statuses payload mapping. ' +
				'Payment: pending, paid, partially_refunded, refunded, failed. ' +
				'Shipping: pending, shipped, delivered, returned, unshipped.',
			schema: z.object({
				order_id: z.number().describe('Order ID'),
				payment_status: z.string().optional().describe('Payment status'),
				shipping_status: z.string().optional().describe('Shipping status'),
				order_status: z.string().optional().describe('Order status'),
			}),
			handler: async (c, input) => {
				const orderId = input.order_id as number
				const currentResp = await c.get(`/orders/${orderId}`)
				const currentWrapper = currentResp.data as Record<string, unknown>
				const currentOrder = (currentWrapper.order ?? currentWrapper) as Record<string, unknown>

				const statusFields = [
					{
						key: 'order_status',
						action: 'change_order_status',
						current:
							(currentOrder.status as string | undefined) ??
							(currentOrder.order_status as string | undefined),
					},
					{
						key: 'shipping_status',
						action: 'change_shipping_status',
						current: currentOrder.shipping_status as string | undefined,
					},
					{
						key: 'payment_status',
						action: 'change_payment_status',
						current: currentOrder.payment_status as string | undefined,
					},
				] as const

				const operations = statusFields
					.filter(
						(f) => input[f.key] !== undefined && String(input[f.key]) !== String(f.current ?? ''),
					)
					.map((f) => ({
						key: f.key,
						body: { action: f.action, statuses: { [f.key]: input[f.key] } },
					}))

				if (!operations.length) {
					return { message: 'No status changes required', order_id: orderId, results: [] }
				}

				const results = await executeStatusOperations(c, orderId, operations)
				const successCount = results.filter((r) => r.ok === true).length
				if (successCount === 0) {
					throw new FluentCartApiError(
						'SERVER_ERROR',
						'Server error: Failed to update requested status fields',
						500,
						{ results },
					)
				}

				return {
					message:
						successCount === results.length
							? 'Statuses updated successfully'
							: 'Statuses updated partially',
					order_id: orderId,
					results,
				}
			},
		}),

		putTool(client, {
			name: 'fluentcart_order_sync_statuses',
			title: 'Sync Order Statuses',
			description:
				'Synchronise order statuses with the payment gateway. May fail with timezone errors on certain orders (data-dependent upstream issue).',
			schema: z.object({
				order_id: z.number().describe('Order ID'),
			}),
			endpoint: '/orders/:order_id/sync-statuses',
		}),

		createTool(client, {
			name: 'fluentcart_order_create_custom',
			routes: direct('POST', '/orders/{param}/create-custom'),
			title: 'Create Custom Order Line',
			description:
				'Add a custom (non-catalog) line item to an existing order. ' +
				'Requires item name, price, and quantity.',
			schema: z.object({
				order_id: z.number().describe('Order ID'),
				item_name: z.string().describe('Custom item name'),
				item_price: z.number().describe('Item price in currency units'),
				quantity: z.number().optional().describe('Quantity (default: 1)'),
				item_description: z.string().optional().describe('Item description'),
			}),
			handler: async (c, input) => {
				const orderId = input.order_id as number
				const product: Record<string, unknown> = {
					item_name: input.item_name,
					item_price: input.item_price,
					quantity: input.quantity ?? 1,
				}
				if (input.item_description) product.item_description = input.item_description

				const resp = await c.post(`/orders/${orderId}/create-custom`, { product })
				return resp.data
			},
		}),

		postTool(client, {
			name: 'fluentcart_order_generate_licenses',
			title: 'Generate Missing Licenses',
			description: 'Generate any missing licenses for digital products in an order.',
			schema: z.object({
				order_id: z.number().describe('Order ID'),
			}),
			endpoint: '/orders/:order_id/generate-missing-licenses',
		}),
	]
}
