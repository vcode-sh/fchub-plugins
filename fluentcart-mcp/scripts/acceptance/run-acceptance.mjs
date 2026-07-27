#!/usr/bin/env node
// Acceptance orchestrator.
//
//   node scripts/acceptance/run-acceptance.mjs \
//     --lane <name|all> --output <absolute-directory> --source-sha <40-lowercase-hex> [--fixture <path>] [--install]
//
// Exit codes: 0 every requested lane passed cleanly, 1 a lane failed, 2 nothing failed but a lane
// was blocked, 3 nothing failed or blocked but a lane passed with named exclusions, 64 the
// invocation or the evidence contract was rejected before anything ran.
//
// "PASS" means the command ran now and its assertions passed. Nothing here upgrades a blocked
// prerequisite into a pass, and nothing writes a captured payload to disk.

import { copyFileSync, existsSync } from 'node:fs'
import { basename, join, relative, resolve } from 'node:path'
import { parseArgs } from 'node:util'
import {
	createRunDirectory,
	EvidenceError,
	PACKAGE_ROOT,
	resolveFixture,
	resolveOutputRoot,
	SOURCE_SHA_PATTERN,
	writeJsonAtomic,
} from './evidence-writer.mjs'
import { aggregate, ALL_LANE_NAMES, expandLane, LANES, runStep, unprovenOf } from './lanes.mjs'
import { worstStatus } from './test-report.mjs'

const SCHEMA_VERSION = 1
const EXIT = { pass: 0, fail: 1, blocked: 2, pass_with_exclusions: 3, usage: 64 }

// Console output goes through a sink so a caller (the harness tests, a CI wrapper) can capture it
// without monkey-patching process.stdout out from under someone else's reporter.
const console_ = {
	out: (text) => process.stdout.write(text),
	err: (text) => process.stderr.write(text),
}
let io = console_

const USAGE = `Usage: node scripts/acceptance/run-acceptance.mjs --lane <name|all> --output <absolute-directory> --source-sha <sha> [--fixture <path>] [--install]

Lanes: ${ALL_LANE_NAMES.join(', ')}

--output must be an absolute directory outside tracked source (or ${join(PACKAGE_ROOT, 'artifacts', 'acceptance')}).
--install opts into "npm ci"; without it the install step is skipped and recorded as unverified.`

function parseCli(argv) {
	const { values } = parseArgs({
		args: argv,
		strict: true,
		allowPositionals: false,
		options: {
			lane: { type: 'string' },
			output: { type: 'string' },
			'source-sha': { type: 'string' },
			fixture: { type: 'string' },
			install: { type: 'boolean', default: false },
			help: { type: 'boolean', default: false },
		},
	})
	if (values.help) return { help: true }
	for (const required of ['lane', 'output', 'source-sha']) {
		if (!values[required]) throw new EvidenceError(`--${required} is required`)
	}
	if (!SOURCE_SHA_PATTERN.test(values['source-sha'])) {
		throw new EvidenceError(`--source-sha must be 40 lowercase hex characters, received: ${values['source-sha']}`)
	}
	let lanes
	try {
		lanes = expandLane(values.lane)
	} catch (error) {
		throw new EvidenceError(error.message)
	}
	return {
		help: false,
		lanes,
		requested: values.lane,
		output: values.output,
		sourceSha: values['source-sha'],
		fixture: values.fixture,
		install: values.install,
	}
}

function tally(steps) {
	const counts = { steps: steps.length, passed: 0, failed: 0, blocked: 0, skipped: 0 }
	const bucket = { PASS: 'passed', FAIL: 'failed', BLOCKED: 'blocked', SKIPPED: 'skipped' }
	for (const step of steps) counts[bucket[step.status]] += 1
	return counts
}

function report(name, step) {
	const detail = step.note ? ` — ${step.note}` : ''
	io.out(`  [${step.status.padEnd(7)}] ${name}/${step.id}${detail}\n`)
	if (step.tail) io.err(`${step.tail.trimEnd()}\n`)
}

// JUnit is copied only when a step declares that its runner produced one. The harness never
// synthesises a report for a command that did not write one.
function collectJunit(definition, runDirectory) {
	const collected = []
	for (const step of definition.steps) {
		if (!step.junit) continue
		const source = resolve(PACKAGE_ROOT, step.junit)
		if (!existsSync(source)) continue
		const destination = join(runDirectory, `${step.id}-${basename(source)}`)
		copyFileSync(source, destination)
		collected.push(destination)
	}
	return collected
}

