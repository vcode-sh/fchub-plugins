import { describe, expect, it } from 'vitest'
import {
	canExposeTool,
	DEFAULT_WRITE_MODE,
	parseWriteMode,
	WRITE_MODES,
	type WriteMode,
} from '../../src/security/write-policy.js'
import type { ToolRisk, ToolSafety } from '../../src/tools/risk.js'

const guardReady = { persistentState: true, signingSecret: true }
const guardMissing = { persistentState: false, signingSecret: false }

const READ: ToolSafety = { risk: 'read', idempotency: 'inherent', execution: 'rest' }
const REVERSIBLE: ToolSafety = {
	risk: 'reversible-write',
	idempotency: 'inherent',
	execution: 'rest',
}
const GUARDED_MONEY: ToolSafety = {
	risk: 'real-money',
	idempotency: 'guard-required',
	execution: 'guarded-rest',
}

const HIDDEN_RISKS: ToolRisk[] = [
	'destructive-write',
	'control-plane',
	'credential-bearing',
	'infrastructure',
	'external-side-effect',
	'unreviewed-write',
]

describe('parseWriteMode', () => {
	it('defaults to disabled', () => {
		expect(parseWriteMode(undefined)).toBe('disabled')
		expect(parseWriteMode('')).toBe('disabled')
		expect(DEFAULT_WRITE_MODE).toBe('disabled')
	})

	it('accepts each documented mode', () => {
		for (const mode of WRITE_MODES) {
			expect(parseWriteMode(mode)).toBe(mode)
		}
	})

	it('fails configuration on an unknown value rather than falling back', () => {
		for (const value of ['enabled', 'DISABLED', 'yes', 'true', 'read-write']) {
			expect(() => parseWriteMode(value)).toThrow(/Invalid FLUENTCART_WRITE_MODE/)
		}
	})
})

describe('canExposeTool', () => {
	it('exposes reads in every write mode', () => {
		for (const writeMode of WRITE_MODES) {
			expect(canExposeTool(READ, { writeMode, guard: guardMissing })).toBe(true)
		}
	})

	it('hides reversible writes when writes are disabled', () => {
		expect(canExposeTool(REVERSIBLE, { writeMode: 'disabled', guard: guardReady })).toBe(false)
	})

	it('exposes reversible writes in reversible and guarded modes', () => {
		for (const writeMode of ['reversible', 'guarded'] as WriteMode[]) {
			expect(canExposeTool(REVERSIBLE, { writeMode, guard: guardMissing })).toBe(true)
		}
	})

	it('hides real-money actions unless guarded mode is fully available', () => {
		expect(canExposeTool(GUARDED_MONEY, { writeMode: 'disabled', guard: guardReady })).toBe(false)
		expect(canExposeTool(GUARDED_MONEY, { writeMode: 'reversible', guard: guardReady })).toBe(false)
		expect(canExposeTool(GUARDED_MONEY, { writeMode: 'guarded', guard: guardMissing })).toBe(false)
		expect(
			canExposeTool(GUARDED_MONEY, {
				writeMode: 'guarded',
				guard: { persistentState: true, signingSecret: false },
			}),
		).toBe(false)
		expect(
			canExposeTool(GUARDED_MONEY, {
				writeMode: 'guarded',
				guard: { persistentState: false, signingSecret: true },
			}),
		).toBe(false)
	})

	it('exposes a real-money action only with guarded mode plus state and secret', () => {
		expect(canExposeTool(GUARDED_MONEY, { writeMode: 'guarded', guard: guardReady })).toBe(true)
	})

	it('never exposes a real-money action declared as plain REST execution', () => {
		const unguarded: ToolSafety = {
			risk: 'real-money',
			idempotency: 'guard-required',
			execution: 'rest',
		}
		expect(canExposeTool(unguarded, { writeMode: 'guarded', guard: guardReady })).toBe(false)
	})

	it('hides every non-executable risk class in every mode', () => {
		for (const risk of HIDDEN_RISKS) {
			for (const writeMode of WRITE_MODES) {
				const safety: ToolSafety = { risk, idempotency: 'unsupported', execution: 'none' }
				expect(
					canExposeTool(safety, { writeMode, guard: guardReady }),
					`${risk} must stay hidden in ${writeMode} mode`,
				).toBe(false)
			}
		}
	})

	it('hides a non-executable risk even if a row claims REST execution', () => {
		// Defence in depth: the risk class decides, not an optimistic execution field.
		for (const risk of HIDDEN_RISKS) {
			const mislabelled: ToolSafety = { risk, idempotency: 'inherent', execution: 'rest' }
			expect(canExposeTool(mislabelled, { writeMode: 'guarded', guard: guardReady })).toBe(false)
		}
	})
})
