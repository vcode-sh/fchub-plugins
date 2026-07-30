import assert from 'node:assert/strict'
import { Buffer } from 'node:buffer'

export const LEGACY_PROTOCOL = '2025-11-25'
export const MODERN_PROTOCOL = '2026-07-28'

const NAMED_METHODS = new Set(['tools/call', 'prompts/get', 'resources/read'])
const DEFAULT_MAX_BYTES = 1024 * 1024
const DEFAULT_MAX_FRAMES = 100

function cloneObject(value, label) {
	if (value === undefined) return {}
	if (!value || typeof value !== 'object' || Array.isArray(value)) {
		throw new TypeError(`${label} must be an object`)
	}
	return structuredClone(value)
}

export function modernRequest({ id, method, params = {}, clientInfo }) {
	if (method === 'initialize') throw new Error('modern requests cannot use initialize')
	if (typeof method !== 'string' || method.length === 0) {
		throw new TypeError('modern request method must be a non-empty string')
	}
	if (id === undefined || id === null) throw new TypeError('modern request id is required')

	const clonedParams = cloneObject(params, 'modern request params')
	const clonedClientInfo = cloneObject(clientInfo, 'modern request clientInfo')
	if (
		typeof clonedClientInfo.name !== 'string' ||
		typeof clonedClientInfo.version !== 'string'
	) {
		throw new TypeError('modern request clientInfo requires name and version strings')
	}
	const suppliedMeta = cloneObject(clonedParams._meta, 'modern request params._meta')
	clonedParams._meta = {
		...suppliedMeta,
		'io.modelcontextprotocol/protocolVersion': MODERN_PROTOCOL,
		'io.modelcontextprotocol/clientInfo': clonedClientInfo,
		'io.modelcontextprotocol/clientCapabilities': {},
	}

	return {
		jsonrpc: '2.0',
		id,
		method,
		params: clonedParams,
	}
}

export function modernHeaders({ method, name }) {
	if (method === 'initialize') throw new Error('modern requests cannot use initialize')
	if (typeof method !== 'string' || method.length === 0) {
		throw new TypeError('modern request method must be a non-empty string')
	}
	if (NAMED_METHODS.has(method) && (typeof name !== 'string' || name.length === 0)) {
		throw new Error(`${method} requires Mcp-Name`)
	}
	return {
		Accept: 'application/json, text/event-stream',
		'Content-Type': 'application/json',
		'MCP-Protocol-Version': MODERN_PROTOCOL,
		'Mcp-Method': method,
		...(name === undefined ? {} : { 'Mcp-Name': name }),
	}
}

export function decodeJsonRpc(
	text,
	contentType,
	{ maxBytes = DEFAULT_MAX_BYTES, maxFrames = DEFAULT_MAX_FRAMES } = {},
) {
	assert.equal(typeof text, 'string', 'MCP response body must be text')
	const bytes = Buffer.byteLength(text)
	assert.ok(bytes <= maxBytes, `MCP response exceeds ${maxBytes} bytes`)

	if (!contentType.toLowerCase().includes('text/event-stream')) return JSON.parse(text)

	const frames = text
		.split(/\r?\n/)
		.filter((line) => line.startsWith('data:'))
		.map((line) => line.slice(5).trimStart())
	assert.ok(frames.length > 0, 'MCP event stream contained no JSON-RPC message')
	assert.ok(frames.length <= maxFrames, `MCP event stream exceeds ${maxFrames} frames`)
	return JSON.parse(frames.at(-1))
}
