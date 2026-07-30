#!/usr/bin/env node
/**
 * Build the machine-checked API coverage ledger.
 *
 * The ledger answers one question for every route the connected store actually serves: is it
 * reachable through this server, and if not, who decided that and why. A route absent from the
 * ledger is a bug, not an omission — "we never looked at it" is exactly the state this file
 * exists to make impossible.
 *
 * Usage:
 *   node scripts/build-api-coverage.mjs            # write api-coverage.json
 *   node scripts/build-api-coverage.mjs --check    # fail if the checked-in file has drifted
 */

import { existsSync, readdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const PACKAGE_ROOT = dirname(dirname(fileURLToPath(import.meta.url)))

export const CURRENT_FIXTURE = 'tests/fixtures/routes/fluentcart-1.6.0-core-pro-1.6.0.json'
export const CORE_FIXTURE = 'tests/fixtures/routes/fluentcart-1.6.0-core.json'
export const LEGACY_FIXTURE = 'tests/fixtures/routes/fluentcart-1.3.9.json'
export const OUTPUT_FILE = 'api-coverage.json'

const TOOLS_DIR = 'src/tools'
const CLIENT_MODULE = 'src/api/client.ts'
const FACTORY_MODULE = 'src/tools/_factory.ts'

const CORE_COMPONENT = { slug: 'fluent-cart', version: '1.6.0' }
const PRO_COMPONENT = { slug: 'fluent-cart-pro', version: '1.6.0' }

const read = (relative) => readFileSync(join(PACKAGE_ROOT, relative), 'utf8')
const readJson = (relative) => JSON.parse(read(relative))

/**
 * Deterministic string order for everything written to the ledger.
 *
 * Deliberately not `localeCompare`: that consults ICU collation, so the same rows can order
 * differently across Node builds, platforms and locales. A ledger whose order depends on the
 * machine that generated it is not diffable, which is the whole point of sorting it. Plain
 * code-unit comparison is boring and identical everywhere.
 */
export function compareStrings(left, right) {
	if (left === right) return 0
	return left < right ? -1 : 1
}

/** The single ordering for ledger rows: component, then path, then method. */
export function compareRoutes(left, right) {
	return (
		compareStrings(left.component.slug, right.component.slug) ||
		compareStrings(left.path, right.path) ||
		compareStrings(left.method, right.method)
	)
}

// ---------------------------------------------------------------------------
// Reviewed disposition map
// ---------------------------------------------------------------------------

/** Internal maintenance, onboarding, MCP self-management and customer-session routes. */
const EXCLUDED_INTERNAL = {
	'POST /data-backfills/run': ['infrastructure', 'Internal maintenance job. Re-runs data backfills across the store with no preview, no scope control and no restore path.'],
	'POST /onboarding/save-tax': ['control-plane', 'First-run onboarding step. Writes store-wide tax configuration outside the normal settings flow.'],
	'GET /settings/mcp': ['credential-bearing', 'MCP self-management. Returns this server’s own connection settings, including adapter credentials.'],
	'GET /settings/mcp/config-snippets': ['credential-bearing', 'MCP self-management. Emits ready-to-paste client configuration containing connection secrets.'],
	'POST /settings/mcp/install-adapter': ['control-plane', 'MCP self-management. Installs an adapter into the store; an agent must not reconfigure its own transport.'],
	'POST /settings/mcp/toggle': ['control-plane', 'MCP self-management. Enables or disables the store’s MCP surface, including the caller’s own access.'],
	'POST /settings/modules/turnstile/verify': ['external-side-effect', 'Captcha verification for a browser session. Consumes a single-use human-interaction token and is meaningless outside a storefront request.'],
	'POST /customer-profile/subscriptions/{param}/initiate-early-payment': ['real-money', 'Customer-session route. Acts as the logged-in shopper and initiates an early payment against their subscription.'],
}

/** Read routes reviewed live but unsuitable for the contract their tempting tool would promise. */
const EXCLUDED_INCOMPATIBLE_READS = {
	'GET /products/variants': [
		'read',
		'Reviewed against FluentCart 1.6: the route ignores product_id and only reads nested variant_ids params, so it can return HTTP 200 with an empty list for a product that has variants. Per-product reads use GET /products/{param} with the variants relation instead.',
	],
}

/**
 * High-impact operations that stay excluded from the public server.
 *
 * Reversibility is decided by evidence in the same capture, not by how ordinary the verb looks:
 * `POST /shipping/packages` is destructive precisely because the runtime serves no delete for it.
 */
const HIGH_IMPACT = {
	'POST /customer-profile/subscriptions/{param}/pause': ['destructive-write', 'Customer-session lifecycle action. The shared pause service voids open renewal invoices and can delegate a pause to the payment gateway; neither state has a complete restore contract.'],
	'POST /customer-profile/subscriptions/{param}/resume': ['external-side-effect', 'Customer-session lifecycle action. The shared resume service restores scheduled charges for store-billed subscriptions or delegates resumption to the payment gateway.'],
	'POST /email-notification/digest-settings': ['control-plane', 'Changes which digests the store sends to staff. Alters outbound behaviour with no read-back of the previous schedule.'],
	'POST /email-notification/digest-settings/send-test': ['external-side-effect', 'Delivers a real email. Cannot be recalled once sent.'],
	'POST /email-notification/preview': ['external-side-effect', 'Renders a notification through the mail pipeline; the controller is not available here to prove it never dispatches.'],
	'POST /email-notification/reminders': ['control-plane', 'Changes which reminders the store sends to customers, with no read-back of the previous schedule.'],
	'POST /email-notification/send-manual-reminder': ['external-side-effect', 'Delivers a real email to a customer. Cannot be recalled once sent.'],
	'POST /options/attr/groups/reorder': ['destructive-write', 'Overwrites attribute group ordering in bulk with no restore of the previous order.'],
	'POST /orders/calculate-tax': ['destructive-write', 'Treated as an order mutation: the FluentCart controller is not available in this environment to prove the call is preview-only.'],
	'POST /orders/{param}/subscriptions/{param}/charge-now': ['real-money', 'Runs an immediate off-session payment attempt against the open renewal invoice.'],
	'POST /orders/{param}/subscriptions/{param}/create-renewal': ['real-money', 'Creates a renewal invoice and immediately charges it for system subscriptions; manual subscriptions receive a payable invoice and email.'],
	'POST /orders/{param}/subscriptions/{param}/early-payment-link': ['real-money', 'Payment-adjacent. Generates a link that lets a customer be charged ahead of schedule.'],
	'POST /orders/{param}/subscriptions/{param}/skip-renewal': ['destructive-write', 'Skips the next billing period and advances its billing date; the previous period cannot be restored through the runtime.'],
	'POST /orders/{param}/transactions/{param}/sync': ['external-side-effect', 'Synchronises a pending transaction with its payment gateway and can update local payment state from the remote result.'],
	'POST /products/bulk-insert': ['destructive-write', 'Bulk catalogue change with no per-record read-back and no supported bulk undo.'],
	'POST /products/bulk-update': ['destructive-write', 'Bulk catalogue change with no per-record read-back and no supported bulk undo.'],
	'POST /products/variants/bulk-update': ['destructive-write', 'Bulk variation change with no per-record read-back and no supported bulk undo.'],
	'POST /products/variants/group-bulk-update': ['destructive-write', 'Bulk variation-group change with no per-record read-back and no supported bulk undo.'],
	'POST /products/variants/{param}/tax-exempt': ['destructive-write', 'Overwrites a variation’s tax treatment with no restore of the previous value.'],
	'POST /products/{param}/tax-exempt': ['destructive-write', 'Overwrites a product’s tax treatment with no restore of the previous value.'],
	'POST /renewals/{param}/resend': ['external-side-effect', 'Triggers the renewal-created notification flow and sends a real customer email that cannot be recalled.'],
	'POST /renewals/{param}/void': ['destructive-write', 'Cancels the renewal invoice, fails pending transactions and advances the subscription past that billing period without a restore path.'],
	'PUT /saved-views/{param}': ['reversible-write', 'Updating a saved view overwrites it in place, and no read-back of the pre-update record has been captured, so the previous state cannot be restored. No tool reaches this route; an update tool needs that read-back first.'],
	'DELETE /saved-views/{param}': ['destructive-write', 'Removes a saved view with no supported restore.'],
	'POST /settings/pdf-templates/create': ['reversible-write', 'Reversible in principle — the runtime serves DELETE /settings/pdf-templates/delete/{param} — but restore proof belongs to plan 06 Task 3.'],
	'DELETE /settings/pdf-templates/delete/{param}': ['destructive-write', 'Deletes a PDF template with no supported restore.'],
	'POST /settings/pdf-templates/download': ['infrastructure', 'Generates a binary document. Binary payloads cannot be safely projected into a response fixture.'],
	'POST /settings/pdf-templates/receipt/{param}': ['control-plane', 'Changes the receipt customers receive, with no read-back of the replaced template.'],
	'POST /settings/pdf-templates/seller-details': ['control-plane', 'Changes the seller identity printed on customer documents.'],
	'POST /settings/storage-drivers/bucket-list': ['credential-bearing', 'Queries the remote storage provider using stored credentials; the response can echo bucket topology.'],
	'POST /settings/storage-drivers/change-status': ['infrastructure', 'Changes where store files are served from. A wrong value breaks every existing download link.'],
	'POST /settings/storage-drivers/create-bucket': ['infrastructure', 'Creates remote storage infrastructure outside WordPress, and may incur provider cost.'],
	'POST /settings/storage-drivers/reset': ['infrastructure', 'Resets the storage driver configuration with no restore of the previous settings.'],
	'POST /shipping/packages': ['destructive-write', 'Not reversible: the current runtime serves GET and POST for /shipping/packages but no delete, so a created package cannot be removed.'],
	'POST /tax/configuration/settings/eu-vat/oss-rates': ['control-plane', 'Overwrites EU OSS tax rates used to charge customers.'],
	'POST /tax/configuration/settings/eu-vat/reset-rates': ['destructive-write', 'Resets EU VAT rates in bulk with no restore of the previous table.'],
	'POST /tax/country-status/{param}': ['destructive-write', 'Changes whether a country is taxed, altering charges on subsequent orders with no captured previous state.'],
	'POST /tax/product-overrides': ['reversible-write', 'Reversible in principle — the runtime serves DELETE /tax/product-overrides/{param} — but restore proof belongs to plan 06 Task 3.'],
	'DELETE /tax/product-overrides/{param}': ['destructive-write', 'Removes a product tax override with no supported restore.'],
	'PUT /orders/{param}/subscriptions/{param}/pause': ['destructive-write', 'Pauses a subscription through the shared lifecycle service, which voids open renewal invoices or delegates a pause to the payment gateway.'],
	'PUT /orders/{param}/subscriptions/{param}/reactivate': ['destructive-write', 'Reactivation voids pending renewal invoices and transactions, then can advance the next billing date. The old invoice and schedule cannot be restored.'],
	'PUT /orders/{param}/subscriptions/{param}/resume': ['external-side-effect', 'Resumes a subscription through the shared lifecycle service, restoring scheduled charges locally or delegating resumption to the payment gateway.'],
}

/**
 * Routes for tools whose paths are chosen at runtime and cannot be read from a single literal.
 *
 * Plan 03 replaced fixed endpoints with capability-selected variants, so the path now lives in a
 * module constant that a discovery call picks between. Every entry here was read from the named
 * source lines and is checked against the fixture, and any tool that yields no route without an
 * entry fails the build — extraction silently losing a tool is exactly the failure this ledger
 * exists to prevent.
 */
const TOOL_ROUTE_OVERRIDES = {
	fluentcart_tax_eu_rates: {
		source: 'src/tools/tax-eu-vat.ts#EU_RATE_VARIANTS',
		routes: [
			{ method: 'GET', path: '/tax/configuration/settings/eu-vat/oss-rates', preferred: true },
			{ method: 'GET', path: '/tax/configuration/settings/eu-vat/rates', preferred: false },
		],
	},
	fluentcart_attribute_term_create: {
		source: 'src/tools/product-options-terms.ts#TERMS_BULK,TERMS_LEGACY',
		routes: [
			{ method: 'POST', path: '/options/attr/group/{param}/terms', preferred: true },
			{ method: 'POST', path: '/options/attr/group/{param}/term', preferred: false },
		],
	},
	fluentcart_attribute_term_reorder: {
		source: 'src/tools/product-options-terms.ts#REORDER_BULK,REORDER_LEGACY',
		routes: [
			{ method: 'POST', path: '/options/attr/group/{param}/terms/reorder', preferred: true },
			{ method: 'POST', path: '/options/attr/group/{param}/term/{param}/serial', preferred: false },
		],
	},
	fluentcart_email_template_preview: {
		source: 'src/tools/email-notifications.ts#PREVIEW_VARIANTS',
		routes: [
			{ method: 'POST', path: '/email-notification/preview-default-template', preferred: true },
			{ method: 'POST', path: '/email-notification/get-template', preferred: false },
		],
	},
	// The three below declare `ToolRouteMetadata` correctly; their variants simply come from a
	// module constant, which the source reader cannot evaluate. The declaration is the truth and
	// these entries restate it — a test asserts each still matches its constant.
	fluentcart_get_search_capabilities: {
		source: 'src/tools/commerce-search.ts#SEARCH_OPERATIONS',
		routes: [
			{ method: 'GET', path: '/orders', preferred: true },
			{ method: 'GET', path: '/products', preferred: true },
			{ method: 'GET', path: '/customers', preferred: true },
			{ method: 'GET', path: '/subscriptions', preferred: true },
		],
	},
	fluentcart_search_commerce: {
		source: 'src/tools/commerce-search.ts#SEARCH_OPERATIONS',
		routes: [
			{ method: 'GET', path: '/orders', preferred: true },
			{ method: 'GET', path: '/products', preferred: true },
			{ method: 'GET', path: '/customers', preferred: true },
			{ method: 'GET', path: '/subscriptions', preferred: true },
		],
	},
	// A dispatcher: one route per call, chosen by the `kind` argument. No variant is preferred
	// over another because each is the only route for its own reference kind.
	fluentcart_list_reference_data: {
		source: 'src/tools/reference-data.ts#VARIANTS',
		routes: [
			{ method: 'GET', path: '/settings/payment-methods/all', preferred: true },
			{ method: 'GET', path: '/tax/classes', preferred: true },
			{ method: 'GET', path: '/shipping/zones', preferred: true },
			{ method: 'GET', path: '/address-info/countries', preferred: true },
			{ method: 'GET', path: '/labels', preferred: true },
			{ method: 'GET', path: '/products/fetch-term', preferred: true },
		],
	},
}

/**
 * Tool routes the current runtime does not serve.
 *
 * Two different things end up here. A `compatibility-fallback` is deliberate: plan 03 keeps the
 * legacy path as a second variant so the tool still works against an older store, and the
 * preferred variant is served here. A `removed-route-defect` is a real bug — the tool has only
 * the removed path, so it fails against this store. An orphan absent from this map fails the
 * build rather than being quietly tolerated.
 */
const REVIEWED_ORPHAN_TOOL_ROUTES = {
	'POST /email-notification/get-template': { kind: 'compatibility-fallback', owner: 'plan-03', preferred: 'POST /email-notification/preview-default-template' },
	'POST /options/attr/group/{param}/term': { kind: 'compatibility-fallback', owner: 'plan-03', preferred: 'POST /options/attr/group/{param}/terms' },
	'POST /options/attr/group/{param}/term/{param}/serial': { kind: 'compatibility-fallback', owner: 'plan-03', preferred: 'POST /options/attr/group/{param}/terms/reorder' },
	'GET /tax/configuration/settings/eu-vat/rates': { kind: 'compatibility-fallback', owner: 'plan-03', preferred: 'GET /tax/configuration/settings/eu-vat/oss-rates' },
	// `GET /products/{param}/get-bundle-info` was removed from this table on 2026-07-27. It
	// recorded a real defect — fluentcart_product_bundle_info had the path segments transposed —
	// which has since been fixed. The tool now claims GET /products/get-bundle-info/{param}, the
	// very route this entry named as preferred, so nothing is left unowned. The sibling
	// POST /products/save-bundle-info/{param} is claimed by fluentcart_product_bundle_save.
}

/**
 * Live proof that an exposed write can be undone, cited per route.
 *
 * A `reversible-write` claim is only worth the evidence behind it, so the row names the lane
 * that demonstrated the undo rather than asserting reversibility in prose. The validator checks
 * these paths exist, so deleting the proof breaks the build instead of quietly leaving a write
 * exposed on the strength of a sentence nobody re-ran.
 */
const REVERSIBILITY_PROOF = {
	'POST /saved-views': {
		evidence: 'tests/integration/current-reversible-writes.test.ts',
		note: 'Reversibility is demonstrated live: the created id is registered for removal before anything else can fail, read back independently rather than echoed from the request, then deleted and confirmed gone, with every pre-existing view unchanged.',
	},
}

/**
 * Narrow tools whose reversibility depends on a smaller payload than their upstream route allows.
 *
 * These proofs are keyed by tool and route. The FluentCart subscription endpoint also accepts
 * amount, schedule and status fields, so route-wide proof would incorrectly bless mutations the
 * public tool deliberately omits.
 */
const REQUIRED_REVERSIBILITY_PROOF_TOOLS = new Set(['fluentcart_subscription_update'])
const TOOL_ROUTE_REVERSIBILITY_PROOF = {
	'fluentcart_subscription_update::PUT /orders/{param}/subscriptions/{param}/update': {
		evidence:
			'tests/fixtures/releases/fluentcart-1.6.0-subscription-bill-times-restore.json',
		note:
			'The bounded bill_times payload was demonstrated live on a run-owned FluentCart 1.6 fixture: 5 to 6 with independent read-back, then 6 to 5 with final read-back and unchanged bill_count, status and collection method. The evidence explicitly records that FluentCart offers no atomic version precondition.',
	},
}

/**
 * POST-shaped reads that assert `readOnlyHint` in their own annotations instead of carrying a
 * reviewed row in the risk registry.
 *
 * `resolveToolSafety` trusts that annotation, so these are live as reads today without the
 * registry ever having classified them. That is a gap in the reviewed contract, not a labelling
 * detail, so each one is named here with an owner. An unlisted POST-shaped read fails the build.
 */
const REVIEWED_UNREGISTERED_READS = {
	fluentcart_product_terms_by_parent: {
		route: 'POST /products/fetch-term-by-parent',
		owner: 'unassigned',
		reason:
			'Declares readOnlyHint in its tool annotations, so the server resolves it to READ_SAFETY, but it is absent from POST_SHAPED_READS in src/tools/risk-registry.ts. It reads product terms by parent and changes nothing; it needs a reviewed registry row so the classification stops depending on a per-tool annotation.',
	},
}

// ---------------------------------------------------------------------------
// Source extraction
// ---------------------------------------------------------------------------

const FACTORY_METHOD = { getTool: 'GET', postTool: 'POST', putTool: 'PUT', deleteTool: 'DELETE' }

const normalisePath = (raw) =>
	raw.replace(/:[A-Za-z_][A-Za-z0-9_]*/g, '{param}').replace(/\$\{[^}]*\}/g, '{param}')

