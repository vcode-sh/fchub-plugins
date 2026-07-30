import assert from 'node:assert/strict'
import { createServer } from 'node:http'
import { dirname, join, resolve } from 'node:path'
import { after, before, describe, it } from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { Client, StreamableHTTPClientTransport } from '@modelcontextprotocol/client'
import {
	LEGACY_PROTOCOL,
	MODERN_PROTOCOL,
	modernHeaders,
	modernRequest,
} from '../../scripts/protocol-wire.mjs'

const PACKAGE_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..')
const CLIENT_INFO = { name: 'http-dual-era', version: '1.0.0' }
const EXPECTED_TOOLS = ['fluentcart_app_init', 'fluentcart_get_store_context']
const REST_INDEX = {
	namespaces: ['fluent-cart/v2'],
	routes: {
		'/fluent-cart/v2': { endpoints: [{ methods: ['GET'] }] },
		'/fluent-cart/v2/app/init': { endpoints: [{ methods: ['GET'] }] },
	},
}

let capabilityRequests = 0
let fixture
let storeUrl
let service

function distImport(...segments) {
	return import(pathToFileURL(join(PACKAGE_ROOT, 'dist', ...segments)).href)
}

async function rpc(body, headers = {}) {
	const response = await fetch(`${service.url}/mcp`, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			Accept: 'application/json, text/event-stream',
			...headers,
		},
		body: JSON.stringify(body),
	})
	const text = await response.text()
	const data = text.split('\n').find((line) => line.startsWith('data: '))
	return { response, body: JSON.parse(data ? data.slice(6) : text) }
}

async function officialClient(protocol) {
	const client = new Client(CLIENT_INFO, {
		capabilities: {},
		supportedProtocolVersions: [protocol],
		versionNegotiation: {
			mode: protocol === MODERN_PROTOCOL ? { pin: protocol } : 'legacy',
		},
	})
	const transport = new StreamableHTTPClientTransport(new URL(`${service.url}/mcp`))
	await client.connect(transport)
	return client
}

before(async () => {
	fixture = createServer((request, response) => {
		if (request.method === 'GET' && request.url === '/wp-json/fluent-cart/v2') {
			capabilityRequests += 1
			response.writeHead(200, { 'Content-Type': 'application/json' })
			response.end(JSON.stringify(REST_INDEX))
			return
		}
		response.writeHead(404, { 'Content-Type': 'application/json' })
		response.end(JSON.stringify({ code: 'rest_no_route' }))
	})
	await new Promise((ready) => fixture.listen(0, '127.0.0.1', ready))
	storeUrl = `http://127.0.0.1:${fixture.address().port}`
	Object.assign(process.env, {
		FLUENTCART_URL: storeUrl,
		FLUENTCART_USERNAME: 'fixture',
		FLUENTCART_APP_PASSWORD: 'fixture',
		FLUENTCART_WRITE_MODE: 'disabled',
		FLUENTCART_ABILITIES_MODE: 'disabled',
	})

	const { resolveHttpExposure } = await distImport('transport', 'http-config.js')
	const { startHttpServer } = await distImport('transport', 'http.js')
	service = await startHttpServer(
		0,
		resolveHttpExposure({ profile: 'local', host: '127.0.0.1' }),
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

describe('built HTTP serving entry', () => {
	it('serves the same fixture tools to official 2025 and 2026 clients', async () => {
		for (const protocol of [LEGACY_PROTOCOL, MODERN_PROTOCOL]) {
			const client = await officialClient(protocol)
			try {
				assert.equal(client.getNegotiatedProtocolVersion(), protocol)
				assert.deepEqual(
					(await client.listTools()).tools.map(({ name }) => name).sort(),
					EXPECTED_TOOLS,
				)
			} finally {
				await client.close()
			}
		}
		assert.equal(capabilityRequests, 1, 'both HTTP clients share one startup discovery')
	})

	it('preserves both legacy and modern POST bodies through the Node adapter', async () => {
		const legacy = await rpc({
			jsonrpc: '2.0',
			id: 1,
			method: 'initialize',
			params: {
				protocolVersion: LEGACY_PROTOCOL,
				capabilities: {},
				clientInfo: CLIENT_INFO,
			},
		})
		const modernRequestBody = modernRequest({
			id: 2,
			method: 'server/discover',
			clientInfo: CLIENT_INFO,
		})
		const modern = await rpc(modernRequestBody, modernHeaders({ method: modernRequestBody.method }))

		assert.equal(legacy.response.status, 200)
		assert.equal(legacy.body.result.protocolVersion, LEGACY_PROTOCOL)
		assert.equal(legacy.body.result.serverInfo.name, 'fluentcart-mcp')
		assert.equal(modern.response.status, 200)
		assert.deepEqual(modern.body.result.supportedVersions, [MODERN_PROTOCOL])
		assert.equal(modern.body.result.resultType, 'complete')
		assert.equal(modern.body.result.ttlMs, 0)
		assert.equal(modern.body.result.cacheScope, 'private')
		assert.equal(
			modern.body.result._meta['io.modelcontextprotocol/serverInfo'].name,
			'fluentcart-mcp',
		)
		assert.equal(legacy.response.headers.get('mcp-session-id'), null)
		assert.equal(modern.response.headers.get('mcp-session-id'), null)
		assert.equal(legacy.response.headers.get('cache-control'), 'no-store')
		assert.equal(modern.response.headers.get('cache-control'), 'no-store')
		assert.equal(capabilityRequests, 1, 'runtime discovery must run once for both HTTP eras')
	})
})
