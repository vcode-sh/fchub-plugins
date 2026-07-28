import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { getTool, type ToolDefinition } from './_factory.js'
import { projectOrderEnvelope } from './orders-core.js'

/**
 * Sort columns `/customers` will actually honour.
 *
 * `BaseFilter::parseSortBy` tests the requested column against the model's `$fillable` list and
 * substitutes `id` for anything absent from it — no error, no warning, just a different answer.
 * `created_at` is not fillable, so it was advertised and inert: measured live, `created_at`,
 * `updated_at` and `nonsense_column` all returned the identical id-descending page, while `ltv`
 * and `email` each returned a distinct one. A caller asking for the newest customers got them by
 * coincidence, because ids happen to ascend with time, and would stop getting them the moment a
 * store imported history.
 *
 * `purchase_value` was worse than inert. It IS fillable, so the store sorts by it — but the column
 * is JSON held in longtext, so DESC orders it as text and puts rows reading `[]` above the NULL
 * that the biggest spender carries. Measured live on the seeded store, `sort_by=purchase_value`
 * DESC returned customers 25-32, every one of whom has spent nothing, and customer 1 — sixteen
 * orders, ltv 450300, top spender by a factor of 45 — was not on the page at all. The description
 * offered it as the way to find top customers and it returned the precise opposite, confidently.
 *
 * The list is therefore closed. `ltv` is the column that answers the question it was offering.
 */
const SORTABLE_COLUMNS = [
	'id',
	'email',
	'ltv',
	'aov',
	'purchase_count',
	'first_purchase_date',
	'last_purchase_date',
] as const

/**
 * `purchase_value` is a per-currency map of minor units — and almost always empty.
 *
 * Nothing in the ordinary order flow writes it. `Customer::recountStat()` maintains `ltv`,
 * `purchase_count`, `aov` and the two purchase dates and never touches this column; only the
 * WooCommerce migrator, the `wp fluent-cart` recount command and the bulk-action listener fill
 * it. On the seeded store every row was `[]` or `null`, the top customer's included.
 *
 * So it is emitted only when it carries a figure. An empty map on every row spends tokens saying
 * nothing, and reads as "this customer has spent nothing" rather than "this store never wrote the
 * column" — which is the reading that produces a wrong answer.
 */
function purchaseValueOf(value: unknown): Record<string, unknown> {
	if (value === null || value === undefined) return {}
	if (Array.isArray(value)) return value.length === 0 ? {} : { purchase_value: value }
	if (typeof value === 'object') {
		return Object.keys(value).length === 0 ? {} : { purchase_value: value }
	}
	return { purchase_value: value }
}

/**
 * Say what an empty widget list means, instead of handing back `{"widgets":[]}`.
 *
 * `CustomerController::getStats` is one line: `apply_filters('fluent_cart/widgets/single_customer',
 * [], $customer)`. The seed is an empty array, and FluentCart 1.5.5 registers no callback on that
 * filter anywhere — grepped across the whole plugin tree, the `apply_filters` call is its only
 * occurrence. The route is a working endpoint for an extension point nobody has extended, so on a
 * store without a third-party add-on it answers `{"widgets":[]}` for every customer, including one
 * with sixteen orders and 450300 in lifetime value. Verified live, not inferred.
 *
 * That is a true answer to a question nobody asked, and an agent that went looking for a
 * customer's statistics will read it as "this customer has none". The figures it wanted exist and
 * are one call away, so the empty case says where they are. Not an error: an extension point with
 * nothing in it is an accurate empty, and flagging it as a failure would be its own lie.
 */
function customerStatsTool(
	client: FluentCartClient,
	config: Parameters<typeof getTool>[1],
): ToolDefinition {
	const tool = getTool(client, config)
	const inner = tool.handler

	return {
		...tool,
		handler: async (input: Record<string, unknown>) => {
			const result = await inner(input)
			if (result.isError) return result

			let widgets: unknown
			try {
				widgets = (JSON.parse(result.content[0]?.text ?? '') as { widgets?: unknown }).widgets
			} catch {
				return result
			}

			const populated = Array.isArray(widgets)
				? widgets.length > 0
				: widgets !== null && typeof widgets === 'object' && Object.keys(widgets).length > 0
			if (populated) return result

			return {
				content: [
					{
						type: 'text' as const,
						text:
							'This store registers no customer widgets, so this route has nothing to return. ' +
							'/customers/get-stats is an extension point for add-ons; FluentCart itself puts ' +
							'nothing in it, so it answers with an empty list for every customer however much ' +
							'they have spent. The real per-customer figures — purchase_count, ltv and aov in ' +
							'cents, first_purchase_date, last_purchase_date — come from fluentcart_customer_get.',
					},
				],
			}
		},
	}
}