/** Return the balanced `{...}` literal starting at `open`. */
function readBalanced(source, open) {
	let depth = 0
	for (let i = open; i < source.length; i++) {
		if (source[i] === '{') depth++
		else if (source[i] === '}' && --depth === 0) return source.slice(open, i + 1)
	}
	return null
}

/** Every `<client>.get|post|put|delete(path)` and `<client>.request('METHOD', path)` in a body. */
function routesFromCalls(body, param) {
	const routes = []
	const literal = `(\`[^\`]*\`|'[^']*')`
	const verbs = new RegExp(`\\b${param}\\.(get|post|put|delete)\\(\\s*${literal}`, 'g')
	const generic = new RegExp(`\\b${param}\\.request\\(\\s*'(GET|POST|PUT|DELETE)'\\s*,\\s*${literal}`, 'g')

	for (const match of body.matchAll(verbs)) {
		routes.push({ method: match[1].toUpperCase(), path: normalisePath(match[2].slice(1, -1)) })
	}
	for (const match of body.matchAll(generic)) {
		routes.push({ method: match[1], path: normalisePath(match[2].slice(1, -1)) })
	}
	return routes
}

/** Return the balanced expression starting at `open`, for either `(...)` or `{...}`. */
function readBalancedExpr(source, open) {
	const pairs = { '(': ')', '{': '}', '[': ']' }
	const close = pairs[source[open]]
	if (!close) return null
	let depth = 0
	for (let i = open; i < source.length; i++) {
		if (source[i] === source[open]) depth++
		else if (source[i] === close && --depth === 0) return source.slice(open, i + 1)
	}
	return null
}

