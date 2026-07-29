#!/usr/bin/env node

import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { writeJsonAtomic } from './acceptance/evidence-writer.mjs'
import { verifyCandidateImageIdentity } from './proxy-candidate-contract.mjs'
import { expectedReleaseIdentity } from './release-identity.mjs'

export class CandidatePrerequisiteError extends Error {}

function inspectImage(image) {
	const result = spawnSync('docker', ['image', 'inspect', image, '--format={{json .}}'], {
		encoding: 'utf8',
		timeout: 30_000,
	})
	if (result.status !== 0) {
		throw new CandidatePrerequisiteError(
			`candidate image inspection failed: ${result.stderr || result.stdout}`,
		)
	}
	return JSON.parse(result.stdout)
}

export function verifyAcceptanceCandidate(environment = process.env, dependencies = {}) {
	const release = (dependencies.expectedReleaseIdentity ?? expectedReleaseIdentity)()
	const inspect = dependencies.inspectImage ?? inspectImage
	const image = environment.FLUENTCART_ACCEPTANCE_IMAGE
	const imageId = environment.FLUENTCART_ACCEPTANCE_IMAGE_ID
	const imageDigest = environment.FLUENTCART_ACCEPTANCE_IMAGE_DIGEST
	for (const [name, value] of Object.entries({ image, imageId, imageDigest })) {
		if (!value) throw new CandidatePrerequisiteError(`candidate preflight requires ${name}`)
	}
	const identity = verifyCandidateImageIdentity(inspect(image), {
		imageId,
		imageDigest,
		candidateContentDigest: release.candidateContentDigest,
		sourceSha: release.sourceSha,
	})
	return {
		image,
		identity,
		baseCommitSha: release.baseCommitSha,
		sourceShaKind: release.sourceShaKind,
	}
}

export function runCandidatePreflight(environment = process.env, dependencies = {}) {
	const runDirectory = environment.FLUENTCART_ACCEPTANCE_RUN_DIR
	assert.ok(runDirectory, 'candidate preflight requires FLUENTCART_ACCEPTANCE_RUN_DIR')
	const candidate = verifyAcceptanceCandidate(environment, dependencies)
	const evidence = {
		schemaVersion: 1,
		producer: 'scripts/verify-acceptance-candidate.mjs',
		producedAt: new Date().toISOString(),
		...candidate,
	}
	writeJsonAtomic(join(runDirectory, 'candidate.json'), evidence)
	return evidence
}

const direct = process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)
if (direct) {
	try {
		const result = runCandidatePreflight()
		process.stdout.write(`PASS: inspected candidate ${result.identity.imageId}\n`)
	} catch (error) {
		process.stderr.write(`${error?.message ?? String(error)}\n`)
		process.exitCode = error instanceof CandidatePrerequisiteError ? 2 : 1
	}
}
