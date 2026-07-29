import { describe, expect, it } from 'vitest'
import {
	canExposeTool,
	DEFAULT_WRITE_MODE,
	parseWriteMode,
	WRITE_MODES,
} from '../../src/security/write-policy.js'
import type { ToolRisk, ToolSafety } from '../../src/tools/risk.js'

const READ: ToolSafety = { risk: 'read', idempotency: 'inherent', execution: 'rest' }
const REVERSIBLE: ToolSafety = {
	risk: 'reversible-write',
	idempotency: 'inherent',
	execution: 'rest',
}
const UNAVAILABLE_MONEY: ToolSafety = {
	risk: 'real-money',
	idempotency: 'unsupported',
	execution: 'none',
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
	it('supports only disabled and reversible profiles', () => {
		expect(WRITE_MODES).toEqual(['disabled', 'reversible'])
		expect(parseWriteMode(undefined)).toBe('disabled')
		expect(parseWriteMode('')).toBe('disabled')
		expect(DEFAULT_WRITE_MODE).toBe('disabled')
		expect(parseWriteMode('reversible')).toBe('reversible')
	})

	it('rejects the removed guarded profile instead of silently accepting it', () => {
		for (const value of ['guarded', 'enabled', 'DISABLED', 'yes', 'true', 'read-write']) {
			expect(() => parseWriteMode(value)).toThrow(/Invalid FLUENTCART_WRITE_MODE/)
		}
	})
})

describe('canExposeTool', () => {
	it('exposes reads in every supported mode', () => {
		for (const writeMode of WRITE_MODES) {
			expect(canExposeTool(READ, { writeMode })).toBe(true)
		}
	})

	it('exposes reversible writes only in reversible mode', () => {
		expect(canExposeTool(REVERSIBLE, { writeMode: 'disabled' })).toBe(false)
		expect(canExposeTool(REVERSIBLE, { writeMode: 'reversible' })).toBe(true)
	})

	it('keeps unavailable real-money operations absent in every supported mode', () => {
		for (const writeMode of WRITE_MODES) {
			expect(canExposeTool(UNAVAILABLE_MONEY, { writeMode })).toBe(false)
		}
	})

	it('hides every non-executable risk class in every supported mode', () => {
		for (const risk of HIDDEN_RISKS) {
			for (const writeMode of WRITE_MODES) {
				const safety: ToolSafety = { risk, idempotency: 'unsupported', execution: 'none' }
				expect(
					canExposeTool(safety, { writeMode }),
					`${risk} must stay hidden in ${writeMode}`,
				).toBe(false)
			}
		}
	})
})