/**
 * Routes a tool declares through `ToolRouteMetadata`.
 *
 * Authoritative, and tried before anything is inferred: since the plan 03 migration every tool
 * states its own routes, so reading the declaration beats reconstructing it from call sites.
 * Inference stays as the fallback for the modules that predate it.
 *
 * Only literal declarations can be read here. A tool whose variants come from a module constant
 * yields nothing and must carry a reviewed override — the ledger will not guess at a symbol it
 * cannot evaluate.
 */
function routesFromDeclaration(body) {
	const match = /\broutes:\s*/.exec(body)
	if (!match) return []

	// The value is either an object literal or a `direct(...)` / `composite(...)` call. Skip any
	// leading identifier to find the opening bracket, then keep the identifier in the slice so
	// the helper name is still visible to the patterns below.
	const start = match.index + match[0].length
	let open = start
	while (open < body.length && /[\w$.]/.test(body[open])) open++
	const balanced = readBalancedExpr(body, open)
	const expr = balanced === null ? '' : body.slice(start, open) + balanced
	const routes = []

	for (const m of expr.matchAll(/\{\s*method:\s*'(GET|POST|PUT|PATCH|DELETE)'\s*,\s*path:\s*'([^']+)'/g)) {
		routes.push({ method: m[1], path: normalisePath(m[2]) })
	}
	for (const m of expr.matchAll(/\b(?:direct|op)\(\s*'(GET|POST|PUT|PATCH|DELETE)'\s*,\s*'([^']+)'/g)) {
		routes.push({ method: m[1], path: normalisePath(m[2]) })
	}

	const seen = new Set()
	return routes.filter((r) => {
		const key = `${r.method} ${r.path}`
		if (seen.has(key)) return false
		seen.add(key)
		return true
	})
}

