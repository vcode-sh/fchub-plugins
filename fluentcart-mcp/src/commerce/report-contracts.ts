/**
 * What a report has to prove before it may be called a metric.
 *
 * HTTP 200 is not a definition. A route qualifies only once its unit, filters, grouping, timezone,
 * payment scope, currency behaviour and empty-set behaviour are readable from the controller and
 * confirmed by a seeded assertion. Everything else stays diagnostic: reachable through the raw REST
 * tools, never dressed up as an answer about money. Evidence cites FluentCart 1.5.5 with Pro 1.5.4.
 */

export type ReportName =
	| 'sales_summary'
	| 'sales_trend'
	| 'top_products'
	| 'refund_summary'
	| 'future_renewals'
	| 'order_sources'
	| 'subscription_retention'

export const REPORT_NAMES: readonly ReportName[] = [
	'sales_summary',
	'sales_trend',
	'top_products',
	'refund_summary',
	'future_renewals',
	'order_sources',
	'subscription_retention',
]

/** Which timestamp a period filters on, whether its ends are included, and whether it can move. */
export interface DateSemantics {
	column: string
	boundaries: 'inclusive-both-ends'
	retroactive: boolean
}

/** `store-local` means bounds bind as site wall-clock; `provenBy` records how we know. */
export interface TimezoneSemantics {
	basis: 'store-local' | 'utc'
	provenBy: 'seeded-assertion' | 'source-only'
}

/** FluentCart stores `mode` on every order but no report query filters it. */
export interface PaymentModeSemantics {
	scope: 'live-and-test-combined'
	filterable: boolean
}

/**
 * `caller-scoped` pins one currency per request so nothing is summed across two.
 * `summed-across-currencies` is not publishable and may never be accepted.
 */
export interface CurrencySemantics {
	handling: 'caller-scoped' | 'grouped-by-currency' | 'summed-across-currencies'
	scopedBy: string | null
}

export interface ReportEvidence {
	controllerFile: string
	controllerMethod: string
	serviceFile: string
	serviceMethod: string
	/** What a reviewer must know that the field list alone does not convey. */
	notes: string
}

export interface AcceptedReport {
	name: ReportName
	status: 'accepted'
	method: 'GET'
	path: string
	requestFields: string[]
	dates: DateSemantics
	timezone: TimezoneSemantics
	paymentMode: PaymentModeSemantics
	currency: CurrencySemantics
	/** Allowlist of output keys. Anything absent here never reaches a caller. */
	outputProjection: string[]
	/**
	 * Output key to the field the store actually sends, for reports whose response does not use
	 * our vocabulary. Absent means the store's names and ours already agree.
	 */
	sourceFields?: Readonly<Record<string, string>>
	/** Output keys computed here rather than read from the response. */
	derivedFields?: readonly string[]
	emptySet: string
	evidence: ReportEvidence
	/** Caveats a caller must see on every result. */
	warnings: string[]
}

export interface DiagnosticReport {
	name: ReportName
	status: 'diagnostic-only'
	method: 'GET'
	path: string
	/** Precisely why this is not a metric. One sentence a reviewer can check. */
	rejection: string
	evidence: Pick<ReportEvidence, 'controllerFile' | 'controllerMethod'>
}

export type ReportContract = AcceptedReport | DiagnosticReport

/**
 * Routes named in planning that this runtime does not serve at all.
 *
 * Both answer HTTP 404 with `rest_no_route`, which is WordPress saying the route was never
 * registered — not FluentCart saying the report failed. Established by sweeping every report path
 * any tool reaches on FluentCart 1.5.5 with Pro 1.5.4: 40 of the 42 answered, these two did not.
 * `/reports/cart-report` was missing from this list until 2026-07-27, so a contract could have
 * pointed at it and passed the completeness check.
 */
export const ABSENT_ROUTES: readonly string[] = [
	'/reports/get-unfulfilled-orders',
	'/reports/cart-report',
]

function reportsController(file: string, method: string) {
	return { controllerFile: `app/Http/Controllers/Reports/${file}`, controllerMethod: method }
}

