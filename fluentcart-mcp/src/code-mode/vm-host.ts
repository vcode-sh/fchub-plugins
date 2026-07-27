import type {
	QuickJSAsyncContext,
	QuickJSDeferredPromise,
	QuickJSHandle,
} from 'quickjs-emscripten-core'
import type { HostBridge } from './bridge.js'
import type { CodeModeError } from './limits.js'
import { type PristineJson, parseIntoVm } from './marshal.js'

/** Temporary global that the bootstrap deletes once it has closed over the raw host function. */
const HOST_GLOBAL = '__fcHostCall'

/**
 * Shared liveness flag.
 *
 * A REST call can settle after the sandbox has been torn down. Every VM-touching callback checks
 * this first, because resolving a promise or draining jobs on a freed context is a use-after-free
 * in WebAssembly, not a caught exception.
 */
export interface LiveFlag {
	value: boolean
}

/**
 * The only code that ever runs with a reference to the raw host function.
 *
 * It hides the raw callable, wraps it in a promise-returning method, and installs the result as
 * a non-writable, non-configurable global so sandboxed code cannot swap in its own `fluentcart`
 * and confuse a later reader of the transcript about what was actually called.
 */
const BOOTSTRAP_SOURCE = `(function () {
	'use strict'
	var host = globalThis.${HOST_GLOBAL}
	delete globalThis.${HOST_GLOBAL}
	var resolve = Promise.resolve.bind(Promise)
	var api = Object.freeze({
		call: function call(operation, input) {
			return resolve(host(operation, input))
		},
	})
	Object.defineProperty(globalThis, 'fluentcart', {
		value: api,
		writable: false,
		enumerable: true,
		configurable: false,
	})
})();
undefined`

/**
 * Read a VM value into a host value through the captured `JSON` functions.
 *
 * `context.dump` is unsuitable here: it degrades unserialisable input to a string such as
 * `"[object Object]"`, which would then be handed to Zod as if the caller had really passed
 * that string. Round-tripping through pristine `JSON.stringify` keeps the value honest.
 */
function readVmValue(
	context: QuickJSAsyncContext,
	pristine: PristineJson,
	handle: QuickJSHandle | undefined,
): unknown {
	if (handle === undefined) return undefined

	const call = context.callFunction(pristine.stringify, pristine.namespace, handle)
	if (call.error) {
		call.error.dispose()
		return undefined
	}
	if (context.typeof(call.value) !== 'string') {
		call.value.dispose()
		return undefined
	}
	const json = context.getString(call.value)
	call.value.dispose()
	try {
		return JSON.parse(json) as unknown
	} catch {
		return undefined
	}
}

/** Reject the sandbox-side promise with an Error carrying the machine-readable code. */
function rejectWithError(
	context: QuickJSAsyncContext,
	deferred: QuickJSDeferredPromise,
	error: CodeModeError,
): void {
	const handle = context.newError(error.message)
	const code = context.newString(error.code)
	context.setProp(handle, 'code', code)
	code.dispose()
	deferred.reject(handle)
	handle.dispose()
}

/**
 * Install the one and only host capability, then freeze it.
 *
 * Returns nothing on purpose: after this call there is no host-side object the caller could
 * hand to sandboxed code to widen its reach.
 */
export function installFluentCartBridge(
	context: QuickJSAsyncContext,
	bridge: HostBridge,
	pristine: PristineJson,
	live: LiveFlag,
): void {
	const runtime = context.runtime

	const hostFunction = context.newFunction(HOST_GLOBAL, (operationHandle, inputHandle) => {
		const operation = readVmValue(context, pristine, operationHandle)
		const input = readVmValue(context, pristine, inputHandle)
		const deferred = context.newPromise()

		void bridge.call(operation, input).then((outcome) => {
			if (!live.value) return

			if (!outcome.ok) {
				rejectWithError(context, deferred, outcome.error)
				return
			}

			const value = parseIntoVm(context, pristine, outcome.json)
			if (value === null) {
				rejectWithError(context, deferred, {
					code: 'NON_JSON_RESULT',
					message: 'The operation returned a payload that is not valid JSON.',
				})
				return
			}
			deferred.resolve(value)
			value.dispose()
		})

		// Settling a deferred queues a microtask inside the VM; without draining, sandboxed code
		// awaiting the promise would never resume.
		void deferred.settled.then(() => {
			if (!live.value) return
			const jobs = runtime.executePendingJobs()
			if (jobs.error) jobs.error.dispose()
		})

		return deferred.handle
	})

	context.setProp(context.global, HOST_GLOBAL, hostFunction)
	hostFunction.dispose()

	const bootstrap = context.evalCode(BOOTSTRAP_SOURCE, 'bootstrap.js')
	if (bootstrap.error) {
		const handle = bootstrap.error
		let message: string
		try {
			message = String((context.dump(handle) as { message?: string })?.message ?? 'unknown')
		} finally {
			handle.dispose()
		}
		throw new Error(`Failed to install the code-mode bridge: ${message}`)
	}
	bootstrap.value.dispose()
}
