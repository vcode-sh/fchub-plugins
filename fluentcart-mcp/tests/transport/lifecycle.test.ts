import { EventEmitter } from 'node:events'
import { describe, expect, it, vi } from 'vitest'
import { installSignalShutdown } from '../../src/transport/lifecycle.js'

describe('stdio lifecycle', () => {
	it.each(['SIGINT', 'SIGTERM'] as const)(
		'closes once on %s and ignores every later shutdown signal',
		async (signal) => {
			const signals = new EventEmitter()
			const close = vi.fn(async () => undefined)
			installSignalShutdown({ close }, { signalSource: signals })

			signals.emit(signal)
			signals.emit(signal)
			signals.emit(signal === 'SIGINT' ? 'SIGTERM' : 'SIGINT')

			await vi.waitFor(() => expect(close).toHaveBeenCalledTimes(1))
		},
	)

	it('returns an uninstaller that removes both signal listeners without closing', () => {
		const signals = new EventEmitter()
		const close = vi.fn(async () => undefined)
		const uninstall = installSignalShutdown({ close }, { signalSource: signals })

		uninstall()
		signals.emit('SIGINT')
		signals.emit('SIGTERM')

		expect(close).not.toHaveBeenCalled()
		expect(signals.listenerCount('SIGINT')).toBe(0)
		expect(signals.listenerCount('SIGTERM')).toBe(0)
	})

	it('reports one redacted stderr failure when close rejects', async () => {
		const signals = new EventEmitter()
		const credential = 'Bearer lifecycle-secret-value'
		const stderr = vi.spyOn(console, 'error').mockImplementation(() => undefined)
		installSignalShutdown(
			{ close: vi.fn(async () => Promise.reject(new Error(`close failed with ${credential}`))) },
			{ signalSource: signals },
		)

		signals.emit('SIGTERM')
		signals.emit('SIGINT')

		try {
			await vi.waitFor(() => expect(stderr).toHaveBeenCalledTimes(1))
			const output = stderr.mock.calls.flat().join(' ')
			expect(output).toContain('fluentcart-mcp transport shutdown failed')
			expect(output).toContain('[REDACTED]')
			expect(output).not.toContain(credential)
		} finally {
			stderr.mockRestore()
		}
	})
})
