#!/usr/bin/env node

import { spawn } from 'node:child_process'
import { mkdir, readdir, readFile, rm, writeFile } from 'node:fs/promises'
import { dirname, join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { startConformanceFixture } from './serve-conformance-fixture.mjs'

const PACKAGE_ROOT = dirname(dirname(fileURLToPath(import.meta.url)))
const CONFORMANCE_BINARY = join(PACKAGE_ROOT, 'node_modules', '.bin', 'conformance')
const FAILURE_ARTIFACT = join(
	PACKAGE_ROOT,
	'test-results',
	'conformance',
	'failure-metadata.json',
)

export const CONFORMANCE_MATRIX = Object.freeze([
	['server-initialize', '2025-11-25'],
	['server-stateless', '2026-07-28'],
	['ping', '2025-11-25'],
	['tools-list', '2025-11-25'],
	['tools-list', '2026-07-28'],
	['tools-call-simple-text', '2025-11-25'],
	['tools-call-simple-text', '2026-07-28'],
	['json-schema-2020-12', '2025-11-25'],
	['json-schema-2020-12', '2026-07-28'],
	['resources-list', '2025-11-25'],
	['resources-list', '2026-07-28'],
	['resources-read-text', '2025-11-25'],
	['resources-read-text', '2026-07-28'],
	['prompts-list', '2025-11-25'],
	['prompts-list', '2026-07-28'],
	['prompts-get-simple', '2025-11-25'],
	['prompts-get-simple', '2026-07-28'],
	['prompts-get-with-args', '2025-11-25'],
	['prompts-get-with-args', '2026-07-28'],
	['dns-rebinding-protection', '2025-11-25'],
	['dns-rebinding-protection', '2026-07-28'],
	['http-header-validation', '2026-07-28'],
])

export function buildConformanceArgs(mcpUrl, scenario, version, outputDir) {
	return [
		'server',
		'--url',
		mcpUrl,
		'--scenario',
		scenario,
		'--spec-version',
		version,
		'--output-dir',
		outputDir,
	]
}

async function findCheckFiles(directory) {
	const found = []
	for (const entry of await readdir(directory, { withFileTypes: true }).catch(() => [])) {
		const path = join(directory, entry.name)
		if (entry.isDirectory()) found.push(...(await findCheckFiles(path)))
		else if (entry.name === 'checks.json') found.push(path)
	}
	return found
}

async function readChecks(outputDir) {
	const files = await findCheckFiles(outputDir)
	const checks = []
	for (const file of files) {
		const parsed = JSON.parse(await readFile(file, 'utf8'))
		if (Array.isArray(parsed)) checks.push(...parsed)
	}
	return checks
}

function executeBinary(binary, args, outputDir) {
	return new Promise((resolve, reject) => {
		const child = spawn(binary, args, {
			cwd: PACKAGE_ROOT,
			stdio: ['ignore', 'pipe', 'pipe'],
		})
		let stdout = ''
		let stderr = ''
		child.stdout.on('data', (chunk) => {
			stdout += chunk
		})
		child.stderr.on('data', (chunk) => {
			stderr += chunk
		})
		child.once('error', reject)
		child.once('close', async (code) => {
			try {
				resolve({ exitCode: code ?? 1, checks: await readChecks(outputDir), stdout, stderr })
			} catch (error) {
				reject(error)
			}
		})
	})
}

function checkMetadata(check) {
	return Object.fromEntries(
		['id', 'name', 'description', 'status', 'errorMessage', 'specReferences']
			.filter((key) => check[key] !== undefined)
			.map((key) => [key, check[key]]),
	)
}

function runnerFailure(error) {
	return {
		id: 'conformance-runner',
		name: 'ConformanceRunner',
		description: 'The official conformance invocation completed and emitted checks.',
		status: 'FAILURE',
		errorMessage: error instanceof Error ? error.message : String(error),
	}
}

export async function runConformanceMatrix({
	mcpUrl,
	binary = CONFORMANCE_BINARY,
	resultsRoot,
	artifactPath = FAILURE_ARTIFACT,
	execute = executeBinary,
}) {
	await rm(artifactPath, { force: true })
	await rm(resultsRoot, { recursive: true, force: true })
	await mkdir(resultsRoot, { recursive: true })
	const runs = []

	try {
		for (const [index, [scenario, specVersion]] of CONFORMANCE_MATRIX.entries()) {
			const outputDir = join(resultsRoot, `${String(index + 1).padStart(2, '0')}-${scenario}`)
			const args = buildConformanceArgs(mcpUrl, scenario, specVersion, outputDir)
			let result
			try {
				result = await execute(binary, args, outputDir)
			} catch (error) {
				result = { exitCode: 1, checks: [runnerFailure(error)] }
			}
			const checks =
				result.checks.length === 0
					? [runnerFailure('The official runner emitted no checks.json metadata.')]
					: result.checks
			runs.push({
				scenario,
				specVersion,
				exitCode: result.exitCode,
				checks: checks.map(checkMetadata),
			})
			process.stderr.write(
				`[conformance] ${scenario} ${specVersion}: ${checks.length} checks, exit ${result.exitCode}\n`,
			)
		}
	} finally {
		await rm(resultsRoot, { recursive: true, force: true })
	}

	const failures = runs.reduce(
		(total, run) =>
			total + run.checks.filter(({ status }) => status === 'FAILURE').length + (run.exitCode ? 1 : 0),
		0,
	)
	const warnings = runs.reduce(
		(total, run) => total + run.checks.filter(({ status }) => status === 'WARNING').length,
		0,
	)
	if (failures > 0 || warnings > 0) {
		await mkdir(dirname(artifactPath), { recursive: true })
		await writeFile(
			artifactPath,
			`${JSON.stringify({ schemaVersion: 1, runs }, null, 2)}\n`,
			'utf8',
		)
		throw new Error(
			`Official conformance returned warning or failure checks: ${failures} failures, ${warnings} warnings.`,
		)
	}
	return { runs: runs.length, failures, warnings }
}

async function waitForHealth(url, timeoutMs = 10_000) {
	const deadline = Date.now() + timeoutMs
	let lastError
	while (Date.now() < deadline) {
		try {
			const response = await fetch(url, { signal: AbortSignal.timeout(1_000) })
			if (response.ok) return
			lastError = new Error(`Health returned HTTP ${response.status}.`)
		} catch (error) {
			lastError = error
		}
		await new Promise((resolve) => setTimeout(resolve, 50))
	}
	throw new Error(`Conformance fixture health check timed out: ${String(lastError)}`)
}

export async function main(argv = process.argv.slice(2)) {
	if (argv.length > 0) {
		if (argv.includes('--expected-failures')) {
			throw new Error('Expected-failure files require explicit owner approval and are disabled.')
		}
		throw new Error(`Unexpected conformance runner arguments: ${argv.join(' ')}`)
	}
	const resultsRoot = join(
		PACKAGE_ROOT,
		'test-results',
		'conformance',
		`.raw-${process.pid}`,
	)
	const fixture = await startConformanceFixture()
	try {
		await waitForHealth(`${fixture.url}/health`)
		const summary = await runConformanceMatrix({
			mcpUrl: `${fixture.url}/mcp`,
			resultsRoot,
		})
		process.stderr.write(
			`[conformance] ${summary.runs} scenario/version runs passed with zero failures and warnings.\n`,
		)
	} finally {
		await fixture.close()
	}
}

if (process.argv[1] && pathToFileURL(process.argv[1]).href === import.meta.url) {
	main().catch((error) => {
		process.stderr.write(`${error instanceof Error ? error.message : String(error)}\n`)
		process.exitCode = 1
	})
}
