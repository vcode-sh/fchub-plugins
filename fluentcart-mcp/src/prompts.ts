import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js'
import { z } from 'zod'

/**
 * Prompts that work in whichever mode the server is running.
 *
 * These used to hardcode a numbered list of tool names. Every one of those names is absent in
 * `dynamic` mode — the default — which exposes five meta-tools and discovers the rest at call
 * time, so the flagship "Analyze Store Performance" prompt was instructing the model to call four
 * tools that did not exist. Six of the fifteen names were missing in `curated` too, and two of
 * them were broken upstream regardless: `report_sales_growth` answers HTTP 500 on FluentCart
 * 1.5.5, and `report_top_products_sold` has been deprecated since 1.4 and returns nothing.
 *
 * So a prompt now leads with the question it is trying to answer, which is true in every mode,
 * and names tools only when those tools are actually registered. When they are not, it points at
 * whatever discovery mechanism this mode does offer. A prompt that names nothing still describes
 * a real task the model can carry out; a prompt that names a missing tool wastes a turn and
 * teaches the caller the server is broken.
 */

/** Dynamic mode's discovery trio, in the order they are meant to be used. */
const DISCOVERY_TOOLS = {
	search: 'fluentcart_search_tools',
	describe: 'fluentcart_describe_tools',
	execute: 'fluentcart_execute_read_tool',
} as const

/** Code mode's pair. */
const CODE_TOOLS = { search: 'fluentcart_search_api', execute: 'fluentcart_execute_code' } as const

interface Step {
	/** Preferred tool, in order. The first one registered wins. */
	prefer: string[]
	/** What this step is for, phrased so it still reads correctly with no tool named. */
	goal: string
}

/**
 * Render the "how to do it" half of a prompt against the tools this server actually registered.
 *
 * Steps whose tools are all missing keep their goal and lose their tool name, so the model is
 * told what to find out rather than which absent function to call.
 */
function route(steps: Step[], available: ReadonlySet<string>): string {
	const lines = steps.map((step, index) => {
		const tool = step.prefer.find((name) => available.has(name))
		return tool ? `${index + 1}. ${tool} — ${step.goal}` : `${index + 1}. ${step.goal}`
	})

	const named = steps.some((step) => step.prefer.some((name) => available.has(name)))
	if (named) return `Work through these:\n${lines.join('\n')}`

	if (available.has(DISCOVERY_TOOLS.search)) {
		return (
			`Find the tools for each step with ${DISCOVERY_TOOLS.search}, check their inputs with ` +
			`${DISCOVERY_TOOLS.describe} if the schema is unclear, then run them with ` +
			`${DISCOVERY_TOOLS.execute}:\n${lines.join('\n')}`
		)
	}
	if (available.has(CODE_TOOLS.search)) {
		return (
			`Locate the endpoints with ${CODE_TOOLS.search}, then fetch and combine them in one ` +
			`${CODE_TOOLS.execute} script:\n${lines.join('\n')}`
		)
	}
	return `Work through these:\n${lines.join('\n')}`
}

function userMessage(text: string) {
	return { messages: [{ role: 'user' as const, content: { type: 'text' as const, text } }] }
}

const DATE_ARGS = {
	startDate: z.string().describe('Start date (YYYY-MM-DD)'),
	endDate: z.string().describe('End date (YYYY-MM-DD)'),
}

