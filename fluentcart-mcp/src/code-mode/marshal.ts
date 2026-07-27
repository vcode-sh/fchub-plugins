import type { QuickJSAsyncContext, QuickJSHandle } from 'quickjs-emscripten-core'
import { CODE_MODE_LIMITS, type CodeModeError, codeModeError } from './limits.js'

/**
 * Handles to the context's original `JSON` functions.
 *
 * Captured immediately after the context is created and before any sandboxed statement runs.
 * Sandboxed code can freely reassign `JSON.stringify`, but it cannot reach these handles, so
 * the value the caller receives is always the real serialisation of the real result.
 */
export interface PristineJson {
	namespace: QuickJSHandle
	stringify: QuickJSHandle
	parse: QuickJSHandle
	dispose(): void
}

export function capturePristineJson(context: QuickJSAsyncContext): PristineJson {
	const namespace = context.getProp(context.global, 'JSON')
	const stringify = context.getProp(namespace, 'stringify')
	const parse = context.getProp(namespace, 'parse')
	return {
		namespace,
		stringify,
		parse,
		dispose() {
			parse.dispose()
			stringify.dispose()
			namespace.dispose()
		},
	}
}

/**
 * Turn a JSON string produced by the host into a VM value.
 *
 * Uses the captured `JSON.parse` so a sandbox that replaced the global cannot intercept, alter
 * or observe payloads on their way in.
 */
export function parseIntoVm(
	context: QuickJSAsyncContext,
	pristine: PristineJson,
	json: string,
): QuickJSHandle | null {
	const text = context.newString(json)
	try {
		const result = context.callFunction(pristine.parse, pristine.namespace, text)
		if (result.error) {
			result.error.dispose()
			return null
		}
		return result.value
	} finally {
		text.dispose()
	}
}

export type MarshalOutcome = { ok: true; json: string } | { ok: false; error: CodeModeError }

/**
 * Serialise the sandbox's return value.
 *
 * Deliberately not `context.dump`: that helper falls back to `String(value)` when the value
 * cannot be serialised, so a circular object would come back as the string `"[object Object]"`
 * and be reported as a successful answer. Calling the captured `JSON.stringify` instead makes
 * the failure explicit and gives the exact byte count the budget needs.
 */
export function marshalResult(
	context: QuickJSAsyncContext,
	pristine: PristineJson,
	handle: QuickJSHandle,
	maxCharacters: number = CODE_MODE_LIMITS.maxOutputCharacters,
): MarshalOutcome {
	const vmType = context.typeof(handle)
	const call = context.callFunction(pristine.stringify, pristine.namespace, handle)

	if (call.error) {
		const detail = describeThrown(context, call.error)
		call.error.dispose()
		return {
			ok: false,
			error: codeModeError(
				'NON_JSON_RESULT',
				`The returned value could not be serialised as JSON: ${detail}.`,
			),
		}
	}

	if (context.typeof(call.value) !== 'string') {
		call.value.dispose()
		return {
			ok: false,
			error: codeModeError(
				'NON_JSON_RESULT',
				`Code returned ${vmType === 'undefined' ? 'no value' : `a ${vmType}`}, which has no JSON representation. End the code with \`return\` and a plain object, array or primitive.`,
			),
		}
	}

	const json = context.getString(call.value)
	call.value.dispose()

	if (json.length > maxCharacters) {
		return {
			ok: false,
			error: codeModeError(
				'RESPONSE_TOO_LARGE',
				`The result serialises to ${json.length} characters, over the ${maxCharacters} character budget. Return fewer records or project the fields you actually need before returning.`,
			),
		}
	}

	return { ok: true, json }
}

/** Best-effort human description of any thrown VM value, including non-Error primitives. */
export function describeThrown(context: QuickJSAsyncContext, handle: QuickJSHandle): string {
	let dumped: unknown
	try {
		dumped = context.dump(handle)
	} catch {
		return 'unknown error'
	}

	if (dumped === null) return 'null'
	if (typeof dumped === 'string') return dumped
	if (typeof dumped !== 'object') return String(dumped)

	const record = dumped as Record<string, unknown>
	const message = typeof record.message === 'string' ? record.message : undefined
	const name = typeof record.name === 'string' ? record.name : undefined
	if (message) return name ? `${name}: ${message}` : message

	try {
		return JSON.stringify(dumped) ?? 'unknown error'
	} catch {
		return 'unknown error'
	}
}

/**
 * QuickJS reports every budget breach as an `InternalError`, so the message text is the only
 * signal available to tell a runaway loop from a memory blowout from deep recursion.
 */
const VM_ERROR_CODES: readonly { needle: string; code: 'MEMORY_EXCEEDED' | 'STACK_EXCEEDED' }[] = [
	{ needle: 'out of memory', code: 'MEMORY_EXCEEDED' },
	{ needle: 'stack overflow', code: 'STACK_EXCEEDED' },
]

export interface VmErrorContext {
	/** True when the wall-clock deadline had passed by the time the error surfaced. */
	wallClockExpired: boolean
	/** True when the interpreter CPU allowance was already spent. */
	cpuExpired: boolean
}

const CPU_EXCEEDED = codeModeError(
	'CPU_BUDGET_EXCEEDED',
	`Execution exceeded the ${CODE_MODE_LIMITS.maxCpuMs} ms interpreter CPU limit.`,
)

const WALL_CLOCK_EXCEEDED = codeModeError(
	'WALL_CLOCK_EXCEEDED',
	`Execution exceeded the ${CODE_MODE_LIMITS.maxWallClockMs} ms wall-clock limit.`,
)

/**
 * Map a VM-thrown value to the structured error the caller sees.
 *
 * Budget state outranks the exception text. Interrupting QuickJS mid-expression does not always
 * surface as `InternalError: interrupted`: aborting a `Promise.resolve().then(...)` storm raises
 * a bare `TypeError: not a function` instead, because the interpreter abandons the call halfway.
 * Reporting that verbatim would tell the caller their code has a bug when in fact it ran out of
 * time, so an exhausted budget is named first and the message is treated as a symptom.
 *
 * Memory and stack breaches are checked before that, because those messages are unambiguous and
 * a long allocation loop can plausibly exhaust both the heap and the clock.
 */
export function classifyVmError(
	context: QuickJSAsyncContext,
	handle: QuickJSHandle,
	state: VmErrorContext,
): CodeModeError {
	const description = describeThrown(context, handle)
	const lowered = description.toLowerCase()

	for (const { needle, code } of VM_ERROR_CODES) {
		if (lowered.includes(needle)) {
			return codeModeError(
				code,
				code === 'MEMORY_EXCEEDED'
					? `Sandbox exceeded its ${CODE_MODE_LIMITS.maxHeapBytes / (1024 * 1024)} MiB heap.`
					: 'Sandbox exceeded its call-stack limit. Rewrite unbounded recursion as a loop.',
			)
		}
	}

	if (state.cpuExpired) return CPU_EXCEEDED
	if (state.wallClockExpired) return WALL_CLOCK_EXCEEDED
	if (lowered.includes('interrupted')) return CPU_EXCEEDED

	return codeModeError('UNCAUGHT_EXCEPTION', description)
}
