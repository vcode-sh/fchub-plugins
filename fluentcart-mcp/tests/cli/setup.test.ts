import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { FluentCartApiError } from '../../src/api/errors.js'

vi.mock('@clack/prompts', () => ({
	intro: vi.fn(),
	outro: vi.fn(),
	cancel: vi.fn(),
	text: vi.fn(),
	password: vi.fn(),
	confirm: vi.fn(),
	isCancel: vi.fn(() => false),
	spinner: vi.fn(() => ({
		start: vi.fn(),
		stop: vi.fn(),
	})),
	log: {
		error: vi.fn(),
		success: vi.fn(),
	},
}))

vi.mock('../../src/api/test-connection.js', () => ({
	testConnection: vi.fn(),
}))

vi.mock('../../src/config/types.js', () => ({
	getConfigPath: vi.fn(() => '/tmp/fluentcart-mcp-test/config.json'),
}))

vi.mock('node:fs', async () => {
	const actual = await vi.importActual<typeof import('node:fs')>('node:fs')
	return {
		...actual,
		existsSync: vi.fn(() => true),
		mkdirSync: vi.fn(),
		writeFileSync: vi.fn(),
	}
})

import { writeFileSync } from 'node:fs'
import * as p from '@clack/prompts'
import { testConnection } from '../../src/api/test-connection.js'
import { runSetup } from '../../src/cli/setup.js'

const mockText = vi.mocked(p.text)
const mockPassword = vi.mocked(p.password)
const mockConfirm = vi.mocked(p.confirm)
const mockIsCancel = vi.mocked(p.isCancel)
const mockTestConnection = vi.mocked(testConnection)
const mockWriteFileSync = vi.mocked(writeFileSync)

beforeEach(() => {
	vi.clearAllMocks()
})

afterEach(() => {
	vi.restoreAllMocks()
})

describe('runSetup', () => {
	it('completes happy path: prompts, tests connection, writes config', async () => {
		mockText.mockResolvedValueOnce('https://shop.test')
		mockText.mockResolvedValueOnce('admin')
		mockPassword.mockResolvedValueOnce('app-pass-1234')
		mockTestConnection.mockResolvedValue({ ok: true, storeName: 'Test Shop' })

		await runSetup()

		expect(p.intro).toHaveBeenCalledWith('fluentcart-mcp setup')
		expect(mockTestConnection).toHaveBeenCalledWith('https://shop.test', 'admin', 'app-pass-1234')
		expect(mockWriteFileSync).toHaveBeenCalledWith(
			'/tmp/fluentcart-mcp-test/config.json',
			expect.stringContaining('"url": "https://shop.test"'),
			'utf-8',
		)
		expect(p.outro).toHaveBeenCalled()
	})

	it('strips trailing slashes from URL before saving', async () => {
		mockText.mockResolvedValueOnce('https://shop.test///')
		mockText.mockResolvedValueOnce('admin')
		mockPassword.mockResolvedValueOnce('pass')
		mockTestConnection.mockResolvedValue({ ok: true, storeName: 'Shop' })

		await runSetup()

		const writtenConfig = JSON.parse(mockWriteFileSync.mock.calls[0]![1] as string)
		expect(writtenConfig.url).toBe('https://shop.test')
	})

	it('exits when URL prompt is cancelled', async () => {
		mockText.mockResolvedValueOnce(Symbol('cancel') as unknown as string)
		mockIsCancel.mockReturnValueOnce(true)

		await expect(runSetup()).rejects.toThrow(/process\.exit/)
		expect(p.cancel).toHaveBeenCalledWith('Setup cancelled.')
	})

	it('exits when username prompt is cancelled', async () => {
		mockText.mockResolvedValueOnce('https://shop.test')
		mockIsCancel.mockReturnValueOnce(false) // url not cancelled
		mockText.mockResolvedValueOnce(Symbol('cancel') as unknown as string)
		mockIsCancel.mockReturnValueOnce(true) // username cancelled

		await expect(runSetup()).rejects.toThrow(/process\.exit/)
		expect(p.cancel).toHaveBeenCalledWith('Setup cancelled.')
	})

	it('exits when password prompt is cancelled', async () => {
		mockText.mockResolvedValueOnce('https://shop.test')
		mockIsCancel.mockReturnValueOnce(false)
		mockText.mockResolvedValueOnce('admin')
		mockIsCancel.mockReturnValueOnce(false)
		mockPassword.mockResolvedValueOnce(Symbol('cancel') as unknown as string)
		mockIsCancel.mockReturnValueOnce(true)

		await expect(runSetup()).rejects.toThrow(/process\.exit/)
		expect(p.cancel).toHaveBeenCalledWith('Setup cancelled.')
	})

	it('shows error and exits when connection fails and user declines retry', async () => {
		mockText.mockResolvedValueOnce('https://shop.test')
		mockText.mockResolvedValueOnce('admin')
		mockPassword.mockResolvedValueOnce('wrong-pass')
		mockTestConnection.mockResolvedValue({
			ok: false,
			error: new FluentCartApiError('AUTH_FAILED', 'Invalid credentials', 401),
		})
		mockConfirm.mockResolvedValueOnce(false)

		await expect(runSetup()).rejects.toThrow(/process\.exit/)
		expect(p.log.error).toHaveBeenCalledWith('AUTH_FAILED: Invalid credentials')
		expect(mockWriteFileSync).not.toHaveBeenCalled()
	})

	it('retries when connection fails and user confirms retry', async () => {
		// First attempt — fails
		mockText.mockResolvedValueOnce('https://shop.test')
		mockText.mockResolvedValueOnce('admin')
		mockPassword.mockResolvedValueOnce('wrong')
		mockTestConnection.mockResolvedValueOnce({
			ok: false,
			error: new FluentCartApiError('AUTH_FAILED', 'Bad creds', 401),
		})
		mockConfirm.mockResolvedValueOnce(true)

		// Second attempt — succeeds
		mockText.mockResolvedValueOnce('https://shop.test')
		mockText.mockResolvedValueOnce('admin')
		mockPassword.mockResolvedValueOnce('correct')
		mockTestConnection.mockResolvedValueOnce({ ok: true, storeName: 'Shop' })

		await runSetup()

		expect(mockTestConnection).toHaveBeenCalledTimes(2)
		expect(mockWriteFileSync).toHaveBeenCalled()
	})
})
