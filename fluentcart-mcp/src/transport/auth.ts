import { timingSafeEqual } from 'node:crypto'
import type { NextFunction, Request, Response } from 'express'

/** Shortest bearer key accepted for a bind that is reachable beyond loopback. */
const MINIMUM_PUBLIC_KEY_LENGTH = 32

const LOOPBACK_HOSTS = new Set(['127.0.0.1', '::1', '[::1]', 'localhost'])

/**
 * Refuse to expose an unauthenticated MCP endpoint beyond loopback.
 *
 * Called before `app.listen()`, so a misconfigured deployment fails at startup rather than
 * serving an open store-administration API to the network and finding out later.
 *
 * @throws when the bind address is reachable off-host and no strong bearer key is configured.
 */
export function assertSafeHttpExposure(host: string, apiKey?: string): void {
	if (isLoopbackHost(host)) return

	if (!apiKey || apiKey.trim().length === 0) {
		throw new Error(
			`Refusing to bind ${host}: a non-loopback HTTP transport requires FLUENTCART_MCP_API_KEY ` +
				`(at least ${MINIMUM_PUBLIC_KEY_LENGTH} characters). Bind 127.0.0.1 for local use.`,
		)
	}

	if (apiKey.trim().length < MINIMUM_PUBLIC_KEY_LENGTH) {
		throw new Error(
			`Refusing to bind ${host}: FLUENTCART_MCP_API_KEY must be at least ${MINIMUM_PUBLIC_KEY_LENGTH} characters.`,
		)
	}
}

function isLoopbackHost(host: string): boolean {
	// Exact matching only. "localhost.example.com" is somebody else's domain, not our loopback.
	return LOOPBACK_HOSTS.has(host.trim().toLowerCase())
}

export function createBearerAuth(): (req: Request, res: Response, next: NextFunction) => void {
	const apiKey = process.env.FLUENTCART_MCP_API_KEY

	if (!apiKey) {
		// Only reachable on loopback: assertSafeHttpExposure refuses any other bind without a key.
		return (_req, _res, next) => next()
	}

	const keyBuf = Buffer.from(apiKey, 'utf8')

	return (req, res, next) => {
		// One opaque failure for every rejection. A caller must not be able to distinguish
		// "no key configured" from "wrong key" from "malformed header".
		const reject = () => {
			res.status(401).json({ error: 'Unauthorized' })
		}

		const header = req.headers.authorization
		if (!header?.startsWith('Bearer ')) {
			reject()
			return
		}

		const tokenBuf = Buffer.from(header.slice(7), 'utf8')
		if (tokenBuf.length !== keyBuf.length || !timingSafeEqual(tokenBuf, keyBuf)) {
			reject()
			return
		}

		next()
	}
}
