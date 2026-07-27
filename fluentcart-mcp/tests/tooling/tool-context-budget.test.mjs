import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { dirname, join } from 'node:path'
import { before, describe, it } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	DEFINITION_BUDGETS,
	LARGEST_TOOLS_LIMIT,
	loadDescriptionExceptions,
	MAX_DESCRIPTION_CHARACTERS,
	MEASURED_MODES,
	measureAllModes,
	measureMode,
	REGRESSION_BASELINES,
	resolveMode,
	SERIALIZER,
	TOKENIZER,
	toReport,
} from '../../scripts/measure-tool-context.mjs'

const PACKAGE_ROOT = dirname(dirname(dirname(fileURLToPath(import.meta.url))))
const SCRIPT = join(PACKAGE_ROOT, 'scripts', 'measure-tool-context.mjs')
const REPORT_KEYS = [
	'serializer',
	'tokenizer',
	'mode',
	'toolCount',
	'characters',
	'cl100kTokens',
	'o200kTokens',
	'largestTools',
]

let measurements
let reports

before(async () => {
	measurements = await measureAllModes()
	reports = measurements.map((measurement) => toReport(measurement))
})

function measurementFor(mode) {
	return measurements.find((measurement) => measurement.mode === mode)
}

function runScript() {
	return execFileSync(process.execPath, [SCRIPT, '--no-build', '--quiet'], {
		cwd: PACKAGE_ROOT,
		encoding: 'utf8',
	})
}

describe('measurement report shape', () => {
	it('covers all four modes exactly once, in a fixed order', () => {
		assert.deepEqual([...MEASURED_MODES], ['dynamic', 'curated', 'code', 'full'])
		assert.deepEqual(
			reports.map((report) => report.mode),
			[...MEASURED_MODES],
		)
	})

	it('uses stable key ordering and names its serializer and tokenizer', () => {
		for (const report of reports) {
			const expected = report.unavailable ? [...REPORT_KEYS, 'unavailable'] : REPORT_KEYS
			assert.deepEqual(Object.keys(report), expected, `key order for ${report.mode}`)
			assert.equal(report.serializer, SERIALIZER)
			assert.equal(report.tokenizer, TOKENIZER)
		}
	})

	it('reports per-tool contributions that account for the whole payload', () => {
		for (const measurement of measurements) {
			if (!measurement.available) continue
			// JSON.stringify of an array is '[' + items.join(',') + ']', so the per-tool character
			// counts must rebuild the total exactly. A mismatch means the per-tool figures were
			// measured against something other than the transmitted payload.
			const sum = measurement.tools.reduce((total, tool) => total + tool.characters, 0)
			const separators = Math.max(measurement.tools.length - 1, 0)
			assert.equal(
				sum + separators + 2,
				measurement.characters,
				`per-tool sum, ${measurement.mode}`,
			)
		}
	})

	it('lists the costliest tools first and caps the list', () => {
		for (const report of reports) {
			if (report.unavailable) continue
			assert.ok(report.largestTools.length > 0, `${report.mode} reported no per-tool contributions`)
			assert.ok(report.largestTools.length <= LARGEST_TOOLS_LIMIT)
			for (let index = 1; index < report.largestTools.length; index += 1) {
				const previous = report.largestTools[index - 1]
				const current = report.largestTools[index]
				assert.ok(
					previous.cl100kTokens > current.cl100kTokens ||
						(previous.cl100kTokens === current.cl100kTokens && previous.name < current.name),
					`${report.mode}: ${previous.name} is not ordered before ${current.name}`,
				)
			}
		}
	})
})

describe('determinism', () => {
	it('prints byte-identical JSON on repeated runs', () => {
		assert.equal(runScript(), runScript())
	})

	it('prints exactly what the in-process measurement produces, key order included', () => {
		assert.equal(runScript(), `${JSON.stringify(reports, null, 2)}\n`)
	})
})

