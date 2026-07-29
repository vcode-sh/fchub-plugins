#!/usr/bin/env node
/**
 * Inspect the npm tarball that publication will upload.
 *
 * The tarball is opened and read rather than trusted: it must satisfy the package `files`
 * allowlist, carry the expected version inside its own `package.json`, and contain no tests,
 * credentials, maps or undeclared generated files. The recorded SHA-256 is the digest a publish
 * job must consume, so what is inspected and what is published cannot drift apart.
 */

import { createHash } from 'node:crypto'
import { readFileSync } from 'node:fs'
import { basename, dirname, join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { gunzipSync } from 'node:zlib'
import {
	expectedReleaseIdentity,
	PROVENANCE_PATH,
	releaseIdentityFailures,
} from './release-identity.mjs'

const PACKAGE_ROOT = dirname(dirname(fileURLToPath(import.meta.url)))
const BLOCK = 512

/** npm always adds these regardless of the `files` allowlist. */
const ALWAYS_ALLOWED = ['package.json', 'README.md', 'LICENSE']

const FORBIDDEN_PATTERNS = [
	{ pattern: /\.map$/, reason: 'source or declaration map' },
	{ pattern: /(^|\/)(tests?|__tests__)\//, reason: 'test directory' },
	{ pattern: /\.(test|spec)\.[cm]?[jt]s$/, reason: 'test file' },
	{ pattern: /(^|\/)coverage\//, reason: 'coverage output' },
	{ pattern: /(^|\/)\.env($|\.|\/)/, reason: 'environment file' },
	{ pattern: /(^|\/)\.npmrc$/, reason: 'npm credentials' },
	{ pattern: /\.(pem|key|p12|pfx)$/, reason: 'credential material' },
	{ pattern: /(^|\/)(vitest|biome)\.[a-z]*$/, reason: 'development config' },
	{ pattern: /(^|\/)tsconfig[^/]*\.json$/, reason: 'development config' },
	{ pattern: /(^|\/)\.git($|\/)/, reason: 'repository metadata' },
	{
		pattern: /(^|\/)node_modules\/@modelcontextprotocol\/(sdk|conformance)\//,
		reason: 'legacy or conformance-only module',
	},
]

const SDK_V2 = [
	'@modelcontextprotocol/server',
	'@modelcontextprotocol/node',
	'@modelcontextprotocol/express',
]

/** Minimal ustar reader: enough to list entries, spot symlinks and read a small file. */
export function readTar(buffer) {
	const entries = []
	let offset = 0

	while (offset + BLOCK <= buffer.length) {
		const header = buffer.subarray(offset, offset + BLOCK)
		const name = header.toString('utf8', 0, 100).replace(/\0.*$/, '')
		if (name === '') break

		const sizeField = header.toString('utf8', 124, 136).replace(/\0.*$/, '').trim()
		const size = Number.parseInt(sizeField, 8) || 0
		const typeFlag = header.toString('utf8', 156, 157)
		const prefix = header.toString('utf8', 345, 500).replace(/\0.*$/, '')
		const start = offset + BLOCK

		entries.push({
			name: prefix === '' ? name : `${prefix}/${name}`,
			typeFlag,
			size,
			linkName: header.toString('utf8', 157, 257).replace(/\0.*$/, ''),
			data: buffer.subarray(start, start + size),
		})
		offset = start + Math.ceil(size / BLOCK) * BLOCK
	}
	return entries
}

export function unsafePath(name) {
	if (name.startsWith('/') || /^[a-zA-Z]:[\\/]/.test(name)) return 'absolute path'
	if (name.split(/[\\/]/).includes('..')) return 'parent traversal'
	return null
}

/** Every path inside an npm tarball is prefixed `package/`; strip it before matching rules. */
function withoutPrefix(name) {
	return name.startsWith('package/') ? name.slice('package/'.length) : name
}

export function inspectNpmPack(archivePath, options = {}) {
	const raw = readFileSync(archivePath)
	const entries = readTar(gunzipSync(raw))
	const failures = []
	const fail = (message) => failures.push(message)

	const pkg = JSON.parse(readFileSync(join(PACKAGE_ROOT, 'package.json'), 'utf8'))
	const allowedRoots = [...(pkg.files ?? []), ...ALWAYS_ALLOWED]
	const files = entries.filter((entry) => entry.typeFlag !== '5')

	for (const entry of entries) {
		const unsafe = unsafePath(entry.name)
		if (unsafe) fail(`${unsafe}: ${entry.name}`)
		if (entry.typeFlag === '1' || entry.typeFlag === '2') {
			fail(`symlink: ${entry.name} -> ${entry.linkName}`)
		}

		const relative = withoutPrefix(entry.name)
		if (!entry.name.startsWith('package/')) fail(`entry outside package/: ${entry.name}`)
		if (entry.typeFlag === '5') continue

		for (const { pattern, reason } of FORBIDDEN_PATTERNS) {
			if (pattern.test(relative)) fail(`${reason}: ${relative}`)
		}

		const root = relative.split('/')[0]
		if (!allowedRoots.includes(root) && !allowedRoots.includes(relative)) {
			fail(`not covered by the package files allowlist: ${relative}`)
		}
	}

	const packed = files.find((entry) => withoutPrefix(entry.name) === 'package.json')
	if (!packed) {
		fail('missing package.json')
	} else {
		const parsed = JSON.parse(packed.data.toString('utf8'))
		if (parsed.version !== pkg.version) {
			fail(`tarball package.json version ${parsed.version} does not match ${pkg.version}`)
		}
		if (parsed.engines?.node !== '>=24.0.0') fail('package requires an unexpected Node engine')
		for (const name of SDK_V2) {
			if (parsed.dependencies?.[name] !== '2.0.0') fail(`${name} is not pinned to 2.0.0`)
		}
		if (parsed.dependencies?.['@modelcontextprotocol/sdk']) {
			fail('legacy @modelcontextprotocol/sdk is a direct runtime dependency')
		}
		if (parsed.dependencies?.['@modelcontextprotocol/conformance']) {
			fail('@modelcontextprotocol/conformance is a runtime dependency')
		}
		// `devDependencies` stays in a published manifest — consumers never install it. What must
		// not appear is dev dependency *code*, which the files allowlist check above covers.
	}

	if (!files.some((entry) => withoutPrefix(entry.name) === 'dist/index.js')) {
		fail('missing dist/index.js')
	}

	const provenanceEntry = files.find((entry) => withoutPrefix(entry.name) === PROVENANCE_PATH)
	let provenance = null
	if (provenanceEntry) {
		try {
			provenance = JSON.parse(provenanceEntry.data.toString('utf8'))
		} catch {
			fail(`invalid JSON in ${PROVENANCE_PATH}`)
		}
	}
	for (const finding of releaseIdentityFailures(
		provenance,
		options.expectedIdentity ?? expectedReleaseIdentity(),
		options.invocationId,
	)) {
		fail(finding)
	}

	return {
		archive: basename(archivePath),
		version: pkg.version,
		fileCount: files.length,
		sha256: createHash('sha256').update(raw).digest('hex'),
		provenance,
		failures,
	}
}

if (process.argv[1] && pathToFileURL(process.argv[1]).href === import.meta.url) {
	const target = process.argv[2]
	if (!target) {
		process.stderr.write('usage: node scripts/inspect-npm-pack.mjs <path-to.tgz>\n')
		process.exit(2)
	}
	const result = inspectNpmPack(target)
	process.stdout.write(`${JSON.stringify(result, null, 2)}\n`)
	if (result.failures.length > 0) process.exit(1)
	process.stderr.write(`${result.archive}: ${result.fileCount} files, no findings\n`)
}
