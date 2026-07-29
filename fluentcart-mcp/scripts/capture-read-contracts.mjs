#!/usr/bin/env node
/**
 * Capture the response *shape* of a fixed allowlist of FluentCart read endpoints.
 *
 * What reaches disk is types, object keys, array item shapes and pagination metadata — never a
 * value. Every leaf in the fixture comes from a closed token set, which is what makes "this file
 * contains no store data" a structural property rather than a promise.
 *
 * Credentials come from the process environment and nowhere else: this script opens no credential
 * file, prints no header and logs no response body. An unexpected personal or secret-looking key
 * stops the run, because redacting and carrying on asks the operator to trust a redactor they
 * cannot see — the wrong default for a file destined for version control.
 */

import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
// The digest is computed by the same function the runtime and the contract test use. Two
// implementations of "the same" hash drift the moment one of them learns to sort its input.
import { routeProfileDigest } from '../dist/commerce/context.js'
import { assertAllowedLiveTarget } from './live-target-policy.mjs'

const PACKAGE_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const ROUTE_FIXTURE = 'tests/fixtures/routes/fluentcart-1.5.5-core-pro-1.5.4.json'
const OUTPUT = 'tests/fixtures/rest/fluentcart-1.5.5-all-active-read-contracts.json'
const REQUEST_TIMEOUT_MS = 15_000
const PAGINATION_PROBE = 500
const PAGINATION_PROBE_SMALL = 3

/** Leaf tokens a shape may contain. The fixture test asserts nothing else ever appears. */
export const SHAPE_TOKENS = ['string', 'number', 'boolean', 'null', 'empty', 'mixed', 'unknown']

/**
 * Read endpoints whose payloads are catalogue, reference or aggregate data. Person-level
 * collections are deliberately absent: the safest way to keep customer records out of a fixture
 * is never to fetch them.
 */
const C = 'app/Http/Controllers'
const R = `${C}/Reports`
const S = 'app/Modules/Shipping/Http/Controllers'

function endpoint(path, controller, method, paginated = false) {
	return { path, controller, method, paginated }
}

