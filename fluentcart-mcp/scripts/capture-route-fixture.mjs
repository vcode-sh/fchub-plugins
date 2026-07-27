#!/usr/bin/env node
/**
 * Capture a provenance-bearing route fixture. Two mutually exclusive evidence kinds, because they
 * are not interchangeable: `--rest-index <url> --profile <json>` reads a live registry from a
 * running store, while `--openapi <path>` reads the documentation contract, which proves nothing
 * about what any store actually exposes.
 *
 * Only `(method, canonical path)` pairs are written; callback names, argument schemas and links
 * are dropped, and a structural allowlist runs before anything reaches disk, so a fixture cannot
 * become an accidental site dump. Requires `npm run build` — the canonicaliser is imported from
 * dist so fixtures and the running server cannot disagree about what a route is called.
 */

import { createHash } from 'node:crypto'
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

function fail(message) {
	process.stderr.write(`capture-route-fixture: ${message}\n`)
	process.exit(1)
}

const packageRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const normaliserPath = resolve(packageRoot, 'dist/api/route-normalisation.js')
if (!existsSync(normaliserPath)) fail('run `npm run build` first (dist is missing)')

const { canonicaliseRoute, isFluentCartRoute, isHttpMethod, isNamespaceRoot, sortOperations } =
	await import(normaliserPath)

const SCHEMA_VERSION = 1
const FETCH_TIMEOUT_MS = 10_000
const VERSION_PATTERN = /^\d+(\.\d+)*(-[0-9A-Za-z.-]+)?$/
const OPENAPI_METHODS = new Set(['get', 'post', 'put', 'patch', 'delete'])
const ARG_FLAGS = new Set(['--rest-index', '--url', '--profile', '--openapi', '--output'])

function parseArgs(argv) {
	const options = {}

	for (let index = 0; index < argv.length; index += 1) {
		const flag = argv[index]
		if (!ARG_FLAGS.has(flag)) fail(`unknown argument: ${flag}`)
		const value = argv[index + 1]
		if (!value || value.startsWith('--')) fail(`${flag} requires a value`)
		index += 1

		if (flag === '--rest-index' || flag === '--url') options.restIndex = value
		else if (flag === '--profile') options.profile = value
		else if (flag === '--openapi') options.openapi = value
		else options.output = value
	}

	if (!options.output) fail('--output is required')

	const live = Boolean(options.restIndex || options.profile)
	if (live && options.openapi) fail('--rest-index/--profile and --openapi are mutually exclusive')
	if (!live && !options.openapi) fail('provide either --rest-index with --profile, or --openapi')
	if (live && !options.restIndex) fail('--profile requires --rest-index')
	if (live && !options.profile) fail('--rest-index requires --profile')

	return options
}

/** A profile is trusted only after it proves it names every component and every version. */
function readProfile(path) {
	const resolved = resolve(path)
	if (!existsSync(resolved)) fail(`profile not found: ${resolved}`)

	let profile
	try {
		profile = JSON.parse(readFileSync(resolved, 'utf8'))
	} catch {
		fail(`profile is not valid JSON: ${resolved}`)
	}

	const components = profile?.activeComponents
	if (!VERSION_PATTERN.test(String(profile?.wordpress ?? ''))) fail('profile has no `wordpress`')
	if (!Array.isArray(components) || components.length === 0) fail('profile has no components')

	const seen = new Set()
	const activeComponents = components.map((component) => {
		const slug = typeof component?.slug === 'string' ? component.slug.trim() : ''
		const version = typeof component?.version === 'string' ? component.version.trim() : ''
		if (!slug) fail(`profile component has no slug: ${JSON.stringify(component)}`)
		if (!VERSION_PATTERN.test(version)) fail(`profile component "${slug}" has no valid version`)
		if (seen.has(slug)) fail(`profile lists component "${slug}" more than once`)
		seen.add(slug)
		return { slug, version }
	})

	activeComponents.sort((left, right) => (left.slug < right.slug ? -1 : 1))
	return { wordpress: profile.wordpress, activeComponents }
}