/** A caller-supplied payment status is discarded; FluentCart overwrites it with this fixed set. */
const COUNTED_PAYMENT_STATUSES = 'paid, refunded, partially_paid, partially_refunded'

/** Named rather than indexed, so a report cannot lose a caveat by slicing the wrong element. */
const WARN_STATUSES = `Counts orders with payment status ${COUNTED_PAYMENT_STATUSES}; the caller cannot narrow this.`
const WARN_TEST_MODE =
	'Includes test-mode orders. FluentCart applies no mode filter to any report query.'
const WARN_RETROACTIVE =
	'Refunds are attributed to the order creation date, so a closed period can still move.'
const WARN_PRO =
	'Caller date ranges are honoured only while FluentCart Pro is active; without it the store silently reports a rolling 30-day window.'

const WARN_TIMEZONE =
	'Period boundaries are read in the store timezone according to the ORM, but no seeded assertion has confirmed which wall clock the store actually compares against. Treat a result near a day boundary as approximate.'

/**
 * The gap between this and the order list, said out loud on every result.
 *
 * FluentCart answers "how much did the store sell" with six different numbers, and an agent that
 * quotes the wrong one is not slightly off — it is wrong about money. Measured 2026-07-28 on the
 * seeded store (34 orders, EUR and PLN, FluentCart 1.5.5 with Pro 1.5.4), all time, every currency:
 *
 *   4,738.69 / 25 orders  /reports/revenue            ← this contract. One table, no joins.
 *   4,738.69 / 25 orders  /reports/revenue-by-group   segmented, same figure
 *   4,738.69 / 25 orders  /reports/fetch-order-by-group
 *   4,712.70 / 24 orders  /reports/sales-report, /reports/order-chart
 *   4,962.70              /reports/product-report
 *   4,683.99 / 22 orders  /reports/dashboard-stats (paid only)
 *          — / 34 orders  /orders
 *
 * The database says 25 orders carry a countable payment status and their total_paid sums to
 * 473,869 minor units, so 4,738.69 is the correct figure and 25 the correct count. The rest are
 * explained rather than merely different:
 *
 * - 34 is every order. Nine are payment_status `pending` (six on-hold, three draft) and no report
 *   counts them. Both numbers are right; only one of them answers "what did we sell".
 * - 4,712.70 drops one order. Those two routes inner-join a subquery over `fct_order_items`, so an
 *   order that has no line items at all disappears with its revenue. The store holds exactly one
 *   such order, worth 25.99.
 * - 4,962.70 is not a different question, it is a broken query.
 *   `ProductReportService::getProductReportData` joins a subquery grouped by
 *   `(order_id, object_id)` and then sums the ORDER-level column `o.total_paid`, so an order is
 *   added once per distinct variation it contains. One seeded order holds three variations and is
 *   counted three times: 473,869 − 2,599 (the item-less order) + 25,000 (that order twice more)
 *   = 496,270, which is the figure to the cent. It overstates, and it overstates more the more
 *   multi-line orders a store takes.
 *
 * The arithmetic above is re-derived from live data by
 * tests/integration/report-family-reconciliation.test.ts, so a change upstream fails a test rather
 * than quietly ageing this comment.
 */
const WARN_ORDER_LIST_GAP =
	'order_count counts only orders with a countable payment status, so it is lower than the total in fluentcart_order_list, which returns every order including pending, draft and on-hold ones. The difference is excluded revenue, not missing orders. Money here is total_paid — what was actually collected — not what was ordered.'

/**
 * Named for the same reason the others are: a rival figure a caller may already be holding.
 *
 * `fluentcart_report_product` reads `/reports/product-report` and answers the same question with a
 * larger number. Callers reconcile the two by asking which is right, so the answer travels with
 * every result rather than living only in a source comment.
 */
const WARN_RIVAL_TOTALS =
	'Other FluentCart report routes answer this question with different figures. fluentcart_report_product overstates gross sales — its query sums the order total once per distinct product variation in the order — and fluentcart_report_sales / fluentcart_report_order_chart drop any order that has no line items. This figure reconciles with the order list; those do not.'

const REVENUE_WARNINGS = [
	WARN_STATUSES,
	WARN_TEST_MODE,
	WARN_RETROACTIVE,
	WARN_PRO,
	WARN_TIMEZONE,
	WARN_ORDER_LIST_GAP,
	WARN_RIVAL_TOTALS,
]