// `/coupons` is deliberately absent: its rows carry `conditions.email_restrictions`, a list of
// customer email addresses. The capture refuses it, and the right answer is not to fetch it.
export const ENDPOINTS = [
	endpoint('/products', `${C}/ProductController.php`, 'index', true),
	endpoint('/products/bulk-edit-data', `${C}/ProductController.php`, 'bulkEditFetch'),
	endpoint('/products/fetch-term', `${C}/ProductController.php`, 'getProductTermsList'),
	endpoint(
		'/products/findSubscriptionVariants',
		`${C}/ProductController.php`,
		'findSubscriptionVariants',
	),
	endpoint(
		'/products/get-max-excerpt-word-count',
		`${C}/ProductController.php`,
		'getMaxExcerptWordCount',
	),
	endpoint('/labels', `${C}/LabelController.php`, 'index', true),
	endpoint('/address-info/countries', `${C}/AddressInfoController.php`, 'countriesOption'),
	endpoint('/integration/addons', `${C}/AddonsController.php`, 'getAddons'),
	endpoint('/dashboard/stats', `${C}/DashboardController.php`, 'getDashboardStats'),
	endpoint('/shipping/classes', `${S}/ShippingClassController.php`, 'index'),
	endpoint('/shipping/packages', `${S}/ShippingClassController.php`, 'getPackages'),
	endpoint('/shipping/zone/countries', `${S}/ShippingZoneController.php`, 'getCountriesByContinent'),
	endpoint('/shipping/zone/states', `${S}/ShippingZoneController.php`, 'getZoneStates'),
	endpoint('/shipping/zones', `${S}/ShippingZoneController.php`, 'index'),
	endpoint('/tax/classes', `${C}/TaxRateController.php`, 'getClasses'),
	endpoint('/tax/rates', `${C}/TaxRateController.php`, 'index'),
	endpoint('/tax/configuration/rates', `${C}/TaxConfigurationController.php`, 'getTaxRates'),
	endpoint(
		'/tax/configuration/settings/eu-vat/oss-rates',
		`${C}/TaxEUController.php`,
		'getOssCountryRates',
	),
	endpoint(
		'/tax/configuration/settings/eu-vat/product-overrides',
		`${C}/TaxEUController.php`,
		'getEuProductOverrides',
	),
	endpoint('/reports/overview', `${R}/OverviewReportController.php`, 'getOverview'),
	endpoint('/reports/revenue', `${R}/RevenueReportController.php`, 'getRevenue'),
	endpoint('/reports/sales-report', `${R}/DefaultReportController.php`, 'getSalesReport'),
	endpoint('/reports/fetch-report-meta', `${R}/ReportingController.php`, 'getReportMeta'),
	endpoint('/reports/quick-order-stats', `${R}/ReportingController.php`, 'getOrderQuickStats'),
	endpoint('/reports/report-overview', `${R}/ReportingController.php`, 'getReportOverview'),
	endpoint('/reports/dashboard-stats', `${R}/ReportingController.php`, 'getDashboardStats'),
	endpoint('/reports/sales-growth-chart', `${R}/ReportingController.php`, 'getSalesGrowthChart'),
	endpoint('/reports/country-heat-map', `${R}/ReportingController.php`, 'getCountryHeatMap'),
	endpoint('/reports/get-dashboard-summary', `${R}/ReportingController.php`, 'getDashBoardSummary'),
	endpoint(
		'/reports/order-value-distribution',
		`${R}/OrderReportController.php`,
		'getOrderValueDistribution',
	),
	endpoint('/reports/fetch-order-by-group', `${R}/OrderReportController.php`, 'getOrderByGroup'),
	endpoint(
		'/reports/fetch-report-by-day-and-hour',
		`${R}/OrderReportController.php`,
		'getReportByDayAndHour',
	),
	endpoint(
		'/reports/item-count-distribution',
		`${R}/OrderReportController.php`,
		'getItemCountDistribution',
	),
	endpoint(
		'/reports/order-completion-time',
		`${R}/OrderReportController.php`,
		'getOrderCompletionTime',
	),
	endpoint('/reports/order-chart', `${R}/OrderReportController.php`, 'getOrderChart'),
	endpoint(
		'/reports/fetch-top-sold-products',
		`${R}/DefaultReportController.php`,
		'getTopSoldProducts',
	),
	endpoint(
		'/reports/fetch-top-sold-variants',
		`${R}/DefaultReportController.php`,
		'getTopSoldVariants',
	),
	endpoint('/reports/refund-chart', `${R}/RefundReportController.php`, 'getRefundChart'),
	endpoint(
		'/reports/weeks-between-refund',
		`${R}/RefundReportController.php`,
		'getWeeksBetweenRefund',
	),
	endpoint(
		'/reports/refund-data-by-group',
		`${R}/RefundReportController.php`,
		'getRefundDataByGroup',
	),
	endpoint(
		'/reports/subscription-chart',
		`${R}/SubscriptionReportController.php`,
		'getSubscriptionChart',
	),
	endpoint('/reports/daily-signups', `${R}/SubscriptionReportController.php`, 'getDailySignups'),
	endpoint(
		'/reports/retention-chart',
		`${R}/SubscriptionReportController.php`,
		'getRetentionChart',
	),
	endpoint(
		'/reports/subscription-retention',
		`${R}/SubscriptionReportController.php`,
		'getRetentionData',
	),
	endpoint(
		'/reports/subscription-cohorts',
		`${R}/SubscriptionReportController.php`,
		'getCohortData',
	),
	endpoint(
		'/reports/product-performance',
		`${R}/ProductReportController.php`,
		'getProductPerformance',
	),
	endpoint('/reports/sources', `${R}/SourceReportController.php`, 'index'),
]

