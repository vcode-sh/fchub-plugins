import assert from 'node:assert/strict'
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { dirname, extname, join, relative, resolve } from 'node:path'
import { describe, it } from 'node:test'
import { fileURLToPath } from 'node:url'

const packageRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..')
const packageJsonPath = join(packageRoot, 'package.json')
const packageLockPath = join(packageRoot, 'package-lock.json')
const pkg = JSON.parse(readFileSync(packageJsonPath, 'utf8'))
const lock = JSON.parse(readFileSync(packageLockPath, 'utf8'))

const EXCLUDED_DIRECTORIES = new Set([
	'.git',
	'node_modules',
	'dist',
	'dist-packages',
	'release-artifacts',
])
const SCANNED_EXTENSIONS = new Set(['.js', '.json', '.mjs', '.mts', '.sh', '.ts', '.yaml', '.yml'])
const LEGACY_SDK = ['@modelcontextprotocol', 'sdk'].join('/')
const DEPENDENCY_SECTIONS = [
	'dependencies',
	'devDependencies',
	'optionalDependencies',
	'peerDependencies',
]

function walk(directory) {
	const files = []

	for (const entry of readdirSync(directory)) {
		if (EXCLUDED_DIRECTORIES.has(entry)) continue

		const fullPath = join(directory, entry)
		if (statSync(fullPath).isDirectory()) {
			files.push(...walk(fullPath))
			continue
		}

		const extension = extname(entry)
		if (SCANNED_EXTENSIONS.has(extension)) files.push(fullPath)
	}

	return files
}

const STALE_RULES = [
	{ id: 'codemod-marker', pattern: new RegExp(['@mcp-', 'codemod-error'].join(''), 'i') },
	{
		id: 'legacy-monolithic-sdk',
		pattern: new RegExp(['@modelcontextprotocol', 'sdk'].join('\\/')),
	},
	{
		id: 'removed-v1-import',
		pattern: new RegExp(
			['@modelcontextprotocol', 'server', '(?:mcp|stdio|streamableHttp)\\.js'].join('\\/'),
		),
	},
	{ id: 'schema-first-handler', pattern: new RegExp(['setRequest', 'Handler'].join('')) },
	{ id: 'removed-sse-transport', pattern: new RegExp(['SSEServer', 'Transport'].join('')) },
	{
		id: 'v1-server-double-cast',
		pattern: new RegExp(['as unknown as ', '(?:McpServer|Server)'].join('')),
	},
	{
		id: 'modern-initialize-language',
		pattern: new RegExp(['modern ', 'initiali(?:s|z)(?:e|ation)'].join(''), 'i'),
	},
	{
		id: 'protocol-session-language',
		pattern: new RegExp(['protocol ', 'sessions?'].join(''), 'i'),
	},
]

const STALE_ALLOWLIST = new Map([
	['scripts/inspect-npm-pack.mjs', new Set(['legacy-monolithic-sdk'])],
	['scripts/inspect-mcpb.mjs', new Set(['legacy-monolithic-sdk'])],
])

function staleViolations(entries) {
	return entries.flatMap(({ path, text }) =>
		STALE_RULES.flatMap(({ id, pattern }) => {
			pattern.lastIndex = 0
			if (!pattern.test(text) || STALE_ALLOWLIST.get(path)?.has(id)) return []
			return [`${path}: ${id}`]
		}),
	)
}

function ownedEntries() {
	const roots = [
		join(packageRoot, 'src'),
		join(packageRoot, 'scripts'),
		join(packageRoot, 'tests'),
		join(packageRoot, '..', '.github', 'workflows'),
	]
	return roots.flatMap((root) =>
		walk(root).map((path) => ({
			path: relative(packageRoot, path),
			text: readFileSync(path, 'utf8'),
		})),
	)
}

function assertLegacySdkLockBoundary(packages) {
	const legacyPackages = Object.entries(packages)
		.filter(([path]) => path.endsWith(`node_modules/${LEGACY_SDK}`))
		.map(([path, metadata]) => ({ path, dev: metadata.dev, version: metadata.version }))
	const legacyEdges = Object.entries(packages)
		.flatMap(([owner, metadata]) =>
			DEPENDENCY_SECTIONS.flatMap((section) => {
				const range = metadata[section]?.[LEGACY_SDK]
				return range === undefined ? [] : [{ owner, section, range }]
			}),
		)
		.sort((a, b) => a.owner.localeCompare(b.owner) || a.section.localeCompare(b.section))

	assert.deepEqual(legacyPackages, [
		{
			path: `node_modules/${LEGACY_SDK}`,
			dev: true,
			version: '1.30.0',
		},
	])
	assert.deepEqual(
		legacyEdges,
		[
			{
				owner: 'node_modules/@modelcontextprotocol/conformance',
				section: 'dependencies',
				range: '^1.29.0',
			},
		],
		'only pinned conformance may depend on the monolithic SDK',
	)
}

describe('MCP SDK v2 dependency and import boundary', () => {
	it('pins the modular SDK packages and removes the monolithic SDK', () => {
		assert.equal(pkg.dependencies['@modelcontextprotocol/server'], '2.0.0')
		assert.equal(pkg.dependencies['@modelcontextprotocol/node'], '2.0.0')
		assert.equal(pkg.dependencies['@modelcontextprotocol/express'], '2.0.0')
		assert.equal(pkg.devDependencies['@modelcontextprotocol/client'], '2.0.0')
		assert.equal(pkg.devDependencies['@modelcontextprotocol/conformance'], '0.2.0-alpha.11')
		assert.equal(pkg.dependencies[LEGACY_SDK], undefined)
		assert.equal(
			pkg.scripts['check:sdk-current'],
			'node scripts/verify-mcp-sdk-current.mjs --live --json',
		)
	})

	it('leaves no monolithic SDK reference in runtime source', () => {
		assert.deepEqual(
			staleViolations(
				walk(join(packageRoot, 'src')).map((path) => ({
					path: relative(packageRoot, path),
					text: readFileSync(path, 'utf8'),
				})),
			),
			[],
		)
	})

	it('scans scripts, tests and workflows instead of protecting only src', () => {
		for (const path of [
			'scripts/obsolete.mjs',
			'tests/obsolete.test.mjs',
			'../.github/workflows/obsolete.yml',
		]) {
			assert.deepEqual(staleViolations([{ path, text: `import ${JSON.stringify(LEGACY_SDK)}` }]), [
				`${path}: legacy-monolithic-sdk`,
			])
		}
	})

	it('has no stale SDK v1 or misleading modern-handshake tokens in active owned files', () => {
		assert.deepEqual(staleViolations(ownedEntries()), [])
	})

	it('keeps the sole lockfile v1 package test-only under pinned conformance', () => {
		assertLegacySdkLockBoundary(lock.packages)
	})

	it('rejects a second v1 owner even when npm deduplicates the resolved package', () => {
		const packages = structuredClone(lock.packages)
		packages['node_modules/second-sdk-v1-owner'] = structuredClone(
			packages['node_modules/@modelcontextprotocol/conformance'],
		)

		assert.throws(
			() => assertLegacySdkLockBoundary(packages),
			/only pinned conformance may depend on the monolithic SDK/,
		)
	})
})
