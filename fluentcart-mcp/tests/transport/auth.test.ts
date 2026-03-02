import type { Request, Response } from 'express'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createBearerAuth } from '../../src/transport/auth.js'

function mockReq(headers: Record<string, string> = {}): Request {
	return { headers } as unknown as Request
}

function mockRes(): Response & { statusCode: number; body: unknown } {
	const res = {
		statusCode: 0,
		body: undefined as unknown,
		status(code: number) {
			res.statusCode = code
			return res
		},
		json(data: unknown) {
			res.body = data
			return res
		},
	}
	return res as unknown as Response & { statusCode: number; body: unknown }
}

describe('createBearerAuth', () => {
	const originalKey = process.env.FLUENTCART_MCP_API_KEY

	afterEach(() => {
		if (originalKey !== undefined) {
			process.env.FLUENTCART_MCP_API_KEY = originalKey
		} else {
			// biome-ignore lint/performance/noDelete: delete is required for process.env cleanup
			delete process.env.FLUENTCART_MCP_API_KEY
		}
	})

	describe('when FLUENTCART_MCP_API_KEY is not set', () => {
		beforeEach(() => {
			// biome-ignore lint/performance/noDelete: delete is required for process.env cleanup
			delete process.env.FLUENTCART_MCP_API_KEY
		})

		it('calls next without checking headers', () => {
			const middleware = createBearerAuth()
			const next = vi.fn()
			middleware(mockReq(), mockRes() as unknown as Response, next)
			expect(next).toHaveBeenCalled()
		})
	})

	describe('when FLUENTCART_MCP_API_KEY is set', () => {
		beforeEach(() => {
			process.env.FLUENTCART_MCP_API_KEY = 'test-secret-key'
		})

		it('allows valid Bearer token', () => {
			const middleware = createBearerAuth()
			const next = vi.fn()
			middleware(
				mockReq({ authorization: 'Bearer test-secret-key' }),
				mockRes() as unknown as Response,
				next,
			)
			expect(next).toHaveBeenCalled()
		})

		it('rejects missing Authorization header with 401', () => {
			const middleware = createBearerAuth()
			const next = vi.fn()
			const res = mockRes()
			middleware(mockReq(), res as unknown as Response, next)
			expect(next).not.toHaveBeenCalled()
			expect(res.statusCode).toBe(401)
			expect(res.body).toEqual({ error: 'Missing or invalid Authorization header' })
		})

		it('rejects non-Bearer Authorization header with 401', () => {
			const middleware = createBearerAuth()
			const next = vi.fn()
			const res = mockRes()
			middleware(mockReq({ authorization: 'Basic abc123' }), res as unknown as Response, next)
			expect(next).not.toHaveBeenCalled()
			expect(res.statusCode).toBe(401)
		})

		it('rejects invalid token with 401', () => {
			const middleware = createBearerAuth()
			const next = vi.fn()
			const res = mockRes()
			middleware(mockReq({ authorization: 'Bearer wrong-key' }), res as unknown as Response, next)
			expect(next).not.toHaveBeenCalled()
			expect(res.statusCode).toBe(401)
			expect(res.body).toEqual({ error: 'Invalid API key' })
		})
	})
})