function restRootUrl(input) {
	let parsed
	try {
		parsed = new URL(input)
	} catch {
		fail(`--rest-index must be an absolute URL, received: ${input}`)
	}

	parsed.search = ''
	parsed.hash = ''
	const base = parsed.toString().replace(/\/+$/, '')
	return base.endsWith('/wp-json') ? `${base}/` : `${base}/wp-json/`
}

async function fetchRestIndex(url) {
	const response = await fetch(url, {
		// The root index is public; no credential belongs on this request.
		headers: { Accept: 'application/json' },
		redirect: 'error',
		signal: AbortSignal.timeout(FETCH_TIMEOUT_MS),
	}).catch((error) => fail(`could not read ${url}: ${error.message}`))

	if (!response.ok) fail(`${url} returned HTTP ${response.status}`)

	try {
		return JSON.parse(await response.text())
	} catch {
		fail(`${url} did not return JSON`)
	}
}

/**
 * Reduce a REST index to operations plus the counts that make drift visible. Both conventions are
 * recorded, because the namespace root is discovery metadata rather than an application
 * operation, and a count that silently includes or excludes it looks like a regression.
 */
function readRestIndex(document) {
	if (!document || typeof document.routes !== 'object' || document.routes === null) {
		fail('REST index has no `routes` object')
	}

	const prefixed = Object.keys(document.routes).filter(isFluentCartRoute)
	if (prefixed.length === 0) fail('REST index exposes no fluent-cart/v2 routes')

	const exactInclusive = new Set()
	const applicationExact = new Set()
	const byCanonical = new Map()

	for (const path of prefixed) {
		for (const endpoint of document.routes[path]?.endpoints ?? []) {
			for (const method of endpoint?.methods ?? []) {
				if (!isHttpMethod(method)) continue

				const exact = `${method} ${path}`
				exactInclusive.add(exact)
				if (isNamespaceRoot(path)) continue
				applicationExact.add(exact)

				const canonical = `${method} ${canonicaliseRoute(path)}`
				if (!byCanonical.has(canonical)) {
					byCanonical.set(canonical, { method, path: canonicaliseRoute(path), exact: new Set() })
				}
				// A Set, because one route registered through several endpoint variants is still one
				// route. Only genuinely distinct patterns count as a collapse.
				byCanonical.get(canonical).exact.add(exact)
			}
		}
	}

	const operations = sortOperations(
		[...byCanonical.values()].map(({ method, path }) => ({ method, path })),
	)

	return {
		operations,
		canonicalCollapses: [...byCanonical.entries()]
			.filter(([, entry]) => entry.exact.size > 1)
			.map(([canonical, entry]) => ({ canonical, exact: [...entry.exact].sort() }))
			.sort((left, right) => (left.canonical < right.canonical ? -1 : 1)),
		counts: {
			prefixedPathsInclusive: prefixed.length,
			exactPairsInclusive: exactInclusive.size,
			applicationPaths: prefixed.filter((path) => !isNamespaceRoot(path)).length,
			applicationExactPairs: applicationExact.size,
			applicationCanonicalPairs: operations.length,
		},
	}
}

