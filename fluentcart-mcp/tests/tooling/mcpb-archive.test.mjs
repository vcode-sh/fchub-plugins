import assert from 'node:assert/strict'
import { existsSync, mkdtempSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { after, describe, it } from 'node:test'
import { fileURLToPath } from 'node:url'
import { crc32, gunzipSync, gzipSync } from 'node:zlib'
import {
	assertSafeContext,
	readContextIdentity,
} from '../../scripts/build-validated-docker-image.mjs'
import { inspectMcpb } from '../../scripts/inspect-mcpb.mjs'
import { inspectNpmPack, readTar } from '../../scripts/inspect-npm-pack.mjs'
import { expectedReleaseIdentity } from '../../scripts/release-identity.mjs'

const PACKAGE_ROOT = dirname(dirname(dirname(fileURLToPath(import.meta.url))))
const DIST_PACKAGES = join(PACKAGE_ROOT, 'dist-packages')
const PACKAGE = JSON.parse(readFileSync(join(PACKAGE_ROOT, 'package.json'), 'utf8'))
const VERSION = PACKAGE.version
const PACKED_PACKAGE = {
	version: VERSION,
	files: PACKAGE.files,
	engines: PACKAGE.engines,
	dependencies: PACKAGE.dependencies,
	devDependencies: PACKAGE.devDependencies,
}
const CONTRACT = JSON.parse(readFileSync(join(PACKAGE_ROOT, 'release-contract.json'), 'utf8'))
const CONTRACT_RAW = readFileSync(join(PACKAGE_ROOT, 'release-contract.json'), 'utf8')
const RELEASE_IDENTITY = expectedReleaseIdentity(PACKAGE_ROOT)
const GOOD_PROVENANCE = { ...RELEASE_IDENTITY, invocationId: 'current-build' }
const SDK_PACKAGES = [
	'@modelcontextprotocol/server',
	'@modelcontextprotocol/node',
	'@modelcontextprotocol/express',
]

/**
 * How many tools a well-formed bundle advertises, read from the generated manifest.
 *
 * `inspectMcpb` cross-checks the bundle's count against the release contract, so hardcoding a
 * number here means the synthetic "good bundle" stops being well-formed the moment curated
 * membership changes — which is a failure about this fixture, not about the inspector. Reading
 * the real figure keeps the rejection tests below testing the thing each of them changes.
 */
const ADVERTISED_TOOL_COUNT = JSON.parse(readFileSync(join(PACKAGE_ROOT, 'manifest.json'), 'utf8'))
	._meta['sh.vcode.fluentcart-mcp'].advertisedToolCount

const scratch = mkdtempSync(join(tmpdir(), 'mcpb-archive-test-'))
after(() => rmSync(scratch, { recursive: true, force: true }))

const FILE_MODE = 0o100644
const SYMLINK_MODE = 0o120777

function makeZip(entries) {
	const locals = []
	const centrals = []
	let offset = 0

	for (const entry of entries) {
		const name = Buffer.from(entry.name, 'utf8')
		const data = Buffer.from(entry.data ?? '')
		const sum = crc32(data)

		const local = Buffer.alloc(30)
		local.writeUInt32LE(0x04034b50, 0)
		local.writeUInt16LE(20, 4)
		local.writeUInt32LE(sum, 14)
		local.writeUInt32LE(data.length, 18)
		local.writeUInt32LE(data.length, 22)
		local.writeUInt16LE(name.length, 26)
		locals.push(local, name, data)

		const central = Buffer.alloc(46)
		central.writeUInt32LE(0x02014b50, 0)
		central.writeUInt16LE(0x0314, 4)
		central.writeUInt16LE(20, 6)
		central.writeUInt32LE(sum, 16)
		central.writeUInt32LE(data.length, 20)
		central.writeUInt32LE(data.length, 24)
		central.writeUInt16LE(name.length, 28)
		central.writeUInt32LE(((entry.mode ?? FILE_MODE) << 16) >>> 0, 38)
		central.writeUInt32LE(offset, 42)
		centrals.push(central, name)
		offset += 30 + name.length + data.length
	}

	const directory = Buffer.concat(centrals)
	const eocd = Buffer.alloc(22)
	eocd.writeUInt32LE(0x06054b50, 0)
	eocd.writeUInt16LE(entries.length, 8)
	eocd.writeUInt16LE(entries.length, 10)
	eocd.writeUInt32LE(directory.length, 12)
	eocd.writeUInt32LE(offset, 16)
	return Buffer.concat([...locals, directory, eocd])
}

function tarHeader(name, size, typeFlag, linkName) {
	const header = Buffer.alloc(512)
	header.write(name, 0, 100, 'utf8')
	header.write('000644 \0', 100, 8, 'utf8')
	header.write(`${size.toString(8).padStart(11, '0')} `, 124, 12, 'utf8')
	header.write('00000000000 ', 136, 12, 'utf8')
	header.write(typeFlag, 156, 1, 'utf8')
	if (linkName) header.write(linkName, 157, 100, 'utf8')
	header.write('ustar\0', 257, 6, 'utf8')
	header.write('00', 263, 2, 'utf8')

	header.write('        ', 148, 8, 'utf8')
	let sum = 0
	for (const byte of header) sum += byte
	header.write(`${sum.toString(8).padStart(6, '0')}\0 `, 148, 8, 'utf8')
	return header
}

function makeTgz(entries) {
	const blocks = []
	for (const entry of entries) {
		const data = Buffer.from(entry.data ?? '')
		blocks.push(tarHeader(entry.name, data.length, entry.typeFlag ?? '0', entry.linkName))
		if (data.length > 0) {
			const padded = Buffer.alloc(Math.ceil(data.length / 512) * 512)
			data.copy(padded)
			blocks.push(padded)
		}
	}
	blocks.push(Buffer.alloc(1024))
	return gzipSync(Buffer.concat(blocks))
}

let counter = 0
function writeArchive(extension, buffer) {
	counter += 1
	const path = join(scratch, `case-${counter}.${extension}`)
	writeFileSync(path, buffer)
	return path
}

/** A bundle that should pass, so each rejection test changes exactly one thing. */
function goodMcpbEntries(overrides = {}) {
	const manifest = {
		version: VERSION,
		description: 'Curated and capability-discovered tools for a FluentCart store.',
		server: { entry_point: 'dist/index.js' },
		tools: Array.from({ length: ADVERTISED_TOOL_COUNT }, (_, index) => ({ name: `tool_${index}` })),
		_meta: { 'sh.vcode.fluentcart-mcp': { advertisedToolCount: ADVERTISED_TOOL_COUNT } },
		...overrides.manifest,
	}
	return [
		{ name: 'manifest.json', data: JSON.stringify(manifest) },
		{
			name: 'package.json',
			data: JSON.stringify(PACKED_PACKAGE),
		},
		{ name: 'dist/index.js', data: 'export {}\n' },
		{
			name: 'dist/release-provenance.json',
			data: JSON.stringify(overrides.provenance ?? GOOD_PROVENANCE),
		},
		...SDK_PACKAGES.map((name) => ({
			name: `node_modules/${name}/package.json`,
			data: JSON.stringify({ name, version: '2.0.0' }),
		})),
		{ name: 'node_modules/zod/index.js', data: 'module.exports={}\n' },
		...(overrides.extra ?? []),
	]
}

function mcpbFailures(entries) {
	return inspectMcpb(writeArchive('mcpb', makeZip(entries))).failures.join(' | ')
}

describe('MCPB archive inspection', () => {
	it('accepts a well-formed bundle', () => {
		assert.deepEqual(inspectMcpb(writeArchive('mcpb', makeZip(goodMcpbEntries()))).failures, [])
	})

	it('rejects a manifest version that disagrees with the package', () => {
		const entries = goodMcpbEntries({ manifest: { version: '0.0.1' } })
		assert.match(mcpbFailures(entries), /manifest version 0\.0\.1 does not match/)
	})

	it('rejects a missing runtime entry point', () => {
		const entries = goodMcpbEntries().filter((entry) => entry.name !== 'dist/index.js')
		assert.match(mcpbFailures(entries), /missing dist\/index\.js/)
	})

	it('rejects a development dependency inside the bundle', () => {
		const entries = goodMcpbEntries({ extra: [{ name: 'node_modules/vitest/index.js', data: '' }] })
		assert.match(mcpbFailures(entries), /development dependency shipped: vitest/)
	})

	it('rejects every missing installed modular SDK package', () => {
		for (const name of SDK_PACKAGES) {
			const path = `node_modules/${name}/package.json`
			const entries = goodMcpbEntries().filter((entry) => entry.name !== path)
			assert.match(mcpbFailures(entries), new RegExp(`missing installed ${name}`))
		}
	})

	it('rejects every installed modular SDK package at the wrong version', () => {
		for (const name of SDK_PACKAGES) {
			const path = `node_modules/${name}/package.json`
			const entries = goodMcpbEntries().map((entry) =>
				entry.name === path
					? { ...entry, data: JSON.stringify({ name, version: '2.0.1' }) }
					: entry,
			)
			assert.match(mcpbFailures(entries), new RegExp(`${name} installed version 2\\.0\\.1`))
		}
	})

	it('rejects tests, maps, coverage and dev config from our own tree', () => {
		for (const name of [
			'tests/unit.test.js',
			'dist/index.js.map',
			'coverage/lcov.info',
			'src/thing.test.ts',
			'tsconfig.json',
		]) {
			const entries = goodMcpbEntries({ extra: [{ name, data: '' }] })
			assert.match(mcpbFailures(entries), /must not ship/, `expected ${name} to be rejected`)
		}
	})

	it('rejects credential material anywhere, including vendored paths', () => {
		for (const name of ['.env', 'node_modules/thing/.npmrc', 'certs/server.pem']) {
			const entries = goodMcpbEntries({ extra: [{ name, data: '' }] })
			assert.match(mcpbFailures(entries), /credential material/, `expected ${name} to be rejected`)
		}
	})

	it('rejects traversal and absolute archive paths', () => {
		assert.match(
			mcpbFailures(goodMcpbEntries({ extra: [{ name: '../outside.js', data: '' }] })),
			/parent traversal/,
		)
		assert.match(
			mcpbFailures(goodMcpbEntries({ extra: [{ name: '/etc/passwd', data: '' }] })),
			/absolute path/,
		)
	})

	it('rejects a symlink entry', () => {
		const entries = goodMcpbEntries({
			extra: [{ name: 'dist/link.js', data: '../../../etc/passwd', mode: SYMLINK_MODE }],
		})
		assert.match(mcpbFailures(entries), /symlink: dist\/link\.js/)
	})

	it('rejects a tool inventory the release contract cannot account for', () => {
		const entries = goodMcpbEntries({
			manifest: {
				tools: [{ name: 'only_one' }],
				_meta: { 'sh.vcode.fluentcart-mcp': { advertisedToolCount: 1 } },
			},
		})
		assert.match(mcpbFailures(entries), /release contract accounts for/)
	})

	it('rejects a manifest that claims a count it does not list', () => {
		const entries = goodMcpbEntries({
			manifest: { _meta: { 'sh.vcode.fluentcart-mcp': { advertisedToolCount: 274 } } },
		})
		assert.match(
			mcpbFailures(entries),
			new RegExp(`claims 274 tools but lists ${ADVERTISED_TOOL_COUNT}`),
		)
	})

	it('rejects a stale tool count in the product description', () => {
		const entries = goodMcpbEntries({
			manifest: { description: 'Over 200+ tools for your store.' },
		})
		assert.match(mcpbFailures(entries), /stale tool count/)
	})

	it('rejects a same-version bundle built from a different source tree', () => {
		const entries = goodMcpbEntries({
			provenance: { ...GOOD_PROVENANCE, sourceTreeDigest: 'sha256:stale' },
		})
		assert.match(mcpbFailures(entries), /source tree digest/)
	})
})

const OPENAI_PLUGIN_ENTRIES = [
	{
		name: 'package/openai-plugin/.codex-plugin/plugin.json',
		data: '{"name":"fluentcart-mcp"}',
	},
	{
		name: 'package/openai-plugin/.mcp.json',
		data: '{"fluentcart":{"command":"npx"}}',
	},
]

function goodTarEntries(extra = [], provenance = GOOD_PROVENANCE) {
	return [
		{ name: 'package/package.json', data: JSON.stringify(PACKED_PACKAGE) },
		{ name: 'package/README.md', data: '# readme\n' },
		{ name: 'package/dist/index.js', data: 'export {}\n' },
		{ name: 'package/dist/release-provenance.json', data: JSON.stringify(provenance) },
		...OPENAI_PLUGIN_ENTRIES,
		...extra,
	]
}

function npmFailures(entries) {
	return inspectNpmPack(writeArchive('tgz', makeTgz(entries))).failures.join(' | ')
}

describe('npm tarball inspection', () => {
	it('accepts a matching tarball and records what publication must consume', () => {
		const path = writeArchive('tgz', makeTgz(goodTarEntries()))
		const result = inspectNpmPack(path)
		assert.deepEqual(result.failures, [])
		assert.match(result.sha256, /^[0-9a-f]{64}$/)
		assert.equal(result.version, VERSION)
		assert.equal(result.archive, path.split('/').pop())
	})

	it('rejects a version that disagrees with the package', () => {
		const entries = goodTarEntries().map((entry) =>
			entry.name === 'package/package.json'
				? { ...entry, data: JSON.stringify({ version: '0.0.1', files: ['dist'] }) }
				: entry,
		)
		assert.match(npmFailures(entries), /does not match/)
	})

	it('rejects files outside the package files allowlist', () => {
		assert.match(
			npmFailures(goodTarEntries([{ name: 'package/src/index.ts', data: '' }])),
			/allowlist/,
		)
	})

	it('rejects tests, maps, coverage and credentials', () => {
		for (const [name, reason] of [
			['package/tests/a.test.js', /test directory/],
			['package/dist/index.js.map', /source or declaration map/],
			['package/coverage/lcov.info', /coverage output/],
			['package/.env', /environment file/],
			['package/tsconfig.json', /development config/],
		]) {
			assert.match(
				npmFailures(goodTarEntries([{ name, data: '' }])),
				reason,
				`expected ${name} rejected`,
			)
		}
	})

	it('rejects traversal, absolute paths and symlinks', () => {
		assert.match(
			npmFailures(goodTarEntries([{ name: 'package/../evil.js', data: '' }])),
			/parent traversal/,
		)
		assert.match(npmFailures(goodTarEntries([{ name: '/etc/passwd', data: '' }])), /absolute path/)
		assert.match(
			npmFailures(
				goodTarEntries([{ name: 'package/dist/link.js', typeFlag: '2', linkName: '/etc/passwd' }]),
			),
			/symlink/,
		)
	})

	it('rejects a missing runtime entry point', () => {
		const entries = goodTarEntries().filter((entry) => entry.name !== 'package/dist/index.js')
		assert.match(npmFailures(entries), /missing dist\/index\.js/)
	})

	it('rejects either missing OpenAI plugin manifest', () => {
		for (const { name } of OPENAI_PLUGIN_ENTRIES) {
			const entries = goodTarEntries().filter((entry) => entry.name !== name)
			assert.match(
				npmFailures(entries),
				new RegExp(`missing ${name.slice('package/'.length).replaceAll('.', '\\.')}`),
			)
		}
	})

	it('rejects undeclared files inside the OpenAI plugin package root', () => {
		assert.match(
			npmFailures(
				goodTarEntries([{ name: 'package/openai-plugin/internal-notes.txt', data: 'private' }]),
			),
			/exact OpenAI plugin allowlist/,
		)
	})

	it('rejects a same-version tarball built with a different package lock', () => {
		const entries = goodTarEntries([], {
			...GOOD_PROVENANCE,
			sourceTreeDigest: CONTRACT.sourceTreeDigest,
			packageLockDigest: 'sha256:stale',
		})
		assert.match(npmFailures(entries), /package-lock digest/)
	})

	it('rejects an otherwise-current tarball from an earlier pack invocation', () => {
		const path = writeArchive('tgz', makeTgz(goodTarEntries()))
		const result = inspectNpmPack(path, {
			expectedIdentity: RELEASE_IDENTITY,
			invocationId: 'new-build',
		})
		assert.match(result.failures.join(' | '), /release invocation id/)
	})
})

describe('built release artefacts', () => {
	it('passes inspection for the artefacts in dist-packages', (t) => {
		const built = existsSync(DIST_PACKAGES) ? readdirSync(DIST_PACKAGES) : []
		const tarball = built.find((name) => name.endsWith('.tgz'))
		const bundle = built.find((name) => name.endsWith('.mcpb'))

		if (!(tarball && bundle)) {
			t.skip('run `npm run pack:release` first; no built artefacts to inspect')
			return
		}

		const npmResult = inspectNpmPack(join(DIST_PACKAGES, tarball))
		const mcpbResult = inspectMcpb(join(DIST_PACKAGES, bundle))
		assert.deepEqual(npmResult.failures, [])
		assert.deepEqual(mcpbResult.failures, [])
		assert.equal(npmResult.provenance.invocationId, mcpbResult.provenance.invocationId)
	})
})

/**
 * A minimal context that should pass, so each rejection test changes exactly one thing.
 * Regular files only, every root inside the declared allowlist.
 */
function goodContextEntries(extra = []) {
	return [
		{ name: 'Dockerfile.release', data: 'FROM node:22-alpine\n' },
		{ name: 'package.json', data: JSON.stringify({ name: 'fluentcart-mcp', version: VERSION }) },
		{ name: 'release-contract.json', data: CONTRACT_RAW },
		{ name: 'dist/index.js', data: 'console.error("ok")\n' },
		{ name: 'dist/release-provenance.json', data: JSON.stringify(GOOD_PROVENANCE) },
		{ name: 'node_modules/which/bin/node-which', data: '#!/usr/bin/env node\n' },
		...extra,
	]
}

/** Run the real validator over crafted entries and return the rejection message, or null. */
function contextFailure(entries) {
	const archive = writeArchive('tar.gz', makeTgz(entries))
	const parsed = readTar(gunzipSync(readFileSync(archive)))
	try {
		assertSafeContext(parsed)
		return null
	} catch (error) {
		return error.message
	}
}

describe('Docker build context validation', () => {
	it('accepts a context of regular files inside the allowlist', () => {
		assert.equal(contextFailure(goodContextEntries()), null)
	})

	// Every symlink is refused whatever it points at. These three cases are the ones that would
	// actually hurt, so they are named individually rather than trusted to a single blanket
	// assertion — if the rule is ever relaxed to inspect targets, these must still fail.
	it('rejects a symlink whose target is an absolute path', () => {
		assert.match(
			contextFailure(
				goodContextEntries([{ name: 'node_modules/evil', typeFlag: '2', linkName: '/etc/passwd' }]),
			),
			/symlink: node_modules\/evil -> \/etc\/passwd/,
		)
	})

	it('rejects a symlink whose target escapes the archive root', () => {
		assert.match(
			contextFailure(
				goodContextEntries([
					{ name: 'dist/escape.js', typeFlag: '2', linkName: '../../../../etc/shadow' },
				]),
			),
			/symlink: dist\/escape\.js -> \.\.\/\.\.\/\.\.\/\.\.\/etc\/shadow/,
		)
	})

	it('rejects a hard link as well as a symbolic one', () => {
		assert.match(
			contextFailure(
				goodContextEntries([{ name: 'dist/hard.js', typeFlag: '1', linkName: '../../etc/passwd' }]),
			),
			/symlink: dist\/hard\.js/,
		)
	})

	it('rejects an entry path containing a parent traversal', () => {
		assert.match(
			contextFailure(goodContextEntries([{ name: 'dist/../../outside.js', data: '' }])),
			/parent traversal/,
		)
	})

	it('rejects an absolute entry path', () => {
		assert.match(
			contextFailure(goodContextEntries([{ name: '/etc/passwd', data: '' }])),
			/absolute path/,
		)
	})

	it('rejects a root outside the declared allowlist', () => {
		assert.match(
			contextFailure(goodContextEntries([{ name: 'src/index.ts', data: '' }])),
			/outside the context allowlist: src\/index\.ts/,
		)
	})

	// The npm launcher directory is excluded when the context is packed, so its reappearance
	// means the archive was produced some other way and should not be built.
	it('rejects node_modules/.bin even when it holds a plain file', () => {
		assert.match(
			contextFailure(goodContextEntries([{ name: 'node_modules/.bin/whatever', data: '' }])),
			/excluded from the declared context: node_modules\/\.bin\/whatever/,
		)
	})

	it('explains how to rebuild when an excluded symlink shows up', () => {
		const message = contextFailure(
			goodContextEntries([
				{
					name: 'node_modules/.bin/node-which',
					typeFlag: '2',
					linkName: '../which/bin/node-which',
				},
			]),
		)
		assert.match(message, /symlink: node_modules\/\.bin\/node-which/)
		assert.match(message, /npm run pack:release/)
	})

	it('reports every problem at once rather than the first', () => {
		const message = contextFailure(
			goodContextEntries([
				{ name: '/etc/passwd', data: '' },
				{ name: 'dist/link', typeFlag: '2', linkName: '/etc/shadow' },
				{ name: 'src/leak.ts', data: '' },
			]),
		)
		assert.match(message, /absolute path/)
		assert.match(message, /symlink/)
		assert.match(message, /outside the context allowlist/)
	})

	it('binds the context package, contract, source tree, lockfile record, and source SHA', () => {
		const entries = readTar(gunzipSync(makeTgz(goodContextEntries())))
		const identity = readContextIdentity(entries, RELEASE_IDENTITY.sourceSha)
		assert.equal(identity.version, VERSION)
		assert.equal(identity.provenance.packageLockDigest, RELEASE_IDENTITY.packageLockDigest)
	})

	it('rejects a same-version context whose source tree record is stale', () => {
		const entries = goodContextEntries().map((entry) =>
			entry.name === 'dist/release-provenance.json'
				? {
						...entry,
						data: JSON.stringify({ ...GOOD_PROVENANCE, sourceTreeDigest: 'sha256:stale' }),
					}
				: entry,
		)
		assert.throws(
			() => readContextIdentity(readTar(gunzipSync(makeTgz(entries))), RELEASE_IDENTITY.sourceSha),
			/source tree digest/,
		)
	})
})

describe('the packed Docker context', () => {
	const contextPath = join(DIST_PACKAGES, 'fluentcart-mcp-docker-context.tar.gz')
	const built = existsSync(contextPath)

	it('exists after npm run pack:release', {
		skip: built ? false : 'run npm run pack:release first',
	}, () => {
		assert.ok(built)
	})

	it('contains no symlink at all', {
		skip: built ? false : 'run npm run pack:release first',
	}, () => {
		const entries = readTar(gunzipSync(readFileSync(contextPath)))
		const links = entries.filter((entry) => entry.typeFlag === '1' || entry.typeFlag === '2')
		assert.deepEqual(
			links.map((entry) => `${entry.name} -> ${entry.linkName}`),
			[],
		)
	})

	it('ships no npm launcher directory', {
		skip: built ? false : 'run npm run pack:release first',
	}, () => {
		const entries = readTar(gunzipSync(readFileSync(contextPath)))
		const bin = entries.filter((entry) =>
			entry.name.replace(/^\.\//, '').startsWith('node_modules/.bin'),
		)
		assert.deepEqual(
			bin.map((entry) => entry.name),
			[],
		)
	})

	it('passes the real validator end to end', {
		skip: built ? false : 'run npm run pack:release first',
	}, () => {
		const entries = readTar(gunzipSync(readFileSync(contextPath)))
		assert.equal(assertSafeContext(entries), entries.length)
	})
})
