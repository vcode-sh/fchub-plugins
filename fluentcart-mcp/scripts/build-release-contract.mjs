#!/usr/bin/env node
/**
 * Generate `release-contract.json` — the one generated truth every release surface reads.
 *
 * Every row measures the registry its own route fixture would produce, so the numbers differ by
 * profile. A row whose fixture is absent is emitted BLOCKED with the exact missing path; a count
 * is never invented. The digest covers a declared input list excluding this contract, since a
 * file cannot contain its own hash, and the Git SHA belongs in CI metadata for the same reason.
 */

import { existsSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join, sep } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { encode as encodeCl100k } from 'gpt-tokenizer/encoding/cl100k_base'
import { encode as encodeO200k } from 'gpt-tokenizer/encoding/o200k_base'
import {
	loadServerModule,
	MEASURED_MODES,
	serializeToolsList,
	SERIALIZER,
	TOKENIZER,
} from './measure-tool-context.mjs'
import {
	computeSourceTreeDigest,
	DIGEST_EXCLUDED,
	DIGEST_INPUTS,
	digestInputPaths,
} from './release-contract-inputs.mjs'
import { buildReleaseTruth } from './release-truth.mjs'

const PACKAGE_ROOT = dirname(dirname(fileURLToPath(import.meta.url)))
export const CONTRACT_PATH = join(PACKAGE_ROOT, 'release-contract.json')
export const WRITE_MODES = ['disabled', 'reversible']

const CURRENT_CORE_PRO = 'tests/fixtures/routes/fluentcart-1.6.0-core-pro-1.6.0.json'
const CURRENT_CORE_ONLY = 'tests/fixtures/routes/fluentcart-1.6.0-core.json'
const PREVIOUS_CORE_PRO = 'tests/fixtures/routes/fluentcart-1.5.5-core-pro-1.5.4.json'
const PREVIOUS_CORE_ONLY = 'tests/fixtures/routes/fluentcart-1.5.5-core.json'
const LEGACY_RUNTIME = 'tests/fixtures/routes/fluentcart-1.3.9-runtime.json'
const ABILITIES = 'tests/fixtures/abilities/fluentcart-1.6.0-wordpress-7.0.2.json'

function liveRow(name, componentFixture, writeMode) {
	const evidenceKind = 'live-rest-index'
	return { name, componentFixture, writeMode, evidenceKind, replaces: null }
}

/** Current release rows plus the captured legacy surfaces, each measured against its own fixture. */
export const PROFILES = [
	liveRow('legacy-1.3.9-runtime-rest-disabled', LEGACY_RUNTIME, 'disabled'),
	liveRow('core-1.5.5-rest-disabled', PREVIOUS_CORE_ONLY, 'disabled'),
	liveRow('core-1.5.5-pro-1.5.4-rest-disabled', PREVIOUS_CORE_PRO, 'disabled'),
	liveRow('core-1.5.5-pro-1.5.4-rest-reversible', PREVIOUS_CORE_PRO, 'reversible'),
	liveRow('core-1.6.0-rest-disabled', CURRENT_CORE_ONLY, 'disabled'),
	liveRow('core-1.6.0-pro-1.6.0-rest-disabled', CURRENT_CORE_PRO, 'disabled'),
	liveRow('core-1.6.0-pro-1.6.0-rest-reversible', CURRENT_CORE_PRO, 'reversible'),
]

async function importDist(...segments) {
	return import(pathToFileURL(join(PACKAGE_ROOT, 'dist', ...segments)).href)
}

/** The unfiltered registry, built against an unresolvable host. */
async function loadRegistry() {
	const { createAllTools } = await importDist('tools', 'index.js')
	const { createClient } = await importDist('api', 'client.js')
	const { resolveApiUrls } = await importDist('config', 'types.js')
	const fixture = { url: 'https://fixture.invalid', username: 'fixture', appPassword: 'fixture' }
	return createAllTools(createClient(resolveApiUrls(fixture)))
}

/** Registry-wide ceiling per write mode, with no route evidence applied. */
async function measureWritePolicyExposure(serverModule, registry) {
	const { canExposeTool } = await importDist('security', 'write-policy.js')

	// Asked of the server, not recomputed: two ways of counting one registry is one too many.
	const exposed = {}
	for (const writeMode of WRITE_MODES) {
		process.env.FLUENTCART_WRITE_MODE = writeMode
		exposed[writeMode] = serverModule.resolveServerContext().tools.length
	}

	const policy = { writeMode: 'reversible' }
	const fromModules = registry.filter((t) => canExposeTool(t.safety, policy)).length

	return {
		...exposed,
		// Definitions the server composes outside the tool modules, stated rather than absorbed.
		composedBeyondModules: exposed.reversible - fromModules,
		note: 'Exposure per write mode as the server composes it, independent of route evidence.',
	}
}

