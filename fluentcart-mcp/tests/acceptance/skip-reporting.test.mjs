// The regression that must never come back: a skipped test reading as a pass.
//
// Both test runners exit 0 when tests are skipped. A harness that trusts the exit code reports PASS
// for a lane that skipped the only test proving its capability, and a green PASS on a skipped test
// is worse than a BLOCKED — it looks like evidence. Everything here is judged on the runner's own
// report, and none of these cases may come back as a bare PASS.

import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { LANES } from '../../scripts/acceptance/lanes.mjs'
import { classify, parseJunit } from '../../scripts/acceptance/test-report.mjs'

describe('a skipped test can never read as a pass', () => {
	const junit = (cases) =>
		`<testsuites><testsuite>${cases
			.map(([name, state]) =>
				state === 'pass'
					? `<testcase name="${name}"/>`
					: `<testcase name="${name}"><${state}/></testcase>`,
			)
			.join('')}</testsuite></testsuites>`

	it('reads counts and names out of both runners’ JUnit', () => {
		const report = parseJunit(
			junit([
				['runs a thing', 'pass'],
				['skips a thing (BLOCKED: no fixture)', 'skipped'],
				['breaks a thing', 'failure'],
			]),
		)
		assert.deepEqual(
			{
				tests: report.tests,
				passed: report.passed,
				skipped: report.skipped,
				failed: report.failed,
			},
			{ tests: 3, passed: 1, skipped: 1, failed: 1 },
		)
		assert.deepEqual(report.skips, [
			{ name: 'skips a thing (BLOCKED: no fixture)', reason: 'no fixture' },
		])
	})

	it('blocks when the test a lane exists to prove was skipped, however much else passed', () => {
		const report = parseJunit(
			junit([
				['records why the fixture cannot be owned', 'pass'],
				['registers nothing for cleanup', 'pass'],
				['the lane itself is inert', 'pass'],
				['previews a guarded refund (BLOCKED: transaction rows cannot be cleaned up)', 'skipped'],
			]),
		)
		const verdict = classify(report, { exitCode: 0, proves: ['previews a guarded refund'] })
		assert.equal(verdict.status, 'BLOCKED')
		assert.match(verdict.note, /transaction rows cannot be cleaned up/)
		assert.equal(verdict.unproven.length, 1)
	})

	it('blocks when a declared proof is absent from the run entirely', () => {
		// A renamed test must fail towards BLOCKED, never towards a pass nobody noticed.
		const report = parseJunit(junit([['something unrelated', 'pass']]))
		const verdict = classify(report, { exitCode: 0, proves: ['previews a guarded refund'] })
		assert.equal(verdict.status, 'BLOCKED')
		assert.match(verdict.note, /declared proof is absent/)
	})

	it('blocks when every test skipped, so nothing at all was verified', () => {
		const report = parseJunit(
			junit([
				['a (BLOCKED: x)', 'skipped'],
				['b (BLOCKED: y)', 'skipped'],
			]),
		)
		assert.equal(classify(report, { exitCode: 0 }).status, 'BLOCKED')
	})

	it('marks a lane that proved something and skipped something as excluded, not passed', () => {
		const report = parseJunit(
			junit([
				['restores an owned record', 'pass'],
				['saves product pricing (BLOCKED: leaves an orphan revision)', 'skipped'],
			]),
		)
		const verdict = classify(report, { exitCode: 0 })
		assert.equal(verdict.status, 'PASS_WITH_EXCLUSIONS')
		assert.deepEqual(verdict.unproven, [
			{
				name: 'saves product pricing (BLOCKED: leaves an orphan revision)',
				reason: 'leaves an orphan revision',
			},
		])
	})

	it('passes only when nothing was skipped and nothing failed', () => {
		const report = parseJunit(
			junit([
				['a', 'pass'],
				['b', 'pass'],
			]),
		)
		const verdict = classify(report, { exitCode: 0 })
		assert.equal(verdict.status, 'PASS')
		assert.deepEqual(verdict.unproven, [])
	})

	it('fails on a failing test and on a non-zero exit with no failing test', () => {
		const failing = parseJunit(junit([['a', 'failure']]))
		assert.equal(classify(failing, { exitCode: 1 }).status, 'FAIL')
		const clean = parseJunit(junit([['a', 'pass']]))
		assert.equal(classify(clean, { exitCode: 1 }).status, 'FAIL')
	})

	it('falls back to the exit code only where no report exists, and says so', () => {
		assert.equal(classify(null, { exitCode: 0 }).status, 'PASS')
		assert.equal(classify(null, { exitCode: 1 }).status, 'FAIL')
	})

	it('names a skip whose author recorded no reason, rather than inventing one', () => {
		const report = parseJunit(junit([['a nameless skip', 'skipped']]))
		assert.match(report.skips[0].reason, /no reason recorded/)
	})

	it('makes every test-running step declare a reporter, so none is judged on its exit code', () => {
		for (const [name, lane] of Object.entries(LANES)) {
			for (const step of lane.steps) {
				const runsTests = step.kind === 'node-test' || step.requiresScript?.startsWith('test:')
				if (!runsTests) continue
				assert.ok(step.reporter, `${name}/${step.id} runs tests but declares no reporter`)
			}
		}
	})
})