/** Shared by both projections of `/reports/revenue`; one request, two allowlists. */
const REVENUE_BASE = {
	method: 'GET' as const,
	path: '/reports/revenue',
	requestFields: ['params[startDate]', 'params[endDate]', 'params[currency]', 'params[groupKey]'],
	dates: {
		column: 'fct_orders.created_at',
		boundaries: 'inclusive-both-ends' as const,
		retroactive: true,
	},
	timezone: { basis: 'store-local' as const, provenBy: 'source-only' as const },
	paymentMode: { scope: 'live-and-test-combined' as const, filterable: false },
	currency: { handling: 'caller-scoped' as const, scopedBy: 'params[currency]' },
	warnings: REVENUE_WARNINGS,
}

/**
 * FluentCart names the same figures differently in the two halves of one `/reports/revenue`
 * response: the summary block calls gross sales `gross_sale` and tax `tax_total`, while every
 * trend row in the same payload calls them `total_sales` and `total_tax`. Neither name is wrong,
 * they are simply built by different code, and a caller has no way to guess which half it is
 * holding.
 *
 * So the raw names stop here. Both shapes are mapped onto one vocabulary, and these two tables
 * are the only place in the server that knows what the store calls anything. A contract test
 * checks every value below against the captured response fixture, because the previous version of
 * this file guessed the summary names from the trend rows and shipped four permanently-null
 * fields — including total sales — behind a warning that made it look deliberate.
 */
const SUMMARY_SOURCE_FIELDS = {
	gross_sales: 'gross_sale',
	net_revenue: 'net_revenue',
	tax: 'tax_total',
	shipping: 'shipping_total',
	refunded_amount: 'total_refunded_amount',
	order_count: 'order_count',
	refunded_orders: 'refunded_orders',
} as const

const TREND_SOURCE_FIELDS = {
	gross_sales: 'total_sales',
	net_revenue: 'net_revenue',
	order_count: 'order_count',
} as const

const REVENUE_EVIDENCE = {
	controllerFile: 'app/Http/Controllers/Reports/RevenueReportController.php',
	controllerMethod: 'getRevenue',
	serviceFile: 'app/Services/Report/RevenueReportService.php',
	serviceMethod: 'getRevenueData',
}