/**
 * What stops a row being measured, or null when nothing does. A documentation contract is never
 * runtime evidence, so a docs-contract row stays blocked whatever sits on disk.
 */
function blockerFor(profile) {
	if (profile.evidenceKind === 'docs-contract') {
		return { missingFixture: profile.replaces.componentFixture, reason: profile.replaces.reason }
	}
	for (const path of [profile.componentFixture]) {
		if (path && !existsSync(join(PACKAGE_ROOT, path.split('/').join(sep)))) {
			return { missingFixture: path, reason: 'declared fixture has never been captured' }
		}
	}
	return null
}

/**
 * Capability evidence from a profile's own route fixture. Without it every row measured the same
 * unpruned registry, and three profiles claimed a 1.3.9 store exposes what core-plus-Pro does.
 */
function capabilitiesFor(path, canonicaliseRoute) {
	const fixture = JSON.parse(readFileSync(join(PACKAGE_ROOT, path.split('/').join(sep)), 'utf8'))
	const operations = new Set(fixture.operations.map((e) => `${e.method} ${e.path}`))
	const has = (method, route) => operations.has(`${method} ${canonicaliseRoute(route)}`)
	return { has, operations, source: 'live-rest-index' }
}

/** The outgoing `tools/list` result for one mode, against supplied capability evidence. */
async function collectWireTools(serverModule, capabilities, mode) {
	const { Client } = await import('@modelcontextprotocol/client')
	const { InMemoryTransport } = await import('@modelcontextprotocol/server')
	const context = serverModule.resolveServerContext(capabilities)
	const server = await serverModule.createServerFromContextAsync(context, mode)
	const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair()

	const sent = []
	const send = serverTransport.send.bind(serverTransport)
	serverTransport.send = async (message, options) => (sent.push(message), send(message, options))

	const client = new Client({ name: 'build-release-contract', version: '1' }, { capabilities: {} })
	await Promise.all([server.connect(serverTransport), client.connect(clientTransport)])
	let cursor
	do {
		cursor = (await client.listTools(cursor === undefined ? {} : { cursor })).nextCursor
	} while (cursor !== undefined)
	await client.close()
	await server.close()

	return sent.flatMap((m) => (Array.isArray(m.result?.tools) ? m.result.tools : []))
}

async function measureProfile(serverModule, profile, capabilities) {
	process.env.FLUENTCART_WRITE_MODE = profile.writeMode
	const exposedDefinitionCount = serverModule.resolveServerContext(capabilities).tools.length

	const modes = {}
	for (const mode of MEASURED_MODES) {
		const tools = await collectWireTools(serverModule, capabilities, mode)
		const text = serializeToolsList(tools)
		const counts = { cl100kTokens: encodeCl100k(text).length, o200kTokens: encodeO200k(text).length }
		modes[mode] = { toolCount: tools.length, characters: text.length, ...counts }
	}
	return { exposedDefinitionCount, modes }
}

function abilityBridgeEvidence() {
	const fixture = JSON.parse(readFileSync(join(PACKAGE_ROOT, ABILITIES), 'utf8'))
	const support = JSON.parse(readFileSync(join(PACKAGE_ROOT, 'compatibility-support.json'), 'utf8'))
	const parity = support.abilityParity
	const capturedNames = fixture.abilities.map((ability) => ability.name).sort()
	const parityNames = parity.rows.map((row) => row.ability).sort()
	if (JSON.stringify(capturedNames) !== JSON.stringify(parityNames)) {
		throw new Error('ability parity ledger does not match the captured FluentCart catalogue')
	}
	const readCount = fixture.abilities.filter(
		(ability) => ability.annotations.mcpReadOnlyHint === true,
	).length
	if (
		fixture.abilities.length !== parity.capturedCatalogueSize ||
		readCount !== parity.auditedReadCount
	) {
		throw new Error('ability parity counts do not match the captured FluentCart catalogue')
	}
	return {
		status: 'MEASURED',
		evidence: 'live-wordpress-abilities-rest',
		fixture: ABILITIES,
		wordpress: fixture.profile.wordpress,
		components: Object.fromEntries(
			fixture.profile.activeComponents.map((component) => [component.slug, component.version]),
		),
		capturedCatalogueSize: fixture.abilities.length,
		auditedReadCount: readCount,
		writeCount: fixture.abilities.length - readCount,
		adapter: fixture.adapter,
		executionMethod: parity.executionMethod,
		executionMethodEvidence: parity.executionMethodEvidence,
	}
}

