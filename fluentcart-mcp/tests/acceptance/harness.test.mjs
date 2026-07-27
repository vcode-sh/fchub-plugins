import assert from 'node:assert/strict'
import { mkdirSync, mkdtempSync, readdirSync, readFileSync, rmSync, symlinkSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { isAbsolute, join, resolve } from 'node:path'
import { after, describe, it } from 'node:test'
import {
	createRunDirectory,
	IGNORED_EVIDENCE_DIR,
	PACKAGE_ROOT,
	resolveOutputRoot,
} from '../../scripts/acceptance/evidence-writer.mjs'
import {
	ALL_LANE_NAMES,
	aggregate,
	expandLane,
	LANE_NAMES,
	LANES,
	resolveStep,
} from '../../scripts/acceptance/lanes.mjs'
import { main } from '../../scripts/acceptance/run-acceptance.mjs'

const SHA = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678'
const EXPECTED_LANES =
	'deterministic route-drift capabilities transport tokens dynamic-live code-live readonly-live reversible-live guarded-preview guarded-execute-test archives docs all'.split(
		' ',
	)
const temporaries = []
const outcome = (status) => ({ status })

function temporary() {
	const directory = mkdtempSync(join(tmpdir(), 'fcmcp-acceptance-'))
	temporaries.push(directory)
	return directory
}

after(() => {
	for (const directory of temporaries) rmSync(directory, { recursive: true, force: true })
})

describe('output path contract', () => {
	it('rejects a relative output directory', () => {
		assert.throws(() => resolveOutputRoot('artifacts/acceptance'), /absolute/)
	})

	it('rejects a traversal segment', () => {
		assert.throws(() => resolveOutputRoot(`${temporary()}/../escape`), /"\.\." segment/)
	})

	it('rejects an output directory inside tracked source', () => {
		for (const inside of ['src/evidence', 'tests', '../web-docs']) {
			assert.throws(() => resolveOutputRoot(resolve(PACKAGE_ROOT, inside)), /tracked source/)
		}
	})

	it('permits the anchored ignored evidence directory', () => {
		assert.equal(
			resolveOutputRoot(join(IGNORED_EVIDENCE_DIR, 'run')),
			join(IGNORED_EVIDENCE_DIR, 'run'),
		)
	})

	it('rejects a symlinked output directory', () => {
		const root = temporary()
		mkdirSync(join(root, 'real'))
		symlinkSync(join(root, 'real'), join(root, 'link'))
		assert.throws(() => resolveOutputRoot(join(root, 'link')), /must not be a symlink/)
	})

	it('rejects an ancestor symlink that resolves back into tracked source', () => {
		const root = temporary()
		symlinkSync(PACKAGE_ROOT, join(root, 'sneak'))
		assert.throws(() => resolveOutputRoot(join(root, 'sneak', 'evidence')), /tracked source/)
	})

	it('canonicalises a symlinked ancestor that stays outside the repository', () => {
		// macOS `mktemp -d` hands back a path under the /var -> /private/var symlink, so the smoke
		// command in plan 08 depends on this resolving rather than being refused.
		assert.ok(isAbsolute(resolveOutputRoot(temporary())))
	})
})

describe('run directory', () => {
	it('rejects a source SHA that is not 40 lowercase hex characters', () => {
		const root = resolveOutputRoot(temporary())
		for (const bad of ['', 'abc123', SHA.toUpperCase(), `${SHA}0`, 'z'.repeat(40)]) {
			assert.throws(() => createRunDirectory(root, bad), /40 lowercase hex/)
		}
	})

	it('creates exactly one run directory named for the source SHA', () => {
		const root = resolveOutputRoot(temporary())
		assert.equal(createRunDirectory(root, SHA), join(root, SHA))
		assert.deepEqual(readdirSync(root), [SHA])
		assert.equal(createRunDirectory(root, SHA), join(root, SHA))
		assert.deepEqual(readdirSync(root), [SHA])
	})

	it('refuses to create a run directory inside tracked source', () => {
		assert.throws(() => createRunDirectory(join(PACKAGE_ROOT, 'dist'), SHA), /tracked source/)
	})
})

function describeStep(entry) {
	if (entry.kind === 'npm') return ['npm', ...entry.args]
	if (entry.kind === 'node') return ['node', entry.file, ...(entry.args ?? [])]
	return ['node', '--test', ...entry.files]
}

describe('lane registry', () => {
	it('declares every lane the release programme names', () => {
		assert.deepEqual(ALL_LANE_NAMES, EXPECTED_LANES)
	})

	it('expands "all" to every real lane and never to itself', () => {
		assert.deepEqual(expandLane('all'), LANE_NAMES)
		assert.ok(!expandLane('all').includes('all'))
		assert.throws(() => expandLane('sneaky'), /unknown lane "sneaky"/)
	})

	it('gives every lane a description and at least one step', () => {
		for (const [name, lane] of Object.entries(LANES)) {
			assert.ok(lane.description?.length > 10, `${name} needs a description`)
			assert.ok(lane.steps.length > 0, `${name} needs at least one step`)
		}
	})

	it('runs the deterministic commands in the specified order', () => {
		assert.deepEqual(LANES.deterministic.steps.map(describeStep), [
			['npm', 'ci'],
			['npm', 'run', 'typecheck'],
			['npm', 'run', 'lint'],
			['npm', 'run', 'test:unit'],
			['npm', 'run', 'test:tooling'],
			['npm', 'run', 'build'],
			['npm', 'run', 'check:compatibility'],
			['node', 'scripts/build-api-coverage.mjs', '--check'],
			['npm', 'run', 'measure:context'],
			['node', 'scripts/build-release-contract.mjs', '--check'],
			['node', 'scripts/build-manifest.mjs', '--check'],
		])
	})
})

const installStep = LANES.deterministic.steps[0]

describe('prerequisite detection', () => {
	it('skips npm ci by default so a concurrent install is never wiped', () => {
		const resolved = resolveStep(installStep, { optIns: {} })
		assert.equal(resolved.status, 'SKIPPED')
		assert.match(resolved.note, /--install/)
	})

	it('runs npm ci only when explicitly opted in', () => {
		const resolved = resolveStep(installStep, { optIns: { install: true } })
		assert.equal(resolved.status, 'READY')
		assert.deepEqual(resolved.command.slice(-1), ['ci'])
	})

	it('blocks on a missing npm script and names it exactly', () => {
		const step = { id: 'x', kind: 'npm', args: ['run', 'nope'], requiresScript: 'nope' }
		assert.deepEqual(resolveStep(step, {}), { status: 'BLOCKED', note: 'missing npm script: nope' })
	})

	it('blocks on a missing script file and names it exactly', () => {
		const step = { id: 'x', kind: 'node', file: 'scripts/not-here.mjs', args: [] }
		const note = 'missing script file: scripts/not-here.mjs'
		assert.deepEqual(resolveStep(step, {}), { status: 'BLOCKED', note })
	})

	it('blocks on a missing test file and names it exactly', () => {
		const step = { id: 'x', kind: 'node-test', files: ['tests/acceptance/absent.test.mjs'] }
		const note = 'missing test file: tests/acceptance/absent.test.mjs'
		assert.deepEqual(resolveStep(step, {}), { status: 'BLOCKED', note })
	})

	it('never invents a pass, a shell or an npx for any registered step', () => {
		let ready = 0
		for (const step of Object.values(LANES).flatMap((lane) => lane.steps)) {
			const resolved = resolveStep(step, { optIns: { install: true } })
			assert.ok(['READY', 'BLOCKED', 'SKIPPED'].includes(resolved.status))
			if (resolved.status === 'BLOCKED') assert.match(resolved.note, /missing/)
			if (resolved.status !== 'READY') continue
			ready += 1
			assert.ok(
				isAbsolute(resolved.command[0]),
				`${resolved.command[0]} must be an exact binary path`,
			)
			for (const part of resolved.command) {
				assert.ok(!/(^|[/\\])npx$/.test(part), 'npx is never permitted')
				assert.doesNotMatch(part, /[;&|`]|\$\(/, 'arguments are never shell-interpolated')
			}
		}
		assert.ok(ready > 0, 'at least the local npm scripts must resolve')
	})
})

describe('status aggregation', () => {
	it('lets a failure outrank everything else', () => {
		assert.equal(aggregate([outcome('PASS'), outcome('BLOCKED'), outcome('FAIL')]), 'FAIL')
	})

	it('never converts a blocked or skipped lane into a pass', () => {
		assert.equal(aggregate([outcome('PASS'), outcome('BLOCKED')]), 'BLOCKED')
		assert.equal(aggregate([outcome('SKIPPED'), outcome('SKIPPED')]), 'BLOCKED')
		assert.equal(aggregate([]), 'BLOCKED')
	})

	it('passes only when something actually ran and passed', () => {
		assert.equal(aggregate([outcome('SKIPPED'), outcome('PASS')]), 'PASS')
	})

	it('carries an exclusion up to the lane rather than rounding it to a pass', () => {
		const excluded = outcome('PASS_WITH_EXCLUSIONS')
		assert.equal(aggregate([outcome('PASS'), excluded]), 'PASS_WITH_EXCLUSIONS')
		assert.equal(aggregate([excluded]), 'PASS_WITH_EXCLUSIONS')
		// A block and a failure still outrank it.
		assert.equal(aggregate([excluded, outcome('BLOCKED')]), 'BLOCKED')
		assert.equal(aggregate([excluded, outcome('FAIL')]), 'FAIL')
	})
})

const HERE = 'scripts/acceptance'
const PROBE = {
	description: 'internal probe lane used by the harness tests only',
	steps: [
		installStep,
		{ id: 'absent', kind: 'node', file: `${HERE}/not-written.mjs`, args: [] },
		{ id: 'ok', kind: 'node', file: `${HERE}/evidence-writer.mjs`, args: [] },
		{
			id: 'boom',
			kind: 'node',
			file: `${HERE}/run-acceptance.mjs`,
			args: ['--lane', 'deterministic'],
		},
		{ id: 'after', kind: 'node', file: `${HERE}/evidence-writer.mjs`, args: [] },
	],
}

async function runProbe(output) {
	LANES['probe-lane'] = PROBE
	const sink = { out: () => undefined, err: () => undefined }
	try {
		return await main(['--lane', 'probe-lane', '--output', output, '--source-sha', SHA], sink)
	} finally {
		Reflect.deleteProperty(LANES, 'probe-lane')
	}
}

describe('end-to-end run', () => {
	it('preserves child exit codes, stops after a failure and persists no child output', async () => {
		const output = temporary()
		assert.equal(await runProbe(output), 1, 'a failing lane must exit non-zero')

		const runDirectory = join(output, SHA)
		assert.deepEqual(readdirSync(runDirectory).sort(), ['lane-probe-lane.json', 'summary.json'])

		const raw = readFileSync(join(runDirectory, 'lane-probe-lane.json'), 'utf8')
		const evidence = JSON.parse(raw)
		assert.deepEqual(
			evidence.steps.map((entry) => [entry.id, entry.status]),
			[
				['install', 'SKIPPED'],
				['absent', 'BLOCKED'],
				['ok', 'PASS'],
				['boom', 'FAIL'],
				['after', 'SKIPPED'],
			],
		)
		assert.equal(evidence.lane.status, 'FAIL')
		assert.equal(evidence.steps[3].exitCode, 64, 'the child exit code must survive verbatim')
		assert.equal(evidence.lane.summary.installVerified, false)
		assert.ok(!raw.includes('refused to run'), 'captured child output must never be persisted')
		assert.ok(raw.includes('"stdoutBytes"'), 'only shapes and counts are recorded')

		const summary = JSON.parse(readFileSync(join(runDirectory, 'summary.json'), 'utf8'))
		assert.equal(summary.status, 'FAIL')
		assert.equal(summary.counts.failed, 1)
		assert.equal(summary.sourceSha, SHA)
	})
})
