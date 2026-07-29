import { redactSensitive } from '../security/redaction.js'

export interface ServiceHandle {
	close(): Promise<void>
}

type ShutdownSignal = 'SIGINT' | 'SIGTERM'
type SignalListener = () => void

interface SignalSource {
	on(signal: ShutdownSignal, listener: SignalListener): unknown
	off(signal: ShutdownSignal, listener: SignalListener): unknown
}

export interface SignalShutdownOptions {
	drainMs?: number
	/** Test seam; production always defaults to the current process. */
	signalSource?: SignalSource
}

function safeErrorMessage(error: unknown): string {
	const redacted = redactSensitive(error instanceof Error ? error : new Error(String(error)))
	if (
		typeof redacted === 'object' &&
		redacted !== null &&
		'message' in redacted &&
		typeof redacted.message === 'string'
	) {
		return redacted.message
	}
	return typeof redacted === 'string' ? redacted : (JSON.stringify(redacted) ?? 'Unknown error')
}

/** Report transport errors out of band; stdout belongs exclusively to MCP frames. */
export function reportOperationalError(error: unknown): void {
	console.error(`fluentcart-mcp transport error: ${safeErrorMessage(error)}`)
}

async function closeWithinDrain(handle: ServiceHandle, drainMs: number | undefined): Promise<void> {
	if (drainMs === undefined) {
		await handle.close()
		return
	}
	if (!Number.isSafeInteger(drainMs) || drainMs < 0) {
		throw new RangeError('drainMs must be a non-negative whole number')
	}

	await new Promise<void>((resolve, reject) => {
		const timer = setTimeout(
			() => reject(new Error(`transport close exceeded its ${drainMs}ms drain budget`)),
			drainMs,
		)
		handle
			.close()
			.then(resolve, reject)
			.finally(() => clearTimeout(timer))
	})
}

/**
 * Close one owned service handle on the first termination signal.
 *
 * The listeners are removed before closing starts, and the in-flight promise is retained, so
 * repeated or crossed signals cannot close the same transport twice.
 */
export function installSignalShutdown(
	handle: ServiceHandle,
	options: SignalShutdownOptions = {},
): () => void {
	const signalSource = options.signalSource ?? process
	let closing: Promise<void> | undefined

	const uninstall = () => {
		signalSource.off('SIGINT', shutdown)
		signalSource.off('SIGTERM', shutdown)
	}
	const shutdown = () => {
		if (closing) return
		uninstall()
		closing = closeWithinDrain(handle, options.drainMs).catch((error) => {
			console.error(`fluentcart-mcp transport shutdown failed: ${safeErrorMessage(error)}`)
		})
	}

	signalSource.on('SIGINT', shutdown)
	signalSource.on('SIGTERM', shutdown)
	return uninstall
}