/** Keys that mean a person or a secret. Encountering one is a stop, not a redaction. */
// `/tax/configuration/settings` is also absent: its shape contains `require_vat_number`. Even
// though the observed leaf is configuration rather than a stored identifier, preserving the
// fail-closed key policy is safer than adding a contextual exception. `/reports/sales-growth`
// returned HTTP 500 without extra inputs, so it is not promoted to a successful default contract.
const FORBIDDEN_KEY =
	/^(.*_)?(email|phone|mobile|password|secret|token|nonce|authorization|cookie|iban|cvv|ssn|last4|vat_number|tax_id)(_.*)?$/i
const FORBIDDEN_KEY_EXACT = new Set(
	'first_name last_name full_name display_name user_login username address_1 address_2 street postcode postal_code zip ip_address api_key access_key private_key card_last4 vat_number tax_id'.split(' '),
)
/** Values that look like credential material, whatever the key is called. */
const SECRET_VALUE = /\b(sk|pk|rk)_(live|test)_[A-Za-z0-9]{8,}|eyJ[A-Za-z0-9_-]{10,}\.|Bearer\s+[A-Za-z0-9._-]{16,}/

function fail(message) {
	process.stderr.write(`capture-read-contracts: ${message}\n`)
	process.exit(1)
}

function assertSafeKey(key, where) {
	if (FORBIDDEN_KEY.test(key) || FORBIDDEN_KEY_EXACT.has(key.toLowerCase())) {
		fail(`refusing to record ${where}: key "${key}" looks personal or secret. Remove the endpoint from the allowlist or narrow the projection; this script does not redact.`)
	}
}

/** Reduce a parsed JSON value to its shape. Values are inspected for safety, never retained. */
export function toShape(value, where = '$') {
	if (value === null) return 'null'
	if (Array.isArray(value)) {
		if (value.length === 0) return { array: 'empty' }
		let item = null
		for (const [index, entry] of value.entries()) {
			const shape = toShape(entry, `${where}[${index}]`)
			item = item === null ? shape : mergeShapes(item, shape)
		}
		return { array: item }
	}
	if (typeof value === 'object') {
		const object = {}
		for (const key of Object.keys(value).sort()) {
			assertSafeKey(key, `${where}.${key}`)
			object[key] = toShape(value[key], `${where}.${key}`)
		}
		return { object }
	}
	if (typeof value === 'string' && SECRET_VALUE.test(value)) {
		fail(`refusing to record ${where}: a value looks like credential material.`)
	}
	const type = typeof value
	return type === 'string' || type === 'number' || type === 'boolean' ? type : 'unknown'
}

/** Union two shapes. Object keys merge; anything genuinely inconsistent becomes `mixed`. */
export function mergeShapes(left, right) {
	if (JSON.stringify(left) === JSON.stringify(right)) return left
	if (left === 'null') return right
	if (right === 'null') return left

	if (left?.object && right?.object) {
		const object = {}
		for (const key of [...new Set([...Object.keys(left.object), ...Object.keys(right.object)])].sort()) {
			const a = left.object[key]
			const b = right.object[key]
			object[key] = a === undefined ? b : b === undefined ? a : mergeShapes(a, b)
		}
		return { object }
	}

	if (left?.array && right?.array) {
		if (left.array === 'empty') return right
		if (right.array === 'empty') return left
		return { array: mergeShapes(left.array, right.array) }
	}

	return 'mixed'
}

/** Find the node carrying pagination metadata, wherever the controller chose to put it. */
function findPaginationNode(value) {
	if (value === null || typeof value !== 'object') return null
	if (!Array.isArray(value)) {
		const keys = Object.keys(value)
		if (keys.includes('per_page') && (keys.includes('current_page') || keys.includes('total'))) {
			return value
		}
	}
	for (const entry of Array.isArray(value) ? value : Object.values(value)) {
		const found = findPaginationNode(entry)
		if (found) return found
	}
	return null
}