export const REPORT_CONTRACTS: Readonly<Record<ReportName, ReportContract>> = {
	sales_summary: {
		...REVENUE_BASE,
		name: 'sales_summary',
		status: 'accepted',
		outputProjection: [...Object.keys(SUMMARY_SOURCE_FIELDS), 'average_order_value'],
		sourceFields: SUMMARY_SOURCE_FIELDS,
		// The store sends no average. It is gross sales over order count, computed here so the
		// caller does not have to divide two numbers whose scopes it cannot see.
		derivedFields: ['average_order_value'],
		emptySet: 'All summary fields return zero; the response is never structurally empty.',
		evidence: {
			...REVENUE_EVIDENCE,
			notes:
				'Single table, no joins — which is exactly why this figure reconciles with the order list where the item-joining reports do not. gross_sale is SUM(o.total_paid) and net_revenue subtracts refunds, tax and shipping tax from it, so both describe money collected rather than money ordered; on an order that is partially paid the two diverge. Amounts are divided by 100 in SQL, so the response carries decimals while the column stores minor units. The summary block uses different field names from the trend rows in the same response; both are mapped in this file.',
		},
	},
	sales_trend: {
		...REVENUE_BASE,
		name: 'sales_trend',
		status: 'accepted',
		outputProjection: ['period', ...Object.keys(TREND_SOURCE_FIELDS)],
		sourceFields: TREND_SOURCE_FIELDS,
		emptySet:
			'One zero-filled row per period is always returned; getPeriodRange pre-builds the buckets.',
		evidence: {
			...REVENUE_EVIDENCE,
			notes:
				'Grouped by DATE_FORMAT over created_at. An OMITTED groupKey is resolved by range width — daily to 91 days, monthly to 365, yearly beyond (ReportHelper::defineGroupKey). An UNRECOGNISED one is rewritten to payment_method, not to daily (ReportHelper::sanitizeGroupKey), so it returns a payment-method breakdown shaped like a time series. Only monthly and yearly may safely be named.',
		},
	},
	top_products: {
		name: 'top_products',
		status: 'accepted',
		method: 'GET',
		path: '/reports/fetch-top-sold-products',
		requestFields: ['params[startDate]', 'params[endDate]', 'params[currency]'],
		dates: {
			column: 'fct_orders.created_at',
			boundaries: 'inclusive-both-ends',
			retroactive: false,
		},
		timezone: { basis: 'store-local', provenBy: 'source-only' },
		paymentMode: { scope: 'live-and-test-combined', filterable: false },
		currency: { handling: 'caller-scoped', scopedBy: 'params[currency]' },
		outputProjection: ['product_id', 'product_name', 'quantity_sold', 'total_amount'],
		emptySet: 'Returns an empty list.',
		evidence: {
			controllerFile: 'app/Http/Controllers/Reports/DefaultReportController.php',
			controllerMethod: 'getTopSoldProducts',
			serviceFile: 'app/Services/Report/DefaultReportService.php',
			serviceMethod: 'fetchTopSoldProducts',
			notes:
				'Ranked by SUM(quantity) descending, capped at 20 rows by the controller. Aggregates fct_order_items directly, so total_amount is SUM(line_total) rather than the order-level column the revenue reports use — a different figure by construction, not a discrepancy. Product names come from the most recent order item, not the product record, so a renamed product shows its last-sold title.',
		},
		warnings: [
			WARN_STATUSES,
			WARN_TEST_MODE,
			WARN_PRO,
			WARN_TIMEZONE,
			'Ranking is by units sold, not revenue. A cheap high-volume product outranks an expensive one.',
			'Limited to the 20 highest-selling products; the store applies this cap, not this server.',
			'total_amount sums order-item line totals, so these figures do not add up to gross_sales in fluentcart_report_sales_summary. That total is order-level and also covers orders with no line items at all; the gap is a real one and neither number is wrong.',
		],
	},

	// ── Diagnostic only. Reachable through the raw report tools, never as a metric. ──

	refund_summary: {
		name: 'refund_summary',
		status: 'diagnostic-only',
		method: 'GET',
		path: '/reports/refund-chart',
		rejection:
			'The date range filters order creation, not refund timing, so "refunds this month" actually means "refunds attached to orders created this month, whenever they happened". A refund rate that moves in a period where nothing was refunded is not a metric.',
		evidence: reportsController('RefundReportController.php', 'getRefundChart'),
	},
	future_renewals: {
		name: 'future_renewals',
		status: 'diagnostic-only',
		method: 'GET',
		path: '/reports/future-renewals',
		rejection:
			'Caller dates are ignored for a hardcoded today-plus-one-quarter window, amounts are returned in minor units while every neighbouring report returns decimals, and fct_subscriptions carries neither a currency nor a mode column, so cross-currency summation is unavoidable.',
		evidence: reportsController('SubscriptionReportController.php', 'getFutureRenewals'),
	},
	order_sources: {
		name: 'order_sources',
		status: 'diagnostic-only',
		method: 'GET',
		path: '/reports/sources',
		rejection:
			'The query selects utm_term, utm_content and utm_id without grouping or aggregating them, so under default ONLY_FULL_GROUP_BY it errors and otherwise returns an arbitrary row per group. Untagged orders are also dropped, so totals cannot reconcile with revenue.',
		evidence: reportsController('SourceReportController.php', 'index'),
	},

	/**
	 * The best payload in the plugin, and still not a metric.
	 *
	 * With filters nested under `params`, `/reports/subscription-retention` returns a real monthly
	 * MRR and churn series — mrr, new_subscriptions_mrr, churned_subscriptions,
	 * churned_subscriptions_mrr, retention_rate, retention_rate_money, active_paid_subscriptions —
	 * 4,429 bytes against 382 for a single month. Nothing else here comes close, and the server has
	 * no MRR tool at all, so the temptation to promote it is considerable.
	 *
	 * What stops it is the same thing that stopped `future_renewals`, and it is worth stating
	 * plainly because the two rejections must not drift apart. `fct_subscriptions` has no currency
	 * column: the currency of a subscription lives inside the JSON `config` blob, where a SUM cannot
	 * reach it. So every money figure in the series adds EUR to PLN. `params[currency]` is accepted,
	 * sanitised, carried through ReportHelper::processParams into the attributes array — and then
	 * never read by SubscriptionReportService::getRetentionData. Verified live rather than inferred:
	 * requesting EUR and requesting PLN returned byte-identical 4,429-byte responses.
	 *
	 * Everything else about the report is sound, which is what makes this worth recording rather
	 * than merely refusing. Caller dates are honoured (a 2026-03-01..2026-03-05 range returned one
	 * March bucket; 1990 returned one 1990 bucket). Amounts are decimals, divided by 100 in SQL,
	 * matching the revenue reports rather than the minor units `future_renewals` hands back. Yearly,
	 * weekly and daily plans are normalised to a monthly equivalent in SQL. Pending and intended
	 * subscriptions are excluded; cancelled and expired ones are deliberately kept, because churn
	 * cannot be counted without them.
	 *
	 * The counts and the count-derived retention_rate carry no currency at all and would qualify on
	 * their own. Admitting them would mean relaxing the rule that every accepted report pins a
	 * currency through `params[currency]`, which is not a decision to take while writing the
	 * contract that the rule exists to constrain.
	 */
	subscription_retention: {
		name: 'subscription_retention',
		status: 'diagnostic-only',
		method: 'GET',
		path: '/reports/subscription-retention',
		rejection:
			'Money cannot be scoped: fct_subscriptions stores currency inside the JSON config column, so the SUM cannot filter on it and mrr, new_subscriptions_mrr, churned_subscriptions_mrr and period_gross add every currency the store sells in together. params[currency] is accepted and discarded — verified live by byte-identical 4,429-byte responses for EUR and for PLN. The month buckets are also anchored to the start date day-of-month, so a range whose final month ends before that day drops that month entirely (SubscriptionReportService::getRetentionData, DatePeriod over P1M).',
		evidence: reportsController('SubscriptionReportController.php', 'getRetentionData'),
	},
}