describe('definition budget contract', () => {
	for (const mode of MEASURED_MODES) {
		it(`holds ${mode} definitions to their budget in both encodings`, (t) => {
			const measurement = measurementFor(mode)
			if (!measurement.available) {
				// Skipped, never passed: an unimplemented mode has no numbers to gate.
				t.skip(`${mode} mode is not implemented in this build, so its budget was not asserted`)
				return
			}

			const budget = DEFINITION_BUDGETS[mode]
			t.diagnostic(
				`${mode}: ${measurement.toolCount} tools, ${measurement.characters} characters, ${measurement.cl100kTokens} cl100k, ${measurement.o200kTokens} o200k`,
			)

			if (budget === null) {
				// Full mode is measured and reported; it is never a default gate.
				assert.ok(measurement.toolCount > 0, 'full mode must still be measured')
				return
			}

			assert.ok(
				measurement.cl100kTokens <= budget,
				`${mode} definitions cost ${measurement.cl100kTokens} cl100k tokens, over the ${budget} budget`,
			)
			assert.ok(
				measurement.o200kTokens <= budget,
				`${mode} definitions cost ${measurement.o200kTokens} o200k tokens, over the ${budget} budget`,
			)
		})
	}

	it('gates dynamic, curated and code but never full', () => {
		assert.equal(DEFINITION_BUDGETS.dynamic, 1500)
		assert.equal(DEFINITION_BUDGETS.code, 1200)
		assert.equal(DEFINITION_BUDGETS.curated, 12000)
		assert.equal(DEFINITION_BUDGETS.full, null)
	})
})

describe('tool description limit', () => {
	it('keeps every transmitted description within 800 characters unless reviewed', () => {
		const exceptions = loadDescriptionExceptions()
		const offenders = new Map()

		for (const measurement of measurements) {
			if (!measurement.available) continue
			for (const tool of measurement.tools) {
				if (tool.descriptionCharacters <= MAX_DESCRIPTION_CHARACTERS) continue
				if (exceptions.has(tool.name)) continue
				offenders.set(tool.name, tool.descriptionCharacters)
			}
		}

		const described = [...offenders].map(([name, size]) => `${name} (${size} characters)`)
		assert.deepEqual(
			described,
			[],
			`Shorten these descriptions, or name each one in tool-description-exceptions.json with a reviewer and a reason: ${described.join('; ')}`,
		)
	})
})

describe('mode availability', () => {
	it('resolves every mode the build declares to itself', () => {
		const built = { TOOLSET_MODES: ['dynamic', 'curated', 'code', 'full'] }
		for (const mode of MEASURED_MODES) assert.equal(resolveMode(built, mode), mode)
	})

	it('reports an undeclared mode as unavailable instead of falling back to the full registry', () => {
		const partial = { TOOLSET_MODES: ['dynamic', 'full'] }
		assert.equal(resolveMode(partial, 'curated'), null)
		assert.equal(resolveMode(partial, 'code'), null)
	})

	it('maps full onto the legacy static registry for a build that predates the mode split', () => {
		const legacy = {}
		assert.equal(resolveMode(legacy, 'full'), 'static')
		assert.equal(resolveMode(legacy, 'dynamic'), 'dynamic')
		assert.equal(resolveMode(legacy, 'curated'), null)
		assert.equal(resolveMode(legacy, 'code'), null)
	})

	it('renders an unavailable mode as a null row rather than inventing numbers', () => {
		const report = toReport({ mode: 'code', available: false, tools: [] })
		assert.equal(report.toolCount, null)
		assert.equal(report.characters, null)
		assert.equal(report.cl100kTokens, null)
		assert.equal(report.o200kTokens, null)
		assert.equal(report.unavailable, true)
		assert.deepEqual(report.largestTools, [])
	})
})

describe('measurement isolation', () => {
	it('measures the wire payload without touching the network', async () => {
		const originalFetch = globalThis.fetch
		let calls = 0
		globalThis.fetch = (...args) => {
			calls += 1
			return originalFetch(...args)
		}

		try {
			await measureMode('full')
		} finally {
			globalThis.fetch = originalFetch
		}

		assert.equal(calls, 0, 'listing tool definitions must not reach the store')
	})
})

describe('regression baselines', () => {
	it('keeps the 2026-07-27 measurements seeded verbatim', () => {
		assert.deepEqual(REGRESSION_BASELINES.full, {
			toolCount: 274,
			characters: 168127,
			cl100kTokens: 36680,
			o200kTokens: 37856,
		})
		assert.deepEqual(REGRESSION_BASELINES.dynamic, {
			toolCount: 3,
			characters: 2094,
			cl100kTokens: 447,
			o200kTokens: 456,
		})
	})

	it('records the current build against those baselines', (t) => {
		for (const [mode, baseline] of Object.entries(REGRESSION_BASELINES)) {
			const measurement = measurementFor(mode)
			if (!measurement?.available) continue
			t.diagnostic(
				`${mode}: ${measurement.toolCount} tools / ${measurement.characters} chars / ${measurement.cl100kTokens} cl100k, against baseline ${baseline.toolCount} / ${baseline.characters} / ${baseline.cl100kTokens}`,
			)
		}

		// A drift is recorded rather than failed: full mode is never a gate, and the dynamic
		// registry legitimately grew when execution was split by risk class.
		assert.ok(measurementFor('full').available, 'full mode must be measurable')
	})
})
