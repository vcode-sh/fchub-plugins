#!/usr/bin/env node
/**
 * Inspect a packed MCPB bundle as an archive, before anyone uploads it.
 *
 * The central directory is parsed directly rather than shelling out to `unzip`, because the
 * checks that matter most — symlinks and path traversal — live in fields a listing does not
 * show. A bundle is judged on what it actually contains, not on what the build script intended.
 */

import { readFileSync } from 'node:fs'
import { basename, dirname, join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { inflateRawSync } from 'node:zlib'
import {
	expectedReleaseIdentity,
	PROVENANCE_PATH,
	releaseIdentityFailures,
} from './release-identity.mjs'

const PACKAGE_ROOT = dirname(dirname(fileURLToPath(import.meta.url)))

const EOCD_SIGNATURE = 0x06054b50
const CENTRAL_SIGNATURE = 0x02014b50
const LOCAL_SIGNATURE = 0x04034b50
const UNIX_SYMLINK = 0xa000

/** Applied to every path, vendored or not: a credential must never ship at all. */
const CREDENTIAL_PATTERNS = [/(^|\/)\.env($|\.|\/)/, /(^|\/)\.npmrc$/, /\.(pem|key|p12|pfx)$/]

/**
 * Applied only outside `node_modules/`. Third-party packages ship their own maps and test
 * fixtures and we do not get a vote; our own tree shipping them is a packaging defect.
 */
const OWN_TREE_PATTERNS = [
	/\.map$/,
	/(^|\/)(tests?|__tests__)\//,
	/\.(test|spec)\.[cm]?[jt]s$/,
	/(^|\/)coverage\//,
	/(^|\/)\.git($|\/)/,
	/(^|\/)(vitest|biome|tsconfig.*)\.(json|jsonc|ts)$/,
]
const FORBIDDEN_MODULES = [
	'node_modules/@modelcontextprotocol/sdk/',
	'node_modules/@modelcontextprotocol/conformance/',
]
const SDK_V2 = [
	'@modelcontextprotocol/server',
	'@modelcontextprotocol/node',
	'@modelcontextprotocol/express',
]

export function readCentralDirectory(buffer) {
	let eocd = -1
	for (let offset = buffer.length - 22; offset >= 0; offset -= 1) {
		if (buffer.readUInt32LE(offset) === EOCD_SIGNATURE) {
			eocd = offset
			break
		}
	}
	if (eocd < 0) throw new Error('not a ZIP archive: no end-of-central-directory record')

	const total = buffer.readUInt16LE(eocd + 10)
	let cursor = buffer.readUInt32LE(eocd + 16)
	const entries = []

	for (let index = 0; index < total; index += 1) {
		if (buffer.readUInt32LE(cursor) !== CENTRAL_SIGNATURE) {
			throw new Error(`corrupt central directory at entry ${index}`)
		}
		const nameLength = buffer.readUInt16LE(cursor + 28)
		const extraLength = buffer.readUInt16LE(cursor + 30)
		const commentLength = buffer.readUInt16LE(cursor + 32)
		entries.push({
			name: buffer.toString('utf8', cursor + 46, cursor + 46 + nameLength),
			compression: buffer.readUInt16LE(cursor + 10),
			compressedSize: buffer.readUInt32LE(cursor + 20),
			externalAttributes: buffer.readUInt32LE(cursor + 38),
			localOffset: buffer.readUInt32LE(cursor + 42),
		})
		cursor += 46 + nameLength + extraLength + commentLength
	}
	return entries
}

export function isSymlink(entry) {
	return ((entry.externalAttributes >>> 16) & 0xf000) === UNIX_SYMLINK
}

export function readEntry(buffer, entry) {
	if (buffer.readUInt32LE(entry.localOffset) !== LOCAL_SIGNATURE) {
		throw new Error(`corrupt local header for ${entry.name}`)
	}
	const nameLength = buffer.readUInt16LE(entry.localOffset + 26)
	const extraLength = buffer.readUInt16LE(entry.localOffset + 28)
	const start = entry.localOffset + 30 + nameLength + extraLength
	const raw = buffer.subarray(start, start + entry.compressedSize)
	return entry.compression === 0 ? raw : inflateRawSync(raw)
}

export function unsafePath(name) {
	if (name.startsWith('/') || /^[a-zA-Z]:[\\/]/.test(name)) return 'absolute path'
	if (name.split(/[\\/]/).includes('..')) return 'parent traversal'
	return null
}

function readJsonEntry(buffer, entries, name) {
	const entry = entries.find((candidate) => candidate.name === name)
	if (!entry) return null
	return JSON.parse(readEntry(buffer, entry).toString('utf8'))
}

/**
 * The manifest may advertise only what the release contract can account for: the curated names
 * it could resolve plus the dynamic and code-mode meta-tools. A bundle that promises more tools
 * than the contract measured is advertising tools nobody proved exist.
 */
function checkToolInventory(manifest, contract, fail) {
	const advertised = Array.isArray(manifest.tools) ? manifest.tools.length : 0
	const meta = manifest._meta?.['sh.vcode.fluentcart-mcp'] ?? {}

	if (meta.advertisedToolCount !== undefined && meta.advertisedToolCount !== advertised) {
		fail(`manifest claims ${meta.advertisedToolCount} tools but lists ${advertised}`)
	}

	const measured = contract.profiles.filter((profile) => profile.modes)
	const metaTools = measured.reduce(
		(most, profile) =>
			Math.max(most, (profile.modes.dynamic?.toolCount ?? 0) + (profile.modes.code?.toolCount ?? 0)),
		0,
	)
	const expected = (contract.curatedNames?.resolvable ?? 0) + metaTools
	if (expected > 0 && advertised !== expected) {
		fail(`manifest advertises ${advertised} tools, release contract accounts for ${expected}`)
	}
	// `200+` cannot use a trailing \b: the boundary after a non-word `+` never matches.
	if (/\b(274|279)\b|200\+/.test(manifest.description ?? '')) {
		fail('manifest description states a stale tool count')
	}
}

export function inspectMcpb(archivePath, options = {}) {
	const buffer = readFileSync(archivePath)
	const entries = readCentralDirectory(buffer)
	const failures = []
	const fail = (message) => failures.push(message)
	const names = entries.map((entry) => entry.name)

	for (const entry of entries) {
		const unsafe = unsafePath(entry.name)
		if (unsafe) fail(`${unsafe}: ${entry.name}`)
		if (isSymlink(entry)) fail(`symlink: ${entry.name}`)
		if (CREDENTIAL_PATTERNS.some((pattern) => pattern.test(entry.name))) {
			fail(`credential material: ${entry.name}`)
		}
		if (FORBIDDEN_MODULES.some((path) => entry.name.startsWith(path))) {
			fail(`legacy or conformance-only module: ${entry.name}`)
		}
		if (entry.name.startsWith('node_modules/')) continue
		if (OWN_TREE_PATTERNS.some((pattern) => pattern.test(entry.name))) {
			fail(`must not ship: ${entry.name}`)
		}
	}

	if (!names.includes('dist/index.js')) fail('missing dist/index.js')
	if (!names.includes('manifest.json')) fail('missing manifest.json')

	const manifest = readJsonEntry(buffer, entries, 'manifest.json')
	const packed = readJsonEntry(buffer, entries, 'package.json')
	const pkg = JSON.parse(readFileSync(join(PACKAGE_ROOT, 'package.json'), 'utf8'))
	const contract = JSON.parse(readFileSync(join(PACKAGE_ROOT, 'release-contract.json'), 'utf8'))
	const provenance = readJsonEntry(buffer, entries, PROVENANCE_PATH)

	if (manifest && manifest.version !== pkg.version) {
		fail(`manifest version ${manifest.version} does not match package ${pkg.version}`)
	}
	if (packed && packed.version !== pkg.version) {
		fail(`bundled package.json version ${packed.version} does not match ${pkg.version}`)
	}
	if (packed?.engines?.node !== '>=24.0.0') fail('bundled package requires an unexpected Node engine')
	for (const name of SDK_V2) {
		if (packed?.dependencies?.[name] !== '2.0.0') fail(`${name} is not pinned to 2.0.0`)
		const installed = readJsonEntry(buffer, entries, `node_modules/${name}/package.json`)
		if (!installed) fail(`missing installed ${name} package.json`)
		else if (installed.name !== name || installed.version !== '2.0.0') {
			fail(`${name} installed version ${installed.version ?? 'missing'}, expected 2.0.0`)
		}
	}
	if (packed?.dependencies?.['@modelcontextprotocol/sdk']) {
		fail('legacy @modelcontextprotocol/sdk is a direct runtime dependency')
	}
	if (packed?.dependencies?.['@modelcontextprotocol/conformance']) {
		fail('@modelcontextprotocol/conformance is a runtime dependency')
	}
	if (manifest && manifest.server?.entry_point !== 'dist/index.js') {
		fail(`manifest entry_point is ${manifest.server?.entry_point}, expected dist/index.js`)
	}

	for (const name of Object.keys(packed?.devDependencies ?? {})) {
		if (names.some((entry) => entry.startsWith(`node_modules/${name}/`))) {
			fail(`development dependency shipped: ${name}`)
		}
	}

	if (manifest) checkToolInventory(manifest, contract, fail)
	for (const finding of releaseIdentityFailures(
		provenance,
		options.expectedIdentity ?? expectedReleaseIdentity(),
		options.invocationId,
	)) {
		fail(finding)
	}

	return { archive: basename(archivePath), entryCount: entries.length, provenance, failures }
}

if (process.argv[1] && pathToFileURL(process.argv[1]).href === import.meta.url) {
	const target = process.argv[2]
	if (!target) {
		process.stderr.write('usage: node scripts/inspect-mcpb.mjs <path-to.mcpb>\n')
		process.exit(2)
	}
	const result = inspectMcpb(target)
	process.stdout.write(`${JSON.stringify(result, null, 2)}\n`)
	if (result.failures.length > 0) process.exit(1)
	process.stderr.write(`${result.archive}: ${result.entryCount} entries, no findings\n`)
}
