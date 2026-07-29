import assert from 'node:assert/strict'
import { readdirSync, readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { describe, it } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	buildLedger,
	extractRiskRegistry,
	extractTools,
	HIGH_IMPACT,
	safetyFor,
} from '../../scripts/build-api-coverage.mjs'

const PACKAGE_ROOT = dirname(dirname(dirname(fileURLToPath(import.meta.url))))
const ledger = buildLedger()
const rowFor = (route) => ledger.routes.find((row) => `${row.method} ${row.path}` === route)

/**
 * The two operations retained as audit-only real-money rows. Nothing may ever be reachable at
 * real-money risk.
 */
const AUDIT_ONLY_MONEY_ACTIONS = ['fluentcart_order_refund', 'fluentcart_subscription_cancel']

describe('every high-impact route is accounted for', () => {
	it('has a ledger row for each classified high-impact route', () => {
		for (const route of Object.keys(HIGH_IMPACT)) {
			assert.ok(rowFor(route), `${route} is classified high-impact but absent from the ledger`)
		}
	})

	it('keeps every high-impact route excluded', () => {
		for (const route of Object.keys(HIGH_IMPACT)) {
			const row = rowFor(route)
			assert.equal(row.routeDisposition, 'excluded', `${route} must stay excluded`)
			assert.deepEqual(row.toolExposures, [], `${route} must expose no tool`)
		}
	})

	it('explains every exclusion in its own words', () => {
		for (const route of Object.keys(HIGH_IMPACT)) {
			const row = rowFor(route)
			assert.match(row.reason, /^High-impact operation\. /)
			assert.ok(row.reason.length > 60, `${route} needs a real reason, not a label`)
		}
	})
})

describe('mandated risk classification', () => {
	const expectations = [
		[
			'email delivery',
			['POST /email-notification/digest-settings/send-test', 'POST /email-notification/preview'],
			'external-side-effect',
		],
		['manual reminders', ['POST /email-notification/send-manual-reminder'], 'external-side-effect'],
		[
			'product and variation bulk changes',
			[
				'POST /products/bulk-insert',
				'POST /products/bulk-update',
				'POST /products/variants/bulk-update',
				'POST /products/variants/group-bulk-update',
			],
			'destructive-write',
		],
		['tax resets', ['POST /tax/configuration/settings/eu-vat/reset-rates'], 'destructive-write'],
		['tax country-status changes', ['POST /tax/country-status/{param}'], 'destructive-write'],
		[
			'storage reset, status and bucket creation',
			[
				'POST /settings/storage-drivers/reset',
				'POST /settings/storage-drivers/change-status',
				'POST /settings/storage-drivers/create-bucket',
			],
			'infrastructure',
		],
		[
			'early-payment link generation',
			['POST /orders/{param}/subscriptions/{param}/early-payment-link'],
			'real-money',
		],
		[
			'PDF template deletion',
			['DELETE /settings/pdf-templates/delete/{param}'],
			'destructive-write',
		],
	]

	for (const [label, routes, expected] of expectations) {
		it(`classifies ${label} as ${expected}`, () => {
			for (const route of routes) {
				assert.equal(rowFor(route).risk, expected, `${route} must be ${expected}`)
			}
		})
	}

	it('treats order tax calculation as an order mutation, not a preview', () => {
		const row = rowFor('POST /orders/calculate-tax')
		assert.equal(row.routeDisposition, 'excluded')
		assert.notEqual(row.risk, 'read')
		// The classification must say why it is not being taken on trust.
		assert.match(row.reason, /preview-only/)
	})

	it('classifies each remaining high-impact route as something other than a read', () => {
		for (const [route, [risk]] of Object.entries(HIGH_IMPACT)) {
			assert.notEqual(risk, 'read', `${route} is high-impact and cannot be a read`)
			assert.equal(rowFor(route).risk, risk)
		}
	})
})

describe('real-money operations are audit-only', () => {
	const registry = extractRiskRegistry()
	const tools = extractTools()

	it('keeps the two audited actions classified as non-executable', () => {
		for (const name of AUDIT_ONLY_MONEY_ACTIONS) {
			const safety = registry.get(name)
			assert.ok(safety, `${name} must retain its audit row`)
			assert.equal(safety.risk, 'real-money')
			assert.equal(safety.idempotency, 'unsupported')
			assert.equal(safety.execution, 'none')
		}
	})

	it('exposes no real-money route', () => {
		for (const row of ledger.routes.filter((r) => r.routeDisposition === 'exposed')) {
			assert.notEqual(row.risk, 'real-money', `${row.method} ${row.path} exposes real-money risk`)
		}
	})

	it('never lets an unreviewed tool reach a high-impact route', () => {
		const highImpact = new Set(Object.keys(HIGH_IMPACT))
		for (const tool of tools) {
			if (safetyFor(tool, registry).execution === 'none') continue
			for (const route of tool.routes) {
				const id = `${route.method} ${route.path}`
				assert.ok(
					!highImpact.has(id),
					`${tool.name} is executable and reaches high-impact route ${id}`,
				)
			}
		}
	})
})

describe('safety is not a confirm flag', () => {
	it('adds no generic confirmation parameter to any tool schema', () => {
		const offenders = []
		for (const file of readdirSync(join(PACKAGE_ROOT, 'src/tools')).filter((f) =>
			f.endsWith('.ts'),
		)) {
			const source = readFileSync(join(PACKAGE_ROOT, 'src/tools', file), 'utf8')
			// A boolean the caller sets on itself proves nothing about intent and is not a guard.
			if (/\b(confirm|confirmed|i_understand|force)\s*:\s*z\.(boolean|literal)/.test(source)) {
				offenders.push(file)
			}
		}
		assert.deepEqual(offenders, [], 'a self-asserted boolean is not a safety control')
	})
})
