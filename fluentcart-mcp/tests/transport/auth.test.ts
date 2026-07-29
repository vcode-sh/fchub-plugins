import type { Request, Response } from 'express'
import { describe, expect, it, vi } from 'vitest'
import {
	createBearerAuth,
	getTransportPrincipal,
	type TransportPrincipal,
} from '../../src/transport/auth.js'
import type { HttpExposureConfig } from '../../src/transport/http-config.js'

const KEY = 'transport-test-key-0123456789abcdef'
const STATIC_ID = 'sha256:af95c04b661aa4ed4c0293a19679ea24a41179200459de334d3cbe797e617c8e'

function request(authorization?: string): Request {
	return {
		headers: authorization === undefined ? {} : { authorization },
	} as unknown as Request
}

function response(): Response & {
	statusCode: number
	body: unknown
	headers: Record<string, string>
} {
	const target = {
		statusCode: 0,
		body: undefined as unknown,
		headers: {} as Record<string, string>,
		status(code: number) {
			target.statusCode = code
			return target
		},
		set(name: string, value: string) {
			target.headers[name.toLowerCase()] = value
			return target
		},
		json(body: unknown) {
			target.body = body
			return target
		},
	}
	return target as unknown as Response & {
		statusCode: number
		body: unknown
		headers: Record<string, string>
	}
}

const local: HttpExposureConfig = {
	profile: 'local',
	host: '127.0.0.1',
	allowedHosts: ['127.0.0.1'],
	allowedOrigins: ['127.0.0.1'],
}
const privateProfile: HttpExposureConfig = {
	profile: 'private',
	host: '0.0.0.0',
	allowedHosts: ['mcp.example'],
	allowedOrigins: ['console.example'],
	bearerKey: KEY,
}

function run(
	config: HttpExposureConfig,
	authorization?: string,
): {
	req: Request
	res: ReturnType<typeof response>
	next: ReturnType<typeof vi.fn>
	principal?: TransportPrincipal
} {
	const req = request(authorization)
	const res = response()
	const next = vi.fn()
	createBearerAuth(config)(req, res, next)
	return { req, res, next, principal: getTransportPrincipal(req) }
}

describe('HTTP bearer authentication', () => {
	it('assigns a non-secret loopback principal when local authentication is disabled', () => {
		const result = run(local)
		expect(result.next).toHaveBeenCalledOnce()
		expect(result.principal).toEqual({ kind: 'anonymous-loopback', id: 'loopback' })
		expect(JSON.stringify(result.principal)).not.toContain(KEY)
	})

	it('accepts the configured key, removes the header, and stores only its digest principal', () => {
		const result = run(privateProfile, `Bearer ${KEY}`)
		expect(result.next).toHaveBeenCalledOnce()
		expect(result.req.headers.authorization).toBeUndefined()
		expect(result.principal).toEqual({ kind: 'static', id: STATIC_ID })
		expect(JSON.stringify(result.principal)).not.toContain(KEY)
		expect('auth' in result.req).toBe(false)
	})

	it('gives every missing, malformed, wrong-length and wrong bearer the same challenge', () => {
		const attempts = [
			undefined,
			KEY,
			`Basic ${KEY}`,
			'Bearer ',
			'Bearer short',
			`Bearer ${'x'.repeat(KEY.length)}`,
		]
		const outcomes = attempts.map((header) => run(privateProfile, header))

		for (const { next, res } of outcomes) {
			expect(next).not.toHaveBeenCalled()
			expect(res.statusCode).toBe(401)
			expect(res.headers['www-authenticate']).toBe('Bearer')
			expect(res.body).toEqual({ error: 'Unauthorized' })
			expect(JSON.stringify(res.body)).not.toContain(KEY)
		}
		expect(new Set(outcomes.map(({ res }) => JSON.stringify(res.body))).size).toBe(1)
	})
})