export function registerPrompts(
	server: McpServer,
	available: ReadonlySet<string> = new Set(),
): void {
	server.registerPrompt(
		'analyze-store-performance',
		{
			title: 'Analyze Store Performance',
			description: 'Revenue, order volume and best sellers over a date range, with trends.',
			argsSchema: DATE_ARGS,
		},
		({ startDate, endDate }) =>
			userMessage(
				`Analyze store performance from ${startDate} to ${endDate}.\n\n` +
					route(
						[
							{
								prefer: ['fluentcart_report_sales_summary', 'fluentcart_report_revenue'],
								goal: 'totals for the period: gross sales, net revenue, refunds, order count and average order value',
							},
							{
								prefer: ['fluentcart_report_sales_trend'],
								goal: 'the same figures bucketed over time, to see the direction of travel',
							},
							{
								prefer: ['fluentcart_report_top_products'],
								goal: 'the best sellers for the period',
							},
						],
						available,
					) +
					'\n\nReports need a single currency, since the store never sums across them; take it and the ' +
					'timezone from the store context if you do not already know them. Read the warnings on each ' +
					'result before quoting a number — they say what the figure counts. Then summarise the trend ' +
					'and what you would do about it.',
			),
	)

	server.registerPrompt(
		'investigate-order',
		{
			title: 'Investigate Order',
			description: 'Deep-dive one order: payment status, transactions and activity timeline.',
			argsSchema: { order_id: z.string().describe('Order ID to investigate') },
		},
		({ order_id }) =>
			userMessage(
				`Investigate order #${order_id}.\n\n` +
					route(
						[
							{
								prefer: ['fluentcart_order_get'],
								goal: 'the order itself, including payment status',
							},
							{ prefer: ['fluentcart_order_transactions'], goal: 'its payment transactions' },
							{
								prefer: ['fluentcart_activity_list'],
								goal: 'the activity timeline for this order',
							},
						],
						available,
					) +
					'\n\nCheck for failed or disputed payments and for a refund that does not match the order ' +
					'total, then lay out the timeline and say plainly what happened.',
			),
	)

	server.registerPrompt(
		'customer-overview',
		{
			title: 'Customer Overview',
			description: 'A complete customer profile: stats, addresses and spending history.',
			argsSchema: { customer_id: z.string().describe('Customer ID to look up') },
		},
		({ customer_id }) =>
			userMessage(
				`Build an overview of customer #${customer_id}.\n\n` +
					route(
						[
							{
								prefer: ['fluentcart_customer_get'],
								goal: 'the customer profile, including purchase_count, ltv and aov',
							},
							// There used to be a second step here preferring `fluentcart_customer_stats` for
							// "their spending and order statistics". That route cannot supply them: it returns
							// `apply_filters('fluent_cart/widgets/single_customer', [], $customer)` and nothing
							// else, and no callback registers against that hook anywhere in FluentCart, so it
							// answers `{"widgets":[]}` for a customer with 16 orders and an ltv of 450300.
							// The figures the step asked for are on the profile the step above already fetches,
							// so this is one call fewer rather than a substitution.
							{ prefer: ['fluentcart_customer_addresses'], goal: 'the addresses on file' },
						],
						available,
					) +
					'\n\nSummarise who they are, what they are worth over their lifetime, and any pattern worth ' +
					'acting on — repeat purchases, a lapse, or a run of refunds.',
			),
	)

	server.registerPrompt(
		'catalog-summary',
		{
			title: 'Catalog Summary',
			description: 'Catalog health: product count, status spread, best and worst sellers.',
		},
		() =>
			userMessage(
				'Generate a catalog health summary.\n\n' +
					route(
						[
							{ prefer: ['fluentcart_product_list'], goal: 'every product and its status' },
							{
								prefer: ['fluentcart_report_top_products'],
								goal: 'the best sellers, to contrast against the catalogue as a whole',
							},
							{ prefer: ['fluentcart_dashboard_overview'], goal: 'overall store metrics' },
						],
						available,
					) +
					'\n\nReport catalogue size, the spread of statuses, the top performers, and anything that ' +
					'needs attention — drafts that were never published, or products with no sales at all. ' +
					'Best sellers are ranked by units sold, not revenue, so say so if you compare them.',
			),
	)

	server.registerPrompt(
		'subscription-health',
		{
			title: 'Subscription Health',
			description: 'Subscription health: active base, renewals and cancellations.',
			argsSchema: DATE_ARGS,
		},
		({ startDate, endDate }) =>
			userMessage(
				`Analyze subscription health from ${startDate} to ${endDate}.\n\n` +
					route(
						[
							{
								prefer: ['fluentcart_subscription_list'],
								goal: 'the subscriptions themselves, with their statuses and billing intervals',
							},
							{
								prefer: ['fluentcart_report_sales_summary'],
								goal: 'revenue over the same period, for context on what the base is worth',
							},
						],
						available,
					) +
					'\n\nWork out the active base, what renewed and what was cancelled, from the subscription ' +
					'records themselves. Two things to avoid: the renewal forecast endpoint ignores the dates ' +
					'you give it and sums across currencies, and the subscription chart has not been verified, ' +
					'so do not quote either as a metric. Say what you could not measure rather than estimating it.',
			),
	)
}
