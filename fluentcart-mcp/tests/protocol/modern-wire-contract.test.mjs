import assert from 'node:assert/strict'
import { spawn } from 'node:child_process'
import { createServer } from 'node:http'
import { dirname, join, resolve } from 'node:path'
import { after, before, describe, it } from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'
import {
	decodeJsonRpc,
	MODERN_PROTOCOL,
	modernHeaders,
	modernRequest,
} from '../../scripts/protocol-wire.mjs'

const PACKAGE_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..')
const ENTRYPOINT = join(PACKAGE_ROOT, 'dist/index.js')
const CLIENT_INFO = { name: 'modern-wire-contract', version: '1.0.0' }
const SERVER_INFO_META = 'io.modelcontextprotocol/serverInfo'
const KEY = 'modern-wire-contract-key-0123456789'
const EXPECTED_TOOLS = ['fluentcart_app_init', 'fluentcart_get_store_context']
const CACHEABLE_METHODS = [
	['tools/list', {}, 'tools'],
	['prompts/list', {}, 'prompts'],
	['resources/list', {}, 'resources'],
	['resources/templates/list', {}, 'resourceTemplates'],
	['resources/read', { uri: 'fluentcart://store/config' }, 'contents'],
]
const REST_INDEX = {
	namespaces: ['fluent-cart/v2'],
	routes: {
		'/fluent-cart/v2': { endpoints: [{ methods: ['GET'] }] },
		'/fluent-cart/v2/app/init': { endpoints: [{ methods: ['GET'] }] },
	},
}

let fixture
let fixtureUrl
let service

function distImport(...segments) {
	return import(pathToFileURL(join(PACKAGE_ROOT, 'dist', ...segments)).href)
}

function headersFor(method, params = {}) {
	return modernHeaders({
		method,
		name:
			method === 'resources/read'
				? params.uri
				: method === 'tools/call' || method === 'prompts/get'
					? params.name
					: undefined,
	})
}

async function rawHttp(id, method, params = {}, headers = headersFor(method, params)) {
	const request = modernRequest({ id, method, params, clientInfo: CLIENT_INFO })
	const response = await fetch(`${service.url}/mcp`, {
		method: 'POST',
		headers: {
			Authorization: `Bearer ${KEY}`,
			...headers,
		},
		body: JSON.stringify(request),
	})
	const text = await response.text()
	assert.equal(
		response.headers.get('mcp-session-id'),
		null,
		`${method} unexpectedly created a session`,
	)
	assert.doesNotMatch(text, /^id:/m, `${method} emitted a resumability event ID`)
	return {
		request,
		response,
		text,
		payload: decodeJsonRpc(text, response.headers.get('content-type') ?? ''),
	}
}

function assertModernResult(call, method) {
	assert.equal(call.response.status, 200, `${method}: ${call.text}`)
	assert.deepEqual(
		{
			jsonrpc: call.payload.jsonrpc,
			id: call.payload.id,
			hasError: Object.hasOwn(call.payload, 'error'),
		},
		{ jsonrpc: '2.0', id: call.request.id, hasError: false },
	)
	assert.equal(call.payload.result.resultType, 'complete', `${method} resultType`)
	assert.equal(call.payload.result.ttlMs, 0, `${method} ttlMs`)
	assert.equal(call.payload.result.cacheScope, 'private', `${method} cacheScope`)
	assert.equal(
		call.payload.result._meta?.[SERVER_INFO_META]?.name,
		'fluentcart-mcp',
		`${method} server identity`,
	)
	assert.equal(typeof call.payload.result._meta?.[SERVER_INFO_META]?.version, 'string')
}

function normaliseId(payload) {
	const cloned = structuredClone(payload)
	Reflect.deleteProperty(cloned, 'id')
	return cloned
}

before(async () => {
	fixture = createServer((request, response) => {
		if (request.method === 'GET' && request.url === '/wp-json/fluent-cart/v2') {
			response.writeHead(200, { 'Content-Type': 'application/json' })
			response.end(JSON.stringify(REST_INDEX))
			return
		}
		if (request.method === 'GET' && request.url === '/wp-json/fluent-cart/v2/app/init') {
			response.writeHead(200, { 'Content-Type': 'application/json' })
			response.end(JSON.stringify({ store_name: 'Modern Wire Fixture' }))
			return
		}
		response.writeHead(404, { 'Content-Type': 'application/json' })
		response.end(JSON.stringify({ code: 'rest_no_route' }))
	})
	await new Promise((ready) => fixture.listen(0, '127.0.0.1', ready))
	fixtureUrl = `http://127.0.0.1:${fixture.address().port}`
	Object.assign(process.env, {
		FLUENTCART_URL: fixtureUrl,
		FLUENTCART_USERNAME: 'fixture',
		FLUENTCART_APP_PASSWORD: 'fixture',
		FLUENTCART_WRITE_MODE: 'disabled',
		FLUENTCART_ABILITIES_MODE: 'disabled',
	})

	const { resolveHttpExposure } = await distImport('transport/http-config.js')
	const { startHttpServer } = await distImport('transport/http.js')
	service = await startHttpServer(
		0,
		resolveHttpExposure({
			profile: 'local',
			host: '127.0.0.1',
			bearerKey: KEY,
		}),
		'full',
		{ drainMs: 50 },
	)
})

