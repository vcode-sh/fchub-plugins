import assert from 'node:assert/strict'
import { createServer, request as httpRequest } from 'node:http'

function parseProtocol(text) {
	for (const line of text.split('\n')) {
		const value = line.startsWith('data:') ? line.slice(5).trim() : line.trim()
		if (!value) continue
		try {
			const message = JSON.parse(value)
			if (typeof message.result?.protocolVersion === 'string') {
				return message.result.protocolVersion
			}
		} catch {}
	}
	return null
}

export async function createHandshakeRelay(targetPort, clientKey) {
	assert.ok(
		Buffer.byteLength(clientKey ?? '', 'utf8') >= 32,
		'client handshake relay requires a key of at least 32 UTF-8 bytes',
	)
	let protocolVersion = null
	const sockets = new Set()
	const upstreamRequests = new Set()
	const server = createServer((incoming, outgoing) => {
		const upstream = httpRequest(
			{
				hostname: '127.0.0.1',
				port: targetPort,
				path: incoming.url,
				method: incoming.method,
					headers: {
						...incoming.headers,
						host: `127.0.0.1:${targetPort}`,
						authorization: `Bearer ${clientKey}`,
					},
			},
			(response) => {
				outgoing.writeHead(response.statusCode, response.headers)
				let body = ''
				response.on('data', (chunk) => {
					body += chunk.toString()
					protocolVersion ??= parseProtocol(body)
					outgoing.write(chunk)
				})
				response.on('end', () => outgoing.end())
			},
		)
		upstreamRequests.add(upstream)
		upstream.once('close', () => upstreamRequests.delete(upstream))
		upstream.once('error', (error) => outgoing.destroy(error))
		incoming.pipe(upstream)
	})
	server.on('connection', (socket) => {
		sockets.add(socket)
		socket.once('close', () => sockets.delete(socket))
	})
	await new Promise((ready, reject) => {
		server.once('error', reject)
		server.listen(0, '127.0.0.1', ready)
	})
	let closePromise = null
	return {
		url: `http://127.0.0.1:${server.address().port}/mcp`,
		protocol: () => protocolVersion,
		close: () => {
			if (closePromise) return closePromise
			closePromise = new Promise((closed, reject) => {
				const timeout = setTimeout(
					() => reject(new Error('client handshake relay cleanup timed out')),
					2_000,
				)
				server.close((error) => {
					clearTimeout(timeout)
					if (error) reject(error)
					else closed()
				})
				for (const upstream of upstreamRequests) upstream.destroy()
				for (const socket of sockets) socket.destroy()
				server.closeIdleConnections?.()
				server.closeAllConnections?.()
			})
			return closePromise
		},
	}
}