/** Routes a custom `createTool` handler reaches through the injected client. */
function routesFromHandler(body) {
	const param = body.match(/\bhandler:\s*(?:async\s*)?\(\s*([A-Za-z_$][\w$]*)/)?.[1]
	return param ? routesFromCalls(body, param) : []
}

/**
 * Routes reached by module-level helpers.
 *
 * Composite tools increasingly call a helper defined outside the `createTool` literal, so the
 * handler body names no path at all. When a module declares exactly one tool this is safe to
 * attribute — there is nothing else in the file it could belong to. Modules with several tools
 * stay ambiguous and must use a reviewed override instead of a guess.
 */
function routesFromModule(source) {
	const clientParams = new Set(
		[...source.matchAll(/\(\s*([A-Za-z_$][\w$]*)\s*:\s*FluentCartClient/g)].map((m) => m[1]),
	)
	const routes = []
	for (const param of clientParams) routes.push(...routesFromCalls(source, param))
	return routes
}

/** Every tool the server can build, with the REST routes it reaches. */
export function extractTools() {
	const tools = []
	const usedOverrides = new Set()
	const files = readdirSync(join(PACKAGE_ROOT, TOOLS_DIR)).filter((f) => f.endsWith('.ts')).sort()

	for (const file of files) {
		const source = read(join(TOOLS_DIR, file))
		const calls = /\b(getTool|postTool|putTool|deleteTool|createTool)\(\s*client\s*,\s*\{/g

		for (const match of source.matchAll(calls)) {
			const factory = match[1]
			const body = readBalanced(source, source.indexOf('{', match.index + match[0].length - 1))
			if (!body) continue
			const name = body.match(/\bname:\s*'([^']+)'/)?.[1]
			if (!name) continue

			let routes = routesFromDeclaration(body)
			if (routes.length === 0) {
				if (factory === 'createTool') {
					routes = routesFromHandler(body)
				} else {
					const endpoint = body.match(/\bendpoint:\s*'([^']+)'/)?.[1]
					routes = endpoint ? [{ method: FACTORY_METHOD[factory], path: normalisePath(endpoint) }] : []
				}
			}

			const override = TOOL_ROUTE_OVERRIDES[name]
			if (routes.length === 0 && override) {
				routes = override.routes.map(({ method, path }) => ({ method, path }))
				usedOverrides.add(name)
			}
			if (routes.length === 0) {
				const soleTool = [...source.matchAll(/\bname:\s*'(fluentcart_[a-z0-9_]+)'/g)].length === 1
				if (soleTool) routes = routesFromModule(source)
			}

			const readOnlyHint = /\breadOnlyHint:\s*true/.test(body)
			tools.push({
				name,
				sourceFile: `${TOOLS_DIR}/${file}`,
				factory,
				readOnlyHint: factory === 'createTool' ? readOnlyHint : factory === 'getTool' || readOnlyHint,
				routes,
				routeSource: usedOverrides.has(name) ? override.source : null,
			})
		}
	}
	tools.usedOverrides = usedOverrides
	return tools
}

/** Reviewed safety rows, read from the registry source so the ledger cannot drift from it. */
export function extractRiskRegistry() {
	const source = read('src/tools/risk-registry.ts')
	const arrays = new Map()
	for (const match of source.matchAll(/const ([A-Z_]+) = \[([^\]]*)\]/g)) {
		arrays.set(match[1], [...match[2].matchAll(/'([^']+)'/g)].map((m) => m[1]))
	}

	const safety = new Map()
	const assign = (names, row) => {
		for (const name of names ?? []) safety.set(name, row)
	}

	assign(arrays.get('POST_SHAPED_READS'), { risk: 'read', idempotency: 'inherent', execution: 'rest' })
	for (const match of source.matchAll(/\.\.\.rows\(([A-Z_]+),\s*\{([\s\S]*?)\}\)/g)) {
		const fields = match[2]
		assign(arrays.get(match[1]), {
			risk: fields.match(/risk:\s*'([^']+)'/)?.[1],
			idempotency: fields.match(/idempotency:\s*'([^']+)'/)?.[1],
			execution: fields.match(/execution:\s*'([^']+)'/)?.[1],
		})
	}
	return safety
}

