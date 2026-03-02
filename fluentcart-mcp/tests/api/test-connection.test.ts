import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { FluentCartApiError } from '../../src/api/errors.js'
import { testConnection } from '../../src/api/test-connection.js'

const fetchMock = vi.fn()

beforeEach(() => {
	vi.stubGlobal('fetch', fetchMock)
})

afterEach(() => {
	vi.restoreAllMocks()
})

function jsonResponse(body: unknown, status = 200): Response {
	return {
		ok: status >= 200 && status < 300,
		status,
		statusText: status === 200 ? 'OK' : 'Error',
		json: () => Promise.resolve(body),
	} as unknown as Response
}

describe('testConnection', () => {
	it('returns ok with storeName on successful response', async () => {
		fetchMock.mockResolvedValue(jsonResponse({ store_name: 'My Shop' }))

		const result = await testConnection('https://example.com', 'admin', 'secret')

		expect(result).toEqual({ ok: true, storeName: 'My Shop' })
		expect(fetchMock).toHaveBeenCalledWith(
			'https://example.com/wp-json/fluent-cart/v2/app/init',
			expect.objectContaining({
				headers: expect.objectContaining({
					Authorization: expect.stringMatching(/^Basic /),
				}),
			}),
		)
	})

	it('falls back to site_title when store_name is missing', async () => {
		fetchMock.mockResolvedValue(jsonResponse({ site_title: 'Fallback Title' }))

		const result = await testConnection('https://example.com', 'admin', 'secret')

		expect(result).toEqual({ ok: true, storeName: 'Fallback Title' })
	})

	it('falls back to Unknown Store when no name fields are present', async () => {
		fetchMock.mockResolvedValue(jsonResponse({}))

		const result = await testConnection('https://example.com', 'admin', 'secret')

		expect(result).toEqual({ ok: true, storeName: 'Unknown Store' })
	})

	it('strips trailing slashes from URL', async () => {
		fetchMock.mockResolvedValue(jsonResponse({ store_name: 'Test' }))

		await testConnection('https://example.com///', 'admin', 'secret')

		expect(fetchMock).toHaveBeenCalledWith(
			'https://example.com/wp-json/fluent-cart/v2/app/init',
			expect.anything(),
		)
	})

	it('returns AUTH_FAILED on 401', async () => {
		fetchMock.mockResolvedValue(jsonResponse({ message: 'Invalid credentials' }, 401))

		const result = await testConnection('https://example.com', 'admin', 'wrong')

		expect(result.ok).toBe(false)
		if (!result.ok) {
			expect(result.error).toBeInstanceOf(FluentCartApiError)
			expect(result.error.code).toBe('AUTH_FAILED')
			expect(result.error.status).toBe(401)
			expect(result.error.message).toBe('Invalid credentials')
		}
	})

	it('returns FORBIDDEN on 403', async () => {
		fetchMock.mockResolvedValue(jsonResponse({ message: 'Insufficient permissions' }, 403))

		const result = await testConnection('https://example.com', 'admin', 'secret')

		expect(result.ok).toBe(false)
		if (!result.ok) {
			expect(result.error.code).toBe('FORBIDDEN')
			expect(result.error.status).toBe(403)
		}
	})

	it('returns NOT_FOUND with helpful message on 404', async () => {
		fetchMock.mockResolvedValue(jsonResponse({ message: 'Not found' }, 404))

		const result = await testConnection('https://example.com', 'admin', 'secret')

		expect(result.ok).toBe(false)
		if (!result.ok) {
			expect(result.error.code).toBe('NOT_FOUND')
			expect(result.error.status).toBe(404)
			expect(result.error.message).toContain('FluentCart REST API not found')
		}
	})

	it('returns SERVER_ERROR on 500', async () => {
		fetchMock.mockResolvedValue(jsonResponse({ message: 'Internal server error' }, 500))

		const result = await testConnection('https://example.com', 'admin', 'secret')

		expect(result.ok).toBe(false)
		if (!result.ok) {
			expect(result.error.code).toBe('SERVER_ERROR')
			expect(result.error.status).toBe(500)
		}
	})

	it('falls back to statusText when response body has no message', async () => {
		const response = {
			ok: false,
			status: 401,
			statusText: 'Unauthorized',
			json: () => Promise.reject(new Error('not json')),
		} as unknown as Response
		fetchMock.mockResolvedValue(response)

		const result = await testConnection('https://example.com', 'admin', 'secret')

		expect(result.ok).toBe(false)
		if (!result.ok) {
			expect(result.error.message).toBe('Unauthorized')
		}
	})

	it('returns TIMEOUT on AbortError', async () => {
		const abortError = new DOMException('The operation was aborted', 'AbortError')
		fetchMock.mockRejectedValue(abortError)

		const result = await testConnection('https://example.com', 'admin', 'secret')

		expect(result.ok).toBe(false)
		if (!result.ok) {
			expect(result.error.code).toBe('TIMEOUT')
			expect(result.error.message).toContain('timed out')
		}
	})

	it('returns CONNECTION_ERROR on network failure', async () => {
		fetchMock.mockRejectedValue(new Error('getaddrinfo ENOTFOUND example.com'))

		const result = await testConnection('https://example.com', 'admin', 'secret')

		expect(result.ok).toBe(false)
		if (!result.ok) {
			expect(result.error.code).toBe('CONNECTION_ERROR')
			expect(result.error.message).toContain('ENOTFOUND')
		}
	})

	it('returns CONNECTION_ERROR with stringified non-Error throws', async () => {
		fetchMock.mockRejectedValue('something broke')

		const result = await testConnection('https://example.com', 'admin', 'secret')

		expect(result.ok).toBe(false)
		if (!result.ok) {
			expect(result.error.code).toBe('CONNECTION_ERROR')
			expect(result.error.message).toBe('something broke')
		}
	})
})
