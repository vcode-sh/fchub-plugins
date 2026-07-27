#!/usr/bin/env node
/**
 * Source-to-route contract for the tool registry, and the compatibility profile gate.
 *
 * `--fixture` asks whether every tool points at an endpoint the selected FluentCart actually
 * serves; `--compatibility` asks whether we hold evidence for each profile we claim to support.
 * A tool whose route was guessed and a profile whose fixture was never captured are the same
 * defect wearing different hats. Every assertion is made against a captured fixture; nothing is
 * inferred from the HTTP verb, the tool name or the shape of the path.
 */

import { existsSync, readFileSync } from 'node:fs'
import { dirname, join, relative } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

export const PACKAGE_ROOT = dirname(dirname(fileURLToPath(import.meta.url)))
export const SUPPORT_PATH = join(PACKAGE_ROOT, 'compatibility-support.json')

const FIXTURE_ENV = {
	FLUENTCART_URL: 'https://fixture.invalid',
	FLUENTCART_USERNAME: 'fixture',
	FLUENTCART_APP_PASSWORD: 'fixture',
	FLUENTCART_WRITE_MODE: 'guarded',
	FLUENTCART_GUARD_SECRET: 'compatibility-fixture-guard-secret-never-signs',
	FLUENTCART_GUARD_STATE_DIR: join(PACKAGE_ROOT, '.compatibility-fixture-guard-state'),
}

function applyFixtureEnv() {
	for (const key of Object.keys(process.env)) {
		if (key.startsWith('FLUENTCART_')) delete process.env[key]
	}
	Object.assign(process.env, FIXTURE_ENV)
}

export function readJson(path) {
	return JSON.parse(readFileSync(path, 'utf8'))
}

/** The canonical fixture form collapses every path parameter to `{param}`. Anything else was
 * never resolved against a real endpoint, so it cannot be matched and must not read as absent. */
export function unresolvedPlaceholder(path) {
	// Template syntax is checked first: `${id}` also matches the brace scan below, and reporting
	// it as a stray `{id}` would send the reader looking for the wrong mistake.
	if (/\$\{|<[a-z_]+>/i.test(path)) return 'template expression'
	const bad = (path.match(/\{[^}]*\}/g) ?? []).find((token) => token !== '{param}')
	if (bad) return bad
	if (/(^|\/):[A-Za-z_]/.test(path)) return path.match(/:[A-Za-z_]\w*/)[0]
	return null
}

function fixtureIndex(fixture) {
	const exact = new Set()
	const byPath = new Map()
	for (const { method, path } of fixture.operations) {
		exact.add(`${method} ${path}`)
		if (!byPath.has(path)) byPath.set(path, new Set())
		byPath.get(path).add(method)
	}
	return { exact, byPath }
}

/** Metadata is required: "no route recorded" and "no route needed" are different claims, and
 * only one of them is safe, so an undeclared tool fails rather than passes. */
export function checkToolRoutes(tool, index) {
	const failures = []
	const route = tool.route
	const add = (rule, detail) => failures.push({ tool: tool.name, rule, detail })

	if (!route || typeof route !== 'object') {
		add('missing-route-metadata', 'no ToolRouteMetadata declared on the tool definition')
		return { failures, kind: null }
	}

	const routes = Array.isArray(route.routes) ? route.routes : []
	const composite = route.composite === true
	if (route.unsupported === true) {
		if (routes.length > 0) add('unsupported-with-routes', 'declared unsupported but still lists routes')
		return { failures, kind: 'unsupported' }
	}

	if (routes.length === 0) {
		add('no-route-declared', 'neither a route nor an explicit unsupported declaration')
		return { failures, kind: null }
	}
	if (routes.length > 1 && !composite) {
		// More than one route without a composite declaration is a run-time fallback chain. For a
		// write that means the first endpoint may already have succeeded before the retry fires.
		const rule = tool.safety?.risk === 'read' ? 'undeclared-composite' : 'write-fallback-routes'
		add(rule, `${routes.length} routes declared without composite: true`)
	}
	if (composite && routes.length < 2) {
		add('composite-without-routes', 'composite: true but fewer than two routes listed')
	}

	for (const endpoint of routes) {
		const path = String(endpoint?.path ?? '')
		const method = String(endpoint?.method ?? '').toUpperCase()
		const placeholder = unresolvedPlaceholder(path)

		if (placeholder) {
			add('unresolved-placeholder', `${method} ${path} contains ${placeholder}`)
			continue
		}
		if (index.exact.has(`${method} ${path}`)) continue

		const served = index.byPath.get(path)
		if (served) add('method-mismatch', `${method} ${path} is not served; fixture has ${[...served].sort().join(', ')}`)
		else add('absent-from-fixture', `${method} ${path} is absent from the selected fixture`)
	}

	return { failures, kind: composite ? 'composite' : 'direct' }
}

