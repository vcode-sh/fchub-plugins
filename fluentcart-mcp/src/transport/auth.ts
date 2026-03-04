import { timingSafeEqual } from 'node:crypto'
import type { NextFunction, Request, Response } from 'express'

export function createBearerAuth(): (req: Request, res: Response, next: NextFunction) => void {
	const apiKey = process.env.FLUENTCART_MCP_API_KEY

	if (!apiKey) {
		return (_req, _res, next) => next()
	}

	return (req, res, next) => {
		const header = req.headers.authorization
		if (!header?.startsWith('Bearer ')) {
			res.status(401).json({ error: 'Missing or invalid Authorization header' })
			return
		}

		const token = header.slice(7)
		const tokenBuf = Buffer.from(token)
		const keyBuf = Buffer.from(apiKey)
		if (tokenBuf.length !== keyBuf.length || !timingSafeEqual(tokenBuf, keyBuf)) {
			res.status(401).json({ error: 'Invalid API key' })
			return
		}

		next()
	}
}
