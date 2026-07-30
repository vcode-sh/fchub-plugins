import assert from 'node:assert/strict'
import { createServer } from 'node:http'
import { dirname, join, resolve } from 'node:path'
import { after, before, describe, it } from 'node:test'
import { fileURLToPath } from 'node:url'
import { Client } from '@modelcontextprotocol/client'
import { StdioClientTransport } from '@modelcontextprotocol/client/stdio'
import { LEGACY_PROTOCOL, MODERN_PROTOCOL } from '../../scripts/protocol-wire.mjs'

const PACKAGE_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..')
const ENTRYPOINT = join(PACKAGE_ROOT, 'dist', 'index.js')
const EXPECTED_TOOLS = ['fluentcart_app_init', 'fluentcart_get_store_context']
const CLIENT_INFO = { name: 'stdio-dual-era', version: '1.0.0' }
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
})

after(async () => {
	await new Promise((closed) => fixture.close(closed))
})

function optionsFor(protocol) {
	return {
		capabilities: {},
		supportedProtocolVersions: [protocol],
		versionNegotiation: {
			mode: protocol === MODERN_PROTOCOL ? { pin: protocol } : 'legacy',
		},
	}
}

async function listTools(protocol) {
	const requestsBefore = capabilityRequests
	const client = new Client(CLIENT_INFO, optionsFor(protocol))
	const transport = new StdioClientTransport({
		command: process.execPath,
		args: [ENTRYPOINT, '--mode', 'full'],
		cwd: PACKAGE_ROOT,
		env: {
			...process.env,
			FLUENTCART_URL: storeUrl,
			FLUENTCART_USERNAME: 'fixture',
			FLUENTCART_APP_PASSWORD: 'fixture',
			FLUENTCART_WRITE_MODE: 'disabled',
			FLUENTCART_ABILITIES_MODE: 'disabled',
		},
		stderr: 'pipe',
	})
	let stderr = ''
	transport.stderr.on('data', (chunk) => {
		stderr += chunk
	})

	try {
		await client.connect(transport, { timeout: 10_000 })
		assert.equal(client.getNegotiatedProtocolVersion(), protocol)
		const names = (await client.listTools()).tools.map(({ name }) => name).sort()
		assert.deepEqual(names, EXPECTED_TOOLS)
	} finally {
		await client.close()
	}

	assert.equal(stderr, '')
	const expectedProcesses = protocol === MODERN_PROTOCOL ? 2 : 1
	assert.equal(
		capabilityRequests - requestsBefore,
		expectedProcesses,
		'each official-client process must discover its runtime once',
	)
}

describe('built stdio entrypoint', () => {
	it('serves the same fixture tools to official 2025 and 2026 clients', async () => {
		await listTools(LEGACY_PROTOCOL)
		await listTools(MODERN_PROTOCOL)
	})
})
