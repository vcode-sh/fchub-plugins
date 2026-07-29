#!/usr/bin/env node
/** Dual-era stdio smoke for a package installed from the public npm registry. */

import assert from 'node:assert/strict'
import { createServer } from 'node:http'
import { Client } from '@modelcontextprotocol/client'
import { StdioClientTransport } from '@modelcontextprotocol/client/stdio'

const entrypoint = process.argv[2]
if (!entrypoint) throw new Error('usage: smoke-public-stdio.mjs <installed-dist/index.js>')

const routes = {
	namespaces: ['fluent-cart/v2'],
	routes: {
		'/fluent-cart/v2': { endpoints: [{ methods: ['GET'] }] },
		'/fluent-cart/v2/app/init': { endpoints: [{ methods: ['GET'] }] },
	},
}
const fixture = createServer((request, response) => {
	if (request.url === '/wp-json/fluent-cart/v2') {
		response.writeHead(200, { 'Content-Type': 'application/json' })
		response.end(JSON.stringify(routes))
		return
	}
	response.writeHead(404).end()
})
await new Promise((ready) => fixture.listen(0, '127.0.0.1', ready))
const url = `http://127.0.0.1:${fixture.address().port}`

try {
	for (const protocol of ['2025-11-25', '2026-07-28']) {
		const client = new Client(
			{ name: 'public-registry-smoke', version: '2.0.0' },
			{
				capabilities: {},
				supportedProtocolVersions: [protocol],
				versionNegotiation: { mode: protocol === '2026-07-28' ? { pin: protocol } : 'legacy' },
			},
		)
		const transport = new StdioClientTransport({
			command: process.execPath,
			args: [entrypoint, '--mode', 'dynamic'],
			env: {
				...process.env,
				FLUENTCART_URL: url,
				FLUENTCART_USERNAME: 'fixture',
				FLUENTCART_APP_PASSWORD: 'fixture',
				FLUENTCART_WRITE_MODE: 'disabled',
			},
			stderr: 'pipe',
		})
		try {
			await client.connect(transport, { timeout: 10_000 })
			assert.equal(client.getNegotiatedProtocolVersion(), protocol)
			assert.ok((await client.listTools()).tools.length > 0)
		} finally {
			await client.close()
		}
	}
} finally {
	await new Promise((closed) => fixture.close(closed))
}

process.stdout.write('public npm package passed both stdio protocol eras\n')
