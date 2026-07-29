/**
 * Business-risk classification for a tool.
 *
 * Deliberately not derived from the HTTP verb. FluentCart uses POST for previews and for
 * refunds alike, so the verb says nothing useful about whether an action can be undone or
 * whether it moves real money.
 */
export type ToolRisk =
	/** Returns data and changes nothing. */
	| 'read'
	/** Changes state, but the change has a verified read-back and an undo or delete. */
	| 'reversible-write'
	/** Removes or overwrites data with no supported restore path. */
	| 'destructive-write'
	/** Moves real money, or instructs a payment gateway to do so. */
	| 'real-money'
	/** Reaches outside the store: sends email, calls a third party, publishes something. */
	| 'external-side-effect'
	/** Changes who may do what: roles, permissions, modules, installed integrations. */
	| 'control-plane'
	/** Reads or writes credentials, API keys or gateway secrets. */
	| 'credential-bearing'
	/** Changes storage, files or other runtime infrastructure. */
	| 'infrastructure'
	/** Default for any write nobody has classified yet. Never exposed. */
	| 'unreviewed-write'

export interface ToolSafety {
	risk: ToolRisk
	/**
	 * `inherent` — repeating the call cannot double-apply it.
	 * `unsupported` — the action cannot be made safely repeatable at all.
	 */
	idempotency: 'inherent' | 'unsupported'
	/**
	 * `rest` — call FluentCart directly.
	 * `none` — not executable by this server under any configuration.
	 */
	execution: 'rest' | 'none'
}

export const READ_SAFETY: ToolSafety = {
	risk: 'read',
	idempotency: 'inherent',
	execution: 'rest',
}

/** What an unclassified write gets. It is hidden everywhere, which is the point. */
export const UNREVIEWED_WRITE_SAFETY: ToolSafety = {
	risk: 'unreviewed-write',
	idempotency: 'unsupported',
	execution: 'none',
}

/** Risk classes that never execute, regardless of the configured write mode. */
export const NON_EXECUTABLE_RISKS: readonly ToolRisk[] = [
	'destructive-write',
	'control-plane',
	'credential-bearing',
	'infrastructure',
	'external-side-effect',
	'unreviewed-write',
]

export function isNonExecutableRisk(risk: ToolRisk): boolean {
	return NON_EXECUTABLE_RISKS.includes(risk)
}