function readEnvironmentCredentials() {
	const url = process.env.FLUENTCART_URL
	const username = process.env.FLUENTCART_USERNAME
	const appPassword = process.env.FLUENTCART_APP_PASSWORD
	if (!(url && username && appPassword)) {
		fail('FLUENTCART_URL, FLUENTCART_USERNAME and FLUENTCART_APP_PASSWORD must already be in the environment. This script does not open credential files.')
	}
	return { url, username, appPassword }
}

async function getJson(base, credentials, path, params) {
	const url = new URL(`${base}${path}`)
	for (const [key, value] of Object.entries(params ?? {})) url.searchParams.set(key, String(value))

	const authorization = Buffer.from(`${credentials.username}:${credentials.appPassword}`).toString('base64')
	const response = await fetch(url, {
		headers: { Accept: 'application/json', Authorization: `Basic ${authorization}` },
		redirect: 'error',
		signal: AbortSignal.timeout(REQUEST_TIMEOUT_MS),
	}).catch((error) => fail(`GET ${path} failed: ${error.message}`))

	const text = await response.text()
	if (!response.ok) return { status: response.status, body: null }
	try {
		return { status: response.status, body: JSON.parse(text) }
	} catch {
		return fail(`GET ${path} did not return JSON`)
	}
}

/**
 * The runtime these contracts are read from, stated rather than borrowed.
 *
 * This used to reuse the route fixture's profile, which was only coincidentally true and stopped
 * being true when route evidence moved to an isolated capture: that fixture now describes a
 * two-plugin stack while the store being read has every plugin active.
 */
function readLiveProfile() {
	const path = process.argv[process.argv.indexOf('--profile') + 1]
	if (!process.argv.includes('--profile') || !path) {
		fail('--profile <runtime-profile.json> is required: read contracts come from a live store, and the isolated route fixture cannot describe it. Generate one with scripts/capture-runtime-profile.mjs.')
	}
	const resolved = resolve(PACKAGE_ROOT, path)
	if (!existsSync(resolved)) fail(`runtime profile not found: ${path}`)

	const profile = JSON.parse(readFileSync(resolved, 'utf8'))
	if (!profile?.wordpress || !Array.isArray(profile.activeComponents)) {
		fail(`${path} is not a runtime profile: expected wordpress and activeComponents`)
	}
	return profile
}

function readRouteFixture() {
	const path = resolve(PACKAGE_ROOT, ROUTE_FIXTURE)
	if (!existsSync(path)) fail(`route fixture not found: ${ROUTE_FIXTURE}`)
	const fixture = JSON.parse(readFileSync(path, 'utf8'))
	const profile = readLiveProfile()
	// Live runtime plus the route set those contracts were validated against.
	const profileDigest = routeProfileDigest(profile, fixture.operations)
	const operations = new Set(fixture.operations.map((entry) => `${entry.method} ${entry.path}`))
	return { fixture, profile, profileDigest, operations }
}

async function perPage(base, credentials, path, params) {
	const node = findPaginationNode((await getJson(base, credentials, path, params)).body)
	const value = node === null ? Number.NaN : Number(node.per_page)
	return { node, perPage: Number.isFinite(value) ? value : null }
}

/**
 * Probe three sizes, not two. A default and a cap that read the same could mean the store caps the
 * page size or that it ignores `per_page` entirely — very different contracts for a caller paging
 * through a catalogue. The small probe tells them apart, so `requestFields` lists only parameters
 * this store was observed to honour.
 */
async function capturePagination(base, credentials, endpoint) {
	const { node, perPage: observedDefault } = await perPage(base, credentials, endpoint.path)
	if (node === null) {
		return { requestFields: [], responseFields: [], observedDefault: null, observedMaximum: null }
	}

	const small = await perPage(base, credentials, endpoint.path, { per_page: PAGINATION_PROBE_SMALL })
	const large = await perPage(base, credentials, endpoint.path, { per_page: PAGINATION_PROBE })
	const honoured = small.perPage === PAGINATION_PROBE_SMALL

	return {
		requestFields: honoured ? ['page', 'per_page'] : ['page'],
		responseFields: Object.keys(node).sort(),
		observedDefault,
		observedMaximum: honoured ? large.perPage : null,
	}
}

