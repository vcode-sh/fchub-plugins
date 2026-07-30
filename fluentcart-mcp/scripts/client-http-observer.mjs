import assert from 'node:assert/strict'
import { createServer, request as httpRequest } from 'node:http'
import { decodeJsonRpc, LEGACY_PROTOCOL, MODERN_PROTOCOL } from './protocol-wire.mjs'

const MAX_OBSERVED_BODY_BYTES = 1024 * 1024
const IMAGE_ID = /^sha256:[a-f0-9]{64}$/

function headerValue(headers, name) {
	if (headers instanceof Headers) return headers.get(name)
	const wanted = name.toLowerCase()
	for (const [key, value] of Object.entries(headers ?? {})) {
		if (key.toLowerCase() === wanted) return Array.isArray(value) ? value[0] : value
	}
	return null
}

function assertCandidateImageId(value) {
	assert.match(
		value ?? '',
		IMAGE_ID,
		'handshake observation requires the exact candidate image ID',
	)
}

function assertCorrelated(request, response) {
	assert.equal(response?.jsonrpc, '2.0', 'handshake response is not JSON-RPC 2.0')
	assert.equal(response?.id, request.id, 'handshake response ID differs from the request')
	assert.equal(response?.error, undefined, 'handshake response contains a JSON-RPC error')
}

export function assessHandshakeExchange({
	request,
	requestHeaders,
	response,
	candidateImageId,
	observedAt,
	transport = 'http',
}) {
	if (!request || !['initialize', 'server/discover'].includes(request.method)) return null
	assertCandidateImageId(candidateImageId)
	assert.equal(new Date(observedAt).toISOString(), observedAt, 'observedAt must be an ISO timestamp')
	assertCorrelated(request, response)

	if (request.method === 'initialize') {
		assert.notEqual(
			request.params?.protocolVersion,
			MODERN_PROTOCOL,
			`initialize cannot certify ${MODERN_PROTOCOL}`,
		)
		assert.equal(
			request.params?.protocolVersion,
			LEGACY_PROTOCOL,
			`legacy initialize must request ${LEGACY_PROTOCOL}`,
		)
		assert.equal(
			response.result?.protocolVersion,
			LEGACY_PROTOCOL,
			'legacy initialize response is from a different era',
		)
		return {
			era: 'legacy',
			protocolVersion: LEGACY_PROTOCOL,
			negotiationMethod: 'initialize',
			observedAt,
			candidateImageId,
		}
	}

	const meta = request.params?._meta
	assert.equal(
		meta?.['io.modelcontextprotocol/protocolVersion'],
		MODERN_PROTOCOL,
		'discovery request lacks the modern protocol metadata',
	)
	assert.equal(
		typeof meta?.['io.modelcontextprotocol/clientInfo']?.name,
		'string',
		'discovery request lacks clientInfo metadata',
	)
	assert.ok(
		meta?.['io.modelcontextprotocol/clientCapabilities'] &&
			typeof meta['io.modelcontextprotocol/clientCapabilities'] === 'object',
		'discovery request lacks clientCapabilities metadata',
	)
	if (transport === 'http') {
		assert.equal(
			headerValue(requestHeaders, 'MCP-Protocol-Version'),
			MODERN_PROTOCOL,
			`discovery request requires MCP-Protocol-Version: ${MODERN_PROTOCOL}`,
		)
		assert.equal(
			headerValue(requestHeaders, 'Mcp-Method'),
			'server/discover',
			'discovery request requires Mcp-Method: server/discover',
		)
	}
	assert.ok(
		Array.isArray(response.result?.supportedVersions),
		'discovery response lacks supportedVersions',
	)
	assert.ok(
		response.result.supportedVersions.includes(MODERN_PROTOCOL),
		'discovery response is from a different era',
	)
	assert.equal(
		response.result?.protocolVersion,
		undefined,
		'discovery response used the legacy protocolVersion shape',
	)
	return {
		era: 'modern',
		protocolVersion: MODERN_PROTOCOL,
		negotiationMethod: 'server/discover',
		observedAt,
		candidateImageId,
	}
}

function appendBounded(current, chunk, label) {
	const next = current + chunk.toString()
	if (Buffer.byteLength(next) > MAX_OBSERVED_BODY_BYTES) {
		throw new Error(`${label} exceeded ${MAX_OBSERVED_BODY_BYTES} bytes`)
	}
	return next
}

export async function createHandshakeRelay(targetPort, clientKey, candidateImageId = null) {
	assert.ok(
		Buffer.byteLength(clientKey ?? '', 'utf8') >= 32,
		'client handshake relay requires a key of at least 32 UTF-8 bytes',
	)
	if (candidateImageId !== null) assertCandidateImageId(candidateImageId)
	let observedHandshake = null
	let rejectedHandshake = null
	const sockets = new Set()
	const upstreamRequests = new Set()
	const server = createServer((incoming, outgoing) => {
		let requestBody = ''
		let parsedRequest = null
		let parsedResponse = null
		const evaluate = () => {
			if (observedHandshake || rejectedHandshake || !parsedRequest || !parsedResponse) return
			try {
				observedHandshake = assessHandshakeExchange({
					request: parsedRequest,
					requestHeaders: incoming.headers,
					response: parsedResponse,
					candidateImageId,
					observedAt: new Date().toISOString(),
				})
			} catch (error) {
				rejectedHandshake = error.message
			}
		}
		incoming.on('data', (chunk) => {
			try {
				requestBody = appendBounded(requestBody, chunk, 'observed client request')
			} catch (error) {
				rejectedHandshake ??= error.message
			}
		})
		incoming.on('end', () => {
			try {
				parsedRequest = JSON.parse(requestBody)
				evaluate()
			} catch {
				// A non-JSON request is forwarded but cannot become handshake evidence.
			}
		})

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
				let responseBody = ''
				response.on('data', (chunk) => {
					try {
						responseBody = appendBounded(responseBody, chunk, 'observed server response')
					} catch (error) {
						rejectedHandshake ??= error.message
					}
					outgoing.write(chunk)
				})
				response.on('end', () => {
					try {
						parsedResponse = decodeJsonRpc(
							responseBody,
							String(response.headers['content-type'] ?? ''),
						)
						evaluate()
					} catch {
						// A non-JSON-RPC response is forwarded but cannot become handshake evidence.
					}
					outgoing.end()
				})
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
		observation: () => observedHandshake,
		protocol: () => observedHandshake?.protocolVersion ?? null,
		rejection: () => rejectedHandshake,
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
