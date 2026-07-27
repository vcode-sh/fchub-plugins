import type { ReadOnlyApiIndex } from './api-index.js'
import { CODE_MODE_LIMITS, type CodeModeError, codeModeError } from './limits.js'

export type BridgeOutcome =
	/** `json` is the complete serialised payload of one read, ready to hand to the VM. */
	{ ok: true; json: string } | { ok: false; error: CodeModeError }

export interface HostBridgeOptions {
	/** Overrides the default per-execution call ceiling. Tests use it; production does not. */
	maxCalls?: number
	/** Invoked before a dispatch starts, so the sandbox can pause its CPU budget. */
	onCallStart?: () => void
	/** Invoked once a dispatch settles, aborts or fails. */
	onCallEnd?: () => void
}

/**
 * The single host capability handed to sandboxed code.
 *
 * Everything the sandbox can reach passes through `call`. The bridge holds the read-only index
 * and nothing else — there is no field on this object from which a write executor could be
 * obtained, which is why the "no writes in code mode" rule survives future refactors rather
 * than depending on a policy check somebody remembers to keep.
 */
export class HostBridge {
	readonly #index: ReadOnlyApiIndex
	readonly #maxCalls: number
	readonly #onCallStart: (() => void) | undefined
	readonly #onCallEnd: (() => void) | undefined
	readonly #controller = new AbortController()
	#callCount = 0
	#inFlight = 0

	constructor(index: ReadOnlyApiIndex, options: HostBridgeOptions = {}) {
		this.#index = index
		this.#maxCalls = options.maxCalls ?? CODE_MODE_LIMITS.maxApiCalls
		this.#onCallStart = options.onCallStart
		this.#onCallEnd = options.onCallEnd
	}

	/** Number of dispatches attempted, including refused ones. Counted by the host, not the VM. */
	get callCount(): number {
		return this.#callCount
	}

	/** Dispatches started but not yet settled. */
	get inFlight(): number {
		return this.#inFlight
	}

	get aborted(): boolean {
		return this.#controller.signal.aborted
	}

	/**
	 * Signal for in-flight REST work.
	 *
	 * The REST client does not accept an abort signal today, so aborting cannot cancel the
	 * socket; what it does guarantee is that a terminated sandbox stops waiting immediately and
	 * that no late response is ever handed back to a VM that is being torn down.
	 */
	get signal(): AbortSignal {
		return this.#controller.signal
	}

	/** Called when the sandbox terminates for any reason, including timeout and error paths. */
	abort(reason = 'sandbox terminated'): void {
		if (this.#controller.signal.aborted) return
		this.#controller.abort(new Error(reason))
	}

	/**
	 * Resolve one operation through the read-only registry.
	 *
	 * Zod validation runs here, immediately before dispatch, even though the caller may have
	 * validated already: this is the last point at which a value crosses from the sandbox into
	 * the store, so it is the point that has to be right.
	 */
	async call(operation: unknown, input: unknown): Promise<BridgeOutcome> {
		if (this.aborted) {
			return {
				ok: false,
				error: codeModeError('SANDBOX_TERMINATED', 'Sandbox already terminated.'),
			}
		}

		if (typeof operation !== 'string' || operation.length === 0) {
			return {
				ok: false,
				error: codeModeError('INVALID_INPUT', 'Operation name must be a non-empty string.'),
			}
		}

		this.#callCount += 1
		if (this.#callCount > this.#maxCalls) {
			return {
				ok: false,
				error: codeModeError(
					'CALL_BUDGET_EXCEEDED',
					`Exceeded the limit of ${this.#maxCalls} API calls per execution. Fetch fewer, larger pages instead of looping.`,
				),
			}
		}

		const refusal = this.#refuse(operation)
		if (refusal) return { ok: false, error: refusal }

		const tool = this.#index.get(operation)
		if (!tool) {
			return {
				ok: false,
				error: codeModeError('UNKNOWN_OPERATION', `Unknown operation "${operation}".`),
			}
		}

		const parsed = tool.schema.safeParse(input ?? {})
		if (!parsed.success) {
			return {
				ok: false,
				error: codeModeError(
					'INVALID_INPUT',
					`Input rejected by the schema for "${operation}".`,
					parsed.error.issues.map((issue) => ({
						path: issue.path.join('.'),
						message: issue.message,
					})),
				),
			}
		}

		return this.#dispatch(operation, tool.handler, parsed.data as Record<string, unknown>)
	}

	/** Name-level refusals, kept separate so an excluded write reports as a write, not as unknown. */
	#refuse(operation: string): CodeModeError | null {
		if (this.#index.has(operation)) return null
		if (this.#index.isExcludedWrite(operation)) {
			return codeModeError(
				'WRITE_OPERATION_REFUSED',
				`Operation "${operation}" changes store state. Code mode is read-only and never dispatches writes.`,
			)
		}
		return codeModeError(
			'UNKNOWN_OPERATION',
			`Unknown operation "${operation}". Use fluentcart_search_api to find the right read.`,
		)
	}

	async #dispatch(
		operation: string,
		handler: (input: Record<string, unknown>) => Promise<{
			content: { type: 'text'; text: string }[]
			isError?: boolean
		}>,
		input: Record<string, unknown>,
	): Promise<BridgeOutcome> {
		this.#inFlight += 1
		this.#onCallStart?.()
		try {
			const result = await Promise.race([this.#abortPromise(), handler(input)])

			if (result === null) {
				return {
					ok: false,
					error: codeModeError(
						'SANDBOX_TERMINATED',
						'Sandbox terminated while the call was in flight.',
					),
				}
			}

			const text = result.content.map((part) => part.text).join('')

			if (result.isError) {
				return {
					ok: false,
					error: codeModeError('OPERATION_FAILED', text || `"${operation}" failed.`),
				}
			}

			return { ok: true, json: text.length === 0 ? 'null' : text }
		} catch (error) {
			const message = error instanceof Error ? error.message : String(error)
			return { ok: false, error: codeModeError('OPERATION_FAILED', message) }
		} finally {
			this.#inFlight -= 1
			this.#onCallEnd?.()
		}
	}

	/** Resolves with `null` the moment the sandbox is torn down, so no call outlives its VM. */
	#abortPromise(): Promise<null> {
		return new Promise((resolve) => {
			if (this.#controller.signal.aborted) {
				resolve(null)
				return
			}
			this.#controller.signal.addEventListener('abort', () => resolve(null), { once: true })
		})
	}
}
