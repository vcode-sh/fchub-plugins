import assert from 'node:assert/strict'
import { spawn } from 'node:child_process'
import { request as nodeRequest } from 'node:http'
import { connect } from 'node:net'
import { dirname, join, resolve } from 'node:path'
import { after, before, describe, it } from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'

const PACKAGE_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..')
const ENTRYPOINT = join(PACKAGE_ROOT, 'dist', 'index.js')
const KEY = 'acceptance-private-key-0123456789abcdef'
const WRONG_KEY = 'x'.repeat(KEY.length)
const CLIENT = { name: 'acceptance-transport', version: '1.0.0' }
const INITIALIZE = {
	jsonrpc: '2.0',
	id: 1,
	method: 'initialize',
	params: {
		protocolVersion: '2025-11-25',
		capabilities: {},
		clientInfo: CLIENT,
	},
}
const FIXTURE_ENV = {
	FLUENTCART_URL: 'https://fixture.invalid',
	FLUENTCART_USERNAME: 'fixture',
	FLUENTCART_APP_PASSWORD: 'fixture',
	FLUENTCART_WRITE_MODE: 'disabled',
	FLUENTCART_ABILITIES_MODE: 'disabled',
}

let application
let baseUrl
let listener
let config
let factory

function distImport(...segments) {
	return import(pathToFileURL(join(PACKAGE_ROOT, 'dist', ...segments)).href)
}

function rpc(headers = {}, body = INITIALIZE) {
	const payload = typeof body === 'string' ? body : JSON.stringify(body)
	return new Promise((resolveRequest, rejectRequest) => {
		const outgoing = nodeRequest(`${baseUrl}/mcp`, {
			method: 'POST',
			headers: {
				Host: 'mcp.internal',
				'Content-Type': 'application/json',
				Accept: 'application/json, text/event-stream',
				'Content-Length': Buffer.byteLength(payload),
				...headers,
			},
		})
		outgoing.on('response', (incoming) => {
			const chunks = []
			incoming.on('data', (chunk) => chunks.push(Buffer.from(chunk)))
			incoming.on('end', () => {
				resolveRequest(
					new Response(Buffer.concat(chunks), {
						status: incoming.statusCode,
						headers: incoming.headers,
					}),
				)
			})
		})
		outgoing.on('error', rejectRequest)
		outgoing.end(payload)
	})
}

function isListening(port) {
	return new Promise((settle) => {
		const socket = connect({ host: '127.0.0.1', port })
		socket.on('connect', () => {
			socket.destroy()
			settle(true)
		})
		socket.on('error', () => settle(false))
		socket.setTimeout(1000, () => {
			socket.destroy()
			settle(false)
		})
	})
}

function runEntrypoint(args, env = {}) {
	return new Promise((settle) => {
		const child = spawn(process.execPath, [ENTRYPOINT, ...args], {
			cwd: PACKAGE_ROOT,
			env: { ...process.env, ...FIXTURE_ENV, ...env },
			stdio: ['ignore', 'pipe', 'pipe'],
		})
		let stdout = ''
		let stderr = ''
		child.stdout.on('data', (chunk) => {
			stdout += chunk
		})
		child.stderr.on('data', (chunk) => {
			stderr += chunk
		})
		child.on('close', (code) => settle({ code, stdout, stderr }))
		setTimeout(() => child.kill('SIGKILL'), 10_000).unref()
	})
}

before(async () => {
	Object.assign(process.env, FIXTURE_ENV)
	const { resolveHttpExposure } = await distImport('transport', 'http-config.js')
	const { createHttpApplication } = await distImport('transport', 'http.js')
	const { createMcpServerFactory, resolveServerContext } = await distImport('server.js')
	config = resolveHttpExposure({
		profile: 'private',
		host: '0.0.0.0',
		allowedHosts: ['mcp.internal'],
		allowedOrigins: ['console.internal'],
		bearerKey: KEY,
	})
	factory = createMcpServerFactory(resolveServerContext(), 'full')
	application = createHttpApplication(factory, config)
	await new Promise((ready) => {
		listener = application.app.listen(0, config.host, () => {
			baseUrl = `http://127.0.0.1:${listener.address().port}`
			ready()
		})
	})
})

after(async () => {
	await new Promise((closed) => listener.close(closed))
	await application.mcp.close()
	for (const key of Object.keys(FIXTURE_ENV)) delete process.env[key]
})

