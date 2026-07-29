import assert from 'node:assert/strict'
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { dirname, join, relative, resolve } from 'node:path'
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
const SCANNED_EXTENSIONS = new Set(['.js', '.json', '.mjs', '.mts', '.ts'])
const LEGACY_SDK = '@modelcontextprotocol/sdk'
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

		const extension = entry.slice(entry.lastIndexOf('.'))
		if (SCANNED_EXTENSIONS.has(extension)) files.push(fullPath)
	}

	return files
}

function findText(needle) {
	return walk(join(packageRoot, 'src'))
		.filter((file) => readFileSync(file, 'utf8').includes(needle))
		.map((file) => relative(packageRoot, file))
		.sort()
}

function assertLegacySdkLockBoundary(packages) {
	const legacyPackages = Object.entries(packages)
		.filter(([path]) => path.endsWith('node_modules/@modelcontextprotocol/sdk'))
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
			path: 'node_modules/@modelcontextprotocol/sdk',
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
		assert.equal(pkg.devDependencies['@modelcontextprotocol/conformance'], '0.2.0-alpha.10')
		assert.equal(pkg.dependencies['@modelcontextprotocol/sdk'], undefined)
	})

	it('leaves no monolithic SDK reference in runtime source', () => {
		assert.deepEqual(findText('@modelcontextprotocol/sdk'), [])
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