/** Minimal reader for the `paths:` and `info.version` blocks of the generated OpenAPI document. */
function readOpenApi(path) {
	const resolved = resolve(path)
	if (!existsSync(resolved)) fail(`openapi document not found: ${resolved}`)

	const text = readFileSync(resolved, 'utf8')
	const lines = text.split('\n')
	const start = lines.findIndex((line) => /^paths:\s*$/.test(line))
	if (start === -1) fail('openapi document has no `paths:` block')

	const operations = []
	let currentPath = null

	for (const line of lines.slice(start + 1)) {
		if (/^\S/.test(line)) break

		// A path key sits at two spaces and a method at four, so one line is never both.
		const pathMatch = /^ {2}(\/\S*):\s*$/.exec(line)
		if (pathMatch) currentPath = pathMatch[1]

		const method = /^ {4}([a-z]+):\s*$/.exec(line)?.[1]
		if (method && currentPath && OPENAPI_METHODS.has(method)) {
			operations.push({ method: method.toUpperCase(), path: canonicaliseRoute(currentPath) })
		}
	}

	const version = /^info:\s*$[\s\S]*?^\s+version:\s*["']?([^"'\s]+)/m.exec(text)?.[1]
	if (!version) fail('openapi document has no info.version')
	if (operations.length === 0) fail('openapi document yielded no operations')

	return {
		version,
		operations: sortOperations(operations),
		sha256: createHash('sha256').update(text).digest('hex'),
	}
}

const FIXTURE_KEYS = new Set([
	'schemaVersion', 'profile', 'capturedAt', 'evidenceKind', 'source',
	'operations', 'counts', 'canonicalCollapses', 'sourceSha256',
])

/**
 * Refuse to write anything that is not a route fixture. A structural allowlist rather than a
 * substring blocklist: not "does this look like a secret" but "is every field here allowed".
 */
function assertSanitised(fixture) {
	for (const key of Object.keys(fixture)) {
		if (!FIXTURE_KEYS.has(key)) fail(`fixture contains an unexpected key: ${key}`)
	}

	for (const operation of fixture.operations) {
		const keys = Object.keys(operation).sort().join(',')
		if (keys !== 'method,path') fail(`operation has unexpected fields: ${keys}`)
		if (!isHttpMethod(operation.method)) fail(`operation has a bad method: ${operation.method}`)
		if (!operation.path.startsWith('/')) fail(`operation path is not rooted: ${operation.path}`)
		if (/\(\?P<|\\/.test(operation.path)) fail(`operation path is not canonical: ${operation.path}`)
	}

	const serialised = JSON.stringify(fixture)
	for (const marker of [/"callback"/, /"args"/, /"_links"/, /basic /i, /password/i, /nonce/i]) {
		if (marker.test(serialised)) fail(`fixture contains forbidden content matching ${marker}`)
	}
}

async function buildFixture(options, capturedAt) {
	if (options.openapi) {
		const { version, operations, sha256 } = readOpenApi(options.openapi)
		return {
			schemaVersion: SCHEMA_VERSION,
			// No runtime was observed, so no runtime is claimed.
			profile: { wordpress: null, activeComponents: [{ slug: 'fluent-cart', version }] },
			capturedAt,
			evidenceKind: 'docs-contract',
			source: options.openapi,
			sourceSha256: sha256,
			operations,
			counts: {
				applicationCanonicalPairs: operations.length,
				applicationPaths: new Set(operations.map((entry) => entry.path)).size,
			},
		}
	}

	// Validate the profile before touching the network: a capture with no trustworthy provenance
	// is worthless however good the index turns out to be.
	const profile = readProfile(options.profile)
	const index = await fetchRestIndex(restRootUrl(options.restIndex))
	const { operations, counts, canonicalCollapses } = readRestIndex(index)

	return {
		schemaVersion: SCHEMA_VERSION,
		profile,
		capturedAt,
		evidenceKind: 'live-rest-index',
		source: 'sanitised-local-runtime',
		operations,
		counts,
		canonicalCollapses,
	}
}

const options = parseArgs(process.argv.slice(2))
const fixture = await buildFixture(options, new Date().toISOString())

assertSanitised(fixture)

const output = resolve(options.output)
mkdirSync(dirname(output), { recursive: true })
writeFileSync(output, `${JSON.stringify(fixture, null, 2)}\n`, 'utf8')

process.stdout.write(`Wrote ${fixture.operations.length} operations -> ${output}\n`)
