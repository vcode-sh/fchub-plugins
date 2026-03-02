import { describe, expect, it } from 'vitest'
import { errorFromStatus, FluentCartApiError } from '../../src/api/errors.js'

describe('FluentCartApiError', () => {
	it('sets name to FluentCartApiError', () => {
		const error = new FluentCartApiError('AUTH_FAILED', 'bad creds')
		expect(error.name).toBe('FluentCartApiError')
	})

	it('is an instance of Error', () => {
		const error = new FluentCartApiError('NOT_FOUND', 'gone')
		expect(error).toBeInstanceOf(Error)
	})

	it('stores code, message, status, and detail', () => {
		const error = new FluentCartApiError('VALIDATION_ERROR', 'invalid', 422, { field: 'name' })
		expect(error.code).toBe('VALIDATION_ERROR')
		expect(error.message).toBe('invalid')
		expect(error.status).toBe(422)
		expect(error.detail).toEqual({ field: 'name' })
	})

	it('status and detail are optional', () => {
		const error = new FluentCartApiError('TIMEOUT', 'timed out')
		expect(error.status).toBeUndefined()
		expect(error.detail).toBeUndefined()
	})
})

describe('errorFromStatus', () => {
	it('maps 401 to AUTH_FAILED', () => {
		const error = errorFromStatus(401, 'Invalid credentials')
		expect(error.code).toBe('AUTH_FAILED')
		expect(error.status).toBe(401)
		expect(error.message).toBe('Authentication failed: Invalid credentials')
	})

	it('maps 403 to FORBIDDEN', () => {
		const error = errorFromStatus(403, 'No access')
		expect(error.code).toBe('FORBIDDEN')
		expect(error.status).toBe(403)
		expect(error.message).toBe('Permission denied: No access')
	})

	it('maps 404 to NOT_FOUND', () => {
		const error = errorFromStatus(404, 'Product not found')
		expect(error.code).toBe('NOT_FOUND')
		expect(error.status).toBe(404)
		expect(error.message).toBe('Resource not found: Product not found')
	})

	it('maps 422 to VALIDATION_ERROR', () => {
		const error = errorFromStatus(422, 'Invalid input', { title: 'required' })
		expect(error.code).toBe('VALIDATION_ERROR')
		expect(error.status).toBe(422)
		expect(error.detail).toEqual({ title: 'required' })
	})

	it('maps 429 to RATE_LIMITED', () => {
		const error = errorFromStatus(429, 'Too many requests')
		expect(error.code).toBe('RATE_LIMITED')
		expect(error.status).toBe(429)
	})

	it('maps 500 to SERVER_ERROR', () => {
		const error = errorFromStatus(500, 'Internal error')
		expect(error.code).toBe('SERVER_ERROR')
		expect(error.status).toBe(500)
		expect(error.message).toBe('Server error: Internal error')
	})

	it('maps 502 to SERVER_ERROR', () => {
		const error = errorFromStatus(502, 'Bad gateway')
		expect(error.code).toBe('SERVER_ERROR')
	})

	it('maps 503 to SERVER_ERROR', () => {
		const error = errorFromStatus(503, 'Unavailable')
		expect(error.code).toBe('SERVER_ERROR')
	})

	it('maps unknown status codes to UNKNOWN', () => {
		const error = errorFromStatus(418, "I'm a teapot")
		expect(error.code).toBe('UNKNOWN')
		expect(error.status).toBe(418)
		expect(error.message).toBe("API error 418: I'm a teapot")
	})
})
