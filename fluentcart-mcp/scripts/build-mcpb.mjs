#!/usr/bin/env node
/**
 * Build the MCPB bundle in an isolated staging directory.
 *
 * Nothing here touches the working tree. The previous `pack:mcpb` ran `npm prune --production`
 * in place, which destroyed the developer's install and left the lockfile's dev tree missing
 * until someone noticed; production dependencies are now installed into a temporary directory
 * instead. The MCPB CLI is the repository-local pinned binary, never a global install.
 */

import { execFileSync } from 'node:child_process'
import { cpSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { computeSourceTreeDigest } from './release-contract-inputs.mjs'
import { META_NAMESPACE } from './build-manifest.mjs'

export const PACKAGE_ROOT = dirname(dirname(fileURLToPath(import.meta.url)))
export const DIST_PACKAGES = join(PACKAGE_ROOT, 'dist-packages')
export const MCPB_CLI = join(PACKAGE_ROOT, 'node_modules', '.bin', 'mcpb')

/** Metadata copied beside the compiled runtime. Anything absent from this list never ships. */
export const RUNTIME_METADATA = ['package.json', 'manifest.json', 'README.md', 'LICENSE']

function run(command, args, cwd) {
	// stdout is redirected to stderr so a caller can pipe machine-readable output on fd 1.
	execFileSync(command, args, { cwd, stdio: ['ignore', 2, 2] })
}

export function readJson(path) {
	return JSON.parse(readFileSync(path, 'utf8'))
}

/**
 * Compile with `tsconfig.release.json`, which emits runtime JavaScript and declarations but no
 * source or declaration maps. Maps would leak the source tree into a published artefact and are
 * rejected by both inspectors, so the release build never produces them in the first place.
 */
export function compileReleaseDist(outDir) {
	mkdirSync(outDir, { recursive: true })
	run(
		join(PACKAGE_ROOT, 'node_modules', '.bin', 'tsc'),
		['-p', join(PACKAGE_ROOT, 'tsconfig.release.json'), '--outDir', outDir],
		PACKAGE_ROOT,
	)
	return outDir
}

/**
 * Assemble the tree the bundle ships: metadata, the compiled runtime and production dependencies
 * only. `npm ci` runs inside the staging directory against copies of the manifest and lockfile,
 * so the repository's own `node_modules` and `package-lock.json` are never written to.
 */
export function stageRuntimeTree({ stagingDir, releaseDist }) {
	mkdirSync(stagingDir, { recursive: true })
	for (const name of RUNTIME_METADATA) {
		cpSync(join(PACKAGE_ROOT, name), join(stagingDir, name))
	}
	cpSync(join(PACKAGE_ROOT, 'package-lock.json'), join(stagingDir, 'package-lock.json'))
	cpSync(releaseDist, join(stagingDir, 'dist'), { recursive: true })

	run('npm', ['ci', '--omit=dev', '--ignore-scripts', '--no-audit', '--no-fund'], stagingDir)

	// The lockfile is an input to the install, not part of the bundle.
	rmSync(join(stagingDir, 'package-lock.json'), { force: true })
	return stagingDir
}

export function packMcpb({ stagingDir, outputPath }) {
	mkdirSync(dirname(outputPath), { recursive: true })
	run(MCPB_CLI, ['validate', join(stagingDir, 'manifest.json')], PACKAGE_ROOT)
	run(MCPB_CLI, ['pack', stagingDir, outputPath], PACKAGE_ROOT)
	return outputPath
}

/** Fail before compiling rather than after packing an artefact nobody can trust. */
export function assertVersionsAgree() {
	const pkg = readJson(join(PACKAGE_ROOT, 'package.json'))
	const manifest = readJson(join(PACKAGE_ROOT, 'manifest.json'))
	const contract = readJson(join(PACKAGE_ROOT, 'release-contract.json'))

	if (manifest.version !== pkg.version) {
		throw new Error(`manifest.json version ${manifest.version} does not match package.json ${pkg.version}.`)
	}
	if (contract.packageVersion !== pkg.version) {
		throw new Error(`release-contract.json version ${contract.packageVersion} does not match package.json ${pkg.version}.`)
	}
	const currentDigest = computeSourceTreeDigest()
	if (contract.sourceTreeDigest !== currentDigest) {
		throw new Error(
			`release-contract.json source tree ${contract.sourceTreeDigest} does not match current ${currentDigest}.`,
		)
	}
	const manifestMeta = manifest._meta?.[META_NAMESPACE]
	if (manifestMeta?.sourceTreeDigest !== contract.sourceTreeDigest) {
		throw new Error(
			`manifest.json source tree ${manifestMeta?.sourceTreeDigest} does not match release contract ${contract.sourceTreeDigest}.`,
		)
	}
	return pkg.version
}

export function buildMcpb({ outputPath = join(DIST_PACKAGES, 'fluentcart-mcp.mcpb') } = {}) {
	assertVersionsAgree()
	const root = mkdtempSync(join(tmpdir(), 'fluentcart-mcpb-'))
	try {
		const releaseDist = compileReleaseDist(join(root, 'dist'))
		const stagingDir = stageRuntimeTree({ stagingDir: join(root, 'bundle'), releaseDist })
		packMcpb({ stagingDir, outputPath })
		return outputPath
	} finally {
		rmSync(root, { recursive: true, force: true })
	}
}

export function writeChecksums(entries, outputPath) {
	const sorted = [...entries].sort((a, b) => (a.file < b.file ? -1 : 1))
	const body = { algorithm: 'sha256', files: sorted }
	writeFileSync(outputPath, `${JSON.stringify(body, null, 2)}\n`)
	return body
}

if (process.argv[1] && pathToFileURL(process.argv[1]).href === import.meta.url) {
	const output = buildMcpb()
	process.stderr.write(`wrote ${output}\n`)
}