export async function buildContract() {
	const pkg = JSON.parse(readFileSync(join(PACKAGE_ROOT, 'package.json'), 'utf8'))
	const packageLock = JSON.parse(readFileSync(join(PACKAGE_ROOT, 'package-lock.json'), 'utf8'))
	const paths = digestInputPaths()

	const serverModule = await loadServerModule()
	const { canonicaliseRoute } = await importDist('api', 'route-normalisation.js')
	const registry = await loadRegistry()
	const { CATEGORIES } = await importDist('tools', 'dynamic-search.js')
	const { CURATED_TOOL_NAMES } = await importDist('tools', 'curated.js')
	const registryNames = new Set(registry.map((tool) => tool.name))
	const release = buildReleaseTruth(pkg, null, packageLock)

	const profiles = []
	for (const head of PROFILES) {
		const blocker = blockerFor(head)
		if (blocker) {
			const blocked = { status: 'BLOCKED', ...blocker, exposedDefinitionCount: null, modes: null }
			profiles.push({ ...head, ...blocked })
			continue
		}
		const measured = await measureProfile(serverModule, head, capabilitiesFor(head.componentFixture, canonicaliseRoute))
		profiles.push({ ...head, status: 'MEASURED', missingFixture: null, reason: null, ...measured })
	}

	return {
		generatedBy: 'scripts/build-release-contract.mjs',
		serializer: SERIALIZER,
		tokenizer: TOKENIZER,
		packageVersion: pkg.version,
		release,
		sourceTreeDigest: computeSourceTreeDigest(paths),
		sourceTreeInputs: { fileCount: paths.length, declared: DIGEST_INPUTS, excluded: DIGEST_EXCLUDED },
		sourceDefinitionCount: registry.length,
		categoryCount: CATEGORIES.length,
		writePolicyExposure: await measureWritePolicyExposure(serverModule, registry),
		curatedNames: {
			declared: CURATED_TOOL_NAMES.length,
			resolvable: CURATED_TOOL_NAMES.filter((name) => registryNames.has(name)).length,
			unresolved: CURATED_TOOL_NAMES.filter((name) => !registryNames.has(name)),
		},
		capabilityFiltering: {
			appliedToToolRegistry: true,
			note: 'Discovered routes prune the registry before registration: a direct tool needs one declared variant served, a composite needs every route it may call, and a tool with no supported route is not registered at all. Each profile row below is measured under its own fixture, so the counts differ by store.',
		},
		abilitiesBridge: abilityBridgeEvidence(),
		legacyRuntimeSupport: {
			status: 'ROUTE-SURFACE-CAPTURED',
			evidence: 'live-rest-index',
			routeSurfaceProven: true,
			runtimeFixture: LEGACY_RUNTIME,
			// The capture proves which routes a 1.3.9 store serves. It does not prove our tools work
			// against it, because discovery does not prune the registry: the measured row reports the
			// same tools as every other disabled-mode row whatever the fixture contains.
			toolCompatibilityProven: false,
			claimPolicy:
				'Documentation may state that the 1.3.9 route surface is captured, and that pruning leaves an older store a smaller tool list. It may not claim 1.3.9 runtime support until a tool-level compatibility check passes: a smaller list is evidence that pruning works, not that the surviving tools have been exercised against that build.',
		},
		profiles,
	}
}

if (process.argv[1] && pathToFileURL(process.argv[1]).href === import.meta.url) {
	const contract = await buildContract()
	const generated = `${JSON.stringify(contract, null, 2)}\n`

	if (process.argv.includes('--check')) {
		const existing = existsSync(CONTRACT_PATH) ? readFileSync(CONTRACT_PATH, 'utf8') : null
		if (existing !== generated) {
			process.stdout.write('release-contract.json is stale or missing; run scripts/build-release-contract.mjs\n')
			process.exit(1)
		}
		process.stdout.write(`release-contract.json is current at ${contract.sourceTreeDigest}\n`)
	} else {
		writeFileSync(CONTRACT_PATH, generated)
		process.stdout.write(`wrote release-contract.json at ${contract.sourceTreeDigest}\n`)
	}

	for (const profile of contract.profiles.filter((entry) => entry.status === 'BLOCKED')) {
		process.stdout.write(`  BLOCKED ${profile.name} — missing ${profile.missingFixture}\n`)
	}
}