/**
 * Tools plan 03 withdrew because the current FluentCart runtime does not serve their route.
 *
 * They are still declared in source but wrapped in a capability guard, so against this store
 * they are never registered. The reviewed list lives in the regression suite rather than here:
 * duplicating it would let the two rot apart, and that file already fails if a withdrawn name
 * is registered after all.
 */
export function extractWithdrawnTools() {
	const source = read('tests/regression/scenario-coverage.test.ts')
	const start = source.indexOf('const WITHDRAWN_TOOLS')
	if (start === -1) return new Map()

	const block = source.slice(start, source.indexOf('\n}', start))
	const withdrawn = new Map()
	for (const match of block.matchAll(/\n\t(fluentcart_[a-z0-9_]+):\s*\n?\s*'([^']+)'/g)) {
		withdrawn.set(match[1], match[2])
	}
	return withdrawn
}

export function extractCuratedNames() {
	const source = read('src/tools/curated.ts')
	const block = source.slice(0, source.indexOf('export function'))
	return [...new Set([...block.matchAll(/'(fluentcart_[a-z0-9_]+)'/g)].map((m) => m[1]))]
}

/** Mirrors `resolveToolSafety`: an unclassified write is hidden, never quietly executable. */
function safetyFor(tool, registry) {
	return (
		registry.get(tool.name) ??
		(tool.readOnlyHint
			? { risk: 'read', idempotency: 'inherent', execution: 'rest' }
			: { risk: 'unreviewed-write', idempotency: 'unsupported', execution: 'none' })
	)
}