const distUrl = (...parts) => pathToFileURL(join(PACKAGE_ROOT, 'dist', ...parts)).href

async function loadRegistry() {
	applyFixtureEnv()
	const server = await import(distUrl('server.js'))
	const tools = await import(distUrl('tools', 'index.js'))
	const context = server.resolveServerContext()
	return { all: tools.createAllTools(context.client), registered: new Set(context.tools.map((t) => t.name)) }
}

export async function checkRoutes(fixturePath) {
	const absolute = fixturePath.startsWith('/') ? fixturePath : join(PACKAGE_ROOT, fixturePath)
	if (!existsSync(absolute)) throw new Error(`fixture not found: ${fixturePath}`)

	const fixture = readJson(absolute)
	const index = fixtureIndex(fixture)
	const { all, registered } = await loadRegistry()
	const names = { registered: [], omitted: [], direct: [], composite: [], unsupported: [] }
	const failures = []
	for (const tool of all) {
		names[registered.has(tool.name) ? 'registered' : 'omitted'].push(tool.name)
		const result = checkToolRoutes(tool, index)
		failures.push(...result.failures)
		if (result.kind) names[result.kind].push(tool.name)
	}

	for (const list of Object.values(names)) list.sort()
	const counts = Object.fromEntries(Object.entries(names).map(([key, list]) => [key, list.length]))

	const fixtureOperations = fixture.operations.length
	const { profile, evidenceKind } = fixture
	return { fixture: relative(PACKAGE_ROOT, absolute), profile, evidenceKind, fixtureOperations, counts, tools: names, failures }
}

/** Compare one profile's fixture against what the support file says it must prove. */
function checkProfile(profile, legacyRuntime) {
	if (profile.checkedWhen !== undefined && profile.checkedWhen !== legacyRuntime) {
		return {
			id: profile.id,
			status: 'SKIPPED',
			note: `only checked while legacyRuntime is "${profile.checkedWhen}"; it is "${legacyRuntime}"`,
			problems: [],
		}
	}

	const absolute = join(PACKAGE_ROOT, profile.fixture)
	if (!existsSync(absolute)) {
		return {
			id: profile.id,
			status: 'BLOCKED',
			note: `fixture missing: ${profile.fixture}`,
			remediation: profile.capture ?? null,
			problems: [],
		}
	}

	const fixture = readJson(absolute)
	const expect = profile.expect ?? {}
	const problems = []
	const compare = (label, actual, wanted) => {
		if (wanted !== undefined && actual !== wanted) problems.push(`${label} is ${actual}, expected ${wanted}`)
	}

	compare('evidenceKind', fixture.evidenceKind, profile.evidenceKind)
	compare('sourceSha256', fixture.sourceSha256, expect.sourceSha256)
	compare(
		'applicationCanonicalPairs',
		fixture.counts?.applicationCanonicalPairs,
		expect.applicationCanonicalPairs,
	)

	const declared = fixture.profile?.activeComponents ?? []
	const components = new Map(declared.map((entry) => [entry.slug, entry.version]))
	for (const [slug, version] of Object.entries(expect.components ?? {})) {
		if (components.get(slug) !== version) {
			problems.push(`${slug} is ${components.get(slug) ?? 'absent'}, expected ${version}`)
		}
	}
	if (expect.exclusiveComponents) {
		const extra = [...components.keys()].filter((slug) => !expect.exclusiveComponents.includes(slug))
		if (extra.length > 0) problems.push(`capture was not isolated; also active: ${extra.sort().join(', ')}`)
	}

	if (problems.length > 0) return { id: profile.id, status: 'FAILED', problems }
	if (profile.isolationProven === false) {
		return { id: profile.id, status: 'ATTESTED', note: profile.isolationNote, problems: [] }
	}
	return { id: profile.id, status: 'MEASURED', problems: [] }
}

