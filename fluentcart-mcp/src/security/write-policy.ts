import type { ToolSafety } from '../tools/risk.js'
import { isNonExecutableRisk } from '../tools/risk.js'

export type WriteMode = 'disabled' | 'reversible'

export const WRITE_MODES: readonly WriteMode[] = ['disabled', 'reversible']

export const DEFAULT_WRITE_MODE: WriteMode = 'disabled'

export interface WritePolicyConfig {
	writeMode: WriteMode
}

export function parseWriteMode(value: string | undefined): WriteMode {
	if (value === undefined || value === '') return DEFAULT_WRITE_MODE
	if ((WRITE_MODES as readonly string[]).includes(value)) return value as WriteMode
	throw new Error(
		`Invalid FLUENTCART_WRITE_MODE "${value}". Expected one of: ${WRITE_MODES.join(', ')}.`,
	)
}

/**
 * Decide whether a tool may appear at all.
 *
 * This is an exposure decision, not merely an execution decision: a tool that fails this check
 * is absent from every registry, so it cannot be listed, searched, described or called by name.
 * Curated, dynamic, code and full modes all consult this same function, because a safety rule
 * that only some modes honour is not a safety rule.
 */
export function canExposeTool(safety: ToolSafety, config: WritePolicyConfig): boolean {
	if (safety.risk === 'read') return true

	// Risk classes this server does not execute under any configuration.
	if (isNonExecutableRisk(safety.risk)) return false

	if (safety.execution === 'none') return false

	if (safety.risk === 'reversible-write') {
		return config.writeMode === 'reversible'
	}

	return false
}
