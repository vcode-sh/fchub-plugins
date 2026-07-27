import assert from 'node:assert/strict'
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { dirname, join, relative, resolve } from 'node:path'
import { describe, it } from 'node:test'
import { fileURLToPath } from 'node:url'

const packageRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..')
const testsRoot = join(packageRoot, 'tests')

function walk(directory) {
	const found = []
	for (const entry of readdirSync(directory)) {
		if (entry === 'node_modules' || entry.startsWith('.')) continue
		const full = join(directory, entry)
		if (statSync(full).isDirectory()) {
			found.push(...walk(full))
		} else {
			found.push(full)
		}
	}
	return found
}

const files = walk(testsRoot)
const rel = (f) => relative(packageRoot, f)

// This scanner necessarily contains every pattern it searches for, so it cannot scan itself
// without reporting itself. Its own contents are reviewed here, in the file you are reading.
const SELF = 'tests/tooling/no-unsafe-scenarios.test.mjs'
const sourceFiles = files.filter((f) => /\.(ts|mts|mjs|js)$/.test(f) && rel(f) !== SELF)

describe('repository contract: no unsafe live entry points', () => {
	it('has no loose tests/_*.ts programs', () => {
		const loose = files
			.filter((f) => dirname(f) === testsRoot)
			.filter((f) => /(^|\/)_[^/]+\.ts$/.test(f))
			.map(rel)

		assert.deepEqual(
			loose,
			[],
			`loose credential-loading programs still present: ${loose.join(', ')}`,
		)
	})

	it('has no tests/live-qa.ts entry point', () => {
		assert.equal(
			files.some((f) => rel(f) === 'tests/live-qa.ts'),
			false,
			'tests/live-qa.ts is a loose live entry point and must not exist',
		)
	})

	it('has no scenario programs left behind under any directory', () => {
		const scenarios = files
			.filter((f) => /_scenarios-|_tiger-pants-flow|_cleanup\./.test(f))
			.map(rel)
		assert.deepEqual(scenarios, [], `scenario programs still present: ${scenarios.join(', ')}`)
	})

	it('documents no "source .env" instruction anywhere in tests', () => {
		const offenders = sourceFiles
			.filter((f) => /source\s+\.env/.test(readFileSync(f, 'utf8')))
			.map(rel)

		assert.deepEqual(offenders, [], `"source .env" instructions found in: ${offenders.join(', ')}`)
	})

	it('names scripts/run-live-tests.mjs as the only credential-loading entry point', () => {
		const offenders = []
		for (const file of sourceFiles) {
			const text = readFileSync(file, 'utf8')
			// Reading the exact credential file, or any env-file loader, is launcher-only.
			if (/\.env\.test\.local|loadEnvFile\(|parseEnv\(|loadEnv\(/.test(text)) {
				offenders.push(rel(file))
			}
		}

		assert.deepEqual(
			offenders,
			[],
			`only scripts/run-live-tests.mjs may read credentials; offenders: ${offenders.join(', ')}`,
		)
	})

	it('never deletes a hard-coded numeric record id from a live-capable file', () => {
		const offenders = []
		for (const file of sourceFiles) {
			const name = rel(file)
			// Only files that can actually reach a store are in scope. Unit tests drive a stubbed
			// fetch, so a literal id there addresses nothing real.
			if (!name.startsWith('tests/integration/')) continue

			const text = readFileSync(file, 'utf8')
			// e.g. client.delete('/products/123') — a literal id nobody in this run created.
			const pattern = /\.(delete|post|put)\(\s*[`'"]\/[a-z0-9\-/]*\/\d{1,10}[`'"/]/i
			if (pattern.test(text)) offenders.push(name)
		}

		assert.deepEqual(
			offenders,
			[],
			`hard-coded record ids used in a mutating call: ${offenders.join(', ')}`,
		)
	})

	it('never refunds, cancels or restatuses a discovered record', () => {
		// Reviewed homes for guarded-action coverage. Everywhere else, a refund or cancellation
		// is by definition acting on a record the test did not create this run.
		const reviewedGuardDirectories = [
			'tests/tooling/',
			'tests/security/',
			'tests/tools/',
			'tests/acceptance/',
			// Code-mode tests name refund and cancellation only to assert the sandbox refuses
			// them. They build in-memory fixtures and make no network call of any kind.
			'tests/code-mode/',
		]

		const offenders = []
		for (const file of sourceFiles) {
			const name = rel(file)
			if (reviewedGuardDirectories.some((dir) => name.startsWith(dir))) continue

			const text = readFileSync(file, 'utf8')
			const rawRoute = /\.(post|put)\(\s*[`'"][^`'"]*(refund|\/cancel|change-status|update-status)/i
			// Tool-name dispatch in call position, e.g. call('fluentcart_order_refund', {...}).
			// The opening parenthesis is required so that merely listing the name — as the
			// scenario-coverage regression ledger does — is not mistaken for invoking it.
			const toolDispatch =
				/\(\s*[`'"]fluentcart_(order_refund|subscription_cancel|order_status_update)[`'"]\s*,/

			if (rawRoute.test(text) || toolDispatch.test(text)) offenders.push(name)
		}

		assert.deepEqual(
			offenders,
			[],
			`refund/cancel/status mutation found outside the guarded modules: ${offenders.join(', ')}`,
		)
	})

	it('requires every live integration test to obtain the run identity', () => {
		const integrationTests = files.filter(
			(f) => rel(f).startsWith('tests/integration/') && f.endsWith('.test.ts'),
		)
		assert.ok(integrationTests.length > 0, 'expected at least one integration test')

		for (const file of integrationTests) {
			const text = readFileSync(file, 'utf8')
			assert.match(
				text,
				/getLiveRun\(\)|getLiveClient\(\)/,
				`${rel(file)} must enter through the run-owned live harness`,
			)
		}
	})

	it('forbids reading credentials straight from process.env in integration tests', () => {
		const offenders = []
		for (const file of files.filter((f) => rel(f).startsWith('tests/integration/'))) {
			if (rel(file).includes('/support/')) continue
			const text = readFileSync(file, 'utf8')
			if (/process\.env\.FLUENTCART_(URL|USERNAME|APP_PASSWORD)/.test(text)) {
				offenders.push(rel(file))
			}
		}

		assert.deepEqual(
			offenders,
			[],
			`integration tests must use the shared live harness, not raw credentials: ${offenders.join(', ')}`,
		)
	})
})