after(async () => {
	await service?.close()
	await new Promise((closed) => fixture.close(closed))
	for (const name of [
		'FLUENTCART_URL',
		'FLUENTCART_USERNAME',
		'FLUENTCART_APP_PASSWORD',
		'FLUENTCART_WRITE_MODE',
		'FLUENTCART_ABILITIES_MODE',
	]) {
		delete process.env[name]
	}
})

describe('raw modern HTTP wire', () => {
	it('discovers one stateless 2026 surface with instructions and no optional extensions', async () => {
		const discovery = await rawHttp(1, 'server/discover')
		assertModernResult(discovery, 'server/discover')
		assert.deepEqual(discovery.payload.result.supportedVersions, [MODERN_PROTOCOL])
		assert.equal(typeof discovery.payload.result.instructions, 'string')
		assert.ok(discovery.payload.result.instructions.length > 0)
		assert.equal(discovery.payload.result.capabilities.tools.listChanged, false)
		assert.equal(discovery.payload.result.capabilities.resources.listChanged, false)
		assert.equal(discovery.payload.result.capabilities.prompts.listChanged, false)
		for (const absent of ['extensions', 'tasks', 'roots', 'sampling', 'logging']) {
			assert.equal(
				Object.hasOwn(discovery.payload.result.capabilities, absent),
				false,
				`discovery advertised ${absent}`,
			)
		}
	})

	it('emits complete private no-cache results for every other cacheable operation', async () => {
		for (const [method, params, resultKey] of CACHEABLE_METHODS) {
			const call = await rawHttp(
				10 + CACHEABLE_METHODS.findIndex(([name]) => name === method),
				method,
				params,
			)
			assertModernResult(call, method)
			assert.ok(Array.isArray(call.payload.result[resultKey]), `${method} omitted ${resultKey}`)
		}
	})

	it('keeps fresh list results deterministic apart from the JSON-RPC request ID', async () => {
		for (const [index, method] of ['tools/list', 'prompts/list', 'resources/list'].entries()) {
			const first = await rawHttp(30 + index * 2, method)
			const second = await rawHttp(31 + index * 2, method)
			assert.deepEqual(normaliseId(first.payload), normaliseId(second.payload), method)
		}
	})

	it('returns the modern resource-not-found code', async () => {
		const missing = await rawHttp(40, 'resources/read', {
			uri: 'fluentcart://store/definitely-missing',
		})
		assert.equal(missing.response.status, 200)
		assert.equal(missing.payload.error?.code, -32602)
	})

	it('rejects method/name disagreement and an unsupported revision with protocol errors', async () => {
		const mismatch = await rawHttp(
			41,
			'tools/call',
			{ name: 'fluentcart_app_init', arguments: {} },
			modernHeaders({ method: 'tools/call', name: 'definitely-not-the-body-name' }),
		)
		assert.equal(mismatch.response.status, 400)
		assert.equal(mismatch.payload.error?.code, -32020)

		const unsupportedRequest = modernRequest({
			id: 42,
			method: 'server/discover',
			clientInfo: CLIENT_INFO,
		})
		unsupportedRequest.params._meta['io.modelcontextprotocol/protocolVersion'] = '2026-12-31'
		const unsupportedResponse = await fetch(`${service.url}/mcp`, {
			method: 'POST',
			headers: {
				Authorization: `Bearer ${KEY}`,
				...modernHeaders({ method: 'server/discover' }),
				'MCP-Protocol-Version': '2026-12-31',
			},
			body: JSON.stringify(unsupportedRequest),
		})
		const unsupportedText = await unsupportedResponse.text()
		const unsupported = decodeJsonRpc(
			unsupportedText,
			unsupportedResponse.headers.get('content-type') ?? '',
		)
		assert.equal(unsupportedResponse.status, 400)
		assert.equal(unsupported.error?.code, -32022)
		assert.equal(unsupportedResponse.headers.get('mcp-session-id'), null)
	})

	it('does not expose the removed GET event stream', async () => {
		const response = await fetch(`${service.url}/mcp`, {
			method: 'GET',
			headers: {
				Authorization: `Bearer ${KEY}`,
				Accept: 'text/event-stream',
			},
		})
		assert.notEqual(response.status, 200)
		assert.doesNotMatch(response.headers.get('content-type') ?? '', /text\/event-stream/i)
		await response.body?.cancel()
	})
})

