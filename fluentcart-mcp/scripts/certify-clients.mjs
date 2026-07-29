#!/usr/bin/env node

import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { existsSync, lstatSync, mkdirSync, realpathSync, statSync } from 'node:fs'
import { basename, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { writeJsonAtomic } from './acceptance/evidence-writer.mjs'
import {
	certificationState,
	CLIENT_CELLS,
	configurationTargetFor,
	isConfigurationTarget,
	validateClientEvidence,
	versionCommandFor,
	VERSION_COMMANDS,
} from './client-evidence-contract.mjs'
import { ClientAdapters } from './client-adapters.mjs'
import { verifyCandidateImageIdentity } from './proxy-candidate-contract.mjs'
import { expectedReleaseIdentity } from './release-identity.mjs'

export {
	certificationState,
	CLIENT_CELLS,
	configurationTargetFor,
	isConfigurationTarget,
	validateClientEvidence,
	versionCommandFor,
	VERSION_COMMANDS,
} from './client-evidence-contract.mjs'

const CLIENTS = [...new Set(CLIENT_CELLS.map(({ client }) => client))]

function runVersion(command) {
	const result = spawnSync(command[0], command.slice(1), {
		encoding: 'utf8',
		timeout: 30_000,
		env: { ...process.env },
	})
	return result.status === 0 ? result.stdout.trim() : null
}

function resolveCandidate() {
	const identity = expectedReleaseIdentity()
	const image = process.env.FLUENTCART_ACCEPTANCE_IMAGE ?? null
	const imageId = process.env.FLUENTCART_ACCEPTANCE_IMAGE_ID ?? null
	const imageDigest = process.env.FLUENTCART_ACCEPTANCE_IMAGE_DIGEST ?? null
	const candidate = {
		image,
		imageId,
		imageDigest,
		candidateContentDigest: identity.candidateContentDigest,
		baseCommitSha: identity.baseCommitSha,
		sourceSha: identity.sourceSha,
		sourceShaKind: identity.sourceShaKind,
	}
	if (!(image && imageId && imageDigest)) {
		return { ...candidate, image: null, imageId: null, imageDigest: null }
	}
	const inspected = JSON.parse(runVersion(['docker', 'image', 'inspect', image, '--format={{json .}}']))
	verifyCandidateImageIdentity(inspected, candidate)
	return candidate
}

function captureVersions(candidate) {
	return Object.fromEntries(
		CLIENTS.map((client) => {
			if (client === 'Docker smoke' && !candidate.imageId) return [client, null]
			return [client, runVersion(versionCommandFor(client, candidate))]
		}),
	)
}

function clientRoot(runRoot, client) {
	return join(runRoot, client.toLowerCase().replaceAll(' ', '-'))
}

function assertSafeDirectory(path, label) {
	if (!existsSync(path)) return false
	assert.equal(lstatSync(path).isSymbolicLink(), false, `${label} ${path} must not be a symlink`)
	assert.ok(statSync(path).isDirectory(), `${label} ${path} must be a directory`)
	return true
}

function prepareClientRoots(runDirectory) {
	assert.ok(assertSafeDirectory(runDirectory, 'run directory'), 'run directory must exist')
	const canonicalRun = realpathSync(runDirectory)
	const runRoot = join(runDirectory, 'client-config')
	const hasRunRoot = assertSafeDirectory(runRoot, 'client-config root')
	if (hasRunRoot) {
		assert.equal(
			realpathSync(runRoot),
			join(canonicalRun, 'client-config'),
			'client-config root escaped the canonical run directory',
		)
	}
	const roots = Object.fromEntries(CLIENTS.map((client) => [client, clientRoot(runRoot, client)]))
	for (const [client, root] of Object.entries(roots)) {
		assertSafeDirectory(root, `${client.toLowerCase().replaceAll(' ', '-')} root`)
	}
	if (!hasRunRoot) mkdirSync(runRoot, { mode: 0o700 })
	for (const root of Object.values(roots)) {
		if (!existsSync(root)) mkdirSync(root, { mode: 0o700 })
	}
	return { runRoot, roots }
}

export async function certifyClients(options, dependencies = {}) {
	const now = options.now ?? new Date().toISOString()
	const resolveCurrentCandidate = dependencies.resolveCandidate ?? resolveCandidate
	const captureCurrentVersions = dependencies.captureVersions ?? captureVersions
	const { runRoot, roots } = prepareClientRoots(options.runDirectory)
	const candidate = resolveCurrentCandidate()
	const currentVersions = captureCurrentVersions(candidate)
	let adapters = null
	let adapterFailure = null
	let observeCell = dependencies.observeCell
	if (!observeCell && candidate.imageId) {
		try {
			adapters = new ClientAdapters(candidate)
			await adapters.start()
			observeCell = (cell) => adapters.observe(cell)
		} catch (error) {
			adapterFailure = error.message
		}
	}
	const clients = []
	try {
		for (const cell of CLIENT_CELLS) {
			const configurationRoot = roots[cell.client]
			const version = currentVersions[cell.client] ?? null
			if (isConfigurationTarget(cell)) {
				const target = configurationTargetFor(cell)
				clients.push({
					...cell,
					version,
					versionCommand: versionCommandFor(cell.client, candidate),
					configurationRoot,
					evidenceTime: now,
					outcome: 'CONFIGURATION_TARGET',
					reason: null,
					prerequisite: target.prerequisite,
					capabilitySource: target.capabilitySource,
					observedHandshake: null,
				})
				continue
			}
			const observation =
				candidate.imageId && version && observeCell
					? await observeCell({ ...cell, candidate, configurationRoot }).catch((error) => ({
							outcome: 'BLOCKED',
							reason: `named-client adapter failed: ${error.message}`,
						}))
					: {
							outcome: 'BLOCKED',
							reason: !candidate.imageId
								? 'immutable candidate identity is unavailable'
								: !version
									? 'client version command is unavailable'
									: `candidate adapter runtime failed: ${adapterFailure}`,
						}
			clients.push({
				...cell,
				version,
				versionCommand: versionCommandFor(cell.client, candidate),
				configurationRoot,
				evidenceTime: now,
				outcome: observation.outcome,
				reason: observation.outcome === 'PASS' ? null : observation.reason,
				prerequisite: null,
				capabilitySource:
					observation.outcome === 'UNSUPPORTED' ? observation.capabilitySource : null,
				observedHandshake:
					observation.outcome === 'PASS'
						? { protocolVersion: observation.protocolVersion, observedAt: now }
						: null,
			})
		}
	} finally {
		await adapters?.close()
	}
	const evidence = {
		schemaVersion: 3,
		producer: 'scripts/certify-clients.mjs',
		producedAt: now,
		runRoot,
		candidate,
		clients,
	}
	validateClientEvidence(evidence, {
		runDirectory: options.runDirectory,
		now,
		candidate,
		currentVersions,
	})
	writeJsonAtomic(join(options.runDirectory, 'named-clients.json'), evidence)
	return evidence
}

export function validateCurrentClientEvidence(evidence, options) {
	const candidate = resolveCandidate()
	return validateClientEvidence(evidence, {
		runDirectory: options.runDirectory,
		now: new Date().toISOString(),
		candidate,
		currentVersions: captureVersions(candidate),
	})
}

const direct = process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)
if (direct) {
	const runDirectory = process.env.FLUENTCART_ACCEPTANCE_RUN_DIR
	assert.ok(runDirectory, 'client certification requires FLUENTCART_ACCEPTANCE_RUN_DIR')
	const result = await certifyClients({ runDirectory })
	process.stdout.write(
		`${certificationState(result)}: ${basename(runDirectory)} named-client evidence produced\n`,
	)
}
