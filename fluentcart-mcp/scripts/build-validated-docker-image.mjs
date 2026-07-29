#!/usr/bin/env node
/**
 * Build the Docker image from a context archive that has already been inspected and checksummed.
 *
 * The digest is verified before anything is extracted, so the image is built from the same bytes
 * that passed inspection rather than from a second, unverified checkout. Extraction is refused
 * outright if the archive contains a symlink, a traversal path or anything outside the declared
 * allowlist — a build context is a filesystem handed to a daemon, and it gets checked like one.
 */

import { execFileSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import { mkdtempSync, readFileSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { gunzipSync } from 'node:zlib'
import { DOCKER_CONTEXT_ALLOWLIST, DOCKER_CONTEXT_EXCLUSIONS } from './build-release-artifacts.mjs'
import { readTar, unsafePath } from './inspect-npm-pack.mjs'
import { PROVENANCE_PATH, PROVENANCE_SCHEMA_VERSION } from './release-identity.mjs'

const PACKAGE_ROOT = dirname(dirname(fileURLToPath(import.meta.url)))

export function parseArguments(argv) {
	const options = {}
	for (let index = 0; index < argv.length; index += 1) {
		const flag = argv[index]
		if (flag.startsWith('--')) options[flag.slice(2)] = argv[index + 1]
	}
	return options
}

/** The checksum manifest is the authority; a context that does not match it is not built. */
export function verifyContextDigest({ contextPath, checksumsPath }) {
	const manifest = JSON.parse(readFileSync(checksumsPath, 'utf8'))
	const name = contextPath.split('/').pop()
	const expected = manifest.files.find((entry) => entry.file === name)

	if (!expected) throw new Error(`${name} is not listed in ${checksumsPath}`)

	const actual = createHash('sha256').update(readFileSync(contextPath)).digest('hex')
	if (actual !== expected.sha256) {
		throw new Error(`${name} digest ${actual} does not match the recorded ${expected.sha256}`)
	}
	return actual
}

/**
 * Refuse anything that is not plainly a regular file inside the declared context.
 *
 * The symlink rule is absolute and stays that way. A link is a redirection evaluated by whoever
 * extracts the archive, so admitting "safe-looking" ones would mean this validator, `tar`, and
 * the Docker daemon each having to agree on what a target resolves to — and a build context is
 * a filesystem handed to a root daemon, which is the wrong place to be nearly right. The one
 * link a production install actually produces lives in `node_modules/.bin`, and the packer
 * excludes that directory instead, so nothing legitimate needs an exception here.
 */
export function assertSafeContext(entries) {
	const problems = []

	for (const entry of entries) {
		const name = entry.name.replace(/^\.\//, '')

		const unsafe = unsafePath(entry.name)
		if (unsafe) problems.push(`${unsafe}: ${entry.name}`)

		if (entry.typeFlag === '1' || entry.typeFlag === '2') {
			// Named explicitly, because "why is there a symlink" has exactly one common answer and
			// a bare complaint sends the reader hunting for an attack that is not there.
			const hint = DOCKER_CONTEXT_EXCLUSIONS.some((prefix) => name.startsWith(prefix))
				? ' (this path is excluded from the packed context; rebuild it with npm run pack:release)'
				: ''
			problems.push(`symlink: ${entry.name} -> ${entry.linkName}${hint}`)
		}

		const root = name.split('/')[0]
		if (root !== '' && !DOCKER_CONTEXT_ALLOWLIST.includes(root)) {
			problems.push(`outside the context allowlist: ${entry.name}`)
		}

		if (DOCKER_CONTEXT_EXCLUSIONS.some((prefix) => name.startsWith(prefix))) {
			problems.push(`excluded from the declared context: ${entry.name}`)
		}
	}

	if (problems.length > 0) {
		throw new Error(`Docker context rejected:\n${problems.map((line) => `  - ${line}`).join('\n')}`)
	}
	return entries.length
}

/**
 * Version comes from the context's own `package.json` rather than the working tree, so a stale
 * checkout cannot label an image with a version it does not contain.
 */
export function readContextIdentity(entries, sourceSha = null) {
	const packageEntry = entries.find((entry) => entry.name.replace(/^\.\//, '') === 'package.json')
	if (!packageEntry) throw new Error('context archive has no package.json')
	const contractEntry = entries.find(
		(entry) => entry.name.replace(/^\.\//, '') === 'release-contract.json',
	)
	if (!contractEntry) throw new Error('context archive has no release-contract.json')
	const provenanceEntry = entries.find(
		(entry) => entry.name.replace(/^\.\//, '') === PROVENANCE_PATH,
	)
	if (!provenanceEntry) throw new Error(`context archive has no ${PROVENANCE_PATH}`)

	const { version } = JSON.parse(packageEntry.data.toString('utf8'))
	const contract = JSON.parse(contractEntry.data.toString('utf8'))
	const provenance = JSON.parse(provenanceEntry.data.toString('utf8'))
	if (sourceSha !== null && !/^[0-9a-f]{40}$/.test(sourceSha)) {
		throw new Error('--source-sha must be the full 40-character commit SHA the context was built from')
	}
	const contractDigest = `sha256:${createHash('sha256').update(contractEntry.data).digest('hex')}`
	const mismatches = []
	if (provenance.schemaVersion !== PROVENANCE_SCHEMA_VERSION) {
		mismatches.push(`provenance schema ${provenance.schemaVersion}`)
	}
	if (provenance.packageVersion !== version) {
		mismatches.push(`package version ${provenance.packageVersion} != ${version}`)
	}
	if (provenance.sourceTreeDigest !== contract.sourceTreeDigest) {
		mismatches.push('source tree digest does not match the embedded release contract')
	}
	if (provenance.candidateContentDigest !== contract.sourceTreeDigest) {
		mismatches.push('candidate content digest does not match the embedded release contract')
	}
	if (provenance.releaseContractDigest !== contractDigest) {
		mismatches.push('release contract digest does not match the embedded contract bytes')
	}
	if (sourceSha !== null && provenance.sourceSha !== sourceSha) {
		mismatches.push(`source SHA ${provenance.sourceSha} != ${sourceSha}`)
	}
	if (typeof provenance.packageLockDigest !== 'string') {
		mismatches.push('package-lock digest is missing')
	}
	if (typeof provenance.invocationId !== 'string' || provenance.invocationId === '') {
		mismatches.push('release invocation id is missing')
	}
	if (mismatches.length > 0) {
		throw new Error(`Docker context identity rejected:\n${mismatches.map((line) => `  - ${line}`).join('\n')}`)
	}
	return { version, sourceSha: provenance.sourceSha, candidateContentDigest: contract.sourceTreeDigest, provenance }
}

export function buildValidatedDockerImage(options) {
	const contextPath = options.context
	const checksumsPath = options.checksums
	if (!contextPath || !checksumsPath) {
		throw new Error(
			'usage: --context <archive.tar.gz> --checksums <SHA256SUMS.json> [--source-sha <committed-sha>]',
		)
	}

	const digest = verifyContextDigest({ contextPath, checksumsPath })
	const entries = readTar(gunzipSync(readFileSync(contextPath)))
	assertSafeContext(entries)
	const { version, sourceSha, candidateContentDigest } = readContextIdentity(
		entries,
		options['source-sha'],
	)

	const repository = options.repository ?? 'vcodesh/fluentcart-mcp'
	const tags = [
		`${repository}:${version}`,
		...(sourceSha === null ? [] : [`${repository}:${sourceSha.slice(0, 12)}`]),
	]
	const staging = mkdtempSync(join(tmpdir(), 'fluentcart-docker-'))

	try {
		execFileSync('tar', ['-xzf', contextPath, '-C', staging], { stdio: ['ignore', 2, 2] })
		execFileSync(
			'docker',
			[
				'build',
				'--file',
				join(staging, 'Dockerfile.release'),
				'--label',
				`org.opencontainers.image.version=${version}`,
				...(sourceSha === null
					? []
					: ['--label', `org.opencontainers.image.revision=${sourceSha}`]),
				'--label',
				`sh.vcode.fluentcart-mcp.candidate-content-digest=${candidateContentDigest}`,
				...tags.flatMap((tag) => ['--tag', tag]),
				staging,
			],
			{ stdio: ['ignore', 2, 2] },
		)
	} finally {
		rmSync(staging, { recursive: true, force: true })
	}

	return { version, sourceSha, candidateContentDigest, digest, tags, entryCount: entries.length }
}

if (process.argv[1] && pathToFileURL(process.argv[1]).href === import.meta.url) {
	const options = parseArguments(process.argv.slice(2))
	const result = buildValidatedDockerImage(options)
	process.stdout.write(`${JSON.stringify(result, null, 2)}\n`)
	process.stderr.write(`built ${result.tags.join(' and ')} from ${PACKAGE_ROOT}\n`)
}
