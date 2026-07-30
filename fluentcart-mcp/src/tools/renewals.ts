import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { getTool, type ToolDefinition } from './_factory.js'
import { collapseOrderDetail } from './order-detail-projection.js'

function asRecord(value: unknown): Record<string, unknown> | null {
	return value !== null && typeof value === 'object' && !Array.isArray(value)
		? (value as Record<string, unknown>)
		: null
}

function projectCustomer(value: unknown): unknown {
	const customer = asRecord(value)
	if (!customer) return value
	return {
		id: customer.id,
		full_name: customer.full_name,
		email: customer.email,
	}
}

/** A renewal row needs its payment state and owner, not the gateway payload embedded in an order. */
function projectRenewalRow(value: unknown): unknown {
	const row = asRecord(value)
	if (!row) return value
	return {
		id: row.id,
		receipt_number: row.receipt_number,
		status: row.status,
		payment_status: row.payment_status,
		payment_method_title: row.payment_method_title,
		currency: row.currency,
		total_amount: row.total_amount,
		parent_order_id: row.parent_id,
		customer: projectCustomer(row.customer),
		created_at: row.created_at,
	}
}

/** Retain the paginator while replacing its full order rows with invoice-safe summaries. */
function projectRenewalList(data: unknown): unknown {
	const outer = asRecord(data)
	if (!outer) return data
	const wrapped = asRecord(outer.data)
	const root = wrapped ?? outer
	const invoices = asRecord(root.invoices)
	if (!(invoices && Array.isArray(invoices.data))) return data

	const projected = { ...invoices, data: invoices.data.map(projectRenewalRow) }
	if (!wrapped) return { ...outer, invoices: projected }
	return { ...outer, data: { ...wrapped, invoices: projected } }
}

function projectRenewalDetail(data: unknown): unknown {
	const outer = asRecord(data)
	if (!outer) return data
	const wrapped = asRecord(outer.data)
	const root = wrapped ?? outer
	const invoice = asRecord(root.invoice)
	if (!invoice) return data

	const {
		config: _config,
		ip_address: _ipAddress,
		uuid: _uuid,
		meta: _meta,
		vendor_response: _vendorResponse,
		activities: _activities,
		post_content: _postContent,
		...rest
	} = invoice
	const projected = collapseOrderDetail({
		...rest,
		customer: projectCustomer(rest.customer),
		transactions: Array.isArray(rest.transactions)
			? rest.transactions.map((entry) => {
					const transaction = asRecord(entry)
					if (!transaction) return entry
					const { meta: _transactionMeta, uuid: _transactionUuid, ...clean } = transaction
					return clean
				})
			: rest.transactions,
	})

	if (!wrapped) return { ...outer, invoice: projected }
	return { ...outer, data: { ...wrapped, invoice: projected } }
}

export function renewalTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_renewal_list',
			title: 'List Renewal Invoices',
			description:
				'List renewal invoices with their payment state. Amounts are minor units: 1599 EUR means 15.99 EUR. ' +
				'Use parent_order_id to inspect one subscription order and renewal_id with fluentcart_renewal_get for an invoice’s lines and transactions.',
			schema: z.object({
				page: z.number().int().min(1).optional().describe('Page number (default: 1)'),
				per_page: z
					.number()
					.int()
					.min(1)
					.max(50)
					.optional()
					.describe('Results per page (default: store setting, max: 50)'),
				payment_status: z
					.string()
					.optional()
					.describe('Exact renewal payment status, such as pending or paid'),
				parent_order_id: z
					.number()
					.int()
					.positive()
					.optional()
					.describe('Parent subscription order ID'),
				customer_id: z.number().int().positive().optional().describe('Customer ID'),
			}),
			endpoint: '/renewals',
			query: (input) => {
				const { parent_order_id, ...rest } = input
				return parent_order_id === undefined ? rest : { ...rest, parent_id: parent_order_id }
			},
			transform: projectRenewalList,
		}),
		getTool(client, {
			name: 'fluentcart_renewal_get',
			title: 'Get Renewal Invoice',
			description:
				'Get one renewal invoice with its order lines and transactions. The response excludes gateway payloads and internal identifiers.',
			schema: z.object({
				renewal_id: z.number().int().positive().describe('Renewal invoice order ID'),
			}),
			endpoint: '/renewals/:renewal_id',
			transform: projectRenewalDetail,
		}),
	]
}
