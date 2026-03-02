import { readFileSync } from 'node:fs'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { resolveConfig } from '../../src/config/resolver.js'
import { resolveApiUrls } from '../../src/config/types.js'

vi.mock('node:fs', () => ({
	readFileSync: vi.fn(),
}))

const mockedReadFileSync = vi.mocked(readFileSync)

describe('resolveConfig', () => {
	const envBackup: Record<string, string | undefined> = {}

	beforeEach(() => {
		for (const key of [
			'FLUENTCART_URL',
			'FLUENTCART_USERNAME',
			'FLUENTCART_APP_PASSWORD',
			'FLUENTCART_TIMEOUT',
		]) {
			envBackup[key] = process.env[key]
			delete process.env[key]
		}
		mockedReadFileSync.mockReset()
	})

	afterEach(() => {
		for (const [key, value] of Object.entries(envBackup)) {
			if (value === undefined) {
				delete process.env[key]
			} else {
				process.env[key] = value
			}
		}
	})

	it('returns config from env vars when all three are set', () => {
		process.env.FLUENTCART_URL = 'https://shop.test'
		process.env.FLUENTCART_USERNAME = 'admin'
		process.env.FLUENTCART_APP_PASSWORD = 'secret-pass'

		const config = resolveConfig()

		expect(config).toEqual({
			url: 'https://shop.test',
			username: 'admin',
			appPassword: 'secret-pass',
			timeout: undefined,
		})
	})

	it('parses FLUENTCART_TIMEOUT as integer', () => {
		process.env.FLUENTCART_URL = 'https://shop.test'
		process.env.FLUENTCART_USERNAME = 'admin'
		process.env.FLUENTCART_APP_PASSWORD = 'secret-pass'
		process.env.FLUENTCART_TIMEOUT = '5000'

		const config = resolveConfig()

		expect(config.timeout).toBe(5000)
	})

	it('falls back to file config when env vars are missing', () => {
		mockedReadFileSync.mockReturnValue(
			JSON.stringify({
				url: 'https://file-shop.test',
				username: 'file-admin',
				appPassword: 'file-pass',
				timeout: 3000,
			}),
		)

		const config = resolveConfig()

		expect(config).toEqual({
			url: 'https://file-shop.test',
			username: 'file-admin',
			appPassword: 'file-pass',
			timeout: 3000,
		})
	})

	it('throws descriptive error when neither env vars nor file exist', () => {
		mockedReadFileSync.mockImplementation(() => {
			throw new Error('ENOENT: no such file')
		})

		expect(() => resolveConfig()).toThrow('FluentCart MCP server is not configured')
	})

	it('handles invalid JSON in config file gracefully', () => {
		mockedReadFileSync.mockReturnValue('not valid json {{{')

		expect(() => resolveConfig()).toThrow('FluentCart MCP server is not configured')
	})

	it('env vars take priority over file config', () => {
		process.env.FLUENTCART_URL = 'https://env-shop.test'
		process.env.FLUENTCART_USERNAME = 'env-admin'
		process.env.FLUENTCART_APP_PASSWORD = 'env-pass'

		mockedReadFileSync.mockReturnValue(
			JSON.stringify({
				url: 'https://file-shop.test',
				username: 'file-admin',
				appPassword: 'file-pass',
			}),
		)

		const config = resolveConfig()

		expect(config.url).toBe('https://env-shop.test')
		expect(config.username).toBe('env-admin')
		expect(config.appPassword).toBe('env-pass')
		expect(mockedReadFileSync).not.toHaveBeenCalled()
	})
})

describe('resolveApiUrls', () => {
	it('adds adminBase with /wp-json/fluent-cart/v2', () => {
		const resolved = resolveApiUrls({
			url: 'https://shop.test',
			username: 'admin',
			appPassword: 'pass',
		})

		expect(resolved.adminBase).toBe('https://shop.test/wp-json/fluent-cart/v2')
	})

	it('adds publicBase with /wp-json/fluent-cart-public/v2', () => {
		const resolved = resolveApiUrls({
			url: 'https://shop.test',
			username: 'admin',
			appPassword: 'pass',
		})

		expect(resolved.publicBase).toBe('https://shop.test/wp-json/fluent-cart-public/v2')
	})

	it('strips trailing slashes from input URL', () => {
		const resolved = resolveApiUrls({
			url: 'https://shop.test///',
			username: 'admin',
			appPassword: 'pass',
		})

		expect(resolved.adminBase).toBe('https://shop.test/wp-json/fluent-cart/v2')
		expect(resolved.publicBase).toBe('https://shop.test/wp-json/fluent-cart-public/v2')
	})
})
