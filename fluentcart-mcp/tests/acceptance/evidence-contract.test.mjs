import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { existsSync, mkdtempSync, readdirSync, readFileSync, rmSync, statSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { isAbsolute, join } from 'node:path'
import { after, describe, it } from 'node:test'
import {
	assertNoSecrets,
	createRunDirectory,
	findSecrets,
	PACKAGE_ROOT,
	resolveOutputRoot,
	writeJsonAtomic,
} from '../../scripts/acceptance/evidence-writer.mjs'
import { LANE_NAMES, LANES } from '../../scripts/acceptance/lanes.mjs'
import { main } from '../../scripts/acceptance/run-acceptance.mjs'

const SHA = '0123456789abcdef0123456789abcdef01234567'
const LANE_STATUSES = ['PASS', 'FAIL', 'BLOCKED', 'PASS_WITH_EXCLUSIONS']
const temporaries = []

function temporary() {
	const directory = mkdtempSync(join(tmpdir(), 'fcmcp-evidence-'))
	temporaries.push(directory)
	return directory
}

after(() => {
	for (const directory of temporaries) rmSync(directory, { recursive: true, force: true })
})

describe('secret scanning', () => {
	const forbiddenKeys = [
		{ password: 'anything' },
		{ apiToken: 'x' },
		{ AUTHORIZATION: 'x' },
		{ client_secret: 'x' },
		{ confirmationCode: 'x' },
		{ idempotency_key: 'x' },
	]

	it('rejects a forbidden key in any case, at any depth', () => {
		for (const payload of forbiddenKeys) {
			assert.equal(findSecrets({ lane: { summary: payload } }).length, 1, JSON.stringify(payload))
			assert.throws(
				() => assertNoSecrets({ steps: [payload] }, 'probe'),
				/forbidden credential key/,
			)
		}
	})

	it('rejects a credential carried in a value', () => {
		const cases = [
			'Authorization: Basic ZGVtbzpkZW1v',
			'Bearer eyJhbGciOiJIUzI1NiJ9',
			'abcd efgh ijkl mnop qrst uvwx',
			'FLUENTCART_MCP_API_KEY=hunter2',
		]
		for (const value of cases) {
			assert.ok(findSecrets({ note: value }).length > 0, value)
		}
	})

	it('leaves ordinary acceptance evidence alone', () => {
		assert.deepEqual(
			findSecrets({ name: 'tokens', status: 'BLOCKED', note: 'missing test file' }),
			[],
		)
	})

	it('names the pointer so the failure is diagnosable without dumping the payload', () => {
		assert.throws(
			() => assertNoSecrets({ lanes: [{ summary: { password: 'x' } }] }, 'summary.json'),
			/\/lanes\/0\/summary\/password/,
		)
	})
})

describe('atomic evidence writes', () => {
	it('writes complete JSON with owner-only permissions and no temporary residue', () => {
		const directory = createRunDirectory(resolveOutputRoot(temporary()), SHA)
		const target = join(directory, 'probe.json')
		writeJsonAtomic(target, { name: 'probe', status: 'PASS' })
		assert.deepEqual(JSON.parse(readFileSync(target, 'utf8')), { name: 'probe', status: 'PASS' })
		assert.match(readFileSync(target, 'utf8'), /\n$/)
		assert.equal(statSync(target).mode & 0o777, 0o600)
		assert.deepEqual(readdirSync(directory), ['probe.json'])
	})

	it('fails the write loudly and creates nothing when evidence carries a credential', () => {
		const directory = createRunDirectory(resolveOutputRoot(temporary()), SHA)
		const target = join(directory, 'tainted.json')
		assert.throws(
			() => writeJsonAtomic(target, { lane: { summary: { authorization: 'x' } } }),
			/refusing to write/,
		)
		assert.equal(existsSync(target), false)
		assert.deepEqual(readdirSync(directory), [])
	})

	it('refuses an evidence path inside tracked source', () => {
		assert.throws(
			() => writeJsonAtomic(join(PACKAGE_ROOT, 'src', 'leak.json'), { ok: true }),
			/tracked source/,
		)
		assert.throws(() => writeJsonAtomic('relative.json', { ok: true }), /must be absolute/)
	})
})

const PROBE = {
	description: 'internal probe lane used by the evidence contract test only',
	steps: [{ id: 'ok', kind: 'node', file: 'scripts/acceptance/evidence-writer.mjs', args: [] }],
}

async function runProbe(output) {
	LANES['contract-probe'] = PROBE
	const sink = { out: () => undefined, err: () => undefined }
	try {
		return await main(['--lane', 'contract-probe', '--output', output, '--source-sha', SHA], sink)
	} finally {
		Reflect.deleteProperty(LANES, 'contract-probe')
	}
}

function assertLaneResult(lane) {
	assert.deepEqual(Object.keys(lane).sort(), [
		'command',
		'durationMs',
		'evidenceFiles',
		'name',
		'startedAt',
		'status',
		'summary',
	])
	assert.equal(typeof lane.name, 'string')
	assert.ok(LANE_STATUSES.includes(lane.status), `${lane.status} is not a LaneStatus`)
	assert.equal(new Date(lane.startedAt).toISOString(), lane.startedAt)
	assert.ok(Number.isInteger(lane.durationMs) && lane.durationMs >= 0)
	assert.ok(Array.isArray(lane.command) && lane.command.every((part) => typeof part === 'string'))
	for (const value of Object.values(lane.summary)) {
		assert.ok(
			['string', 'number', 'boolean'].includes(typeof value) || value === null,
			`${value} is not a scalar`,
		)
	}
	assert.ok(lane.evidenceFiles.every((file) => isAbsolute(file)))
}

describe('emitted evidence contract', () => {
	it('emits summary.json, one JSON file per lane and no fabricated JUnit', async () => {
		const output = temporary()
		assert.equal(await runProbe(output), 0)
		// The harness records where writes actually land, so an ancestor symlink is already resolved.
		const directory = join(resolveOutputRoot(output), SHA)
		assert.deepEqual(readdirSync(directory).sort(), ['lane-contract-probe.json', 'summary.json'])

		const evidence = JSON.parse(readFileSync(join(directory, 'lane-contract-probe.json'), 'utf8'))
		assertLaneResult(evidence.lane)
		assert.equal(evidence.lane.status, 'PASS')
		assert.deepEqual(evidence.lane.evidenceFiles, [join(directory, 'lane-contract-probe.json')])

		const summary = JSON.parse(readFileSync(join(directory, 'summary.json'), 'utf8'))
		assert.equal(summary.status, 'PASS')
		assert.equal(summary.runDirectory, directory)
		assert.equal(summary.installVerified, false)
		for (const lane of summary.lanes) assertLaneResult(lane)

		// Generated from the run, never hand-maintained. Empty here because the probe skips nothing.
		assert.deepEqual(summary.unprovenCapabilities, [])
		assert.equal(summary.counts.unproven, 0)
	})

	it('carries an unproven-capability entry for every skipped test, with its reason', () => {
		// The shape plan 08 Task 9 hands the owner: what was not verified, and why, per lane.
		const entries = [
			{
				lane: 'reversible-live',
				step: 'reversible-live',
				name: 'saves pricing',
				reason: 'orphan revision',
			},
		]
		for (const entry of entries) {
			assert.deepEqual(Object.keys(entry).sort(), ['lane', 'name', 'reason', 'step'])
			assert.ok(entry.reason.length > 0, 'an entry with no reason explains nothing')
		}
		assert.deepEqual(findSecrets({ unprovenCapabilities: entries }), [])
	})

	it('keeps every lane file name derivable from the registry', () => {
		for (const name of LANE_NAMES) {
			assert.match(`lane-${name}.json`, /^lane-[a-z-]+\.json$/)
		}
		assert.equal(new Set(LANE_NAMES).size, LANE_NAMES.length)
	})

	it('makes protocol evidence count four mandatory test reports', () => {
		assert.equal(LANES.protocol.steps.length, 4)
		for (const step of LANES.protocol.steps) {
			assert.equal(step.reporter, 'node-test')
			assert.equal(step.optIn, undefined)
			assert.ok(step.files.length > 0)
		}
	})
})

const IGNORE_FILE = join(PACKAGE_ROOT, '.gitignore')
const ignoreRules = readFileSync(IGNORE_FILE, 'utf8')
	.split('\n')
	.map((line) => line.trim())
	.filter((line) => line !== '' && !line.startsWith('#'))

describe('gitignore contract', () => {
	it('anchors the two evidence rules the harness depends on', () => {
		assert.ok(ignoreRules.includes('/dist-packages/'), '.gitignore must anchor /dist-packages/')
		assert.ok(
			ignoreRules.includes('/artifacts/acceptance/'),
			'.gitignore must anchor /artifacts/acceptance/',
		)
	})

	it('never widens to a pattern that could hide source or fixtures', () => {
		const tooBroad = [
			'artifacts',
			'artifacts/',
			'/artifacts',
			'/artifacts/',
			'**/artifacts',
			'dist-packages',
			'*.json',
			'**/*.json',
			'*.mjs',
			'*.ts',
			'*',
			'**',
			'tests',
			'tests/',
			'src',
			'src/',
			'scripts',
			'scripts/',
			'fixtures',
		]
		for (const rule of ignoreRules) {
			assert.ok(!tooBroad.includes(rule), `.gitignore rule "${rule}" is too broad`)
		}
	})

	it('leaves representative tracked paths visible to git', () => {
		const paths = [
			'src/index.ts',
			'package.json',
			'manifest.json',
			'scripts/acceptance/run-acceptance.mjs',
			'tests/acceptance/evidence-contract.test.mjs',
			'tests/fixtures/routes/fluentcart-1.6.0-core-pro-1.6.0.json',
			'artifacts/keep-me.json',
		]
		for (const path of paths) {
			const check = spawnSync('git', ['check-ignore', '--no-index', '-q', path], {
				cwd: PACKAGE_ROOT,
			})
			assert.equal(check.status, 1, `.gitignore must not hide ${path}`)
		}
	})

	it('does hide the two evidence destinations', () => {
		for (const path of [
			'dist-packages/fluentcart-mcp-1.1.0.tgz',
			'artifacts/acceptance/run/summary.json',
		]) {
			const check = spawnSync('git', ['check-ignore', '--no-index', '-q', path], {
				cwd: PACKAGE_ROOT,
			})
			assert.equal(check.status, 0, `.gitignore must hide ${path}`)
		}
	})
})
