import { setTimeout as sleep, setImmediate as yieldToHost } from 'node:timers/promises'
import {
	DefaultIntrinsics,
	type QuickJSAsyncContext,
	type QuickJSAsyncWASMModule,
	type QuickJSHandle,
} from 'quickjs-emscripten-core'
import type { ReadOnlyApiIndex } from './api-index.js'
import { HostBridge } from './bridge.js'
import { type CodeModeError, CpuBudget, codeModeError, type ResolvedLimits } from './limits.js'
import {
	capturePristineJson,
	classifyVmError,
	marshalResult,
	type PristineJson,
} from './marshal.js'
import { installFluentCartBridge, type LiveFlag } from './vm-host.js'

export interface ExecutionResult {
	ok: boolean
	/** Complete serialised result. Present only when `ok` is true. */
	json?: string
	error?: CodeModeError
	/** Dispatches the host attempted, counted host-side and never reported by the sandbox. */
	callCount: number
	durationMs: number
}

/** Lets the owning sandbox keep an auditable count of contexts created and destroyed. */
export interface ExecutionHooks {
	onContextCreated: () => void
	onContextDestroyed: () => void
}

interface SettledValue {
	settled: boolean
	handle: QuickJSHandle | null
	rejected: boolean
}

/**
 * Run one snippet in a brand new runtime and context.
 *
 * The context is destroyed on every path out of this function, including timeouts, budget
 * breaches and host errors, so no sandbox state ever survives into the next execution.
 */
export async function runExecution(
	quickjs: QuickJSAsyncWASMModule,
	source: string,
	index: ReadOnlyApiIndex,
	limits: ResolvedLimits,
	startedAt: number,
	hooks: ExecutionHooks,
	signal?: AbortSignal,
): Promise<ExecutionResult> {
	const cpu = new CpuBudget(limits.maxCpuMs)
	const live: LiveFlag = { value: true }
	const deadline = startedAt + limits.maxWallClockMs

	const context = quickjs.newContext({ intrinsics: DefaultIntrinsics })
	hooks.onContextCreated()

	const runtime = context.runtime
	runtime.setMemoryLimit(limits.maxHeapBytes)
	runtime.setMaxStackSize(limits.maxStackBytes)
	runtime.setInterruptHandler(
		() => signal?.aborted === true || cpu.exceeded || Date.now() > deadline,
	)

	const bridge = new HostBridge(index, {
		maxCalls: limits.maxApiCalls,
		signal,
	})
	const pristine = capturePristineJson(context)

	try {
		installFluentCartBridge(context, bridge, pristine, live)
		const result = await evaluate(context, source, {
			bridge,
			pristine,
			cpu,
			limits,
			deadline,
			startedAt,
			signal,
		})
		return signal?.aborted
			? {
					ok: false,
					error: codeModeError('EXECUTION_CANCELLED', 'Execution cancelled by the caller.'),
					callCount: bridge.callCount,
					durationMs: Date.now() - startedAt,
				}
			: result
	} catch (error) {
		return {
			ok: false,
			error: codeModeError(
				'SANDBOX_UNAVAILABLE',
				error instanceof Error ? error.message : String(error),
			),
			callCount: bridge.callCount,
			durationMs: Date.now() - startedAt,
		}
	} finally {
		// Order matters: stop every callback from touching the VM, cut off in-flight REST work,
		// then free. Anything that resolves afterwards finds `live.value === false` and returns.
		live.value = false
		bridge.abort()
		pristine.dispose()
		context.dispose()
		hooks.onContextDestroyed()
	}
}

interface EvaluationScope {
	bridge: HostBridge
	pristine: PristineJson
	cpu: CpuBudget
	limits: ResolvedLimits
	deadline: number
	startedAt: number
	signal?: AbortSignal
}

async function evaluate(
	context: QuickJSAsyncContext,
	source: string,
	scope: EvaluationScope,
): Promise<ExecutionResult> {
	const { bridge, pristine, cpu, limits, deadline, startedAt, signal } = scope

	const finish = (partial: Omit<ExecutionResult, 'callCount' | 'durationMs'>): ExecutionResult => ({
		...partial,
		callCount: bridge.callCount,
		durationMs: Date.now() - startedAt,
	})

	const budgetState = () => ({
		wallClockExpired: Date.now() > deadline,
		cpuExpired: cpu.exceeded,
	})

	// The wrapper gives sandboxed code top-level `await` and `return` without exposing module
	// syntax. Strict mode makes tampering with the frozen bridge throw instead of failing quietly.
	const evaluated = await context.evalCodeAsync(`(async () => {\n${source}\n})()`, 'sandbox.js', {
		type: 'global',
		strict: true,
	})

	if (evaluated.error) {
		const error = classifyVmError(context, evaluated.error, budgetState())
		evaluated.error.dispose()
		return finish({ ok: false, error })
	}

	const settled = await settle(context, evaluated.value, deadline, bridge, cpu, signal)

	if (!settled.settled || settled.handle === null) {
		return finish({
			ok: false,
			error: codeModeError(
				'WALL_CLOCK_EXCEEDED',
				`Execution exceeded the ${limits.maxWallClockMs} ms wall-clock limit before producing a value.`,
			),
		})
	}

	try {
		if (settled.rejected) {
			return finish({ ok: false, error: classifyVmError(context, settled.handle, budgetState()) })
		}

		const marshalled = marshalResult(context, pristine, settled.handle, limits.maxOutputCharacters)
		return marshalled.ok
			? finish({ ok: true, json: marshalled.json })
			: finish({ ok: false, error: marshalled.error })
	} finally {
		settled.handle.dispose()
	}
}

/**
 * Drive the VM's microtask queue until the top-level promise settles or the clock runs out.
 *
 * Draining is the host's job here: QuickJS runs no event loop of its own, so a promise waiting
 * on a REST call only advances when `executePendingJobs` is called after the host resolves it.
 */
async function settle(
	context: QuickJSAsyncContext,
	promise: QuickJSHandle,
	deadline: number,
	bridge: HostBridge,
	cpu: CpuBudget,
	signal?: AbortSignal,
): Promise<SettledValue> {
	const runtime = context.runtime
	const handle = promise

	for (;;) {
		const state = context.getPromiseState(handle)

		if (state.type === 'fulfilled') {
			if (state.notAPromise) return { settled: true, handle, rejected: false }
			handle.dispose()
			return { settled: true, handle: state.value, rejected: false }
		}
		if (state.type === 'rejected') {
			handle.dispose()
			return { settled: true, handle: state.error, rejected: true }
		}

		const jobs = runtime.executePendingJobs()
		if (jobs.error) jobs.error.dispose()

		if (signal?.aborted || Date.now() > deadline) {
			handle.dispose()
			return { settled: false, handle: null, rejected: false }
		}

		// Idling here is host time, not interpreter time, so it must not spend the CPU budget:
		// otherwise a script blocked on a promise nobody resolves would be reported as a runaway
		// loop. Waiting on the store is worth a real pause; a microtask chain is not.
		cpu.pause()
		try {
			await (bridge.inFlight > 0 ? sleep(2) : yieldToHost())
		} finally {
			cpu.resume()
		}
	}
}
