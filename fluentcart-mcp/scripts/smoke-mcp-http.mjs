#!/usr/bin/env node

import assert from 'node:assert/strict'
import { resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import {
	decodeJsonRpc,
	LEGACY_PROTOCOL,
	MODERN_PROTOCOL,
	modernHeaders,
	modernRequest,
} from './protocol-wire.mjs'

const EXPECTED_TOOL_NAMES = [
	'fluentcart_search_tools',
	'fluentcart_describe_tools',
	'fluentcart_execute_read_tool',
]
const SERVER_INFO_META = 'io.modelcontextprotocol/serverInfo'

function assertServerInfo(value, label) {
	assert.equal(value?.name, 'fluentcart-mcp', `${label} server name`)
	assert.equal(typeof value?.version, 'string', `${label} server version`)
}

function assertImmutableCapabilities(value, label) {
	for (const family of ['tools', 'resources', 'prompts']) {
		assert.equal(value?.[family]?.listChanged, false, `${label} ${family}.listChanged`)
	}
	for (const absent of ['extensions', 'tasks', 'roots', 'sampling', 'logging']) {
		assert.equal(Object.hasOwn(value ?? {}, absent), false, `${label} advertised ${absent}`)
	}
}

function assertModernResult(value, label) {
	assert.equal(value?.resultType, 'complete', `${label} resultType`)
	assert.equal(value?.ttlMs, 0, `${label} ttlMs`)
	assert.equal(value?.cacheScope, 'private', `${label} cacheScope`)
	assertServerInfo(value?._meta?.[SERVER_INFO_META], `${label} metadata`)
}

function toolNames(result) {
	assert.ok(Array.isArray(result?.tools), 'tools/list did not return a tools array')
	return result.tools.map(({ name }) => name)
}

export function validateDualEraSmoke(evidence) {
	assert.ok(evidence?.legacy, 'dual-era smoke requires a legacy transcript')
	assert.ok(evidence?.modern, 'dual-era smoke requires a modern transcript')
	const expected = evidence.expectedToolNames ?? EXPECTED_TOOL_NAMES

	assert.equal(evidence.legacy.sessionId, null, 'legacy emitted Mcp-Session-Id')
	assert.equal(
		evidence.legacy.initialize?.protocolVersion,
		LEGACY_PROTOCOL,
		'legacy initialise selected the wrong revision',
	)
	assertServerInfo(evidence.legacy.initialize?.serverInfo, 'legacy initialise')
	assertImmutableCapabilities(evidence.legacy.initialize?.capabilities, 'legacy initialise')
	assert.deepEqual(
		toolNames(evidence.legacy.tools),
		expected,
		'legacy tools/list surface or order differs',
	)

	assert.equal(evidence.modern.sessionId, null, 'modern emitted Mcp-Session-Id')
	assert.deepEqual(
		evidence.modern.discovery?.supportedVersions,
		[MODERN_PROTOCOL],
		'modern discovery advertised the wrong revisions',
	)
	assert.equal(
		evidence.modern.discovery?.protocolVersion,
		undefined,
		'modern discovery used the legacy result shape',
	)
	assertModernResult(evidence.modern.discovery, 'modern server/discover')
	assertImmutableCapabilities(evidence.modern.discovery?.capabilities, 'modern discovery')
	assertModernResult(evidence.modern.tools, 'modern tools/list')
	assert.deepEqual(
		toolNames(evidence.modern.tools),
		expected,
		'modern tools/list surface or order differs',
	)

	return {
		legacyProtocol: LEGACY_PROTOCOL,
		modernProtocol: MODERN_PROTOCOL,
		toolCount: expected.length,
	}
}

async function main() {
	const baseUrl = process.argv[2]
	const apiKey = process.argv[3]
	if (!baseUrl || !apiKey) {
		throw new Error('usage: smoke-mcp-http.mjs <mcp-url> <bearer-key>')
	}

	async function request(message, headers = {}) {
		const response = await fetch(baseUrl, {
			method: 'POST',
			headers: {
				Authorization: `Bearer ${apiKey}`,
				'Content-Type': 'application/json',
				Accept: 'application/json, text/event-stream',
				...headers,
			},
			body: JSON.stringify(message),
		})
		const text = await response.text()
		assert.equal(response.status, 200, `MCP request returned HTTP ${response.status}: ${text}`)
		const payload = decodeJsonRpc(text, response.headers.get('content-type') ?? '')
		assert.equal(payload.jsonrpc, '2.0')
		assert.equal(payload.id, message.id)
		assert.ok(!payload.error, `MCP returned an error: ${JSON.stringify(payload.error)}`)
		return {
			result: payload.result,
			sessionId: response.headers.get('mcp-session-id'),
		}
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
		assert.equal(response.headers.get('mcp-session-id'), null)
	}

	const legacyInitialize = await request({
		jsonrpc: '2.0',
		id: 1,
		method: 'initialize',
		params: {
			protocolVersion: LEGACY_PROTOCOL,
			capabilities: {},
			clientInfo: { name: 'docker-release-smoke', version: '1.0.0' },
		},
	})
	await notify({
		jsonrpc: '2.0',
		method: 'notifications/initialized',
		params: {},
	})
	const legacyList = await request({
		jsonrpc: '2.0',
		id: 2,
		method: 'tools/list',
		params: {},
	})

	const modernDiscoveryRequest = modernRequest({
		id: 3,
		method: 'server/discover',
		clientInfo: { name: 'docker-release-smoke', version: '1.0.0' },
	})
	const modernDiscovery = await request(
		modernDiscoveryRequest,
		modernHeaders({ method: modernDiscoveryRequest.method }),
	)
	const modernListRequest = modernRequest({
		id: 4,
		method: 'tools/list',
		clientInfo: { name: 'docker-release-smoke', version: '1.0.0' },
	})
	const modernList = await request(
		modernListRequest,
		modernHeaders({ method: modernListRequest.method }),
	)

	for (const result of [legacyList.result, modernList.result]) {
		for (const tool of result.tools ?? []) {
			assert.equal(typeof tool.name, 'string')
			assert.ok(tool.name.length > 0)
			assert.equal(typeof tool.description, 'string')
			assert.ok(tool.inputSchema && typeof tool.inputSchema === 'object')
		}
	}

	const summary = validateDualEraSmoke({
		legacy: {
			initialize: legacyInitialize.result,
			tools: legacyList.result,
			sessionId: legacyInitialize.sessionId ?? legacyList.sessionId,
		},
		modern: {
			discovery: modernDiscovery.result,
			tools: modernList.result,
			sessionId: modernDiscovery.sessionId ?? modernList.sessionId,
		},
		expectedToolNames: EXPECTED_TOOL_NAMES,
	})
	process.stdout.write(
		`MCP dual-era smoke succeeded (${summary.legacyProtocol}, ${summary.modernProtocol}; ${summary.toolCount} fixture-profile tools)\n`,
	)
}

const direct = process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)
if (direct) {
	main().catch((error) => {
		process.stderr.write(`${error.message}\n`)
		process.exitCode = 1
	})
}
