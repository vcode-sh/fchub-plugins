import { z } from 'zod'

/**
 * Shared builders for variant write payloads.
 *
 * FluentCart's variant endpoints want a complete `other_info` block on every write, including
 * the subscription fields a one-time variant never uses. These helpers assemble that block so
 * the create and update tools cannot drift apart on what a full payload looks like.
 */
export const DEFAULT_OTHER_INFO = {
	payment_type: 'onetime',
	times: '',
	repeat_interval: '',
	trial_days: '',
	billing_summary: '',
	manage_setup_fee: 'no',
	signup_fee_name: '',
	signup_fee: '',
	setup_fee_per_item: 'no',
} as const

export function buildVariantFromExisting(
	existing: Record<string, unknown> | undefined,
	productId: number,
	variantId: number,
): Record<string, unknown> {
	const existingPrice = typeof existing?.item_price === 'number' ? existing.item_price / 100 : 0
	const existingComparePrice =
		typeof existing?.compare_price === 'number' ? existing.compare_price / 100 : 0
	const rawOtherInfo = (existing?.other_info ?? DEFAULT_OTHER_INFO) as Record<string, unknown>
	const existingOtherInfo = { ...rawOtherInfo }

	// Convert signup_fee from cents if stored as number
	if (typeof existingOtherInfo.signup_fee === 'number') {
		existingOtherInfo.signup_fee = existingOtherInfo.signup_fee / 100
	}

	return {
		id: variantId,
		post_id: productId,
		variation_title: existing?.variation_title ?? '',
		item_price: existingPrice,
		compare_price: existingComparePrice,
		...(typeof existing?.sku === 'string' && existing.sku.trim()
			? { sku: existing.sku.trim() }
			: {}),
		fulfillment_type: existing?.fulfillment_type ?? 'physical',
		stock_status: existing?.stock_status ?? 'in-stock',
		item_status: existing?.item_status ?? 'active',
		total_stock: existing?.total_stock ?? 0,
		available: existing?.available ?? 0,
		committed: existing?.committed ?? 0,
		on_hold: existing?.on_hold ?? 0,
		other_info: existingOtherInfo,
	}
}

export const subscriptionSchema = {
	payment_type: z
		.enum(['onetime', 'subscription'])
		.optional()
		.describe('Payment type (default: onetime)'),
	compare_price: z
		.number()
		.optional()
		.describe('Compare-at/strike-through price in currency units'),
	repeat_interval: z
		.string()
		.optional()
		.describe('Subscription interval: daily, weekly, monthly, quarterly, half_yearly, yearly'),
	times: z.number().optional().describe('Number of billing cycles (0 or omit for unlimited)'),
	trial_days: z.number().optional().describe('Trial period in days'),
	billing_summary: z.string().optional().describe('Human-readable billing summary'),
	manage_setup_fee: z.enum(['yes', 'no']).optional().describe('Enable setup fee (default: no)'),
	signup_fee_name: z.string().optional().describe('Label for setup fee'),
	signup_fee: z.number().optional().describe('Setup fee in currency units'),
	setup_fee_per_item: z.enum(['yes', 'no']).optional().describe('Charge per item'),
	item_status: z
		.enum(['active', 'inactive'])
		.optional()
		.describe('Variant status (default: active)'),
}

export function buildOtherInfo(input: Record<string, unknown>): Record<string, unknown> {
	const paymentType = (input.payment_type as string) || 'onetime'
	if (paymentType === 'subscription') {
		return {
			payment_type: 'subscription',
			times: String((input.times as number) ?? ''),
			repeat_interval: (input.repeat_interval as string) || 'monthly',
			trial_days: String((input.trial_days as number) ?? ''),
			billing_summary: (input.billing_summary as string) || '',
			manage_setup_fee: (input.manage_setup_fee as string) || 'no',
			signup_fee_name: (input.signup_fee_name as string) || '',
			signup_fee: (input.signup_fee as number) ?? '',
			setup_fee_per_item: (input.setup_fee_per_item as string) || 'no',
		}
	}
	return { payment_type: 'onetime' }
}