// ---------------------------------------------------------------------------
// Ledger construction
// ---------------------------------------------------------------------------

const key = (operation) => `${operation.method} ${operation.path}`

function evidenceFor(route, tools, fixture = CURRENT_FIXTURE) {
	const sources = [...new Set(tools.map((t) => t.sourceFile))].sort()
	return {
		schemaEvidence: tools.map((t) => `${t.sourceFile}#${t.name}`).sort(),
		permissionEvidence: [`${fixture}#${route}`, CLIENT_MODULE],
		responseEvidence: [...sources.map((s) => s), FACTORY_MODULE],
	}
}

export function buildLedger() {
	const current = readJson(CURRENT_FIXTURE)
	const core = readJson(CORE_FIXTURE)
	const legacy = readJson(LEGACY_FIXTURE)
	const coreKeys = new Set(core.operations.map(key))
	const legacyKeys = new Set(legacy.operations.map(key))
	const registry = extractRiskRegistry()
	const curated = new Set(extractCuratedNames())
	const withdrawn = extractWithdrawnTools()

	// A withdrawn tool is declared in source but capability-gated out against this store, so it
	// is not part of the registry the ledger describes. Counting it would overstate the surface.
	const declared = extractTools()
	const tools = declared.filter((tool) => !withdrawn.has(tool.name))

	const byRoute = new Map()
	for (const tool of tools) {
		for (const route of tool.routes) {
			const id = key(route)
			if (!byRoute.has(id)) byRoute.set(id, [])
			byRoute.get(id).push(tool)
		}
	}

	const rows = current.operations.map((operation) => {
		const id = key(operation)
		const attached = byRoute.get(id) ?? []
		const exposed = attached.filter((tool) => safetyFor(tool, registry).execution !== 'none')
		const suppressed = attached.filter((tool) => safetyFor(tool, registry).execution === 'none')
		const isCore = coreKeys.has(id)
		const evidenceFixture = isCore ? CORE_FIXTURE : CURRENT_FIXTURE
		const component = isCore ? CORE_COMPONENT : PRO_COMPONENT

		const base = {
			method: operation.method,
			path: operation.path,
			component: { ...component, evidenceFixture },
			contractOrigin: legacyKeys.has(id) ? 'legacy-docs' : 'current-runtime',
		}

		const excludedAs = (risk, reason) => ({
			...base,
			routeDisposition: 'excluded',
			toolExposures: [],
			reason,
			risk,
			schemaEvidence: [],
			permissionEvidence: [`${evidenceFixture}#${id}`],
			responseEvidence: [],
			suppressedTools: suppressed.concat(exposed).map((t) => t.name).sort(compareStrings),
		})

		if (EXCLUDED_INTERNAL[id]) return excludedAs(...EXCLUDED_INTERNAL[id])
		if (EXCLUDED_INCOMPATIBLE_READS[id]) {
			return excludedAs(...EXCLUDED_INCOMPATIBLE_READS[id])
		}
		if (HIGH_IMPACT[id]) {
			const [risk, reason] = HIGH_IMPACT[id]
			return excludedAs(risk, `High-impact operation. ${reason}`)
		}
		if (exposed.length === 0) {
			const suppressedRisks = suppressed.map((tool) => safetyFor(tool, registry).risk)
			return excludedAs(
				suppressedRisks.sort((a, b) => (a === 'read' ? 1 : b === 'read' ? -1 : 0))[0] ?? 'read',
				suppressed.length > 0
					? `Served only by ${suppressed.map((t) => t.name).join(', ')}, whose reviewed risk makes it non-executable, so no caller can reach this route.`
					: 'No tool reaches this route and no reviewed candidate claims it. It remains unowned pending plan 06 Tasks 2 and 3.',
			)
		}

		// A route's risk is the worst thing reachable *through this route*, not the worst thing any
		// tool touching it can do elsewhere. A separate read can fetch GET /orders/{param}, and
		// labelling that lookup "real-money" would be false: the same data is
		// independently readable through a plain read tool. So a GET that any read tool serves is a
		// read, and everything else takes the highest reviewed risk among its exposing tools.
		// A write that ships on a reversibility claim carries the lane that proved it, in the
		// response evidence rather than only in prose.
		const proof = REVERSIBILITY_PROOF[id]
		const toolProofs = exposed
			.map((tool) => TOOL_ROUTE_REVERSIBILITY_PROOF[`${tool.name}::${id}`])
			.filter(Boolean)
		const evidence = evidenceFor(id, exposed, evidenceFixture)
		if (proof) evidence.responseEvidence = [...evidence.responseEvidence, proof.evidence].sort()
		for (const toolProof of toolProofs) {
			evidence.responseEvidence = [...evidence.responseEvidence, toolProof.evidence].sort()
		}

		const risks = exposed.map((tool) => safetyFor(tool, registry).risk)
		const worst =
			operation.method === 'GET' && risks.includes('read')
				? 'read'
				: risks.sort((a, b) => (a === 'read' ? 1 : b === 'read' ? -1 : 0))[0]

		return {
			...base,
			routeDisposition: 'exposed',
			toolExposures: exposed
				.map((tool) => ({
					publicName: tool.name,
					disposition: curated.has(tool.name) ? 'curated' : 'dynamic',
				}))
				.sort((a, b) => compareStrings(a.publicName, b.publicName)),
			reason: `Reached by ${exposed.map((t) => t.name).sort(compareStrings).join(', ')}.${proof ? ` ${proof.note}` : ''}${toolProofs.length > 0 ? ` ${toolProofs.map((entry) => entry.note).join(' ')}` : ''}`,
			risk: worst,
			...evidence,
			suppressedTools: suppressed.map((t) => t.name).sort(),
		}
	})

	rows.sort(compareRoutes)

	const orphans = [...byRoute.keys()]
		.filter((id) => !current.operations.some((operation) => key(operation) === id))
		.sort(compareStrings)
		.map((id) => {
			const reviewed = REVIEWED_ORPHAN_TOOL_ROUTES[id]
			const tools = byRoute.get(id).map((t) => t.name).sort()
			if (!reviewed) return { route: id, tools, kind: 'unreviewed', reason: 'UNREVIEWED orphan.' }

			const reason =
				reviewed.kind === 'compatibility-fallback'
					? `Deliberate fallback. Documented in 1.3.9 and removed by 1.5.5, retained as a second variant so the tool still works against an older store. The preferred variant ${reviewed.preferred} is served here.`
					: `Defect. Documented in 1.3.9 and removed by 1.5.5, and the tool has no served variant, so it fails against this store.${reviewed.preferred ? ` The current runtime serves ${reviewed.preferred} instead.` : ' The current runtime serves no replacement.'}`
			return { route: id, tools, ...reviewed, reason }
		})

	return {
		schemaVersion: 1,
		generator: 'scripts/build-api-coverage.mjs',
		fixtures: { current: CURRENT_FIXTURE, core: CORE_FIXTURE, legacy: LEGACY_FIXTURE },
		attribution:
			'Isolated captures prove 365 operations with FluentCart Core 1.6.0 alone and 396 with FluentCart Pro 1.6.0 added. The exact 31-operation set difference is attributed to Pro; the shared 365-operation set is attributed to Core.',
		counts: {
			routes: rows.length,
			coreRoutes: rows.filter((r) => r.component.slug === 'fluent-cart').length,
			proRoutes: rows.filter((r) => r.component.slug === 'fluent-cart-pro').length,
			exposed: rows.filter((r) => r.routeDisposition === 'exposed').length,
			excluded: rows.filter((r) => r.routeDisposition === 'excluded').length,
			deltaSince139: rows.filter((r) => r.contractOrigin === 'current-runtime').length,
			tools: tools.length,
			declaredTools: declared.length,
			withdrawnTools: declared.length - tools.length,
			orphanToolRoutes: orphans.length,
		},
		routes: rows,
		withdrawnTools: declared
			.filter((tool) => withdrawn.has(tool.name))
			.map((tool) => ({
				publicName: tool.name,
				sourceFile: tool.sourceFile,
				routes: tool.routes.map((route) => `${route.method} ${route.path}`).sort(),
				reason: withdrawn.get(tool.name),
			}))
			.sort((a, b) => compareStrings(a.publicName, b.publicName)),
		orphanToolRoutes: orphans,
		unregisteredReads: Object.entries(REVIEWED_UNREGISTERED_READS)
			.map(([publicName, entry]) => ({ publicName, ...entry }))
			.sort((a, b) => compareStrings(a.publicName, b.publicName)),
	}
}

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