export function isAccepted(contract: ReportContract): contract is AcceptedReport {
	return contract.status === 'accepted'
}

export function acceptedReports(): AcceptedReport[] {
	return REPORT_NAMES.map((name) => REPORT_CONTRACTS[name]).filter(isAccepted)
}

/** Contract fields an accepted report may not omit. Missing one means it is not accepted. */
export const REQUIRED_ACCEPTED_FIELDS =
	'name status method path requestFields dates timezone paymentMode currency outputProjection emptySet evidence warnings'.split(
		' ',
	) as (keyof AcceptedReport)[]

/** Reasons an accepted contract is malformed, or an empty list when it is sound. */
export function contractDefects(contract: AcceptedReport): string[] {
	const defects: string[] = []
	const record = contract as unknown as Record<string, unknown>

	for (const field of REQUIRED_ACCEPTED_FIELDS) {
		const value = record[field]
		const empty =
			value === undefined || value === null || (Array.isArray(value) && value.length === 0)
		if (empty) defects.push(`${contract.name} is missing ${field}`)
	}

	// Everything below inspects a field that may itself be the one missing, so each is guarded.
	// A completeness checker that throws on an incomplete contract reports nothing useful.
	if (contract.path && ABSENT_ROUTES.includes(contract.path)) {
		defects.push(`${contract.name} points at ${contract.path}, which this runtime does not serve`)
	}
	if (contract.currency?.handling === 'summed-across-currencies') {
		defects.push(`${contract.name} sums across currencies without an upstream grouping contract`)
	}
	if (contract.currency?.handling === 'caller-scoped' && !contract.currency.scopedBy) {
		defects.push(`${contract.name} claims caller-scoped currency but names no request field`)
	}
	for (const key of [
		'controllerFile',
		'controllerMethod',
		'serviceFile',
		'serviceMethod',
	] as const) {
		if (!contract.evidence?.[key]) defects.push(`${contract.name} cites no ${key}`)
	}
	return defects
}
