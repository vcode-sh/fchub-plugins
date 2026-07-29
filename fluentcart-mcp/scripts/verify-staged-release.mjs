#!/usr/bin/env node
/** Verify a downloaded staging handoff before any mutable release action is allowed. */

import { createHash } from 'node:crypto'
import { existsSync, readFileSync, readdirSync } from 'node:fs'
import { basename, dirname, join, posix, resolve } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { gunzipSync } from 'node:zlib'
import { inspectMcpb } from './inspect-mcpb.mjs'
import { inspectNpmPack, readTar } from './inspect-npm-pack.mjs'
import { PROVENANCE_PATH, releaseIdentityFailures } from './release-identity.mjs'

const SCRIPT_ROOT = dirname(dirname(fileURLToPath(import.meta.url)))
const DIGEST = /^sha256:[0-9a-f]{64}$/
const SHA = /^[0-9a-f]{40}$/
const UUID = /^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i
const REQUIRED_FIXED = [
	'fluentcart-mcp.mcpb',
	'fluentcart-mcp-docker-context.tar.gz',
	'previous-release-state.json',
	'release-contract.json',
]

function sha256(value, prefix = false) {
	const digest = createHash('sha256').update(value).digest('hex')
	return prefix ? `sha256:${digest}` : digest
}

function readJson(path) {
	return JSON.parse(readFileSync(path, 'utf8'))
}

function safeRelativePath(path) {
	return (
		typeof path === 'string' &&
		path !== '' &&
		!path.startsWith('/') &&
		!path.split(/[\\/]/).includes('..')
	)
}

function downloadedFiles(root, directory = '') {
	const files = []
	for (const entry of readdirSync(join(root, directory), { withFileTypes: true })) {
		const relative = posix.join(directory, entry.name)
		if (entry.isDirectory()) files.push(...downloadedFiles(root, relative))
		else if (entry.isFile()) files.push(relative)
		else throw new Error(`${relative} is not a regular downloaded file`)
	}
	return files
}

export function verifyStagingChecksums(root) {
	const statePath = join(root, 'staging-state.json')
	const checksumsPath = join(root, 'SHA256SUMS.json')
	const state = readJson(statePath)
	const checksumsRaw = readFileSync(checksumsPath)
	const checksums = JSON.parse(checksumsRaw.toString('utf8'))

	if (![2, 3].includes(state.schemaVersion)) {
		throw new Error('staging-state.json must use schema version 2 or 3')
	}
	if (!/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/.test(state.version ?? '')) {
		throw new Error('staging-state.json has an invalid version')
	}
	if (!SHA.test(state.sourceSha ?? '')) throw new Error('staging-state.json has an invalid sourceSha')
	if (!DIGEST.test(state.sourceTreeDigest ?? '')) {
		throw new Error('staging-state.json has an invalid sourceTreeDigest')
	}
	if (state.schemaVersion === 2) {
		if (typeof state.npmIntegrity !== 'string' || !state.npmIntegrity.startsWith('sha512-')) {
			throw new Error('staging-state.json has an invalid npmIntegrity')
		}
	} else {
		if (!UUID.test(state.npm?.stageId ?? '')) {
			throw new Error('staging-state.json has an invalid npm stageId')
		}
		if (state.npm?.tag !== 'latest') {
			throw new Error('staging-state.json npm stage must target latest')
		}
		if (
			typeof state.npm?.expectedIntegrity !== 'string' ||
			!state.npm.expectedIntegrity.startsWith('sha512-')
		) {
			throw new Error('staging-state.json has an invalid expected npm integrity')
		}
	}
	for (const registry of ['ghcr.io', 'docker.io']) {
		if (!DIGEST.test(state.dockerDigests?.[registry] ?? '')) {
			throw new Error(`staging-state.json has an invalid ${registry} Docker digest`)
		}
	}
	if (sha256(checksumsRaw) !== state.checksumsSha256) {
		throw new Error('SHA256SUMS.json checksum mismatch')
	}
	if (checksums.algorithm !== 'sha256' || !Array.isArray(checksums.files)) {
		throw new Error('SHA256SUMS.json has an invalid schema')
	}

	const required = new Set([...REQUIRED_FIXED, `fluentcart-mcp-${state.version}.tgz`])
	const seen = new Set()
	for (const entry of checksums.files) {
		if (!safeRelativePath(entry.file) || !/^[0-9a-f]{64}$/.test(entry.sha256 ?? '')) {
			throw new Error('SHA256SUMS.json contains an invalid entry')
		}
		if (seen.has(entry.file)) throw new Error(`SHA256SUMS.json repeats ${entry.file}`)
		seen.add(entry.file)
		const target = join(root, entry.file)
		if (!existsSync(target)) throw new Error(`${entry.file} is missing`)
		if (sha256(readFileSync(target)) !== entry.sha256) {
			throw new Error(`${entry.file} checksum mismatch`)
		}
	}
	for (const file of required) {
		if (!seen.has(file)) throw new Error(`SHA256SUMS.json is missing ${file}`)
	}
	for (const file of downloadedFiles(root)) {
		if (file === 'SHA256SUMS.json' || file === 'staging-state.json') continue
		if (!seen.has(file)) throw new Error(`${file} is not checksummed`)
	}
	return { state, checksums }
}