describe('built HTTP entrypoint profiles', () => {
	it('refuses a non-loopback local profile before binding', async () => {
		const port = 39_517
		const result = await runEntrypoint([
			'--transport',
			'http',
			'--http-profile',
			'local',
			'--host',
			'0.0.0.0',
			'--port',
			String(port),
		])

		assert.equal(result.code, 1)
		assert.match(result.stderr, /loopback/)
		assert.equal(result.stdout, '')
		assert.equal(await isListening(port), false)
	})

	it('refuses an incomplete private profile before binding', async () => {
		const port = 39_518
		const result = await runEntrypoint(
			[
				'--transport',
				'http',
				'--http-profile',
				'private',
				'--host',
				'0.0.0.0',
				'--allowed-hosts',
				'mcp.internal',
				'--port',
				String(port),
			],
			{ FLUENTCART_MCP_API_KEY: KEY },
		)

		assert.equal(result.code, 1)
		assert.match(result.stderr, /allowed origins/i)
		assert.equal(result.stdout, '')
		assert.equal(await isListening(port), false)
	})

	it('consumes Docker private allowlists from the environment before discovery', async () => {
		const result = await runEntrypoint(
			['--transport', 'http', '--port', '0', '--host', '0.0.0.0', '--http-profile', 'private'],
			{
				FLUENTCART_MCP_API_KEY: KEY,
				FLUENTCART_MCP_ALLOWED_HOSTS: 'mcp.internal',
				FLUENTCART_MCP_ALLOWED_ORIGINS: 'console.internal',
			},
		)

		assert.equal(result.code, 1)
		assert.match(result.stderr, /fixture\.invalid/)
		assert.doesNotMatch(result.stderr, /explicit allowed (hosts|origins)/i)
		assert.equal(result.stdout, '')
	})
})

describe('built private HTTP boundary', () => {
	it('accepts the configured key and keeps the response non-cacheable', async () => {
		const response = await rpc({ Authorization: `Bearer ${KEY}` })
		assert.equal(response.status, 200)
		assert.equal(response.headers.get('cache-control'), 'no-store')
		assert.doesNotMatch(await response.text(), new RegExp(KEY))
	})

	it('returns one generic Bearer challenge for missing, malformed and wrong keys', async () => {
		const responses = await Promise.all([
			rpc(),
			rpc({ Authorization: KEY }),
			rpc({ Authorization: `Basic ${KEY}` }),
			rpc({ Authorization: `Bearer ${WRONG_KEY}` }),
		])
		const outcomes = []
		for (const response of responses) {
			outcomes.push({
				status: response.status,
				challenge: response.headers.get('www-authenticate'),
				cache: response.headers.get('cache-control'),
				body: await response.text(),
			})
		}
		assert.deepEqual(
			new Set(outcomes.map(({ body }) => body)),
			new Set(['{"error":"Unauthorized"}']),
		)
		for (const outcome of outcomes) {
			assert.equal(outcome.status, 401)
			assert.equal(outcome.challenge, 'Bearer')
			assert.equal(outcome.cache, 'no-store')
		}
	})

	it('orders Host and Origin rejection before body handling', async () => {
		const invalidHost = await rpc(
			{ Host: 'evil.example', Authorization: `Bearer ${KEY}` },
			'{"broken":',
		)
		const invalidOrigin = await rpc({
			Origin: 'https://evil.example',
			Authorization: `Bearer ${KEY}`,
		})
		const absentOrigin = await rpc({ Authorization: `Bearer ${KEY}` })

		assert.equal(invalidHost.status, 403)
		assert.equal(invalidOrigin.status, 403)
		assert.equal(absentOrigin.status, 200)
	})

	it('bounds malformed and oversized JSON errors and leaves only health public', async () => {
		const malformed = await rpc({ Authorization: `Bearer ${KEY}` }, '{"jsonrpc":')
		const oversized = await rpc(
			{ Authorization: `Bearer ${KEY}` },
			JSON.stringify({ pad: 'x'.repeat(101 * 1024) }),
		)
		const health = await fetch(`${baseUrl}/health`)
		const ready = await fetch(`${baseUrl}/ready`)

		assert.equal(malformed.status, 400)
		assert.ok((await malformed.text()).length < 256)
		assert.equal(oversized.status, 413)
		assert.ok((await oversized.text()).length < 256)
		assert.deepEqual(await health.json(), { status: 'ok' })
		assert.equal(ready.status, 404)
	})
})

describe('built HTTP service handle', () => {
	it('closes its listener and handler as one bounded service', async () => {
		const { startHttpService } = await distImport('transport', 'http.js')
		const handle = await startHttpService(factory, 0, config, { drainMs: 20 })
		const port = Number(new URL(handle.url).port)
		const loopbackUrl = `http://127.0.0.1:${port}`
		assert.equal(await isListening(port), true)

		await handle.close()
		assert.equal(await isListening(port), false)
		await assert.rejects(fetch(`${loopbackUrl}/health`))
	})
})
