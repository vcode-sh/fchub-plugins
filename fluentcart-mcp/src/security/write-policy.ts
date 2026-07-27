import type { ToolSafety } from '../tools/risk.js'
import { isNonExecutableRisk } from '../tools/risk.js'

export type WriteMode = 'disabled' | 'reversible' | 'guarded'

export const WRITE_MODES: readonly WriteMode[] = ['disabled', 'reversible', 'guarded']

export const DEFAULT_WRITE_MODE: WriteMode = 'disabled'

export interface GuardAvailability {
	/** A writable, persistent, owner-only state directory exists. */
	persistentState: boolean
	/** A signing secret of sufficient length was supplied, never generated. */
	signingSecret: boolean
}

export interface WritePolicyConfig {
	writeMode: WriteMode
	guard: GuardAvailability
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
		return config.writeMode === 'reversible' || config.writeMode === 'guarded'
	}

	if (safety.risk === 'real-money') {
		// Guarded mode alone is not enough: without durable state and a signing secret there is
		// no way to prevent a replayed refund, so the tool stays hidden.
		return (
			config.writeMode === 'guarded' &&
			safety.execution === 'guarded-rest' &&
			config.guard.persistentState &&
			config.guard.signingSecret
		)
	}

	return false
}