async function runOneLane(name, context) {
	const definition = LANES[name]
	const startedAt = new Date().toISOString()
	const began = Date.now()
	io.out(`\n${name}: ${definition.description}\n`)
	const steps = []
	let aborted = null
	for (const step of definition.steps) {
		if (aborted) {
			steps.push({
				unproven: [],
				id: step.id,
				startedAt: new Date().toISOString(),
				durationMs: 0,
				command: null,
				exitCode: null,
				signal: null,
				status: 'SKIPPED',
				note: `not run after ${aborted} failed`,
				stdoutBytes: 0,
				stderrBytes: 0,
			})
			continue
		}
		const outcome = await runStep(step, context)
		report(name, outcome)
		const { tail: _tail, ...persisted } = outcome
		steps.push(persisted)
		if (outcome.status === 'FAIL') aborted = step.id
	}
	const status = aggregate(steps)
	const laneFile = join(context.runDirectory, `lane-${name}.json`)
	const evidenceFiles = [laneFile, ...collectJunit(definition, context.runDirectory)]
	const installStep = steps.find((step) => step.id === 'install')
	const unproven = unprovenOf(steps)
	const lane = {
		name,
		status,
		startedAt,
		durationMs: Date.now() - began,
		command: ['node', 'scripts/acceptance/run-acceptance.mjs', '--lane', name],
		summary: {
			...tally(steps),
			unproven: unproven.length,
			...(installStep ? { installVerified: installStep.status === 'PASS' } : {}),
		},
		evidenceFiles,
	}
	writeJsonAtomic(laneFile, {
		schemaVersion: SCHEMA_VERSION,
		sourceSha: context.sourceSha,
		generatedAt: new Date().toISOString(),
		lane,
		unproven,
		steps,
	})
	io.out(`${name}: ${status}\n`)
	return { lane, unproven }
}

function overall(lanes) {
	return worstStatus(lanes.map((lane) => lane.status))
}

function printClosing(status, lanes, runDirectory, unproven) {
	io.out('\nLane results\n')
	for (const lane of lanes) io.out(`  ${lane.status.padEnd(21)} ${lane.name}\n`)

	if (unproven.length > 0) {
		io.out(`\nUnproven capabilities (${unproven.length})\n`)
		for (const entry of unproven) io.out(`  ${entry.lane}: ${entry.name}\n      ${entry.reason}\n`)
	}

	io.out(`\nOverall: ${status}\nEvidence: ${runDirectory}\n`)
	if (status === 'BLOCKED' || status === 'FAIL') {
		io.out('A blocked lane is not a passing lane. Its capability cannot be claimed as released.\n')
	}
	if (status === 'PASS_WITH_EXCLUSIONS') {
		io.out('Lanes ran and proved what they could. The exclusions above are unverified.\n')
	}
}

/**
 * @param {string[]} argv
 * @param {{out?: (text: string) => void, err?: (text: string) => void}} [streams] capture sink
 * @returns {Promise<number>} process exit code
 */
export async function main(argv, streams) {
	io = streams ? { ...console_, ...streams } : console_
	const options = parseCli(argv)
	if (options.help) {
		io.out(`${USAGE}\n`)
		return EXIT.pass
	}
	const outputRoot = resolveOutputRoot(options.output)
	const fixture = resolveFixture(options.fixture)
	const runDirectory = createRunDirectory(outputRoot, options.sourceSha)
	const context = {
		runDirectory,
		sourceSha: options.sourceSha,
		fixture,
		optIns: { install: options.install },
	}
	io.out(`Acceptance run ${options.sourceSha}\nEvidence: ${runDirectory}\n`)
	const laneReports = []
	for (const name of options.lanes) laneReports.push(await runOneLane(name, context))
	const lanes = laneReports.map((entry) => entry.lane)
	const status = overall(lanes)
	// Generated, never hand-maintained: plan 08 Task 9 asks the owner for an explicit
	// unsupported-capability list, and a list written by hand drifts away from the runs it claims
	// to describe. Every entry here is a test that did not run, with the reason its author gave.
	const unprovenCapabilities = laneReports.flatMap((entry) =>
		entry.unproven.map((skip) => ({ lane: entry.lane.name, ...skip })),
	)
	writeJsonAtomic(join(runDirectory, 'summary.json'), {
		schemaVersion: SCHEMA_VERSION,
		generatedAt: new Date().toISOString(),
		sourceSha: options.sourceSha,
		requestedLane: options.requested,
		runDirectory,
		packageRoot: PACKAGE_ROOT,
		fixture: fixture ? relative(PACKAGE_ROOT, fixture) : null,
		installVerified: options.install,
		status,
		counts: {
			lanes: lanes.length,
			passed: lanes.filter((lane) => lane.status === 'PASS').length,
			passedWithExclusions: lanes.filter((lane) => lane.status === 'PASS_WITH_EXCLUSIONS').length,
			failed: lanes.filter((lane) => lane.status === 'FAIL').length,
			blocked: lanes.filter((lane) => lane.status === 'BLOCKED').length,
			unproven: unprovenCapabilities.length,
		},
		unprovenCapabilities,
		lanes,
	})
	printClosing(status, lanes, runDirectory, unprovenCapabilities)
	return EXIT[status.toLowerCase()]
}

const invokedDirectly = process.argv[1] && resolve(process.argv[1]).endsWith('run-acceptance.mjs')
if (invokedDirectly) {
	try {
		process.exitCode = await main(process.argv.slice(2))
	} catch (error) {
		const message = error?.message ?? String(error)
		io.err(`acceptance harness refused to run: ${message}\n\n${USAGE}\n`)
		process.exitCode = EXIT.usage
	}
}
