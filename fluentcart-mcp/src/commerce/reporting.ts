import type { FluentCartClient } from '../api/client.js'
import type { AcceptedReport, ReportName } from './report-contracts.js'
import { isAccepted, REPORT_CONTRACTS } from './report-contracts.js'

/**
 * One shape for every report answer.
 *
 * Period, payment mode and currency are fields rather than prose because a number without them is
 * not an answer: "revenue was 4000" is unusable until you know which days, which currency and
 * whether test orders were counted.
 */
export interface ReportResult<T> {
	report: ReportName
	period: { from: string; to: string; timezone: string }
	paymentMode: 'live-and-test-combined'
	currency: string
	data: T
	warnings: string[]
}

export interface ReportRequest {
	/** Inclusive start, `YYYY-MM-DD`, interpreted in the store timezone. */
	from: string
	/** Inclusive end, `YYYY-MM-DD`, interpreted in the store timezone. */
	to: string
	/** ISO currency the period is pinned to. Required: nothing is ever summed across currencies. */
	currency: string
	/** Store timezone, echoed into the period so a reader knows what the dates meant. */
	timezone: string
}

export interface TrendRequest extends ReportRequest {
	granularity?: 'daily' | 'monthly' | 'yearly'
}

const DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/

export class ReportRequestError extends Error {}

/**
 * `daily` is requested by omitting `groupKey`, never by sending the word.
 *
 * FluentCart sanitises `groupKey` against a whitelist of billing_country, shipping_country,
 * payment_method, payment_status, default, monthly and yearly — and anything outside it is
 * replaced with `payment_method` rather than rejected. Sending `groupKey=daily` therefore returns
 * revenue grouped by payment method while still looking like a time series: verified live, the
 * store answered `appliedGroupKey: payment_method` with four buckets for a 364-day range.
 *
 * Omitting it instead triggers ReportHelper::defineGroupKey, which picks the bucket from the range
 * width: daily to 91 days, monthly to 365, yearly beyond. That is a real time series, so it is
 * what we ask for — but it means a caller asking for daily over a long range gets monthly, which
 * is why salesTrend reports the granularity the store actually applied instead of the one asked
 * for. Evidence: app/Services/Report/ReportHelper.php lines 23-53 and 163-167.
 */
function groupKeyFor(granularity: TrendRequest['granularity']): string | null {
	return granularity === 'monthly' || granularity === 'yearly' ? granularity : null
}

/** Range width in whole days, matching ReportHelper::defineGroupKey's own diff. */
function rangeDays(from: string, to: string): number {
	const ms = Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)
	return Number.isFinite(ms) ? Math.round(ms / 86_400_000) : 0
}

/** What the store says it grouped by, normalised to our vocabulary. */
function appliedGranularity(body: Record<string, unknown>, from: string, to: string): string {
	const applied = body.appliedGroupKey
	if (applied === 'monthly' || applied === 'yearly' || applied === 'daily') return applied
	if (typeof applied === 'string' && applied !== '') return applied
	// Older builds omit the field; fall back to the documented range rule.
	const days = rangeDays(from, to)
	return days <= 91 ? 'daily' : days <= 365 ? 'monthly' : 'yearly'
}

export function assertValidRequest(request: ReportRequest): void {
	for (const [field, value] of [
		['from', request.from],
		['to', request.to],
	] as const) {
		if (!DATE_PATTERN.test(value)) {
			throw new ReportRequestError(`${field} must be a YYYY-MM-DD date, received "${value}".`)
		}
	}
	if (request.from > request.to) {
		throw new ReportRequestError(`from (${request.from}) is after to (${request.to}).`)
	}
	if (!/^[A-Z]{3}$/.test(request.currency)) {
		throw new ReportRequestError(
			`currency must be a three-letter ISO code, received "${request.currency}". Reports are never summed across currencies, so it cannot be omitted.`,
		)
	}
}

function contractFor(name: ReportName): AcceptedReport {
	const contract = REPORT_CONTRACTS[name]
	if (!isAccepted(contract)) {
		throw new ReportRequestError(
			`"${name}" is diagnostic-only and has no metric contract. ${contract.rejection}`,
		)
	}
	return contract
}

function params(request: ReportRequest, extra: Record<string, string> = {}) {
	return {
		'params[startDate]': request.from,
		'params[endDate]': request.to,
		'params[currency]': request.currency,
		...extra,
	}
}

function envelope<T>(
	name: ReportName,
	contract: AcceptedReport,
	request: ReportRequest,
	data: T,
	extraWarnings: string[] = [],
): ReportResult<T> {
	return {
		report: name,
		period: { from: request.from, to: request.to, timezone: request.timezone },
		paymentMode: contract.paymentMode.scope,
		currency: request.currency,
		data,
		warnings: [...contract.warnings, ...extraWarnings],
	}
}

/**
 * Round a reported figure to two decimals.
 *
 * FluentCart divides minor units by 100 in SQL, so a total that is exactly 4530.69 in the
 * database arrives as 4530.6900000000005. Quoting that at somebody as their revenue is not a
 * rounding error so much as a presentation failure. Two decimals is lossless for every currency
 * the store reports in decimals, and counts are integers, which survive unchanged.
 */
function round2(value: number): number {
	return Math.round(value * 100) / 100
}

/** Read a numeric field without inventing one. An absent figure stays null and is warned about. */
function figure(source: Record<string, unknown>, key: string): number | null {
	const value = source[key]
	if (typeof value === 'number' && Number.isFinite(value)) return round2(value)
	if (typeof value === 'string' && value.trim() !== '' && Number.isFinite(Number(value))) {
		return round2(Number(value))
	}
	return null
}

