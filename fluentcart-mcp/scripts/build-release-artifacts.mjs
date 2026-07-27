#!/usr/bin/env node
/**
 * Build every release artefact once, in an isolated staging directory, and inspect it before it
 * is allowed near `dist-packages/`.
 *
 * The working tree is an input and never an output: the compile targets a temporary directory,
 * `npm pack` and `npm ci` run against copies, and nothing is written back to `dist/`,
 * `node_modules/` or `package-lock.json`. Publication consumes the checksums recorded here, so
 * the artefact that was inspected is provably the artefact that ships.
 */

import { execFileSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import { cpSync, mkdirSync, mkdtempSync, readdirSync, readFileSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { pathToFileURL } from 'node:url'
import {
	assertVersionsAgree,
	compileReleaseDist,
	DIST_PACKAGES,
	PACKAGE_ROOT,
	packMcpb,
	stageRuntimeTree,
	writeChecksums,
} from './build-mcpb.mjs'
import { inspectMcpb } from './inspect-mcpb.mjs'
import { inspectNpmPack } from './inspect-npm-pack.mjs'

/** Exactly what the Docker build may see. Anything else is a source leak into a shipped image. */
export const DOCKER_CONTEXT_ALLOWLIST = [
	'Dockerfile.release',
	'dist',
	'node_modules',
	'package.json',
	'release-contract.json',
]

/**
 * Narrowings inside the allowlist, applied when the context is packed.
 *
 * `node_modules/.bin` holds npm's relative launcher symlinks. The image runs
 * `node dist/index.js` and never invokes a package binary, so shipping them adds an unused
 * directory of symlinks to a filesystem we hand to a daemon. Excluding it keeps the context
 * smaller and — the point — lets the validator keep refusing symlinks outright, rather than
 * teaching it to reason about which links happen to resolve somewhere harmless.
 */
export const DOCKER_CONTEXT_EXCLUSIONS = ['node_modules/.bin']

const MCPB_NAME = 'fluentcart-mcp.mcpb'
const DOCKER_CONTEXT_NAME = 'fluentcart-mcp-docker-context.tar.gz'
const CHECKSUMS_NAME = 'SHA256SUMS.json'

function run(command, args, cwd) {
	execFileSync(command, args, { cwd, stdio: ['ignore', 2, 2] })
}

function sha256(path) {
	return createHash('sha256').update(readFileSync(path)).digest('hex')
}

/**
 * Stage the npm tarball from copies of the metadata and the release build. `--ignore-scripts`
 * keeps the staging directory from running `prepublishOnly`, which would try to compile without
 * a `node_modules` and fail for entirely the wrong reason.
 */
function packNpm({ root, releaseDist, destination }) {
	const staging = join(root, 'npm')
	mkdirSync(staging, { recursive: true })
	for (const name of ['package.json', 'README.md', 'LICENSE']) {
		cpSync(join(PACKAGE_ROOT, name), join(staging, name))
	}
	cpSync(releaseDist, join(staging, 'dist'), { recursive: true })

	const before = new Set(readdirSync(destination))
	run('npm', ['pack', '--ignore-scripts', `--pack-destination=${destination}`], staging)
	const created = readdirSync(destination).filter((name) => !before.has(name) && name.endsWith('.tgz'))

	if (created.length !== 1) {
		throw new Error(`expected exactly one new tarball, got ${created.length ? created.join(', ') : 'none'}`)
	}
	return join(destination, created[0])
}

/**
 * Build the Docker context from the already-staged runtime tree, so the image is assembled from
 * the same bytes the MCPB inspection passed rather than from a second, unverified build.
 */
function packDockerContext({ stagingDir, destination }) {
	cpSync(join(PACKAGE_ROOT, 'Dockerfile.release'), join(stagingDir, 'Dockerfile.release'))
	cpSync(join(PACKAGE_ROOT, 'release-contract.json'), join(stagingDir, 'release-contract.json'))

	const output = join(destination, DOCKER_CONTEXT_NAME)
	run(
		'tar',
		[
			'-czf',
			output,
			'-C',
			stagingDir,
			...DOCKER_CONTEXT_EXCLUSIONS.flatMap((pattern) => ['--exclude', pattern]),
			...DOCKER_CONTEXT_ALLOWLIST,
		],
		PACKAGE_ROOT,
	)
	return output
}

function reportFindings(label, result) {
	if (result.failures.length === 0) return false
	process.stderr.write(`${label} rejected:\n${result.failures.map((line) => `  - ${line}`).join('\n')}\n`)
	return true
}

export function buildReleaseArtifacts() {
	const version = assertVersionsAgree()
	mkdirSync(DIST_PACKAGES, { recursive: true })

	// A stale tarball from an earlier version would otherwise sit beside the new one and both
	// would look equally publishable.
	for (const name of readdirSync(DIST_PACKAGES)) {
		if (name.endsWith('.tgz') || name === MCPB_NAME || name === DOCKER_CONTEXT_NAME) {
			rmSync(join(DIST_PACKAGES, name), { force: true })
		}
	}

	const root = mkdtempSync(join(tmpdir(), 'fluentcart-release-'))
	let artefacts
	try {
		const releaseDist = compileReleaseDist(join(root, 'dist'))
		const tarball = packNpm({ root, releaseDist, destination: DIST_PACKAGES })
		const stagingDir = stageRuntimeTree({ stagingDir: join(root, 'bundle'), releaseDist })
		const mcpb = packMcpb({ stagingDir, outputPath: join(DIST_PACKAGES, MCPB_NAME) })
		const context = packDockerContext({ stagingDir, destination: DIST_PACKAGES })
		artefacts = { tarball, mcpb, context }
	} finally {
		rmSync(root, { recursive: true, force: true })
	}

	const npmResult = inspectNpmPack(artefacts.tarball)
	const mcpbResult = inspectMcpb(artefacts.mcpb)
	const rejected = [
		reportFindings('npm tarball', npmResult),
		reportFindings('MCPB bundle', mcpbResult),
	].some(Boolean)

	if (rejected) {
		for (const path of Object.values(artefacts)) rmSync(path, { force: true })
		throw new Error('release artefacts failed inspection; nothing was kept in dist-packages/')
	}

	const checksums = writeChecksums(
		Object.values(artefacts).map((path) => ({
			file: path.slice(DIST_PACKAGES.length + 1),
			sha256: sha256(path),
		})),
		join(DIST_PACKAGES, CHECKSUMS_NAME),
	)

	return { version, npm: npmResult, mcpb: mcpbResult, checksums }
}

if (process.argv[1] && pathToFileURL(process.argv[1]).href === import.meta.url) {
	const result = buildReleaseArtifacts()
	process.stdout.write(`${JSON.stringify(result.checksums, null, 2)}\n`)
	process.stderr.write(
		`fluentcart-mcp ${result.version}: npm ${result.npm.fileCount} files, MCPB ${result.mcpb.entryCount} entries, all inspections clean\n`,
	)
}
