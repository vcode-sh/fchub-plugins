#!/usr/bin/env node
/** Bind npm publication mode and immutable candidate bytes into checksum-bound release evidence. */

import { createHash } from 'node:crypto'
import { appendFileSync, readFileSync, writeFileSync } from 'node:fs'
import { pathToFileURL } from 'node:url'

const UUID = /^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i

function sha(bytes, algorithm, encoding) {
	return createHash(algorithm).update(bytes).digest(encoding)
}

export function parseNativeStageResult(raw, packageName, version, expectedIntegrity) {
	const parsed = typeof raw === 'string' ? JSON.parse(raw) : raw
	if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
		throw new Error('npm stage publish returned an invalid JSON object')
	}
	const keys = Object.keys(parsed)
	if (keys.length !== 1 || keys[0] !== packageName) {
		throw new Error(`npm stage publish did not return the expected ${packageName} package key`)
	}
	const result = parsed[packageName]
	if (result?.name !== packageName || result?.version !== version) {
		throw new Error('npm stage publish returned the wrong package identity')
	}
	if (result.integrity !== expectedIntegrity) {
		throw new Error('npm stage publish integrity does not match the inspected tarball')
	}
	if (!UUID.test(result.stageId ?? '')) {
		throw new Error('npm stage publish did not return a valid stageId')
	}
	return result
}

export function buildStagingState({
	stageResult,
	tarballBytes,
	checksumsBytes,
	version,
	sourceSha,
	sourceTreeDigest,
	ghcrDigest,
	dockerhubDigest,
}) {
	const expectedIntegrity = `sha512-${sha(tarballBytes, 'sha512', 'base64')}`
	const result = parseNativeStageResult(
		stageResult,
		'fluentcart-mcp',
		version,
		expectedIntegrity,
	)
	return {
		schemaVersion: 3,
		version,
		sourceSha,
		sourceTreeDigest,
		checksumsSha256: sha(checksumsBytes, 'sha256', 'hex'),
		npm: { stageId: result.stageId, tag: 'latest', expectedIntegrity },
		dockerDigests: {
			'ghcr.io': ghcrDigest,
			'docker.io': dockerhubDigest,
		},
	}
}

export function buildDirectPublishingState({
	tarballBytes,
	checksumsBytes,
	version,
	sourceSha,
	sourceTreeDigest,
	ghcrDigest,
	dockerhubDigest,
}) {
	const expectedIntegrity = `sha512-${sha(tarballBytes, 'sha512', 'base64')}`
	return {
		schemaVersion: 4,
		version,
		sourceSha,
		sourceTreeDigest,
		checksumsSha256: sha(checksumsBytes, 'sha256', 'hex'),
		npm: { mode: 'direct', tag: 'latest', expectedIntegrity },
		dockerDigests: {
			'ghcr.io': ghcrDigest,
			'docker.io': dockerhubDigest,
		},
	}
}

function required(name) {
	const value = process.env[name]
	if (!value) throw new Error(`${name} is required`)
	return value
}

function main() {
	const version = required('VERSION')
	const common = {
		tarballBytes: readFileSync(`dist-packages/fluentcart-mcp-${version}.tgz`),
		checksumsBytes: readFileSync('dist-packages/SHA256SUMS.json'),
		version,
		sourceSha: required('SOURCE_SHA'),
		sourceTreeDigest: required('SOURCE_TREE_DIGEST'),
		ghcrDigest: required('GHCR_DIGEST'),
		dockerhubDigest: required('DOCKERHUB_DIGEST'),
	}
	const state =
		process.env.PUBLISH_MODE === 'direct'
			? buildDirectPublishingState(common)
			: buildStagingState({
					...common,
					stageResult: readFileSync(required('STAGE_RESULT'), 'utf8'),
				})
	writeFileSync('dist-packages/staging-state.json', `${JSON.stringify(state, null, 2)}\n`)
	if (process.env.GITHUB_STEP_SUMMARY) {
		const summary =
			state.schemaVersion === 4
				? `npm publication: Trusted Publishing to \`${state.npm.tag}\`\n`
				: `npm stage ID: \`${state.npm.stageId}\`\n`
		appendFileSync(process.env.GITHUB_STEP_SUMMARY, summary)
	}
}

if (process.argv[1] && pathToFileURL(process.argv[1]).href === import.meta.url) main()
