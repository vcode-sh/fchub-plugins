import RELEASE_ASYNC from '@jitl/quickjs-wasmfile-release-asyncify'
import {
	newQuickJSAsyncWASMModuleFromVariant,
	type QuickJSAsyncWASMModule,
} from 'quickjs-emscripten-core'
import type { ReadOnlyApiIndex } from './api-index.js'
import { type ExecutionResult, runExecution } from './execution.js'
import {
	CODE_MODE_LIMITS,
	type CodeModeError,
	codeModeError,
	findForbiddenSyntax,
	type LimitOverrides,
	type ResolvedLimits,
} from './limits.js'

export type { ExecutionResult } from './execution.js'

/** Per-sandbox limit overrides. Production uses the defaults. */
export type SandboxLimits = LimitOverrides

export interface SandboxOptions {
	limits?: SandboxLimits
	/** Injectable module factory. Tests use it to simulate a WebAssembly that will not start. */
	loadModule?: () => Promise<QuickJSAsyncWASMModule>
}

/**
 * The asyncify variant is mandatory: the only host capability is an asynchronous REST call.
 *
 * The variant is wrapped in a resolved promise because the package ships CommonJS-flavoured
 * declarations, so TypeScript sees the module namespace where Node hands over the default
 * export. `newQuickJSAsyncWASMModuleFromVariant` unwraps either shape, but only accepts the
 * `{ default }` form behind a promise.
 */
async function loadDefaultModule(): Promise<QuickJSAsyncWASMModule> {
	return newQuickJSAsyncWASMModuleFromVariant(Promise.resolve(RELEASE_ASYNC))
}

/**
 * A read-only JavaScript sandbox backed by QuickJS compiled to WebAssembly.
 *
 * One runtime and one context are created per execution and destroyed on every path out,
 * including timeouts and crashes. Nothing survives between executions, so one caller's globals,
 * prototype edits or leftover promises cannot be observed by the next.
 */
export class CodeSandbox {
	readonly #index: ReadOnlyApiIndex
	readonly #limits: ResolvedLimits
	readonly #loadModule: () => Promise<QuickJSAsyncWASMModule>
	#modulePromise: Promise<QuickJSAsyncWASMModule> | null = null
	#queue: Promise<unknown> = Promise.resolve()
	#contextsCreated = 0
	#contextsDestroyed = 0

	constructor(index: ReadOnlyApiIndex, options: SandboxOptions = {}) {
		this.#index = index
		this.#limits = { ...CODE_MODE_LIMITS, ...options.limits }
		this.#loadModule = options.loadModule ?? loadDefaultModule
	}

	/** Contexts created and destroyed. The two must be equal whenever no execution is running. */
	get stats(): { contextsCreated: number; contextsDestroyed: number } {
		return { contextsCreated: this.#contextsCreated, contextsDestroyed: this.#contextsDestroyed }
	}

	/**
	 * Prove the WebAssembly module starts and evaluates before code mode is advertised.
	 *
	 * A server that registers `fluentcart_execute_code` and then fails on every call is worse
	 * than one that never offered it, so the caller uses this to decide whether to register.
	 */
	async selfTest(): Promise<{ ok: boolean; reason?: string }> {
		try {
			const result = await this.execute('return { ready: 1 + 1 === 2 }')
			if (!result.ok) return { ok: false, reason: result.error?.message ?? 'unknown failure' }
			if (result.json !== '{"ready":true}') {
				return { ok: false, reason: `unexpected self-test result ${result.json}` }
			}
			return { ok: true }
		} catch (error) {
			return { ok: false, reason: error instanceof Error ? error.message : String(error) }
		}
	}

	/**
	 * Run one snippet.
	 *
	 * Executions are serialised. Emscripten's asyncify transform allows only one suspended call
	 * per WebAssembly module, so overlapping executions on a shared module are a documented
	 * error rather than a slow path.
	 */
	async execute(source: string): Promise<ExecutionResult> {
		const run = this.#queue.then(
			() => this.#executeOnce(source),
			() => this.#executeOnce(source),
		)
		this.#queue = run.catch(() => undefined)
		return run
	}

	async #module(): Promise<QuickJSAsyncWASMModule> {
		if (!this.#modulePromise) {
			this.#modulePromise = this.#loadModule().catch((error: unknown) => {
				// Never cache a failed start: a transient load error would otherwise disable code
				// mode for the lifetime of the process.
				this.#modulePromise = null
				throw error
			})
		}
		return this.#modulePromise
	}

	async #executeOnce(source: string): Promise<ExecutionResult> {
		const startedAt = Date.now()
		const fail = (error: CodeModeError): ExecutionResult => ({
			ok: false,
			error,
			callCount: 0,
			durationMs: Date.now() - startedAt,
		})

		const rejection = this.#guardSource(source)
		if (rejection) return fail(rejection)

		let quickjs: QuickJSAsyncWASMModule
		try {
			quickjs = await this.#module()
		} catch (error) {
			return fail(
				codeModeError(
					'SANDBOX_UNAVAILABLE',
					`The sandbox runtime failed to start: ${error instanceof Error ? error.message : String(error)}`,
				),
			)
		}

		return runExecution(quickjs, source, this.#index, this.#limits, startedAt, {
			onContextCreated: () => {
				this.#contextsCreated += 1
			},
			onContextDestroyed: () => {
				this.#contextsDestroyed += 1
			},
		})
	}

	/** Cheap static refusals, applied before a context is ever created. */
	#guardSource(source: string): CodeModeError | null {
		if (source.length > this.#limits.maxSourceCharacters) {
			return codeModeError(
				'SOURCE_TOO_LARGE',
				`Source is ${source.length} characters, over the ${this.#limits.maxSourceCharacters} character limit.`,
			)
		}

		const forbidden = findForbiddenSyntax(source)
		if (forbidden) {
			return codeModeError(
				'FORBIDDEN_SYNTAX',
				`${forbidden} is not available in the sandbox. There is no module system, filesystem or network beyond fluentcart.call.`,
			)
		}

		return null
	}
}