/**
 * Read each output field from whichever key the store actually sends it under.
 *
 * `sourceFields` maps our vocabulary onto the store's; an output key absent from that map is read
 * under its own name. Derived fields are skipped here and filled in by the caller.
 */
function projectAllowlist(
	source: Record<string, unknown>,
	allowlist: readonly string[],
	sourceFields: Readonly<Record<string, string>> = {},
	derived: readonly string[] = [],
): { data: Record<string, number | null>; missing: string[] } {
	const data: Record<string, number | null> = {}
	const missing: string[] = []
	for (const key of allowlist) {
		if (derived.includes(key)) continue
		const value = figure(source, sourceFields[key] ?? key)
		data[key] = value
		if (value === null) missing.push(key)
	}
	return { data, missing }
}

function missingWarning(missing: string[]): string[] {
	return missing.length === 0
		? []
		: [`The store returned no value for: ${missing.join(', ')}. Reported as null rather than zero.`]
}

function asRecord(value: unknown): Record<string, unknown> {
	return value !== null && typeof value === 'object' ? (value as Record<string, unknown>) : {}
}

export interface SalesSummary {
	[field: string]: number | null
}

export async function salesSummary(
	client: FluentCartClient,
	request: ReportRequest,
): Promise<ReportResult<SalesSummary>> {
	assertValidRequest(request)
	const contract = contractFor('sales_summary')

	const response = await client.get(contract.path, params(request))
	const body = asRecord(asRecord(response.data).data ?? response.data)
	const { data, missing } = projectAllowlist(
		asRecord(body.summary),
		contract.outputProjection,
		contract.sourceFields,
		contract.derivedFields,
	)

	// Averaging is only meaningful when both operands survived the projection and at least one
	// order was counted. Anything else stays null rather than becoming a confident zero.
	const gross = data.gross_sales ?? null
	const count = data.order_count ?? null
	data.average_order_value =
		gross !== null && count !== null && count > 0 ? round2(gross / count) : null

	return envelope('sales_summary', contract, request, data, missingWarning(missing))
}

export interface TrendPoint {
	period: string
	gross_sales: number | null
	net_revenue: number | null
	order_count: number | null
}

export interface TrendResult extends ReportResult<TrendPoint[]> {
	/** What was asked for, and what the store actually grouped by. Often not the same. */
	granularity: { requested: string; applied: string }
}

export async function salesTrend(
	client: FluentCartClient,
	request: TrendRequest,
): Promise<TrendResult> {
	assertValidRequest(request)
	const contract = contractFor('sales_trend')

	const groupKey = groupKeyFor(request.granularity)
	const response = await client.get(
		contract.path,
		params(request, groupKey === null ? {} : { 'params[groupKey]': groupKey }),
	)
	const body = asRecord(asRecord(response.data).data ?? response.data)
	const rows = Array.isArray(body.revenueReport) ? body.revenueReport : []

	const source = contract.sourceFields ?? {}
	const points: TrendPoint[] = rows.map((row) => {
		const record = asRecord(row)
		// The bucket label carries several names across group keys; take the first that is present
		// rather than guessing a date from the row's position in the array.
		const label = record.period ?? record.date ?? record.group ?? record.label
		return {
			period: typeof label === 'string' ? label : '',
			gross_sales: figure(record, source.gross_sales ?? 'gross_sales'),
			net_revenue: figure(record, source.net_revenue ?? 'net_revenue'),
			order_count: figure(record, source.order_count ?? 'order_count'),
		}
	})

	const unlabelled = points.filter((point) => point.period === '').length
	const warnings = unlabelled > 0 ? [`${unlabelled} bucket(s) carried no period label.`] : []

	// The store widens the bucket on its own for long ranges. Saying so is the difference between
	// a monthly series a caller can read correctly and a monthly series they believe is daily.
	const requested = request.granularity ?? 'daily'
	const applied = appliedGranularity(body, request.from, request.to)
	if (applied !== requested) {
		warnings.push(
			`Requested ${requested} buckets but the store grouped by ${applied}: FluentCart selects the bucket from the range width (daily to 91 days, monthly to 365, yearly beyond). Narrow the range to get ${requested} buckets.`,
		)
	}

	return {
		...envelope('sales_trend', contract, request, points, warnings),
		granularity: { requested, applied },
	}
}

export interface TopProduct {
	product_id: number | null
	product_name: string | null
	quantity_sold: number | null
	total_amount: number | null
}

export async function topProducts(
	client: FluentCartClient,
	request: ReportRequest,
): Promise<ReportResult<TopProduct[]>> {
	assertValidRequest(request)
	const contract = contractFor('top_products')

	const response = await client.get(contract.path, params(request))
	const body = asRecord(asRecord(response.data).data ?? response.data)
	const rows = Array.isArray(body.topSoldProducts) ? body.topSoldProducts : []

	const products: TopProduct[] = rows.map((row) => {
		const record = asRecord(row)
		const name = record.product_name
		return {
			product_id: figure(record, 'product_id'),
			product_name: typeof name === 'string' ? name : null,
			quantity_sold: figure(record, 'quantity_sold'),
			total_amount: figure(record, 'total_amount'),
		}
	})

	return envelope('top_products', contract, request, products)
}

export const REPORT_ADAPTERS = {
	sales_summary: salesSummary,
	sales_trend: salesTrend,
	top_products: topProducts,
} as const