const STREAM_MARKER = 'fchub-stream'

/**
 * Every listed evidence path must resolve to a real file, and any `#anchor` must be present in
 * it. A route fixture is checked against its parsed operations rather than by substring match,
 * because "GET /activity" never appears literally in a JSON file of method/path pairs and a
 * substring check would silently pass on nonsense.
 */
function checkEvidencePath(entry, failures, where) {
	if (entry.includes(STREAM_MARKER)) {
		failures.push(`${where}: evidence points into the discontinued Stream plugin: ${entry}`)
		return
	}
	const [path, anchor] = entry.split('#')
	if (!existsSync(join(PACKAGE_ROOT, path))) {
		failures.push(`${where}: evidence path does not exist: ${path}`)
		return
	}
	if (!anchor) return

	if (path.endsWith('.json')) {
		const operations = readJson(path).operations ?? []
		if (!operations.some((operation) => key(operation) === anchor)) {
			failures.push(`${where}: evidence route "${anchor}" not present in ${path}`)
		}
		return
	}
	if (!read(path).includes(anchor)) {
		failures.push(`${where}: evidence anchor "${anchor}" not found in ${path}`)
	}
}

export function validateLedger(ledger, options = {}) {
	const failures = []
	const tools = options.tools ?? extractTools()
	const registry = options.registry ?? extractRiskRegistry()
	const current = readJson(CURRENT_FIXTURE)

	const seen = new Set()
	for (const row of ledger.routes) {
		const id = `${row.component.slug} ${row.method} ${row.path}`
		if (seen.has(id)) failures.push(`duplicate ledger row for ${id}`)
		seen.add(id)

		if (!row.reason || row.reason.trim() === '') failures.push(`${id}: empty reason`)

		if (row.routeDisposition === 'excluded' && row.toolExposures.length > 0) {
			failures.push(`${id}: excluded route must have an empty toolExposures array`)
		}
		if (row.routeDisposition === 'exposed' && row.toolExposures.length === 0) {
			failures.push(`${id}: exposed route must have at least one tool exposure`)
		}

		if (row.routeDisposition === 'exposed') {
			const where = `${id}`
			if (row.schemaEvidence.length === 0) failures.push(`${where}: missing schema evidence`)
			if (row.permissionEvidence.length === 0) failures.push(`${where}: missing permission evidence`)
			if (row.responseEvidence.length === 0) failures.push(`${where}: missing response evidence`)
			for (const entry of [...row.schemaEvidence, ...row.permissionEvidence, ...row.responseEvidence]) {
				checkEvidencePath(entry, failures, where)
			}
			if (row.method !== 'GET' && row.risk === 'read') {
				const unreviewed = row.toolExposures.filter(
					(exposure) =>
						registry.get(exposure.publicName)?.risk !== 'read' &&
						!REVIEWED_UNREGISTERED_READS[exposure.publicName],
				)
				if (unreviewed.length > 0) {
					failures.push(
						`${where}: accepted write classified as risk "read" without a reviewed row: ${unreviewed
							.map((exposure) => exposure.publicName)
							.join(', ')}`,
					)
				}
			}
		}
	}

	// Every fixture operation is present exactly once.
	const rowKeys = new Set(ledger.routes.map((row) => `${row.method} ${row.path}`))
	for (const operation of current.operations) {
		if (!rowKeys.has(key(operation))) failures.push(`fixture operation missing from ledger: ${key(operation)}`)
	}
	if (ledger.routes.length !== current.operations.length) {
		failures.push(`ledger has ${ledger.routes.length} rows for ${current.operations.length} fixture operations`)
	}

	// Duplicate tool names.
	const names = tools.map((tool) => tool.name)
	for (const name of new Set(names.filter((n, i) => names.indexOf(n) !== i))) {
		failures.push(`duplicate tool name: ${name}`)
	}

	// A tool whose routes cannot be read is a hole in the ledger, not a tool without routes.
	for (const tool of tools) {
		if (tool.routes.length === 0) {
			failures.push(
				`tool ${tool.name} (${tool.sourceFile}) yields no REST route; add a reviewed entry to TOOL_ROUTE_OVERRIDES`,
			)
		}
	}

	// A tool whose safe public payload is narrower than its upstream route must carry its own
	// live restore proof. Source files and mocked responses are useful tests, but they are not the
	// evidence that the real FluentCart runtime accepted and restored the bounded mutation.
	for (const row of ledger.routes) {
		for (const exposure of row.toolExposures) {
			if (!REQUIRED_REVERSIBILITY_PROOF_TOOLS.has(exposure.publicName)) continue
			if (row.method === 'GET') continue
			const proofKey = `${exposure.publicName}::${row.method} ${row.path}`
			const proof = TOOL_ROUTE_REVERSIBILITY_PROOF[proofKey]
			if (!proof) {
				failures.push(`${proofKey}: missing required tool-and-route reversibility proof`)
				continue
			}
			if (!row.responseEvidence.includes(proof.evidence)) {
				failures.push(`${proofKey}: missing required reversibility proof evidence ${proof.evidence}`)
			}
		}
	}

	// An override the extractor no longer needs is a hand-maintained claim nobody is checking.
	if (tools.usedOverrides) {
		for (const name of Object.keys(TOOL_ROUTE_OVERRIDES)) {
			if (!tools.usedOverrides.has(name)) {
				failures.push(`stale route override: ${name} resolves from source and no longer needs one`)
			}
		}
	}

	// Orphans must be reviewed.
	const orphanRoutes = new Set(ledger.orphanToolRoutes.map((orphan) => orphan.route))
	for (const orphan of ledger.orphanToolRoutes) {
		if (!REVIEWED_ORPHAN_TOOL_ROUTES[orphan.route]) {
			failures.push(`unreviewed orphan tool route: ${orphan.route} (${orphan.tools.join(', ')})`)
		}
	}

	// A reviewed exception nobody needs any more is how these maps rot: the next reader assumes
	// the defect is still live, and a genuinely new one hides among the fossils.
	for (const route of Object.keys(REVIEWED_ORPHAN_TOOL_ROUTES)) {
		if (!orphanRoutes.has(route)) {
			failures.push(`stale reviewed orphan entry: ${route} is no longer claimed by any tool`)
		}
	}

	return failures
}

