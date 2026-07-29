/**
 * Hard limits and error vocabulary for read-only code mode.
 *
 * Every limit here is a refusal boundary, not a trimming rule. Code mode either returns one
 * complete JSON value or a structured error naming the limit it hit; it never returns a
 * shortened answer that reads like a whole one.
 */
export interface ResolvedLimits {
	maxSourceCharacters: number
	maxApiCalls: number
	maxWallClockMs: number
	maxCpuMs: number
	maxHeapBytes: number
	maxStackBytes: number
	maxOutputCharacters: number
}

/** Per-sandbox overrides. Production uses the defaults; tests lower them to stay fast. */
export type LimitOverrides = Partial<ResolvedLimits>

export const CODE_MODE_LIMITS = {
	/** Longest accepted source. Measured in characters before the sandbox is even created. */
	maxSourceCharacters: 12_000,
	/** Most `fluentcart.call` dispatches allowed in a single execution. */
	maxApiCalls: 10,
	/** Wall-clock ceiling for one execution, including time spent waiting on REST. */
	maxWallClockMs: 5_000,
	/**
	 * Interpreter CPU ceiling. Counted only while the VM is running: time spent waiting on a
	 * host REST call is paused, so a slow store cannot consume the compute budget.
	 */
	maxCpuMs: 2_000,
	/** QuickJS heap ceiling. Exceeding it raises an in-VM out-of-memory error. */
	maxHeapBytes: 32 * 1024 * 1024,
	/**
	 * QuickJS call-stack ceiling. Without this, deep recursion unwinds through the WebAssembly
	 * stack and aborts the whole module instead of raising a catchable in-VM error.
	 */
	maxStackBytes: 256 * 1024,
	/**
	 * Largest serialised result returned to the caller. Mirrors the default response budget
	 * used by direct tool execution so code mode cannot be used to bypass it.
	 */
	maxOutputCharacters: 24_000,
} as const satisfies ResolvedLimits

export type CodeModeErrorCode =
	/** The owning MCP request was cancelled before execution completed. */
	| 'EXECUTION_CANCELLED'
	/** Source exceeded `maxSourceCharacters`. */
	| 'SOURCE_TOO_LARGE'
	/** Source contained a module-loading construct that the sandbox refuses to run. */
	| 'FORBIDDEN_SYNTAX'
	/** The WebAssembly runtime failed its startup self-test. */
	| 'SANDBOX_UNAVAILABLE'
	/** The eleventh `fluentcart.call` in one execution. */
	| 'CALL_BUDGET_EXCEEDED'
	/** Operation name is not in the read-only registry. */
	| 'UNKNOWN_OPERATION'
	/** Operation exists but is not a read, so code mode will never dispatch it. */
	| 'WRITE_OPERATION_REFUSED'
	/** Input failed the operation's Zod schema at the dispatch boundary. */
	| 'INVALID_INPUT'
	/** The underlying read tool returned an MCP error. */
	| 'OPERATION_FAILED'
	/** Execution exceeded `maxWallClockMs`. */
	| 'WALL_CLOCK_EXCEEDED'
	/** Interpreter exceeded `maxCpuMs`. */
	| 'CPU_BUDGET_EXCEEDED'
	/** Sandbox exceeded `maxHeapBytes`. */
	| 'MEMORY_EXCEEDED'
	/** Sandbox exceeded `maxStackBytes`, typically unbounded recursion. */
	| 'STACK_EXCEEDED'
	/** A complete result was produced but is larger than `maxOutputCharacters`. */
	| 'RESPONSE_TOO_LARGE'
	/** The returned value cannot be represented as JSON. */
	| 'NON_JSON_RESULT'
	/** The sandbox was torn down while a call was in flight. */
	| 'SANDBOX_TERMINATED'
	/** Sandboxed code threw and did not catch. */
	| 'UNCAUGHT_EXCEPTION'

export interface CodeModeError {
	code: CodeModeErrorCode
	message: string
	details?: unknown
}

export function codeModeError(
	code: CodeModeErrorCode,
	message: string,
	details?: unknown,
): CodeModeError {
	return details === undefined ? { code, message } : { code, message, details }
}

/** Injectable clock so budget behaviour is testable without real waiting. */
export type Clock = () => number

/**
 * A CPU allowance that only ticks while the interpreter is actually running.
 *
 * The sandbox pauses it around every host REST call. Without that, a store that takes three
 * seconds to answer would burn the compute budget that exists to stop runaway loops, and a
 * slow shop would look identical to a malicious script.
 */
export class CpuBudget {
	readonly #budgetMs: number
	readonly #now: Clock
	#consumedMs = 0
	#runningSince: number | null
	/**
	 * Pauses nest. The host bridge pauses around a REST call and the settle loop pauses around
	 * every await, and those two overlap; counting depth means the budget only restarts once
	 * every reason to be paused has gone away.
	 */
	#pauseDepth = 0

	constructor(budgetMs: number, now: Clock = Date.now) {
		this.#budgetMs = budgetMs
		this.#now = now
		this.#runningSince = now()
	}

	/** Stop counting: the interpreter is not running. */
	pause(): void {
		this.#pauseDepth += 1
		if (this.#pauseDepth > 1 || this.#runningSince === null) return
		this.#consumedMs += this.#now() - this.#runningSince
		this.#runningSince = null
	}

	/** Resume counting: the interpreter is running again. */
	resume(): void {
		if (this.#pauseDepth === 0) return
		this.#pauseDepth -= 1
		if (this.#pauseDepth > 0 || this.#runningSince !== null) return
		this.#runningSince = this.#now()
	}

	get consumedMs(): number {
		const live = this.#runningSince === null ? 0 : this.#now() - this.#runningSince
		return this.#consumedMs + live
	}

	get exceeded(): boolean {
		return this.consumedMs > this.#budgetMs
	}
}

/**
 * Module-loading constructs refused before the source reaches the interpreter.
 *
 * This is defence in depth rather than the primary control: no module loader is ever installed
 * on the runtime, so `import()` already fails at runtime. Rejecting up front turns a confusing
 * late `ReferenceError` into an explicit, named refusal.
 */
const FORBIDDEN_SOURCE_PATTERNS: readonly { pattern: RegExp; label: string }[] = [
	{ pattern: /\bimport\s*\(/, label: 'dynamic import()' },
	{ pattern: /\bimport\s*\.\s*meta\b/, label: 'import.meta' },
	{ pattern: /\bimport\s+[\w*{'"]/, label: 'import declaration' },
	{
		pattern: /\bexport\s+(?:default|const|let|var|function|class|\{)/,
		label: 'export declaration',
	},
	{ pattern: /\brequire\s*\(/, label: 'require()' },
]

/** Returns the label of the first refused construct, or `null` when the source is acceptable. */
export function findForbiddenSyntax(source: string): string | null {
	for (const { pattern, label } of FORBIDDEN_SOURCE_PATTERNS) {
		if (pattern.test(source)) return label
	}
	return null
}
