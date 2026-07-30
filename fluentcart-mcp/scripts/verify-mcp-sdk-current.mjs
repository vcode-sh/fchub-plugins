#!/usr/bin/env node

import { execFileSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import { gunzipSync } from 'node:zlib'
import { readFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const PACKAGE_ROOT = dirname(dirname(fileURLToPath(import.meta.url)))
const REGISTRY_BASE = 'https://registry.npmjs.org'
const REQUEST_TIMEOUT_MS = 10_000
const OFFICIAL_SDK_REPOSITORY = 'https://github.com/modelcontextprotocol/typescript-sdk'
const OFFICIAL_CONFORMANCE_REPOSITORY =
	'https://github.com/modelcontextprotocol/conformance'

export const STABLE_SDK_PACKAGES = [
	'@modelcontextprotocol/server',
	'@modelcontextprotocol/client',
	'@modelcontextprotocol/node',
	'@modelcontextprotocol/express',
	'@modelcontextprotocol/core',
]

export const REQUIRED_MODERN_CONFORMANCE_SCENARIOS = [
	'server-stateless',
	'tools-list',
	'tools-call-simple-text',
	'json-schema-2020-12',
	'resources-list',
	'resources-read-text',
	'prompts-list',
	'prompts-get-simple',
	'prompts-get-with-args',
	'dns-rebinding-protection',
	'http-header-validation',
]

function normaliseRepository(value) {
	const raw = typeof value === 'string' ? value : value?.url
	if (typeof raw !== 'string') return null
	return raw
		.replace(/^git\+/, '')
		.replace(/^git:\/\//, 'https://')
		.replace(/\.git$/, '')
		.replace(/\/$/, '')
}

function assertObject(value, message) {
	if (!value || typeof value !== 'object' || Array.isArray(value)) {
		throw new Error(message)
	}
	return value
}

function assertExactVersion(value, label) {
	if (typeof value !== 'string' || !/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/.test(value)) {
		throw new Error(`${label} must be an exact semver pin`)
	}
	return value
}

function directPin(project, name) {
	return (
		project.packageJson.dependencies?.[name] ??
		project.packageJson.devDependencies?.[name] ??
		null
	)
}

function assertRegistryEntry(registry, name) {
	const entry = assertObject(registry[name], `${name}: missing registry metadata`)
	if (entry.error === 'timeout') throw new Error(`${name}: registry timeout`)
	if (entry.error) throw new Error(`${name}: registry failure: ${entry.error}`)
	const latest = entry.distTags?.latest
	if (typeof latest !== 'string') throw new Error(`${name}: missing registry latest dist-tag`)
	const version = entry.versions?.[latest]
	if (!version || typeof version !== 'object') {
		throw new Error(`${name}: registry metadata is missing latest version ${latest}`)
	}
	if (typeof version.integrity !== 'string' || version.integrity.length === 0) {
		throw new Error(`${name}: registry metadata is missing dist.integrity for ${latest}`)
	}
	return { entry, latest, version }
}

function assertOfficialRepository(name, metadata, expected) {
	const repository = normaliseRepository(metadata.repository)
	if (repository !== expected) {
		throw new Error(
			`${name}: registry repository ${repository ?? '<missing>'} is not ${expected}`,
		)
	}
	return repository
}

function selectConformance(registry) {
	const name = '@modelcontextprotocol/conformance'
	const entry = assertObject(registry[name], `${name}: missing registry metadata`)
	if (entry.error === 'timeout') throw new Error(`${name}: registry timeout`)
	if (entry.error) throw new Error(`${name}: registry failure: ${entry.error}`)

	for (const channel of ['latest', 'alpha']) {
		if (typeof entry.distTags?.[channel] !== 'string') {
			throw new Error(`${name}: missing registry ${channel} dist-tag`)
		}
	}

	const candidates = ['latest', 'alpha'].map((channel) => {
		const version = entry.distTags[channel]
		const metadata = entry.versions?.[version]
		if (!metadata || typeof metadata !== 'object') {
			throw new Error(`${name}: registry metadata is missing ${channel} version ${version}`)
		}
		if (typeof metadata.integrity !== 'string' || metadata.integrity.length === 0) {
			throw new Error(`${name}: registry metadata is missing dist.integrity for ${version}`)
		}
		if (!Array.isArray(metadata.scenarios)) {
			throw new Error(`${name}: published scenario inventory is missing for ${version}`)
		}
		assertOfficialRepository(name, metadata, OFFICIAL_CONFORMANCE_REPOSITORY)
		const missing = REQUIRED_MODERN_CONFORMANCE_SCENARIOS.filter(
			(scenario) => !metadata.scenarios.includes(scenario),
		)
		return { channel, version, metadata, missing }
	})

	const selected =
		candidates.find((candidate) => candidate.channel === 'latest' && candidate.missing.length === 0) ??
		candidates.find((candidate) => candidate.channel === 'alpha' && candidate.missing.length === 0)
	if (!selected) {
		throw new Error(
			`${name}: neither latest nor alpha contains every required 2026-07-28 server scenario`,
		)
	}
	return selected
}

export function verifyCurrentSdk(fixture) {
	if (fixture?.schemaVersion !== 1) {
		throw new Error('SDK registry fixture must use schema version 1')
	}
	const project = assertObject(fixture.project, 'fixture project is missing')
	const registry = assertObject(fixture.registry, 'fixture registry is missing')
	assertObject(project.packageJson, 'project package.json is missing')
	assertObject(project.lockPackages, 'project package-lock graph is missing')
	assertObject(project.installedVersions, 'project installed graph is missing')

	const packageMetadata = STABLE_SDK_PACKAGES.map((name) => {
		const { latest, version } = assertRegistryEntry(registry, name)
		const repository = assertOfficialRepository(name, version, OFFICIAL_SDK_REPOSITORY)
		return { name, latest, version, repository }
	})
	const latestVersions = new Set(packageMetadata.map(({ latest }) => latest))
	if (latestVersions.size !== 1) {
		throw new Error(
			`stable SDK packages do not share one latest version: ${packageMetadata
				.map(({ name, latest }) => `${name}=${latest}`)
				.join(', ')}`,
		)
	}
	const [sdkVersion] = latestVersions

	for (const { name, latest, version } of packageMetadata) {
		const pin = directPin(project, name)
		if (name !== '@modelcontextprotocol/core') {
			assertExactVersion(pin, `${name} package.json pin`)
			if (pin !== latest) {
				throw new Error(`${name}: exact pin ${pin} does not match registry latest ${latest}`)
			}
		} else if (pin !== null) {
			throw new Error(`${name}: core must remain transitively resolved, not directly pinned`)
		}

		const locked = project.lockPackages[name]
		if (!locked || locked.version !== latest) {
			throw new Error(
				`${name}: lockfile version ${locked?.version ?? '<missing>'} does not match registry latest ${latest}`,
			)
		}
		if (locked.integrity !== version.integrity) {
			throw new Error(`${name}: lockfile integrity does not match registry integrity`)
		}
		const installed = project.installedVersions[name]
		if (
			!Array.isArray(installed) ||
			installed.length !== 1 ||
			installed[0] !== latest
		) {
			throw new Error(
				`${name}: installed graph must contain only ${latest}; found ${JSON.stringify(installed)}`,
			)
		}
	}

	const conformance = selectConformance(registry)
	const conformanceName = '@modelcontextprotocol/conformance'
	const conformancePin = directPin(project, conformanceName)
	assertExactVersion(conformancePin, `${conformanceName} package.json pin`)
	if (conformancePin !== conformance.version) {
		throw new Error(
			`${conformanceName}: exact pin ${conformancePin} does not match selected ${conformance.version}`,
		)
	}
	const conformanceLock = project.lockPackages[conformanceName]
	if (conformanceLock?.version !== conformance.version) {
		throw new Error(
			`${conformanceName}: lockfile version ${conformanceLock?.version ?? '<missing>'} does not match selected ${conformance.version}`,
		)
	}
	if (conformanceLock.integrity !== conformance.metadata.integrity) {
		throw new Error(`${conformanceName}: lockfile integrity does not match registry integrity`)
	}
	const conformanceInstalled = project.installedVersions[conformanceName]
	if (
		!Array.isArray(conformanceInstalled) ||
		conformanceInstalled.length !== 1 ||
		conformanceInstalled[0] !== conformance.version
	) {
		throw new Error(
			`${conformanceName}: installed graph must contain only ${conformance.version}; found ${JSON.stringify(conformanceInstalled)}`,
		)
	}

	return {
		schemaVersion: 1,
		status: 'current',
		sdkVersion,
		packages: packageMetadata.map(({ name, latest, version, repository }) => ({
			name,
			version: latest,
			integrity: version.integrity,
			repository,
		})),
		conformance: {
			selected: conformance.version,
			channel: conformance.channel,
			integrity: conformance.metadata.integrity,
			repository: OFFICIAL_CONFORMANCE_REPOSITORY,
			requiredScenarios: [...REQUIRED_MODERN_CONFORMANCE_SCENARIOS],
		},
	}
}

function lockPackages(lock) {
	return Object.fromEntries(
		[...STABLE_SDK_PACKAGES, '@modelcontextprotocol/conformance'].map((name) => {
			const value = lock.packages?.[`node_modules/${name}`]
			return [
				name,
				value
					? {
							version: value.version,
							integrity: value.integrity,
						}
					: null,
			]
		}),
	)
}

function installedVersions(installedLock) {
	const names = [...STABLE_SDK_PACKAGES, '@modelcontextprotocol/conformance']
	return Object.fromEntries(
		names.map((name) => {
			const suffix = `node_modules/${name}`
			const versions = new Set()
			for (const [path, metadata] of Object.entries(installedLock.packages ?? {})) {
				if ((path === suffix || path.endsWith(`/${suffix}`)) && metadata?.version) {
					versions.add(metadata.version)
				}
			}
			return [name, [...versions].sort()]
		}),
	)
}

async function fetchJson(url, label) {
	let response
	try {
		response = await fetch(url, {
			headers: { accept: 'application/json' },
			signal: AbortSignal.timeout(REQUEST_TIMEOUT_MS),
		})
	} catch (error) {
		if (error?.name === 'TimeoutError' || error?.name === 'AbortError') {
			throw new Error(`${label}: registry timeout after ${REQUEST_TIMEOUT_MS}ms`)
		}
		throw new Error(`${label}: registry request failed: ${error.message}`)
	}
	if (!response.ok) throw new Error(`${label}: registry returned HTTP ${response.status}`)
	try {
		return await response.json()
	} catch {
		throw new Error(`${label}: registry returned malformed JSON`)
	}
}

async function fetchBytes(url, label) {
	let response
	try {
		response = await fetch(url, { signal: AbortSignal.timeout(REQUEST_TIMEOUT_MS) })
	} catch (error) {
		if (error?.name === 'TimeoutError' || error?.name === 'AbortError') {
			throw new Error(`${label}: tarball timeout after ${REQUEST_TIMEOUT_MS}ms`)
		}
		throw new Error(`${label}: tarball request failed: ${error.message}`)
	}
	if (!response.ok) throw new Error(`${label}: tarball returned HTTP ${response.status}`)
	return Buffer.from(await response.arrayBuffer())
}

function extractTarFile(tarGzip, wantedPath) {
	const tar = gunzipSync(tarGzip)
	for (let offset = 0; offset + 512 <= tar.length; ) {
		const header = tar.subarray(offset, offset + 512)
		if (header.every((byte) => byte === 0)) break
		const name = header.subarray(0, 100).toString('utf8').replace(/\0.*$/, '')
		const prefix = header.subarray(345, 500).toString('utf8').replace(/\0.*$/, '')
		const path = prefix ? `${prefix}/${name}` : name
		const sizeText = header.subarray(124, 136).toString('ascii').replace(/\0.*$/, '').trim()
		const size = Number.parseInt(sizeText || '0', 8)
		const bodyStart = offset + 512
		if (path === wantedPath) return tar.subarray(bodyStart, bodyStart + size)
		offset = bodyStart + Math.ceil(size / 512) * 512
	}
	throw new Error(`published tarball is missing ${wantedPath}`)
}

async function publishedScenarioInventory(metadata, version) {
	const tarball = metadata.versions?.[version]?.dist?.tarball
	if (typeof tarball !== 'string') {
		throw new Error(
			`@modelcontextprotocol/conformance: registry metadata is missing tarball for ${version}`,
		)
	}
	const script = extractTarFile(
		await fetchBytes(tarball, `@modelcontextprotocol/conformance@${version}`),
		'package/dist/index.js',
	).toString('utf8')
	return REQUIRED_MODERN_CONFORMANCE_SCENARIOS.filter((scenario) =>
		script.includes(scenario),
	)
}

async function loadLiveFixture() {
	const packageJsonPath = join(PACKAGE_ROOT, 'package.json')
	const packageLockPath = join(PACKAGE_ROOT, 'package-lock.json')
	const installedLockPath = join(PACKAGE_ROOT, 'node_modules/.package-lock.json')
	const packageJson = JSON.parse(readFileSync(packageJsonPath, 'utf8'))
	const packageLock = JSON.parse(readFileSync(packageLockPath, 'utf8'))
	let installedLock
	try {
		installedLock = JSON.parse(readFileSync(installedLockPath, 'utf8'))
	} catch {
		throw new Error('installed graph is unavailable; run npm ci before check:sdk-current')
	}

	const registry = {}
	for (const name of [...STABLE_SDK_PACKAGES, '@modelcontextprotocol/conformance']) {
		const metadata = await fetchJson(
			`${REGISTRY_BASE}/${encodeURIComponent(name)}`,
			name,
		)
		const tags = metadata['dist-tags']
		const versions = {}
		const channels = name === '@modelcontextprotocol/conformance' ? ['latest', 'alpha'] : ['latest']
		for (const channel of channels) {
			const version = tags?.[channel]
			const published = version ? metadata.versions?.[version] : null
			versions[version] = published
				? {
						integrity: published.dist?.integrity,
						repository: published.repository ?? metadata.repository,
						...(name === '@modelcontextprotocol/conformance'
							? {
									scenarios: await publishedScenarioInventory(metadata, version),
								}
							: {}),
					}
				: null
		}
		registry[name] = { distTags: tags, versions }
	}

	return {
		schemaVersion: 1,
		project: {
			packageJson,
			lockPackages: lockPackages(packageLock),
			installedVersions: installedVersions(installedLock),
		},
		registry,
		source: {
			packageLockSha256: createHash('sha256')
				.update(readFileSync(packageLockPath))
				.digest('hex'),
			sourceSha:
				process.env.GITHUB_SHA ??
				execFileSync('git', ['rev-parse', 'HEAD'], {
					cwd: resolve(PACKAGE_ROOT, '..'),
					encoding: 'utf8',
				}).trim(),
		},
	}
}

function parseArgs(argv) {
	const args = { fixture: null, live: false, json: false }
	for (let index = 0; index < argv.length; index += 1) {
		const arg = argv[index]
		if (arg === '--fixture') {
			args.fixture = argv[index + 1]
			if (!args.fixture) throw new Error('--fixture requires a path')
			index += 1
		} else if (arg === '--live') {
			args.live = true
		} else if (arg === '--json') {
			args.json = true
		} else {
			throw new Error(`unknown argument: ${arg}`)
		}
	}
	if (args.live === Boolean(args.fixture)) {
		throw new Error('choose exactly one of --live or --fixture <path>')
	}
	return args
}

async function main() {
	const args = parseArgs(process.argv.slice(2))
	const fixture = args.live
		? await loadLiveFixture()
		: JSON.parse(readFileSync(resolve(args.fixture), 'utf8'))
	const evidence = verifyCurrentSdk(fixture)
	if (args.live) {
		evidence.evidence = {
			kind: 'live-npm-registry',
			verifiedAt: new Date().toISOString(),
			sourceSha: fixture.source.sourceSha,
			packageLockSha256: fixture.source.packageLockSha256,
		}
	}
	if (args.json) {
		process.stdout.write(`${JSON.stringify(evidence, null, 2)}\n`)
	} else {
		process.stdout.write(
			`MCP SDK ${evidence.sdkVersion} and conformance ${evidence.conformance.selected} are current.\n`,
		)
	}
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
	main().catch((error) => {
		process.stderr.write(`SDK current check failed: ${error.message}\n`)
		process.exitCode = 1
	})
}
