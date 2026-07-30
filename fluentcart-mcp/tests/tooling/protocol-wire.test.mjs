import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { validateModernDiscoveryResult } from '../../scripts/benchmark-http-code-mode.mjs'
import {
	decodeJsonRpc,
	LEGACY_PROTOCOL,
	MODERN_PROTOCOL,
	modernHeaders,
	modernRequest,
} from '../../scripts/protocol-wire.mjs'

const CLIENT = { name: 'protocol-wire-test', version: '1.0.0' }
const META = {
	'io.modelcontextprotocol/protocolVersion': MODERN_PROTOCOL,
	'io.modelcontextprotocol/clientInfo': CLIENT,
	'io.modelcontextprotocol/clientCapabilities': {},
}

describe('modern raw-wire request contract', () => {
	it('exports only the two deliberately supported protocol revisions', () => {
		assert.equal(LEGACY_PROTOCOL, '2025-11-25')
		assert.equal(MODERN_PROTOCOL, '2026-07-28')
	})

	it('builds modern discovery without falling back to initialize', () => {
		assert.deepEqual(
			modernRequest({
				id: 1,
				method: 'server/discover',
				clientInfo: CLIENT,
			}),
			{
				jsonrpc: '2.0',
				id: 1,
				method: 'server/discover',
				params: { _meta: META },
			},
		)
		assert.deepEqual(modernHeaders({ method: 'server/discover' }), {
			Accept: 'application/json, text/event-stream',
			'Content-Type': 'application/json',
			'MCP-Protocol-Version': MODERN_PROTOCOL,
			'Mcp-Method': 'server/discover',
		})
		assert.throws(
			() => modernRequest({ id: 1, method: 'initialize', clientInfo: CLIENT }),
			/modern requests cannot use initialize/,
		)
	})

	it('adds immutable modern metadata to tools/list', () => {
		const first = modernRequest({
			id: 2,
			method: 'tools/list',
			params: { cursor: 'next' },
			clientInfo: CLIENT,
		})
		first.params._meta['io.modelcontextprotocol/protocolVersion'] = LEGACY_PROTOCOL
		first.params.cursor = 'mutated'

		const second = modernRequest({
			id: 2,
			method: 'tools/list',
			params: { cursor: 'next' },
			clientInfo: CLIENT,
		})
		assert.deepEqual(second.params, { cursor: 'next', _meta: META })
		assert.equal(modernHeaders({ method: 'tools/list' })['Mcp-Method'], 'tools/list')
		assert.equal(
			modernHeaders({ method: 'tools/list' })['MCP-Protocol-Version'],
			second.params._meta['io.modelcontextprotocol/protocolVersion'],
		)
	})

	it('binds tools/call body and Mcp-Name to the same tool', () => {
		const params = { name: 'fluentcart_search_tools', arguments: { query: 'orders' } }
		const request = modernRequest({
			id: 3,
			method: 'tools/call',
			params,
			clientInfo: CLIENT,
		})
		const headers = modernHeaders({ method: 'tools/call', name: params.name })
		assert.equal(headers['Mcp-Method'], request.method)
		assert.equal(headers['Mcp-Name'], request.params.name)
		assert.throws(() => modernHeaders({ method: 'tools/call' }), /requires Mcp-Name/)
	})

	it('binds prompts/get body and Mcp-Name to the same prompt', () => {
		const params = { name: 'order-summary', arguments: { order_id: '42' } }
		const request = modernRequest({
			id: 4,
			method: 'prompts/get',
			params,
			clientInfo: CLIENT,
		})
		const headers = modernHeaders({ method: 'prompts/get', name: params.name })
		assert.equal(headers['Mcp-Method'], request.method)
		assert.equal(headers['Mcp-Name'], request.params.name)
		assert.throws(() => modernHeaders({ method: 'prompts/get' }), /requires Mcp-Name/)
	})

	it('binds resources/read body and Mcp-Name to the same URI', () => {
		const params = { uri: 'fluentcart://store/capabilities' }
		const request = modernRequest({
			id: 5,
			method: 'resources/read',
			params,
			clientInfo: CLIENT,
		})
		const headers = modernHeaders({ method: 'resources/read', name: params.uri })
		assert.equal(headers['Mcp-Method'], request.method)
		assert.equal(headers['Mcp-Name'], request.params.uri)
		assert.throws(() => modernHeaders({ method: 'resources/read' }), /requires Mcp-Name/)
	})
})

describe('bounded JSON-RPC response decoder', () => {
	it('decodes JSON and the final JSON-RPC SSE frame', () => {
		assert.deepEqual(
			decodeJsonRpc('{"jsonrpc":"2.0","id":1,"result":{"ok":true}}', 'application/json'),
			{ jsonrpc: '2.0', id: 1, result: { ok: true } },
		)
		assert.deepEqual(
			decodeJsonRpc(
				[
					'event: message',
					'data: {"jsonrpc":"2.0","method":"notifications/progress"}',
					'',
					'data: {"jsonrpc":"2.0","id":2,"result":{"ok":true}}',
					'',
				].join('\n'),
				'text/event-stream',
			),
			{ jsonrpc: '2.0', id: 2, result: { ok: true } },
		)
	})

	it('rejects oversized payloads and excessive SSE frames', () => {
		assert.throws(
			() => decodeJsonRpc('{"large":"payload"}', 'application/json', { maxBytes: 4 }),
			/exceeds 4 bytes/,
		)
		assert.throws(
			() =>
				decodeJsonRpc('data: {"jsonrpc":"2.0"}\n\ndata: {"jsonrpc":"2.0"}\n', 'text/event-stream', {
					maxFrames: 1,
				}),
			/exceeds 1 frames/,
		)
	})
})

describe('modern HTTP benchmark guard', () => {
	it('rejects HTTP 200 when the body is really a legacy initialise result', () => {
		assert.throws(
			() =>
				validateModernDiscoveryResult({
					jsonrpc: '2.0',
					id: 1,
					result: {
						protocolVersion: LEGACY_PROTOCOL,
						serverInfo: { name: 'fluentcart-mcp', version: '2.1.0' },
					},
				}),
			/not a modern server\/discover result/,
		)
	})
})
