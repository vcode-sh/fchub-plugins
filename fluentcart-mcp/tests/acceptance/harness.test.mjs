import assert from 'node:assert/strict'
import {
	chmodSync,
	mkdirSync,
	mkdtempSync,
	readdirSync,
	readFileSync,
	rmSync,
	symlinkSync,
	writeFileSync,
} from 'node:fs'
import { createServer } from 'node:http'
import { tmpdir } from 'node:os'
import { isAbsolute, join, resolve } from 'node:path'
import { after, describe, it } from 'node:test'
import {
	createRunDirectory,
	IGNORED_EVIDENCE_DIR,
	PACKAGE_ROOT,
	resolveFixture,
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
	'deterministic route-drift capabilities transport protocol proxy soak clients tokens dynamic-live code-live readonly-live reversible-live archives docs all'.split(
		' ',
	)
const RETIRED_GUARDED_LANES = ['guarded-preview', 'guarded-execute-test']
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

describe('fixture path contract', () => {
	it('rejects a fixture outside the package root', () => {
		const fixture = join(temporary(), 'route-fixture.json')
		writeFileSync(fixture, '{}\n')
		assert.throws(() => resolveFixture(fixture), /package root/)
	})

	it('rejects a fixture that is itself a symlink', () => {
		mkdirSync(IGNORED_EVIDENCE_DIR, { recursive: true })
		const directory = mkdtempSync(join(IGNORED_EVIDENCE_DIR, 'fixture-contract-'))
		temporaries.push(directory)
		const fixture = join(directory, 'route-fixture.json')
		const link = join(directory, 'route-fixture-link.json')
		writeFileSync(fixture, '{}\n')
		symlinkSync(fixture, link)
		assert.throws(() => resolveFixture(link), /must not be a symlink/)
	})

	it('rejects a package-local fixture path whose ancestor escapes through a symlink', () => {
		const outside = temporary()
		writeFileSync(join(outside, 'route-fixture.json'), '{}\n')
		mkdirSync(IGNORED_EVIDENCE_DIR, { recursive: true })
		const directory = mkdtempSync(join(IGNORED_EVIDENCE_DIR, 'fixture-ancestor-'))
		temporaries.push(directory)
		const link = join(directory, 'outside')
		symlinkSync(outside, link)
		assert.throws(() => resolveFixture(join(link, 'route-fixture.json')), /package root|symlink/)
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
	it('does not require live refund or cancellation lanes for this release', () => {
		for (const lane of RETIRED_GUARDED_LANES) {
			assert.ok(!Object.hasOwn(LANES, lane), `${lane} must not be release-required`)
		}
	})

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

	it('keeps protocol proof distinct and requires every built over-the-wire client', () => {
		assert.deepEqual(LANES.protocol.steps.map(describeStep), [
			['node', '--test', 'tests/protocol/stdio-dual-era.test.mjs'],
			['node', '--test', 'tests/protocol/http-dual-era.test.mjs'],
			['node', '--test', 'tests/protocol/production-surface.test.mjs'],
		])
		for (const step of LANES.protocol.steps) {
			assert.equal(step.reporter, 'node-test')
			assert.ok(step.requiresFiles.includes('dist/index.js'))
			assert.equal(step.optIn, undefined, `${step.id} must never be skippable`)
		}
		assert.deepEqual(LANES.protocol.steps[0].requiresModules, [
			'@modelcontextprotocol/client',
			'@modelcontextprotocol/client/stdio',
		])
		assert.deepEqual(LANES.protocol.steps[1].requiresModules, ['@modelcontextprotocol/client'])
	})

	it('preflights one supplied candidate before every candidate consumer and never builds in all', () => {
		for (const name of ['proxy', 'soak', 'clients', 'archives']) {
			assert.equal(LANES[name].steps[0].id, 'candidate-preflight', name)
		}
		assert.deepEqual(
			LANES.archives.steps.map(({ id }) => id),
			['candidate-preflight', 'inspect-npm-pack', 'inspect-mcpb', 'docker-smoke'],
		)
		const allSteps = expandLane('all').flatMap((name) => LANES[name].steps)
		assert.ok(!allSteps.some(({ requiresScript }) => requiresScript === 'pack:release'))
		assert.ok(!allSteps.some(({ file }) => file === 'scripts/build-validated-docker-image.mjs'))
	})

	it('places Node reporter flags before test files so executed counts are captured', () => {
		const resolved = resolveStep(LANES.protocol.steps[0], {
			reportPath: '/tmp/fluentcart-mcp-protocol-junit.xml',
		})
		assert.equal(resolved.status, 'READY')
		const reporter = resolved.command.indexOf('--test-reporter=junit')
		const testFile = resolved.command.findIndex((part) => part.endsWith('stdio-dual-era.test.mjs'))
		assert.ok(reporter > 0)
		assert.ok(
			testFile > reporter,
			'Node treats options after a positional test file as test arguments',
		)
	})

	it('passes a route fixture to Node tests through the environment, never as a Node option', () => {
		const fixture = resolve(
			PACKAGE_ROOT,
			'tests/fixtures/routes/fluentcart-1.5.5-core-pro-1.5.4.json',
		)
		const resolved = resolveStep(LANES['route-drift'].steps[0], {
			fixture,
			reportPath: '/tmp/fluentcart-mcp-route-drift-junit.xml',
		})
		assert.equal(resolved.status, 'READY')
		assert.ok(!resolved.command.includes('--fixture'))
		assert.ok(!resolved.command.includes(fixture))
		const reporter = resolved.command.indexOf('--test-reporter=junit')
		const testFile = resolved.command.findIndex((part) => part.endsWith('route-drift.test.mjs'))
		assert.ok(reporter > 0)
		assert.ok(testFile > reporter)
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

	it('blocks on a missing protocol client module and names it exactly', () => {
		const step = {
			id: 'x',
			kind: 'node-test',
			files: ['tests/acceptance/harness.test.mjs'],
			requiresModules: ['@modelcontextprotocol/not-installed'],
		}
		const note = 'missing required module: @modelcontextprotocol/not-installed'
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

function restIndexFor(fixture) {
	const routes = {
		'/fluent-cart/v2': { endpoints: [{ methods: ['GET'] }] },
	}
	for (const operation of fixture.operations) {
		const path = `/fluent-cart/v2${operation.path}`
		if (!routes[path]) routes[path] = { endpoints: [{ methods: [] }] }
		routes[path].endpoints[0].methods.push(operation.method)
	}
	const collapsed = fixture.canonicalCollapses[0]
	const collapsedPath = `/fluent-cart/v2${collapsed.canonical.slice(collapsed.canonical.indexOf(' ') + 1)}`
	const collapsedMethod = collapsed.canonical.slice(0, collapsed.canonical.indexOf(' '))
	routes[collapsedPath].endpoints[0].methods = routes[collapsedPath].endpoints[0].methods.filter(
		(method) => method !== collapsedMethod,
	)
	if (routes[collapsedPath].endpoints[0].methods.length === 0) delete routes[collapsedPath]
	for (const exact of collapsed.exact) {
		const separator = exact.indexOf(' ')
		routes[exact.slice(separator + 1)] = {
			endpoints: [{ methods: [exact.slice(0, separator)] }],
		}
	}
	while (Object.keys(routes).length < fixture.counts.prefixedPathsInclusive) {
		const sequence = Object.keys(routes).length
		routes[`/fluent-cart/v2/__fixture-path-${sequence}`] = { endpoints: [{ methods: [] }] }
	}
	return { routes }
}

async function closeServer(server) {
	await new Promise((settle, reject) => server.close((error) => (error ? reject(error) : settle())))
}

describe('end-to-end run', () => {
	it('ignores an ambient fixture when the coordinator was given no fixture', async () => {
		const checkedFixture = JSON.parse(
			readFileSync(
				resolve(PACKAGE_ROOT, 'tests/fixtures/routes/fluentcart-1.5.5-core-pro-1.5.4.json'),
				'utf8',
			),
		)
		mkdirSync(IGNORED_EVIDENCE_DIR, { recursive: true })
		const fixtureDirectory = mkdtempSync(join(IGNORED_EVIDENCE_DIR, 'route-drift-ambient-'))
		temporaries.push(fixtureDirectory)
		const ambientPath = join(fixtureDirectory, 'ambient-route-fixture.json')
		const ambientFixture = {
			...checkedFixture,
			profile: { ...checkedFixture.profile, wordpress: 'ambient-fixture-must-not-be-used' },
		}
		writeFileSync(ambientPath, `${JSON.stringify(ambientFixture)}\n`)

		const server = createServer((request, response) => {
			if (request.url !== '/wp-json/') {
				response.writeHead(404).end()
				return
			}
			response.writeHead(200, { 'content-type': 'application/json' })
			response.end(JSON.stringify(restIndexFor(checkedFixture)))
		})
		await new Promise((settle, reject) => {
			server.once('error', reject)
			server.listen(0, '127.0.0.1', settle)
		})
		const previous = {
			url: process.env.FLUENTCART_URL,
			fixture: process.env.FLUENTCART_ACCEPTANCE_FIXTURE,
			testContext: process.env.NODE_TEST_CONTEXT,
		}
		process.env.FLUENTCART_URL = `http://127.0.0.1:${server.address().port}`
		process.env.FLUENTCART_ACCEPTANCE_FIXTURE = ambientPath
		Reflect.deleteProperty(process.env, 'NODE_TEST_CONTEXT')

		const output = temporary()
		let stderr = ''
		try {
			const exitCode = await main(
				['--lane', 'route-drift', '--output', output, '--source-sha', SHA],
				{ out: () => undefined, err: (text) => (stderr += text) },
			)
			assert.equal(exitCode, 0, stderr)
		} finally {
			for (const [name, value] of [
				['FLUENTCART_URL', previous.url],
				['FLUENTCART_ACCEPTANCE_FIXTURE', previous.fixture],
				['NODE_TEST_CONTEXT', previous.testContext],
			]) {
				if (value === undefined) Reflect.deleteProperty(process.env, name)
				else process.env[name] = value
			}
			await closeServer(server)
		}

		const evidence = JSON.parse(readFileSync(join(output, SHA, 'lane-route-drift.json'), 'utf8'))
		assert.equal(evidence.steps[0].status, 'PASS')
		assert.deepEqual(evidence.steps[0].counts, {
			tests: 13,
			passed: 13,
			skipped: 0,
			failed: 0,
		})
		const summary = JSON.parse(readFileSync(join(output, SHA, 'summary.json'), 'utf8'))
		assert.equal(summary.fixture, null)
	})

	it('executes route-drift with the exact supplied fixture and reports test counts', async () => {
		const checkedFixture = JSON.parse(
			readFileSync(
				resolve(PACKAGE_ROOT, 'tests/fixtures/routes/fluentcart-1.5.5-core-pro-1.5.4.json'),
				'utf8',
			),
		)
		mkdirSync(IGNORED_EVIDENCE_DIR, { recursive: true })
		const fixtureDirectory = mkdtempSync(join(IGNORED_EVIDENCE_DIR, 'route-drift-harness-'))
		temporaries.push(fixtureDirectory)
		const fixturePath = join(fixtureDirectory, 'supplied-route-fixture.json')
		const suppliedFixture = { ...checkedFixture, capturedAt: 'task-11-exact-supplied-fixture' }
		writeFileSync(fixturePath, `${JSON.stringify(suppliedFixture)}\n`)
		const ambientPath = join(fixtureDirectory, 'ambient-decoy-route-fixture.json')
		writeFileSync(
			ambientPath,
			`${JSON.stringify({
				...checkedFixture,
				profile: { ...checkedFixture.profile, wordpress: 'ambient-decoy-must-not-win' },
			})}\n`,
		)

		const server = createServer((request, response) => {
			if (request.url !== '/wp-json/') {
				response.writeHead(404).end()
				return
			}
			response.writeHead(200, { 'content-type': 'application/json' })
			response.end(JSON.stringify(restIndexFor(suppliedFixture)))
		})
		await new Promise((settle, reject) => {
			server.once('error', reject)
			server.listen(0, '127.0.0.1', settle)
		})
		const previousUrl = process.env.FLUENTCART_URL
		const previousFixture = process.env.FLUENTCART_ACCEPTANCE_FIXTURE
		const previousTestContext = process.env.NODE_TEST_CONTEXT
		process.env.FLUENTCART_URL = `http://127.0.0.1:${server.address().port}`
		process.env.FLUENTCART_ACCEPTANCE_FIXTURE = ambientPath
		Reflect.deleteProperty(process.env, 'NODE_TEST_CONTEXT')

		const output = temporary()
		let stderr = ''
		try {
			const exitCode = await main(
				[
					'--lane',
					'route-drift',
					'--output',
					output,
					'--source-sha',
					SHA,
					'--fixture',
					fixturePath,
				],
				{ out: () => undefined, err: (text) => (stderr += text) },
			)
			assert.equal(exitCode, 0, stderr)
		} finally {
			if (previousUrl === undefined) Reflect.deleteProperty(process.env, 'FLUENTCART_URL')
			else process.env.FLUENTCART_URL = previousUrl
			if (previousFixture === undefined)
				Reflect.deleteProperty(process.env, 'FLUENTCART_ACCEPTANCE_FIXTURE')
			else process.env.FLUENTCART_ACCEPTANCE_FIXTURE = previousFixture
			if (previousTestContext === undefined)
				Reflect.deleteProperty(process.env, 'NODE_TEST_CONTEXT')
			else process.env.NODE_TEST_CONTEXT = previousTestContext
			await closeServer(server)
		}

		const evidence = JSON.parse(readFileSync(join(output, SHA, 'lane-route-drift.json'), 'utf8'))
		const [step] = evidence.steps
		assert.equal(step.status, 'PASS')
		assert.deepEqual(step.counts, { tests: 13, passed: 13, skipped: 0, failed: 0 })
		assert.equal(step.reportRead, true)
		assert.ok(!step.command.includes('--fixture'))
		assert.ok(!step.command.includes(fixturePath))
		const reporter = step.command.indexOf('--test-reporter=junit')
		const testFile = step.command.findIndex((part) => part.endsWith('route-drift.test.mjs'))
		assert.ok(reporter > 0)
		assert.ok(testFile > reporter)

		const summary = JSON.parse(readFileSync(join(output, SHA, 'summary.json'), 'utf8'))
		assert.equal(summary.fixture, fixturePath.slice(PACKAGE_ROOT.length + 1))
	})

	it('blocks every candidate lane before proof when candidate inputs are absent', async () => {
		const names = [
			'FLUENTCART_ACCEPTANCE_IMAGE',
			'FLUENTCART_ACCEPTANCE_IMAGE_ID',
			'FLUENTCART_ACCEPTANCE_IMAGE_DIGEST',
		]
		const previous = Object.fromEntries(names.map((name) => [name, process.env[name]]))
		for (const name of names) Reflect.deleteProperty(process.env, name)
		try {
			for (const lane of ['proxy', 'soak', 'clients', 'archives']) {
				const output = temporary()
				assert.equal(
					await main(['--lane', lane, '--output', output, '--source-sha', SHA], {
						out: () => undefined,
						err: () => undefined,
					}),
					2,
					lane,
				)
				const evidence = JSON.parse(readFileSync(join(output, SHA, `lane-${lane}.json`), 'utf8'))
				assert.equal(evidence.steps[0].status, 'BLOCKED', lane)
				assert.match(evidence.steps[0].note, /candidate prerequisite/, lane)
				assert.ok(
					evidence.steps
						.slice(1)
						.every(({ status, command }) => status === 'SKIPPED' && command === null),
					`${lane} must not execute a proof step`,
				)
			}
		} finally {
			for (const [name, value] of Object.entries(previous)) {
				if (value === undefined) Reflect.deleteProperty(process.env, name)
				else process.env[name] = value
			}
		}
	})

	it('blocks every candidate lane before proof when the supplied image artifact is absent', async () => {
		const fakeBin = temporary()
		const fakeDocker = join(fakeBin, 'docker')
		writeFileSync(fakeDocker, '#!/bin/sh\nprintf "%s\\n" "No such image" >&2\nexit 1\n')
		chmodSync(fakeDocker, 0o755)
		const previous = {
			PATH: process.env.PATH,
			FLUENTCART_ACCEPTANCE_IMAGE: process.env.FLUENTCART_ACCEPTANCE_IMAGE,
			FLUENTCART_ACCEPTANCE_IMAGE_ID: process.env.FLUENTCART_ACCEPTANCE_IMAGE_ID,
			FLUENTCART_ACCEPTANCE_IMAGE_DIGEST: process.env.FLUENTCART_ACCEPTANCE_IMAGE_DIGEST,
		}
		Object.assign(process.env, {
			PATH: `${fakeBin}:${process.env.PATH}`,
			FLUENTCART_ACCEPTANCE_IMAGE: 'candidate.invalid/fluentcart-mcp:missing',
			FLUENTCART_ACCEPTANCE_IMAGE_ID: `sha256:${'a'.repeat(64)}`,
			FLUENTCART_ACCEPTANCE_IMAGE_DIGEST: `sha256:${'b'.repeat(64)}`,
		})
		try {
			for (const lane of ['proxy', 'soak', 'clients', 'archives']) {
				const output = temporary()
				assert.equal(
					await main(['--lane', lane, '--output', output, '--source-sha', SHA], {
						out: () => undefined,
						err: () => undefined,
					}),
					2,
					lane,
				)
				const evidence = JSON.parse(readFileSync(join(output, SHA, `lane-${lane}.json`), 'utf8'))
				assert.equal(evidence.steps[0].status, 'BLOCKED', lane)
				assert.equal(evidence.steps[0].exitCode, 2, lane)
				assert.ok(
					evidence.steps
						.slice(1)
						.every(({ status, command }) => status === 'SKIPPED' && command === null),
					`${lane} must not execute a proof step`,
				)
			}
		} finally {
			for (const [name, value] of Object.entries(previous)) {
				if (value === undefined) Reflect.deleteProperty(process.env, name)
				else process.env[name] = value
			}
		}
	})

	it('fails every candidate lane when inspection finds a different candidate identity', async () => {
		const fakeBin = temporary()
		const fakeDocker = join(fakeBin, 'docker')
		const inspectedId = `sha256:${'a'.repeat(64)}`
		const expectedId = `sha256:${'b'.repeat(64)}`
		const expectedDigest = `sha256:${'c'.repeat(64)}`
		writeFileSync(
			fakeDocker,
			`#!/bin/sh\nprintf '%s\\n' '${JSON.stringify({
				Id: inspectedId,
				RepoDigests: [`candidate.invalid/fluentcart-mcp@${expectedDigest}`],
				Config: { Labels: {} },
			})}'\n`,
		)
		chmodSync(fakeDocker, 0o755)
		const previous = {
			PATH: process.env.PATH,
			FLUENTCART_ACCEPTANCE_IMAGE: process.env.FLUENTCART_ACCEPTANCE_IMAGE,
			FLUENTCART_ACCEPTANCE_IMAGE_ID: process.env.FLUENTCART_ACCEPTANCE_IMAGE_ID,
			FLUENTCART_ACCEPTANCE_IMAGE_DIGEST: process.env.FLUENTCART_ACCEPTANCE_IMAGE_DIGEST,
		}
		Object.assign(process.env, {
			PATH: `${fakeBin}:${process.env.PATH}`,
			FLUENTCART_ACCEPTANCE_IMAGE: 'candidate.invalid/fluentcart-mcp:test',
			FLUENTCART_ACCEPTANCE_IMAGE_ID: expectedId,
			FLUENTCART_ACCEPTANCE_IMAGE_DIGEST: expectedDigest,
		})
		try {
			for (const lane of ['proxy', 'soak', 'clients', 'archives']) {
				const output = temporary()
				assert.equal(
					await main(['--lane', lane, '--output', output, '--source-sha', SHA], {
						out: () => undefined,
						err: () => undefined,
					}),
					1,
					lane,
				)
				const evidence = JSON.parse(readFileSync(join(output, SHA, `lane-${lane}.json`), 'utf8'))
				assert.equal(evidence.steps[0].status, 'FAIL', lane)
				assert.ok(
					evidence.steps
						.slice(1)
						.every(({ status, command }) => status === 'SKIPPED' && command === null),
					`${lane} must not execute a proof step`,
				)
			}
		} finally {
			for (const [name, value] of Object.entries(previous)) {
				if (value === undefined) Reflect.deleteProperty(process.env, name)
				else process.env[name] = value
			}
		}
	})

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
