import { createHash, timingSafeEqual } from 'node:crypto'
import type { NextFunction, Request, RequestHandler, Response } from 'express'
import type { HttpExposureConfig } from './http-config.js'

export interface TransportPrincipal {
	kind: 'anonymous-loopback' | 'static'
	id: string
}

const principals = new WeakMap<Request, TransportPrincipal>()
const ANONYMOUS_LOOPBACK = Object.freeze({
	kind: 'anonymous-loopback',
	id: 'loopback',
} satisfies TransportPrincipal)

export function getTransportPrincipal(request: Request): TransportPrincipal | undefined {
	return principals.get(request)
}

function reject(res: Response): void {
	res.set('WWW-Authenticate', 'Bearer')
	res.status(401).json({ error: 'Unauthorized' })
}

/**
 * Authenticate the resolved profile without creating SDK AuthInfo.
 *
 * The successful header is removed before the Node adapter creates its web Request, so neither
 * the raw key nor an impersonation-capable AuthInfo reaches the MCP factory context.
 */
export function createBearerAuth(config: HttpExposureConfig): RequestHandler {
	if (!config.bearerKey) {
		return (req, _res, next) => {
			principals.set(req, ANONYMOUS_LOOPBACK)
			next()
		}
	}

	const key = config.bearerKey
	const expected = Buffer.from(key, 'utf8')
	const principal = Object.freeze({
		kind: 'static',
		id: `sha256:${createHash('sha256').update(key).digest('hex')}`,
	} satisfies TransportPrincipal)

	return (req: Request, res: Response, next: NextFunction) => {
		const header = req.headers.authorization
		const hasBearerScheme = header?.startsWith('Bearer ') === true
		const supplied = Buffer.from(hasBearerScheme ? header.slice(7) : '', 'utf8')
		const comparable = Buffer.alloc(expected.length)
		supplied.copy(comparable, 0, 0, expected.length)
		const matches =
			timingSafeEqual(comparable, expected) &&
			supplied.length === expected.length &&
			hasBearerScheme

		if (!matches) {
			reject(res)
			return
		}

		req.headers.authorization = undefined
		principals.set(req, principal)
		next()
	}
}
