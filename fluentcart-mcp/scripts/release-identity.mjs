import { execFileSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

export const PACKAGE_ROOT = dirname(dirname(fileURLToPath(import.meta.url)))
export const PROVENANCE_PATH = 'dist/release-provenance.json'
export const PROVENANCE_SCHEMA_VERSION = 2

function sha256(buffer) {
	return `sha256:${createHash('sha256').update(buffer).digest('hex')}`
}

function readJson(path) {
	return JSON.parse(readFileSync(path, 'utf8'))
}

function commitIdentity(root) {
	const fromCi = process.env.GITHUB_SHA
	if (typeof fromCi === 'string' && /^[0-9a-f]{40}$/i.test(fromCi)) {
		return { sourceSha: fromCi.toLowerCase(), sourceShaKind: 'committed-ci' }
	}

	const value = execFileSync('git', ['rev-parse', 'HEAD'], {
		cwd: root,
		encoding: 'utf8',
		stdio: ['ignore', 'pipe', 'ignore'],
	}).trim()
	if (!/^[0-9a-f]{40}$/i.test(value)) {
		throw new Error('could not resolve the full source commit SHA for release provenance')
	}
	return { sourceSha: null, sourceShaKind: 'uncommitted-local', baseCommitSha: value.toLowerCase() }
}

/** Identity shared by every artefact produced from one checkout. */
export function expectedReleaseIdentity(root = PACKAGE_ROOT) {
	const pkg = readJson(join(root, 'package.json'))
	const contractPath = join(root, 'release-contract.json')
	const contract = readJson(contractPath)
	const commit = commitIdentity(root)

	return {
		schemaVersion: PROVENANCE_SCHEMA_VERSION,
		packageVersion: pkg.version,
		sourceTreeDigest: contract.sourceTreeDigest,
		candidateContentDigest: contract.sourceTreeDigest,
		releaseContractDigest: sha256(readFileSync(contractPath)),
		packageLockDigest: sha256(readFileSync(join(root, 'package-lock.json'))),
		baseCommitSha: commit.baseCommitSha ?? commit.sourceSha,
		sourceSha: commit.sourceSha,
		sourceShaKind: commit.sourceShaKind,
	}
}

export function buildReleaseProvenance(invocationId, root = PACKAGE_ROOT) {
	if (typeof invocationId !== 'string' || invocationId.trim() === '') {
		throw new Error('release invocation id must be a non-empty string')
	}
	return { ...expectedReleaseIdentity(root), invocationId }
}

/**
 * Compare an artefact's embedded identity with this checkout.
 *
 * `invocationId` is supplied by the one-shot release packer. Standalone inspection still binds
 * an archive to the exact source, lockfile and contract, while the packer additionally refuses an
 * otherwise-current artefact left by an earlier invocation.
 */
export function releaseIdentityFailures(provenance, expected, invocationId) {
	if (provenance === null || typeof provenance !== 'object' || Array.isArray(provenance)) {
		return [`missing or invalid ${PROVENANCE_PATH}`]
	}

	const failures = []
	const compare = (key, label) => {
		if (provenance[key] !== expected[key]) {
			failures.push(
				`${label} ${String(provenance[key])} does not match expected ${String(expected[key])}`,
			)
		}
	}

	compare('schemaVersion', 'provenance schema')
	compare('packageVersion', 'package version')
	compare('sourceTreeDigest', 'source tree digest')
	compare('candidateContentDigest', 'candidate content digest')
	compare('releaseContractDigest', 'release contract digest')
	compare('packageLockDigest', 'package-lock digest')
	compare('baseCommitSha', 'base commit SHA')
	compare('sourceSha', 'source SHA')
	compare('sourceShaKind', 'source SHA kind')

	if (typeof provenance.invocationId !== 'string' || provenance.invocationId === '') {
		failures.push('release invocation id is missing')
	} else if (invocationId !== undefined && provenance.invocationId !== invocationId) {
		failures.push(
			`release invocation id ${provenance.invocationId} does not match current invocation ${invocationId}`,
		)
	}

	return failures
}
