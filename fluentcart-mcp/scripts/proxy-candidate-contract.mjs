import assert from 'node:assert/strict'

export const CANDIDATE_BEHAVIOURS = [
	'tls',
	'forwarding',
	'streaming',
	'cancellation',
	'reconnect',
	'oversizedBody',
	'rateLimit',
	'connectionLimit',
]

const DIGEST = /^sha256:[0-9a-f]{64}$/
const CONTENT_LABEL = 'sh.vcode.fluentcart-mcp.candidate-content-digest'
const REVISION_LABEL = 'org.opencontainers.image.revision'

export function verifyCandidateImageIdentity(inspected, expected) {
	for (const key of ['imageId', 'imageDigest', 'candidateContentDigest']) {
		assert.match(expected[key] ?? '', DIGEST, `candidate ${key} must be an immutable digest`)
	}
	assert.equal(inspected.Id, expected.imageId, 'candidate image ID differs from the expected image ID')
	const digestMatches =
		inspected.Id === expected.imageDigest ||
		(inspected.RepoDigests ?? []).some((value) => value.endsWith(`@${expected.imageDigest}`))
	assert.ok(digestMatches, 'candidate image digest differs from the expected image digest')
	const labels = inspected.Config?.Labels ?? {}
	assert.equal(
		labels[CONTENT_LABEL],
		expected.candidateContentDigest,
		'candidate content digest label differs from release-contract sourceTreeDigest',
	)
	if (expected.sourceSha === null) {
		assert.equal(labels[REVISION_LABEL], undefined, 'uncommitted candidate cannot claim a revision')
	} else {
		assert.match(expected.sourceSha ?? '', /^[0-9a-f]{40}$/, 'candidate sourceSha must be committed')
		assert.equal(labels[REVISION_LABEL], expected.sourceSha, 'candidate revision differs from sourceSha')
	}
	return {
		...expected,
		imageId: inspected.Id,
		imageDigest: inspected.Id === expected.imageDigest
			? inspected.Id
			: inspected.RepoDigests.find((value) => value.endsWith(`@${expected.imageDigest}`)).split('@').at(-1),
		candidateContentDigest: labels[CONTENT_LABEL],
		sourceSha: labels[REVISION_LABEL] ?? null,
	}
}

export function assessCandidateProxyResult(result, expected) {
	const missing = []
	if (result?.candidateBacked !== true) missing.push('candidateBacked')
	if (
		!result?.identity ||
		Object.entries(expected).some(([key, value]) => result.identity[key] !== value)
	) {
		missing.push('identity')
	}
	for (const behaviour of CANDIDATE_BEHAVIOURS) {
		const observation = result?.observations?.[behaviour]
		if (observation?.passed !== true || observation.candidateImageId !== expected.imageId) {
			missing.push(behaviour)
		}
	}
	return missing.length === 0 ? { status: 'PASS', missing: [] } : { status: 'BLOCKED', missing }
}
