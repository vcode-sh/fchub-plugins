import type { Request, Response } from 'express'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { assertSafeHttpExposure, createBearerAuth } from '../../src/transport/auth.js'

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

const STRONG_KEY = 'k'.repeat(32)

describe('assertSafeHttpExposure', () => {
	it('permits loopback addresses with no key at all', () => {
		for (const host of ['127.0.0.1', '::1', 'localhost']) {
			expect(() => assertSafeHttpExposure(host)).not.toThrow()
		}
	})

	it('treats loopback host matching as case-insensitive', () => {
		expect(() => assertSafeHttpExposure('LOCALHOST')).not.toThrow()
	})

	it('permits bracketed IPv6 loopback', () => {
		expect(() => assertSafeHttpExposure('[::1]')).not.toThrow()
	})

	it('refuses wildcard binds without a bearer key', () => {
		for (const host of ['0.0.0.0', '::']) {
			expect(() => assertSafeHttpExposure(host)).toThrow(/FLUENTCART_MCP_API_KEY/)
		}
	})

	it('refuses non-loopback hostnames without a bearer key', () => {
		expect(() => assertSafeHttpExposure('10.0.0.5')).toThrow(/FLUENTCART_MCP_API_KEY/)
		expect(() => assertSafeHttpExposure('mcp.example.com')).toThrow(/FLUENTCART_MCP_API_KEY/)
	})

	it('refuses loopback lookalike hostnames', () => {
		for (const host of ['localhost.example.com', '127.0.0.1.example.com', 'notlocalhost']) {
			expect(() => assertSafeHttpExposure(host)).toThrow(/FLUENTCART_MCP_API_KEY/)
		}
	})

	it('refuses a weak bearer key on a public bind', () => {
		expect(() => assertSafeHttpExposure('0.0.0.0', 'short')).toThrow(/at least 32/)
		expect(() => assertSafeHttpExposure('0.0.0.0', 'k'.repeat(31))).toThrow(/at least 32/)
	})

	it('refuses a whitespace-padded key that is not really 32 characters', () => {
		expect(() => assertSafeHttpExposure('0.0.0.0', `${' '.repeat(40)}`)).toThrow(/at least 32/)
	})

	it('permits a public bind with a strong bearer key', () => {
		expect(() => assertSafeHttpExposure('0.0.0.0', STRONG_KEY)).not.toThrow()
	})

	it('names the offending host so the operator can see what was refused', () => {
		expect(() => assertSafeHttpExposure('0.0.0.0')).toThrow(/0\.0\.0\.0/)
	})
})

describe('createBearerAuth', () => {
	const originalKey = process.env.FLUENTCART_MCP_API_KEY

	afterEach(() => {
		if (originalKey !== undefined) {
			process.env.FLUENTCART_MCP_API_KEY = originalKey
		} else {
			delete process.env.FLUENTCART_MCP_API_KEY
		}
	})

	describe('when FLUENTCART_MCP_API_KEY is not set', () => {
		beforeEach(() => {
			delete process.env.FLUENTCART_MCP_API_KEY
		})

		it('calls next without checking headers, which is safe only on loopback', () => {
			const middleware = createBearerAuth()
			const next = vi.fn()
			middleware(mockReq(), mockRes() as unknown as Response, next)
			expect(next).toHaveBeenCalled()
		})
	})

	describe('when FLUENTCART_MCP_API_KEY is set', () => {
		beforeEach(() => {
			process.env.FLUENTCART_MCP_API_KEY = STRONG_KEY
		})

		it('allows a valid Bearer token', () => {
			const middleware = createBearerAuth()
			const next = vi.fn()
			middleware(
				mockReq({ authorization: `Bearer ${STRONG_KEY}` }),
				mockRes() as unknown as Response,
				next,
			)
			expect(next).toHaveBeenCalled()
		})

		it('rejects a missing Authorization header without revealing why', () => {
			const middleware = createBearerAuth()
			const next = vi.fn()
			const res = mockRes()
			middleware(mockReq(), res as unknown as Response, next)
			expect(next).not.toHaveBeenCalled()
			expect(res.statusCode).toBe(401)
			expect(res.body).toEqual({ error: 'Unauthorized' })
		})

		it('rejects a non-Bearer Authorization header with the same opaque body', () => {
			const middleware = createBearerAuth()
			const next = vi.fn()
			const res = mockRes()
			middleware(mockReq({ authorization: 'Basic abc123' }), res as unknown as Response, next)
			expect(next).not.toHaveBeenCalled()
			expect(res.statusCode).toBe(401)
			expect(res.body).toEqual({ error: 'Unauthorized' })
		})

		it('rejects a wrong token with the same opaque body as a missing one', () => {
			const middleware = createBearerAuth()
			const next = vi.fn()
			const res = mockRes()
			middleware(mockReq({ authorization: 'Bearer wrong-key' }), res as unknown as Response, next)
			expect(next).not.toHaveBeenCalled()
			expect(res.statusCode).toBe(401)
			// Identical to the missing-header body: the client learns nothing about key existence.
			expect(res.body).toEqual({ error: 'Unauthorized' })
		})

		it('rejects a token of the correct length but wrong content', () => {
			const middleware = createBearerAuth()
			const next = vi.fn()
			const res = mockRes()
			middleware(
				mockReq({ authorization: `Bearer ${'x'.repeat(32)}` }),
				res as unknown as Response,
				next,
			)
			expect(next).not.toHaveBeenCalled()
			expect(res.statusCode).toBe(401)
		})

		it('never echoes the configured key in the response body', () => {
			const middleware = createBearerAuth()
			const res = mockRes()
			middleware(mockReq({ authorization: 'Bearer nope' }), res as unknown as Response, vi.fn())
			expect(JSON.stringify(res.body)).not.toContain(STRONG_KEY)
		})
	})
})