async function captureAll() {
	const { profile, profileDigest, operations } = readRouteFixture()
	const credentials = readEnvironmentCredentials()
	const target = assertAllowedLiveTarget(credentials.url, process.env)
	const base = `${target.origin}/wp-json/fluent-cart/v2`
	const capturedAt = new Date().toISOString()

	const contracts = []
	for (const endpoint of ENDPOINTS) {
		if (!operations.has(`GET ${endpoint.path}`)) {
			fail(`${endpoint.path} is not in ${ROUTE_FIXTURE}; recapture the route fixture before capturing shapes.`)
		}

		const { status, body } = await getJson(base, credentials, endpoint.path)
		if (status !== 200) {
			fail(`${endpoint.path} returned HTTP ${status}; only successful default GET contracts may be recorded.`)
		}
		const contract = {
			profileDigest,
			method: 'GET',
			canonicalPath: endpoint.path,
			status,
			responseShape: body === null ? 'null' : toShape(body, endpoint.path),
			evidence: {
				routeFixture: ROUTE_FIXTURE,
				controllerFile: endpoint.controller,
				controllerMethod: endpoint.method,
				capturedAt,
			},
		}
		if (endpoint.paginated) {
			contract.pagination = await capturePagination(base, credentials, endpoint)
		}
		contracts.push(contract)
	}

	return {
		schemaVersion: 1,
		generatedBy: 'scripts/capture-read-contracts.mjs',
		evidenceScope: 'all-active-compatibility',
		profileDigest,
		profile,
		routeFixture: ROUTE_FIXTURE,
		compatibilityScopes: [
			{
				scope: 'core',
				status: 'BLOCKED',
				reason:
					'No isolated FluentCart Core-only live runtime was available. The all-active response evidence is not promoted to this scope.',
			},
			{
				scope: 'core-pro',
				status: 'BLOCKED',
				reason:
					'No isolated FluentCart Core plus Pro live runtime was available. The all-active response evidence is not promoted to this scope.',
			},
			{
				scope: 'all-active',
				status: 'CAPTURED',
				profileDigest,
				fixture: OUTPUT,
			},
		],
		contracts: contracts.sort((a, b) => (a.canonicalPath < b.canonicalPath ? -1 : 1)),
	}
}

/** `capturedAt` is provenance, not contract: it must not make `--check` fail every single run. */
function withoutTimestamps(document) {
	return JSON.stringify(document, (key, value) => (key === 'capturedAt' ? null : value), 2)
}

// Guarded, because the contract test imports the reducers from here. An unguarded top level would
// make `node --test` reach for the store and its credentials, which is not what a unit test is.
if (process.argv[1] && pathToFileURL(process.argv[1]).href === import.meta.url) {
	const outputPath = resolve(PACKAGE_ROOT, OUTPUT)
	const captured = await captureAll()

	if (process.argv.includes('--check')) {
		if (!existsSync(outputPath)) fail(`${OUTPUT} is missing; run scripts/capture-read-contracts.mjs`)
		const existing = JSON.parse(readFileSync(outputPath, 'utf8'))
		if (withoutTimestamps(existing) !== withoutTimestamps(captured)) {
			fail(`${OUTPUT} no longer matches the live store; rerun the capture and review the diff.`)
		}
		process.stdout.write(`${OUTPUT} matches the live store (${captured.contracts.length} contracts)\n`)
	} else {
		mkdirSync(dirname(outputPath), { recursive: true })
		writeFileSync(outputPath, `${JSON.stringify(captured, null, 2)}\n`)
		process.stdout.write(`wrote ${OUTPUT} (${captured.contracts.length} contracts)\n`)
	}
}
