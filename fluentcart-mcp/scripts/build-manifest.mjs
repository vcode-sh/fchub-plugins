#!/usr/bin/env node
/**
 * Generate `manifest.json` for the MCPB bundle.
 *
 * The advertised inventory is read off the wire, not off the source: for every MEASURED profile in
 * `release-contract.json` a real in-memory MCP session is run and the `tools/list` result recorded,
 * so a name can only be advertised if some proven configuration actually lists it. A curated name
 * that no configuration resolves is dropped rather than promised. The MCPB v0.3 schema forbids
 * extra keys inside `tools[]`, so per-tool provenance and checksums live under `_meta`.
 */

import { createHash } from 'node:crypto'
import { existsSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { DESCRIPTION, LONG_DESCRIPTION, USER_CONFIG } from './manifest-config.mjs'
import { loadServerModule, MEASURED_MODES } from './measure-tool-context.mjs'

const PACKAGE_ROOT = dirname(dirname(fileURLToPath(import.meta.url)))
export const MANIFEST_PATH = join(PACKAGE_ROOT, 'manifest.json')
export const CONTRACT_PATH = join(PACKAGE_ROOT, 'release-contract.json')
export const META_NAMESPACE = 'sh.vcode.fluentcart-mcp'

function sha256(value) {
	return `sha256:${createHash('sha256').update(value).digest('hex')}`
}

/** Record the `tools/list` result exactly as the SDK hands it to the transport. */
async function collectWireTools(serverModule, mode) {
	const { Client } = await import('@modelcontextprotocol/client')
	const { InMemoryTransport } = await import('@modelcontextprotocol/server')

	const context = serverModule.resolveServerContext()
	const server = await serverModule.createServerFromContextAsync(context, mode)
	const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair()

	const sent = []
	const send = serverTransport.send.bind(serverTransport)
	serverTransport.send = async (message, options) => {
		sent.push(message)
		return send(message, options)
	}

	const client = new Client({ name: 'build-manifest', version: '1' }, { capabilities: {} })
	await Promise.all([server.connect(serverTransport), client.connect(clientTransport)])

	let cursor
	do {
		const page = await client.listTools(cursor === undefined ? {} : { cursor })
		cursor = page.nextCursor
	} while (cursor !== undefined)

	await client.close()
	await server.close()

	const tools = []
	for (const message of sent) {
		if (message.result && Array.isArray(message.result.tools)) tools.push(...message.result.tools)
	}
	return tools
}

/**
 * Modes whose listings define the inventory: the curated selection plus the dynamic and code-mode
 * meta-tools. Full mode is measured too, so a curated name records that full also exposes it, but
 * full never widens the inventory — advertising its whole registry is the claim this file exists
 * to stop making.
 */
export const INVENTORY_MODES = ['curated', 'dynamic', 'code']

/**
 * Walk every MEASURED profile and mode, recording where each name appears and what it looked like.
 * A definition that differs between two modes is a bug, not a rounding error, so it throws.
 */
async function collectAdvertisable(contract) {
	const serverModule = await loadServerModule()
	const seen = new Map()
	const inventory = new Set()

	for (const profile of contract.profiles) {
		if (profile.status !== 'MEASURED') continue
		process.env.FLUENTCART_WRITE_MODE = profile.writeMode

		for (const mode of MEASURED_MODES) {
			for (const tool of await collectWireTools(serverModule, mode)) {
				const definition = { description: tool.description ?? '', schema: JSON.stringify(tool.inputSchema ?? null) }
				const entry = seen.get(tool.name) ?? { ...definition, exposure: new Map() }
				if (entry.description !== definition.description || entry.schema !== definition.schema) {
					throw new Error(`Tool "${tool.name}" is advertised with two different definitions across modes.`)
				}
				const modes = entry.exposure.get(profile.name) ?? new Set()
				modes.add(mode)
				entry.exposure.set(profile.name, modes)
				seen.set(tool.name, entry)
				if (INVENTORY_MODES.includes(mode)) inventory.add(tool.name)
			}
		}
	}
	return { seen, inventory }
}

function toMetaTool(name, entry) {
	const exposedBy = [...entry.exposure.entries()]
		.map(([profile, modes]) => ({ profile, modes: [...modes].sort() }))
		.sort((left, right) => (left.profile < right.profile ? -1 : 1))

	// The mode a name first appears in is its provenance: dynamic and code register meta-tools of
	// their own, everything else reaching `tools/list` got there through the curated selection.
	const modes = new Set(exposedBy.flatMap((row) => row.modes))
	const provenance = modes.has('dynamic') ? 'dynamic-meta' : modes.has('code') ? 'code-meta' : 'curated'

	return {
		name,
		provenance,
		descriptionSha256: sha256(entry.description),
		inputSchemaSha256: sha256(entry.schema),
		exposedBy,
	}
}

export async function buildManifest() {
	const pkg = JSON.parse(readFileSync(join(PACKAGE_ROOT, 'package.json'), 'utf8'))
	if (!existsSync(CONTRACT_PATH)) {
		throw new Error('release-contract.json is missing; run scripts/build-release-contract.mjs first.')
	}
	const contract = JSON.parse(readFileSync(CONTRACT_PATH, 'utf8'))
	if (contract.packageVersion !== pkg.version) {
		throw new Error(`release-contract.json is at ${contract.packageVersion} but package.json is at ${pkg.version}.`)
	}

	const { seen, inventory } = await collectAdvertisable(contract)
	const names = [...inventory].sort()
	const metaTools = names.map((name) => toMetaTool(name, seen.get(name)))

	const userConfig = {}
	for (const entry of USER_CONFIG) {
		userConfig[entry.key] = {
			type: entry.type,
			title: entry.title,
			description: entry.description,
			required: entry.required,
			...(entry.default === undefined ? {} : { default: entry.default }),
			...(entry.sensitive === true ? { sensitive: true } : {}),
		}
	}

	const env = {}
	for (const entry of USER_CONFIG) env[entry.env] = `\${user_config.${entry.key}}`

	return {
		manifest_version: '0.3',
		name: pkg.name,
		display_name: 'FluentCart',
		version: pkg.version,
		description: DESCRIPTION,
		long_description: LONG_DESCRIPTION,
		author: { name: 'Vibe Code', email: 'hello@vcode.sh', url: 'https://vcode.sh' },
		license: pkg.license,
		homepage: 'https://fchub.co/docs/fluentcart-mcp',
		documentation: 'https://fchub.co/docs/fluentcart-mcp',
		repository: { type: 'git', url: 'https://github.com/vcode-sh/fchub-plugins' },
		keywords: pkg.keywords,
		server: {
			type: 'node',
			entry_point: 'dist/index.js',
			mcp_config: { command: 'node', args: ['${__dirname}/dist/index.js'], env },
		},
		compatibility: {
			platforms: ['darwin', 'win32', 'linux'],
			runtimes: { node: pkg.engines.node },
		},
		user_config: userConfig,
		// The listed names are what a proven configuration advertises; policy narrows that set at
		// runtime, so the inventory is indicative rather than a promise of availability.
		tools_generated: true,
		tools: names.map((name) => ({ name, description: seen.get(name).description })),
		_meta: {
			[META_NAMESPACE]: {
				generatedBy: 'scripts/build-manifest.mjs',
				packageVersion: pkg.version,
				sourceTreeDigest: contract.sourceTreeDigest,
				release: contract.release,
				serializer: contract.serializer,
				advertisedToolCount: names.length,
				userConfigEnvironment: Object.fromEntries(USER_CONFIG.map((e) => [e.key, e.env])),
				tools: metaTools,
			},
		},
	}
}

export function serializeManifest(manifest) {
	return `${JSON.stringify(manifest, null, 2)}\n`
}

if (process.argv[1] && pathToFileURL(process.argv[1]).href === import.meta.url) {
	const manifest = await buildManifest()
	const generated = serializeManifest(manifest)
	const advertised = manifest._meta[META_NAMESPACE].advertisedToolCount

	if (process.argv.includes('--check')) {
		const existing = existsSync(MANIFEST_PATH) ? readFileSync(MANIFEST_PATH, 'utf8') : null
		if (existing !== generated) {
			process.stdout.write('manifest.json is stale or missing; run scripts/build-manifest.mjs\n')
			process.exit(1)
		}
		process.stdout.write(`manifest.json is current at v${manifest.version} with ${advertised} advertised tools\n`)
	} else {
		writeFileSync(MANIFEST_PATH, generated)
		process.stdout.write(`wrote manifest.json at v${manifest.version} with ${advertised} advertised tools\n`)
	}
}
