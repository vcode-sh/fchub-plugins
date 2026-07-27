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

export const REPORT_NAMES: readonly ReportName[] = [
	'sales_summary',
	'sales_trend',
	'top_products',
	'refund_summary',
	'future_renewals',
	'order_sources',
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

/** Routes named in planning that this runtime does not serve at all. */
export const ABSENT_ROUTES: readonly string[] = ['/reports/get-unfulfilled-orders']

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

const REVENUE_WARNINGS = [WARN_STATUSES, WARN_TEST_MODE, WARN_RETROACTIVE, WARN_PRO, WARN_TIMEZONE]

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
		outputProjection:
			'total_sales net_revenue total_tax shipping_total total_refunds order_count refunded_orders average_order_value'.split(
				' ',
			),
		emptySet: 'All summary fields return zero; the response is never structurally empty.',
		evidence: {
			...REVENUE_EVIDENCE,
			notes:
				'Single table, no joins. Amounts are divided by 100 in SQL, so the response carries decimals while the column stores minor units.',
		},
	},
	sales_trend: {
		...REVENUE_BASE,
		name: 'sales_trend',
		status: 'accepted',
		outputProjection: ['period', 'total_sales', 'net_revenue', 'order_count'],
		emptySet:
			'One zero-filled row per period is always returned; getPeriodRange pre-builds the buckets.',
		evidence: {
			...REVENUE_EVIDENCE,
			notes:
				'Grouped by DATE_FORMAT over created_at. groupKey accepts monthly and yearly; anything else, including an omitted value, resolves to daily.',
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
				'Ranked by SUM(quantity) descending, capped at 20 rows by the controller. Product names come from the most recent order item, not the product record, so a renamed product shows its last-sold title.',
		},
		warnings: [
			WARN_STATUSES,
			WARN_TEST_MODE,
			WARN_PRO,
			WARN_TIMEZONE,
			'Ranking is by units sold, not revenue. A cheap high-volume product outranks an expensive one.',
			'Limited to the 20 highest-selling products; the store applies this cap, not this server.',
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
