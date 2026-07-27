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
 * FluentCart sanitises `groupKey` against a whitelist that omits `daily`, so an unrecognised value
 * falls through to the daily format anyway. We send `daily` only by omission, never by name, so the
 * request never depends on that accident.
 */
function groupKeyFor(granularity: TrendRequest['granularity']): string | null {
	return granularity === 'monthly' || granularity === 'yearly' ? granularity : null
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

/** Read a numeric field without inventing one. An absent figure stays null and is warned about. */
function figure(source: Record<string, unknown>, key: string): number | null {
	const value = source[key]
	if (typeof value === 'number' && Number.isFinite(value)) return value
	if (typeof value === 'string' && value.trim() !== '' && Number.isFinite(Number(value))) {
		return Number(value)
	}
	return null
}

function projectAllowlist(
	source: Record<string, unknown>,
	allowlist: readonly string[],
): { data: Record<string, number | null>; missing: string[] } {
	const data: Record<string, number | null> = {}
	const missing: string[] = []
	for (const key of allowlist) {
		const value = figure(source, key)
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
	const { data, missing } = projectAllowlist(asRecord(body.summary), contract.outputProjection)

	return envelope('sales_summary', contract, request, data, missingWarning(missing))
}

export interface TrendPoint {
	period: string
	total_sales: number | null
	net_revenue: number | null
	order_count: number | null
}

export async function salesTrend(
	client: FluentCartClient,
	request: TrendRequest,
): Promise<ReportResult<TrendPoint[]>> {
	assertValidRequest(request)
	const contract = contractFor('sales_trend')

	const groupKey = groupKeyFor(request.granularity)
	const response = await client.get(
		contract.path,
		params(request, groupKey === null ? {} : { 'params[groupKey]': groupKey }),
	)
	const body = asRecord(asRecord(response.data).data ?? response.data)
	const rows = Array.isArray(body.revenueReport) ? body.revenueReport : []

	const points: TrendPoint[] = rows.map((row) => {
		const record = asRecord(row)
		// The bucket label carries several names across group keys; take the first that is present
		// rather than guessing a date from the row's position in the array.
		const label = record.period ?? record.date ?? record.group ?? record.label
		return {
			period: typeof label === 'string' ? label : '',
			total_sales: figure(record, 'total_sales'),
			net_revenue: figure(record, 'net_revenue'),
			order_count: figure(record, 'order_count'),
		}
	})

	const unlabelled = points.filter((point) => point.period === '').length
	const warnings = unlabelled > 0 ? [`${unlabelled} bucket(s) carried no period label.`] : []
	return envelope('sales_trend', contract, request, points, warnings)
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