/** While the legacy runtime is unproven, nothing we ship may claim it was tested. */
function checkLegacyClaims({ legacyClaimScan: { files, version } }) {
	const claim = /\b(support|supported|supports|compatible|compatibility|tested|works with)\b/i
	const problems = []

	for (const file of files) {
		const path = join(PACKAGE_ROOT, file)
		if (!existsSync(path)) continue
		const lines = readFileSync(path, 'utf8').split('\n')
		for (const [index, line] of lines.entries()) {
			if (!line.includes(version) || !claim.test(line)) continue
			problems.push(`${file}:${index + 1} claims ${version} support: ${line.trim().slice(0, 100)}`)
		}
	}
	return problems
}

export function checkCompatibility() {
	const support = readJson(SUPPORT_PATH)
	const results = support.profiles.map((profile) => checkProfile(profile, support.legacyRuntime))
	const legacyClaims = support.legacyRuntime === 'tested' ? [] : checkLegacyClaims(support)
	const delta = verifySpecificationDelta(support)
	const blocked = results.some((r) => r.status === 'BLOCKED' || r.status === 'FAILED')
	const ok = !blocked && legacyClaims.length === 0 && delta.problems.length === 0
	return { legacyRuntime: support.legacyRuntime, profiles: results, legacyClaims, specificationDelta: delta, ok }
}

/** The recorded delta is a measurement, so it is recomputed rather than trusted. */
function verifySpecificationDelta(support) {
	const declared = support.specificationDelta
	const find = (id) => support.profiles.find((profile) => profile.id === id)
	const baseline = find(declared.baseline)
	const current = find(declared.current)
	const paths = [baseline, current].map((profile) => join(PACKAGE_ROOT, profile.fixture))

	if (paths.some((path) => !existsSync(path))) {
		return { status: 'BLOCKED', problems: [], note: 'a delta fixture is missing' }
	}

	const key = (o) => `${o.method} ${o.path}`
	const [before, after] = paths.map((path) => new Set(readJson(path).operations.map(key)))
	const actual = {
		retained: [...before].filter((key) => after.has(key)).length,
		stale: [...before].filter((key) => !after.has(key)).length,
		currentAbsentFromSpecification: [...after].filter((key) => !before.has(key)).length,
	}
	const problems = Object.entries(actual)
		.filter(([key, value]) => declared[key] !== value)
		.map(([key, value]) => `${key} is ${value}, recorded as ${declared[key]}`)

	return { status: problems.length === 0 ? 'MEASURED' : 'FAILED', ...actual, problems }
}

if (process.argv[1] && pathToFileURL(process.argv[1]).href === import.meta.url) {
	const argv = process.argv.slice(2)

	if (argv.includes('--compatibility')) {
		const result = checkCompatibility()
		process.stdout.write(`${JSON.stringify(result, null, 2)}\n`)
		for (const profile of result.profiles) {
			const capture = profile.remediation ? `\n      capture with: ${profile.remediation}` : ''
			const note = profile.note ? ` — ${profile.note}` : ''
			process.stderr.write(`${profile.status.padEnd(9)} ${profile.id}${note}${capture}\n`)
		}
		for (const claim of result.legacyClaims) process.stderr.write(`CLAIM     ${claim}\n`)
		process.exit(result.ok ? 0 : 1)
	}

	const fixture = argv[argv.indexOf('--fixture') + 1]
	if (!argv.includes('--fixture') || !fixture) {
		process.stderr.write('usage: check-tool-routes.mjs --fixture <path> | --compatibility\n')
		process.exit(2)
	}

	const result = await checkRoutes(fixture)
	process.stdout.write(`${JSON.stringify(result, null, 2)}\n`)
	const tally = Object.entries(result.counts).map(([key, value]) => `${value} ${key}`)
	process.stderr.write(`${result.fixture}: ${tally.join(', ')}, ${result.failures.length} failures\n`)
	process.exit(result.failures.length === 0 ? 0 : 1)
}