export {
	HIGH_IMPACT,
	EXCLUDED_INTERNAL,
	REVIEWED_ORPHAN_TOOL_ROUTES,
	REVIEWED_UNREGISTERED_READS,
	REQUIRED_REVERSIBILITY_PROOF_TOOLS,
	TOOL_ROUTE_OVERRIDES,
	TOOL_ROUTE_REVERSIBILITY_PROOF,
	safetyFor,
}

// ---------------------------------------------------------------------------
// CLI
// ---------------------------------------------------------------------------

function main() {
	const checkOnly = process.argv.includes('--check')
	const ledger = buildLedger()
	const failures = validateLedger(ledger)

	if (failures.length > 0) {
		process.stderr.write(`api-coverage: ${failures.length} contract failure(s)\n`)
		for (const failure of failures) process.stderr.write(`  - ${failure}\n`)
		process.exit(1)
	}

	const serialised = `${JSON.stringify(ledger, null, '\t')}\n`
	const target = join(PACKAGE_ROOT, OUTPUT_FILE)

	if (checkOnly) {
		const existing = existsSync(target) ? readFileSync(target, 'utf8') : ''
		if (existing !== serialised) {
			process.stderr.write('api-coverage: api-coverage.json is out of date; run node scripts/build-api-coverage.mjs\n')
			process.exit(1)
		}
		process.stderr.write(`api-coverage: up to date (${ledger.counts.routes} routes)\n`)
		return
	}

	writeFileSync(target, serialised)
	process.stderr.write(
		`api-coverage: wrote ${ledger.counts.routes} routes (${ledger.counts.exposed} exposed, ${ledger.counts.excluded} excluded)\n`,
	)
}

if (process.argv[1] && pathToFileURL(process.argv[1]).href === import.meta.url) main()