export function customerTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_customer_list',
			title: 'List Customers',
			// "find the customer with the email hello@vcode.sh" is the commonest support question
			// there is, and this tool was invisible to it: measured, the top five for that query were
			// customer_create, customer_update, customer_address_update and two email settings tools.
			// The description never said the word "email" — only the schema field did, and the ranker
			// reads descriptions, not schemas. The same held for "lifetime value" and "average order
			// value", which appeared here only as the abbreviations ltv and aov.
			description:
				'Find customers by name or email, or list them sorted. Each row carries ltv (lifetime ' +
				'value), aov (average order value), purchase_count and the first and last purchase dates. ' +
				'ltv and aov are CENTS, summed across every currency the customer paid in, so sort by ltv ' +
				'DESC for top spenders. Numbers arrive as strings.',
			schema: z.object({
				page: z.number().optional().describe('Page number (default: 1)'),
				per_page: z.number().max(50).optional().describe('Results per page (default: 10, max: 50)'),
				search: z.string().optional().describe('Search by name or email'),
				sort_by: z
					.enum(SORTABLE_COLUMNS)
					.optional()
					.describe(
						'Sort column (default: id). Closed list: the store silently sorts by id instead for anything outside it, created_at included',
					),
				sort_type: z.string().optional().describe('Sort direction: ASC, DESC (default: DESC)'),
			}),
			endpoint: '/customers',
			transform: (data: unknown) => {
				const resp = data as Record<string, unknown>
				const wrapper = (resp?.customers ?? resp) as Record<string, unknown>
				if (wrapper && Array.isArray(wrapper.data)) {
					wrapper.data = (wrapper.data as Record<string, unknown>[]).map((item) => ({
						id: item.id,
						first_name: item.first_name,
						last_name: item.last_name,
						email: item.email,
						full_name: item.full_name,
						status: item.status,
						purchase_count: item.purchase_count,
						ltv: item.ltv,
						aov: item.aov,
						first_purchase_date: item.first_purchase_date,
						last_purchase_date: item.last_purchase_date,
						created_at: item.created_at,
						...purchaseValueOf(item.purchase_value),
					}))
				}
				return resp
			},
		}),

		getTool(client, {
			name: 'fluentcart_customer_get',
			title: 'Get Customer',
			description:
				'One customer in full, including the figures a stats question is usually after: ' +
				'purchase_count, ltv (lifetime value) and aov (average order value) in CENTS summed across ' +
				'currencies, first_purchase_date and last_purchase_date, plus labels and address count.',
			schema: z.object({
				customer_id: z.number().describe('Customer ID'),
			}),
			endpoint: '/customers/:customer_id',
			transform: (data: unknown) => {
				const resp = data as Record<string, unknown>
				const customer = (resp?.customer ?? resp) as Record<string, unknown>
				const { addresses, ...rest } = customer
				const shaped = Array.isArray(addresses)
					? { ...rest, address_count: (addresses as unknown[]).length }
					: rest
				return resp?.customer ? { ...resp, customer: shaped } : shaped
			},
		}),

		customerStatsTool(client, {
			name: 'fluentcart_customer_stats',
			title: 'Get Customer Widgets',
			description:
				'DIAGNOSTIC, not a metric. Third-party widgets registered against one customer, and nothing ' +
				'else. The controller returns only what add-ons hook onto fluent_cart/widgets/single_customer, ' +
				'which stock FluentCart leaves empty, so on most stores this answers with an empty list for ' +
				'every customer regardless of their history. For purchase_count, ltv, aov and the purchase ' +
				'dates use fluentcart_customer_get.',
			schema: z.object({
				customer_id: z.number().describe('Customer ID'),
			}),
			endpoint: '/customers/get-stats/:customer_id',
		}),

		getTool(client, {
			name: 'fluentcart_customer_addresses',
			title: 'Get Customer Addresses',
			// Measured: customer 999999 answers `{"addresses":[]}`, byte for byte what customer 26
			// answers. So an empty list cannot be read as "this customer has no address on file"
			// without checking the customer exists — which is what fluentcart_customer_get is for,
			// being the one customer route that returns 404 for an id that is not there.
			description:
				'Every billing and shipping address on file for one customer. A customer id that does not ' +
				'exist answers with an empty list rather than an error, so confirm the customer with ' +
				'fluentcart_customer_get before reading an empty result as "no address on file".',
			schema: z.object({
				customer_id: z.number().describe('Customer ID'),
			}),
			endpoint: '/customers/:customer_id/address',
		}),

		getTool(client, {
			name: 'fluentcart_customer_attachable_users',
			title: 'Get Attachable Users',
			description: 'Retrieve WordPress users that can be attached to customer records.',
			schema: z.object({
				search: z.string().optional().describe('Search users by name or email'),
			}),
			endpoint: '/customers/attachable-user',
		}),

		getTool(client, {
			name: 'fluentcart_customer_orders_simple',
			title: 'Get Customer Orders (Simple)',
			// The old text sent callers to "order_list with customer_id filter". There is no such
			// filter. `fluentcart_order_list` has no customer parameter, and /orders discards a
			// customer_id sent anyway: measured, /orders?customer_id=117 returned total 34 — every
			// order in the store — identical to /orders with no filter at all. A caller following
			// that sentence would have reported the whole store as one customer's history.
			description:
				'Every order for one customer, unpaginated: the full history in a single response, which ' +
				'grows without bound as the customer buys more. For a paged view use ' +
				'fluentcart_order_customer_orders. Not fluentcart_order_list — it has no customer filter, ' +
				'and the store ignores a customer_id passed to it rather than rejecting it.',
			schema: z.object({
				customer_id: z.number().describe('Customer ID'),
			}),
			endpoint: '/customers/:customer_id/order',
			// This route takes no paging parameter at all, so the only lever on its size is what each
			// row carries. Unprojected it measured 46,531 characters for one customer — over the
			// emergency cap, meaning it could not answer for anybody with real order history.
			transform: projectOrderEnvelope,
		}),
	]
}