function expectedIdentity(root, state) {
	const verificationRoot = join(root, 'verification')
	const contractPath = join(verificationRoot, 'release-contract.json')
	return {
		schemaVersion: 2,
		packageVersion: state.version,
		sourceTreeDigest: state.sourceTreeDigest,
		candidateContentDigest: state.sourceTreeDigest,
		releaseContractDigest: sha256(readFileSync(contractPath), true),
		packageLockDigest: sha256(readFileSync(join(verificationRoot, 'package-lock.json')), true),
		baseCommitSha: state.sourceSha,
		sourceSha: state.sourceSha,
		sourceShaKind: 'committed-ci',
	}
}

function archiveEntry(entries, name) {
	const entry = entries.find((candidate) => candidate.name.replace(/^\.\//, '') === name)
	if (!entry) throw new Error(`Docker context is missing ${name}`)
	return entry.data
}

export function inspectDockerContext(contextPath, expected, invocationId, contractRaw) {
	const entries = readTar(gunzipSync(readFileSync(contextPath)))
	const packageJson = JSON.parse(archiveEntry(entries, 'package.json').toString('utf8'))
	const contextContract = archiveEntry(entries, 'release-contract.json')
	const provenance = JSON.parse(archiveEntry(entries, PROVENANCE_PATH).toString('utf8'))

	if (!contextContract.equals(contractRaw)) {
		throw new Error('Docker context release-contract.json does not match staged release-contract.json')
	}
	if (packageJson.version !== expected.packageVersion) {
		throw new Error('Docker context package version does not match staged version')
	}
	const failures = releaseIdentityFailures(provenance, expected, invocationId)
	if (failures.length > 0) {
		throw new Error(`Docker context provenance failed: ${failures.join(' | ')}`)
	}
	return provenance
}

function verifyRecoveryState(root, contract, version) {
	const previous = readJson(join(root, 'previous-release-state.json'))
	if (
		previous.redacted !== true ||
		previous.candidate?.version !== version ||
		previous.candidate?.npmPublished !== false ||
		previous.candidate?.remoteTagPresent !== false
	) {
		throw new Error('previous release state does not describe the staged unpublished candidate')
	}
	if (previous.npm?.previousLatest !== contract.release?.promotion?.previousLatest) {
		throw new Error('previous npm recovery version does not match the release contract')
	}
	for (const registry of ['ghcr.io', 'docker.io']) {
		if (
			previous.docker?.previousLatestDigests?.[registry] !==
			contract.release?.promotion?.previousDockerDigests?.[registry]
		) {
			throw new Error(`previous ${registry} recovery digest does not match the release contract`)
		}
	}
}

export function verifyStagedRelease(root) {
	const absolute = resolve(root)
	const { state } = verifyStagingChecksums(absolute)
	const verificationRoot = join(absolute, 'verification')
	const pkg = readJson(join(verificationRoot, 'package.json'))
	const contractRaw = readFileSync(join(absolute, 'release-contract.json'))
	const verificationContractRaw = readFileSync(join(verificationRoot, 'release-contract.json'))
	const contract = JSON.parse(contractRaw.toString('utf8'))

	if (!contractRaw.equals(verificationContractRaw)) {
		throw new Error('verification release-contract.json does not match staged release-contract.json')
	}
	if (pkg.version !== state.version || contract.packageVersion !== state.version) {
		throw new Error('staged package and release contract version mismatch')
	}
	if (
		contract.sourceTreeDigest !== state.sourceTreeDigest ||
		contract.release?.version !== state.version
	) {
		throw new Error('staged release contract content identity mismatch')
	}

	verifyRecoveryState(absolute, contract, state.version)
	const expected = expectedIdentity(absolute, state)
	const options = { expectedIdentity: expected }
	const npm = inspectNpmPack(join(absolute, `fluentcart-mcp-${state.version}.tgz`), options)
	const mcpb = inspectMcpb(join(absolute, 'fluentcart-mcp.mcpb'), options)
	const failures = [...npm.failures, ...mcpb.failures]
	if (failures.length > 0) throw new Error(`staged archive inspection failed: ${failures.join(' | ')}`)
	const docker = inspectDockerContext(
		join(absolute, 'fluentcart-mcp-docker-context.tar.gz'),
		expected,
		npm.provenance?.invocationId,
		contractRaw,
	)
	if (
		npm.provenance?.invocationId !== mcpb.provenance?.invocationId ||
		npm.provenance?.invocationId !== docker.invocationId
	) {
		throw new Error('staged archives do not share one release invocation')
	}
	return { version: state.version, sourceSha: state.sourceSha, sourceTreeDigest: state.sourceTreeDigest }
}

if (process.argv[1] && pathToFileURL(process.argv[1]).href === import.meta.url) {
	const index = process.argv.indexOf('--root')
	const root = index < 0 ? dirname(SCRIPT_ROOT) : process.argv[index + 1]
	if (!root) {
		process.stderr.write('usage: node verify-staged-release.mjs --root <staging-directory>\n')
		process.exit(2)
	}
	const result = verifyStagedRelease(root)
	process.stdout.write(
		`verified staged ${basename(root)} ${result.version} at ${result.sourceTreeDigest} from ${result.sourceSha}\n`,
	)
}