function createRawStdioClient() {
	const child = spawn(process.execPath, [ENTRYPOINT, '--mode', 'full'], {
		cwd: PACKAGE_ROOT,
		env: {
			...process.env,
			FLUENTCART_URL: fixtureUrl,
			FLUENTCART_USERNAME: 'fixture',
			FLUENTCART_APP_PASSWORD: 'fixture',
			FLUENTCART_WRITE_MODE: 'disabled',
			FLUENTCART_ABILITIES_MODE: 'disabled',
		},
		stdio: ['pipe', 'pipe', 'pipe'],
	})
	child.stdout.setEncoding('utf8')
	child.stderr.setEncoding('utf8')
	let buffer = ''
	let stderr = ''
	const waiting = new Map()
	child.stderr.on('data', (chunk) => {
		stderr += chunk
	})
	child.stdout.on('data', (chunk) => {
		buffer += chunk
		for (;;) {
			const newline = buffer.indexOf('\n')
			if (newline < 0) break
			const line = buffer.slice(0, newline).trim()
			buffer = buffer.slice(newline + 1)
			if (!line) continue
			const message = JSON.parse(line)
			const settle = waiting.get(message.id)
			if (settle) {
				waiting.delete(message.id)
				settle.resolve(message)
			}
		}
	})
	child.once('error', (error) => {
		for (const settle of waiting.values()) settle.reject(error)
		waiting.clear()
	})

	return {
		stderr: () => stderr,
		request(message) {
			return new Promise((resolveRequest, rejectRequest) => {
				const timeout = setTimeout(() => {
					waiting.delete(message.id)
					rejectRequest(new Error(`stdio request ${message.id} timed out`))
				}, 10_000)
				waiting.set(message.id, {
					resolve(value) {
						clearTimeout(timeout)
						resolveRequest(value)
					},
					reject(error) {
						clearTimeout(timeout)
						rejectRequest(error)
					},
				})
				child.stdin.write(`${JSON.stringify(message)}\n`)
			})
		},
		async close() {
			child.stdin.end()
			await new Promise((resolveClose, rejectClose) => {
				const timeout = setTimeout(() => {
					child.kill('SIGKILL')
					rejectClose(new Error('stdio process did not stop after stdin closed'))
				}, 5_000)
				child.once('close', () => {
					clearTimeout(timeout)
					resolveClose()
				})
			})
		},
	}
}

describe('raw modern stdio wire', () => {
	it('serves discovery and tools/list without a legacy initialise projection', async () => {
		const client = createRawStdioClient()
		try {
			const discoveryRequest = modernRequest({
				id: 101,
				method: 'server/discover',
				clientInfo: CLIENT_INFO,
			})
			const discovery = await client.request(discoveryRequest)
			assert.equal(discovery.jsonrpc, '2.0')
			assert.equal(discovery.id, 101)
			assert.deepEqual(discovery.result.supportedVersions, [MODERN_PROTOCOL])
			assert.equal(discovery.result.resultType, 'complete')
			assert.equal(discovery.result.ttlMs, 0)
			assert.equal(discovery.result.cacheScope, 'private')
			assert.equal(discovery.result._meta[SERVER_INFO_META].name, 'fluentcart-mcp')
			assert.equal(typeof discovery.result.instructions, 'string')

			const listRequest = modernRequest({
				id: 102,
				method: 'tools/list',
				clientInfo: CLIENT_INFO,
			})
			const listed = await client.request(listRequest)
			assert.equal(listed.jsonrpc, '2.0')
			assert.equal(listed.id, 102)
			assert.equal(listed.result.resultType, 'complete')
			assert.equal(listed.result.ttlMs, 0)
			assert.equal(listed.result.cacheScope, 'private')
			assert.equal(listed.result._meta[SERVER_INFO_META].name, 'fluentcart-mcp')
			assert.deepEqual(listed.result.tools.map(({ name }) => name).sort(), EXPECTED_TOOLS)
			assert.equal(client.stderr(), '')
		} finally {
			await client.close()
		}
	})
})
