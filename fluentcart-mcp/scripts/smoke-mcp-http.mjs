#!/usr/bin/env node

import assert from 'node:assert/strict'

const baseUrl = process.argv[2]
const apiKey = process.argv[3]

if (!baseUrl || !apiKey) {
	throw new Error('usage: smoke-mcp-http.mjs <mcp-url> <bearer-key>')
}

function decodeJsonRpc(text, contentType) {
	if (!contentType.includes('text/event-stream')) return JSON.parse(text)

	const messages = text
		.split('\n')
		.filter((line) => line.startsWith('data: '))
		.map((line) => JSON.parse(line.slice(6)))
	assert.ok(messages.length > 0, 'MCP event stream contained no JSON-RPC message')
	return messages.at(-1)
}

async function request(message) {
	const response = await fetch(baseUrl, {
		method: 'POST',
		headers: {
			Authorization: `Bearer ${apiKey}`,
			'Content-Type': 'application/json',
			Accept: 'application/json, text/event-stream',
		},
		body: JSON.stringify(message),
	})
	const text = await response.text()
	assert.equal(response.status, 200, `MCP request returned HTTP ${response.status}: ${text}`)
	const payload = decodeJsonRpc(text, response.headers.get('content-type') ?? '')
	assert.equal(payload.jsonrpc, '2.0')
	assert.equal(payload.id, message.id)
	assert.ok(!payload.error, `MCP returned an error: ${JSON.stringify(payload.error)}`)
	return payload.result
}

async function notify(message) {
	const response = await fetch(baseUrl, {
		method: 'POST',
		headers: {
			Authorization: `Bearer ${apiKey}`,
			'Content-Type': 'application/json',
			Accept: 'application/json, text/event-stream',
		},
		body: JSON.stringify(message),
	})
	assert.equal(response.status, 202, `MCP notification returned HTTP ${response.status}`)
}

const initialised = await request({
	jsonrpc: '2.0',
	id: 1,
	method: 'initialize',
	params: {
		protocolVersion: '2025-11-25',
		capabilities: {},
		clientInfo: { name: 'docker-release-smoke', version: '1.0.0' },
	},
})
assert.equal(initialised.serverInfo?.name, 'fluentcart-mcp')
assert.equal(typeof initialised.serverInfo?.version, 'string')
assert.ok(initialised.capabilities?.tools, 'initialize result did not advertise tool capability')

await notify({
	jsonrpc: '2.0',
	method: 'notifications/initialized',
	params: {},
})

const listed = await request({
	jsonrpc: '2.0',
	id: 2,
	method: 'tools/list',
	params: {},
})
assert.ok(Array.isArray(listed.tools), 'tools/list did not return a tools array')
const expectedNames = [
	'fluentcart_search_tools',
	'fluentcart_describe_tools',
	'fluentcart_execute_read_tool',
]
assert.deepEqual(
	listed.tools.map((tool) => tool.name).sort(),
	expectedNames.sort(),
	'tools/list did not match the expected dynamic-mode fixture surface',
)
for (const tool of listed.tools) {
	assert.equal(typeof tool.name, 'string')
	assert.ok(tool.name.length > 0)
	assert.equal(typeof tool.description, 'string')
	assert.ok(tool.inputSchema && typeof tool.inputSchema === 'object')
}

process.stdout.write(
	`MCP initialize and tools/list succeeded (${listed.tools.length} fixture-profile tools)\n`,
)
