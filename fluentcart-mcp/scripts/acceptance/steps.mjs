// Step resolution and execution for the acceptance harness.
//
// A step is only ever run as an exact argv: `process.execPath` or a resolved binary path, never a
// shell string and never npx. What comes back is judged on the runner's own report where one
// exists, because an exit code cannot tell a proof from a skipped test.

import { spawn } from 'node:child_process'
import { existsSync, mkdtempSync, readFileSync, readdirSync, realpathSync, rmSync, statSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { delimiter, dirname, isAbsolute, join, resolve } from 'node:path'
import { PACKAGE_ROOT } from './evidence-writer.mjs'
import { classify, readJunit } from './test-report.mjs'

let npmRuntime
function resolveNpm() {
	if (npmRuntime !== undefined) return npmRuntime
	const onPath = (process.env.PATH ?? '').split(delimiter).filter(Boolean).map((dir) => join(dir, 'npm'))
	const candidates = [process.env.npm_execpath, ...onPath]
	candidates.push(resolve(dirname(process.execPath), '..', 'lib', 'node_modules', 'npm', 'bin', 'npm-cli.js'))
	npmRuntime = null
	for (const candidate of candidates) {
		if (!candidate || !isAbsolute(candidate) || !existsSync(candidate)) continue
		const real = realpathSync(candidate)
		if (!statSync(real).isFile()) continue
		npmRuntime = real.endsWith('.js') ? { command: process.execPath, prefix: [real] } : { command: real, prefix: [] }
		break
	}
	return npmRuntime
}

function scriptsOf(cwd) {
	const manifest = join(cwd, 'package.json')
	if (!existsSync(manifest)) return null
	return JSON.parse(readFileSync(manifest, 'utf8')).scripts ?? {}
}

function matchDynamicArgument(step) {
	const { dir, prefix, suffix } = step.dynamicArgument
	const absolute = resolve(PACKAGE_ROOT, dir)
	if (!existsSync(absolute)) return { missing: `${dir}/${prefix}*${suffix} (directory ${dir} not produced)` }
	const hits = readdirSync(absolute).filter((f) => f.startsWith(prefix) && f.endsWith(suffix)).sort()
	if (hits.length === 0) return { missing: `${dir}/${prefix}*${suffix} (no matching archive)` }
	return { value: join(dir, hits.at(-1)) }
}

// Decide whether a step can run, and with which exact argv. Never guesses: the return is either
// READY with a command, or BLOCKED/SKIPPED with the exact prerequisite that is missing.
export function resolveStep(step, context) {
	if (step.optIn && !context.optIns?.[step.optIn]) return { status: 'SKIPPED', note: step.skipNote }
	const cwd = resolve(PACKAGE_ROOT, step.cwd ?? '.')
	for (const required of step.requiresFiles ?? []) {
		if (!existsSync(resolve(PACKAGE_ROOT, required))) {
			return { status: 'BLOCKED', note: `missing required file: ${required}` }
		}
	}
	if (step.kind === 'node' && !existsSync(resolve(PACKAGE_ROOT, step.file))) {
		return { status: 'BLOCKED', note: `missing script file: ${step.file}` }
	}
	const args = [...(step.args ?? [])]
	if (step.dynamicArgument) {
		const matched = matchDynamicArgument(step)
		if (matched.missing) return { status: 'BLOCKED', note: `missing required archive: ${matched.missing}` }
		args.push(matched.value)
	}
	if (step.acceptsFixture && context.fixture) args.push('--fixture', context.fixture)
	// The Docker build stamps the commit onto the image labels and tags, so it needs the same
	// SHA the run is recorded under. Passing it from the run context keeps image provenance and
	// evidence provenance identical by construction rather than by the operator remembering.
	if (step.acceptsSourceSha && context.sourceSha) args.push('--source-sha', context.sourceSha)
	if (step.reporter && step.reporterVia !== 'env' && context.reportPath) {
		args.push(...reporterArgs(step.reporter, context.reportPath))
	}
	return buildCommand(step, args, cwd)
}

/**
 * Flags that make a runner write a JUnit report we can read counts and names out of.
 *
 * `node --test` keeps its human output because a second reporter pair is added rather than
 * replacing the first; vitest's `--outputFile` does not disturb its console reporter either.
 */
function reporterArgs(reporter, path) {
	if (reporter === 'vitest') return ['--reporter=junit', `--outputFile=${path}`]
	return [
		'--test-reporter=spec',
		'--test-reporter-destination=stdout',
		'--test-reporter=junit',
		`--test-reporter-destination=${path}`,
	]
}

function buildCommand(step, args, cwd) {
	if (step.kind === 'npm') {
		const scripts = scriptsOf(cwd)
		if (scripts === null) return { status: 'BLOCKED', note: `missing package.json in ${step.cwd ?? '.'}` }
		if (step.requiresScript && !scripts[step.requiresScript]) {
			return { status: 'BLOCKED', note: `missing npm script: ${step.requiresScript}` }
		}
		const npm = resolveNpm()
		if (!npm) return { status: 'BLOCKED', note: 'missing prerequisite: npm executable not found on PATH' }
		return { status: 'READY', command: [npm.command, ...npm.prefix, ...args], cwd }
	}
	if (step.kind === 'node') {
		return { status: 'READY', command: [process.execPath, resolve(PACKAGE_ROOT, step.file), ...args], cwd }
	}
	const missing = step.files.filter((file) => !existsSync(resolve(PACKAGE_ROOT, file)))
	if (missing.length > 0) return { status: 'BLOCKED', note: `missing test file: ${missing.join(', ')}` }
	const files = step.files.map((file) => resolve(PACKAGE_ROOT, file))
	return { status: 'READY', command: [process.execPath, '--test', ...files, ...args], cwd }
}

// Child output is counted, never persisted: a live lane's stdout can carry real commerce records.
function runProcess(command, cwd, env) {
	return new Promise((settle) => {
		const child = spawn(command[0], command.slice(1), { cwd, env, shell: false, stdio: ['ignore', 'pipe', 'pipe'] })
		const counts = { stdoutBytes: 0, stderrBytes: 0 }
		let tail = ''
		child.stdout.on('data', (chunk) => {
			counts.stdoutBytes += chunk.length
		})
		child.stderr.on('data', (chunk) => {
			counts.stderrBytes += chunk.length
			tail = `${tail}${chunk}`.slice(-4000)
		})
		child.on('error', (error) => settle({ ...counts, exitCode: null, signal: null, tail: error.message }))
		child.on('close', (exitCode, signal) => settle({ ...counts, exitCode, signal, tail }))
	})
}

// Returns a step record. `tail` is for the console only; callers strip it before writing evidence.
export async function runStep(step, context) {
	const startedAt = new Date().toISOString()
	// A JUnit report is written outside the evidence directory and read for counts and names only.
	// Its <failure> bodies can quote a live payload, so the file itself never becomes evidence.
	const reportPath = step.reporter
		? join(mkdtempSync(join(tmpdir(), 'fcmcp-report-')), 'junit.xml')
		: null
	const resolved = resolveStep(step, { ...context, reportPath })
	const base = { id: step.id, startedAt, durationMs: 0, command: null, exitCode: null, signal: null }
	if (resolved.status !== 'READY') {
		return { ...base, status: resolved.status, note: resolved.note, stdoutBytes: 0, stderrBytes: 0 }
	}

	const began = Date.now()
	const env = { ...process.env, ...(step.env ?? {}), FLUENTCART_ACCEPTANCE_RUN_DIR: context.runDirectory }
	if (context.fixture) env.FLUENTCART_ACCEPTANCE_FIXTURE = context.fixture
	if (step.reporterVia === 'env' && reportPath) {
		env.NODE_OPTIONS = [process.env.NODE_OPTIONS ?? '', ...reporterArgs(step.reporter, reportPath)]
			.filter(Boolean)
			.join(' ')
	}
	const outcome = await runProcess(resolved.command, resolved.cwd, env)
	const signalled = outcome.signal !== null
	const report = signalled ? null : readReport(reportPath)
	const verdict = signalled
		? { status: 'FAIL', note: `killed by ${outcome.signal}`, unproven: [] }
		: classify(report, { exitCode: outcome.exitCode, proves: step.proves ?? [] })

	return {
		...base,
		status: verdict.status,
		command: resolved.command,
		exitCode: outcome.exitCode,
		signal: outcome.signal,
		durationMs: Date.now() - began,
		stdoutBytes: outcome.stdoutBytes,
		stderrBytes: outcome.stderrBytes,
		note: verdict.note,
		counts: report
			? { tests: report.tests, passed: report.passed, skipped: report.skipped, failed: report.failed }
			: null,
		reportRead: report !== null,
		unproven: verdict.unproven,
		tail: verdict.status === 'FAIL' ? outcome.tail : undefined,
	}
}

/** Read and discard the report. Absent is not an error: a step may declare one and still crash. */
function readReport(path) {
	if (!path || !existsSync(path)) return null
	try {
		return readJunit(path)
	} finally {
		rmSync(dirname(path), { recursive: true, force: true })
	}
}
