// Transport and principal boundaries, exercised against the built server.
//
// Two properties matter more than any status code here. The first is that a misconfigured public
// bind dies before a socket exists, so there is never a window in which an unauthenticated store
// administration API is reachable. The second is that every rejection looks identical: a caller
// must not be able to tell a wrong key from a missing one, because that difference is an oracle.

import assert from 'node:assert/strict'
import { spawn } from 'node:child_process'
import { connect } from 'node:net'
import { dirname, join, resolve } from 'node:path'
import { after, before, describe, it } from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'

const PACKAGE_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..')
const ENTRYPOINT = join(PACKAGE_ROOT, 'dist', 'index.js')
const API_KEY = 'acceptance-transport-key-of-sufficient-length'
const WRONG_KEY = 'x'.repeat(API_KEY.length)
const UNAUTHORIZED = '{"error":"Unauthorized"}'

// A store host that cannot resolve, so nothing here can reach a real shop by accident.
const FIXTURE_ENV = { FLUENTCART_URL: 'https://fixture.invalid', FLUENTCART_USERNAME: 'fixture' }
FIXTURE_ENV.FLUENTCART_APP_PASSWORD = 'fixture'
FIXTURE_ENV.FLUENTCART_WRITE_MODE = 'disabled'

const CLIENT = { name: 'acceptance-transport', version: '1.0.0' }
const PARAMS = { protocolVersion: '2025-03-26', capabilities: {}, clientInfo: CLIENT }
const INITIALIZE = { jsonrpc: '2.0', id: 1, method: 'initialize', params: PARAMS }

let auth
let baseUrl
let server

function distImport(...segments) {
	return import(pathToFileURL(join(PACKAGE_ROOT, 'dist', ...segments)).href)
}

function mcp(headers, body = INITIALIZE) {
	return fetch(`${baseUrl}/mcp`, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			Accept: 'application/json, text/event-stream',
			...headers,
		},
		body: typeof body === 'string' ? body : JSON.stringify(body),
	})
}

/** Run the built entrypoint to completion and capture both streams separately. */
function runEntrypoint(args, env) {
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
		setTimeout(() => child.kill('SIGKILL'), 20_000).unref()
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
		socket.setTimeout(2000, () => {
			socket.destroy()
			settle(false)
		})
	})
}

before(async () => {
	Object.assign(process.env, FIXTURE_ENV)
	process.env.FLUENTCART_MCP_API_KEY = API_KEY
	auth = await distImport('transport', 'auth.js')
	const { createApp } = await distImport('transport', 'http.js')

	const app = createApp('127.0.0.1')
	await new Promise((ready) => {
		server = app.listen(0, '127.0.0.1', () => {
			baseUrl = `http://127.0.0.1:${server.address().port}`
			ready()
		})
	})
})

after(() => {
	server?.close()
	delete process.env.FLUENTCART_MCP_API_KEY
})

describe('exposure policy', () => {
	it('permits every loopback form with no key at all', () => {
		for (const host of ['127.0.0.1', '::1', '[::1]', 'localhost', 'LOCALHOST', ' localhost ']) {
			assert.doesNotThrow(() => auth.assertSafeHttpExposure(host), host)
		}
	})

	it('refuses a wildcard or non-loopback bind without a key', () => {
		for (const host of ['0.0.0.0', '::', '192.168.1.10', 'mcp.example.com']) {
			assert.throws(() => auth.assertSafeHttpExposure(host), /Refusing to bind/, host)
		}
	})

	it('refuses a loopback lookalike, which is somebody else’s domain', () => {
		assert.throws(() => auth.assertSafeHttpExposure('localhost.example.com'), /Refusing to bind/)
		assert.throws(() => auth.assertSafeHttpExposure('notlocalhost'), /Refusing to bind/)
	})

	it('refuses a key shorter than 32 characters, padding included', () => {
		assert.throws(() => auth.assertSafeHttpExposure('0.0.0.0', 'short'), /at least 32/)
		assert.throws(() => auth.assertSafeHttpExposure('0.0.0.0', `${' '.repeat(40)}k`), /at least 32/)
		assert.throws(
			() => auth.assertSafeHttpExposure('0.0.0.0', ''),
			/requires FLUENTCART_MCP_API_KEY/,
		)
	})

	it('permits a public bind once a strong key exists, and names the host when it refuses', () => {
		assert.doesNotThrow(() => auth.assertSafeHttpExposure('0.0.0.0', API_KEY))
		assert.throws(() => auth.assertSafeHttpExposure('10.0.0.5'), /10\.0\.0\.5/)
	})
})

describe('built entrypoint startup', () => {
	it('exits 1 before binding when a public bind has no key', async () => {
		const port = 39_517
		const result = await runEntrypoint(
			['--transport', 'http', '--host', '0.0.0.0', '--port', String(port)],
			{ FLUENTCART_MCP_API_KEY: '' },
		)
		assert.equal(result.code, 1)
		assert.match(result.stderr, /Refusing to bind 0\.0\.0\.0/)
		assert.equal(result.stdout, '', 'stdout is reserved for JSON-RPC')
		assert.equal(await isListening(port), false, 'nothing may listen after a refused exposure')
	})

	it('keeps stdout free of anything but JSON-RPC when startup fails', async () => {
		// The store host is unresolvable, so discovery fails and the process reports and exits.
		const result = await runEntrypoint([], {})
		assert.equal(result.code, 1)
		assert.equal(result.stdout, '', 'a diagnostic on stdout would corrupt the JSON-RPC stream')
		assert.ok(result.stderr.length > 0, 'the reason must still be reported, on stderr')
	})

	it('answers --version on stdout and stops', async () => {
		const result = await runEntrypoint(['--version'], {})
		assert.equal(result.code, 0)
		assert.match(result.stdout.trim(), /^\d+\.\d+\.\d+/)
	})
})

describe('bearer authentication', () => {
	it('accepts the configured key', async () => {
		const response = await mcp({ Authorization: `Bearer ${API_KEY}` })
		assert.equal(response.status, 200)
		assert.match(await response.text(), /"serverInfo"/)
	})

	it('rejects a missing, malformed and wrong key with one identical body', async () => {
		const responses = await Promise.all([
			mcp({}),
			mcp({ Authorization: API_KEY }),
			mcp({ Authorization: `Basic ${Buffer.from('a:b').toString('base64')}` }),
			mcp({ Authorization: `Bearer ${WRONG_KEY}` }),
			mcp({ Authorization: 'Bearer ' }),
		])

		const bodies = new Set()
		for (const response of responses) {
			assert.equal(response.status, 401)
			bodies.add((await response.text()).trim())
		}
		assert.deepEqual([...bodies], [UNAUTHORIZED], 'every rejection must be indistinguishable')
		// The same-length wrong key above also proves the comparison never echoes the real one.
		assert.ok(![...bodies].some((body) => body.includes(API_KEY)))
	})

	it('guards /mcp but leaves /health open for a liveness probe', async () => {
		const health = await fetch(`${baseUrl}/health`)
		assert.equal(health.status, 200)
		assert.deepEqual(await health.json(), { status: 'ok' })
	})
})

describe('adversarial requests', () => {
	it('refuses malformed JSON-RPC with 400 and stays up', async () => {
		const response = await mcp({ Authorization: `Bearer ${API_KEY}` }, { not: 'a message' })
		assert.equal(response.status, 400)
		const alive = await fetch(`${baseUrl}/health`)
		assert.equal(alive.status, 200)
	})

	it('refuses a body that is not JSON at all', async () => {
		const response = await mcp({ Authorization: `Bearer ${API_KEY}` }, '{"jsonrpc": ')
		assert.ok(response.status >= 400 && response.status < 500, `got ${response.status}`)
	})

	it('refuses an oversized body rather than buffering it', async () => {
		const huge = JSON.stringify({ ...INITIALIZE, params: { pad: 'p'.repeat(8 * 1024 * 1024) } })
		const response = await mcp({ Authorization: `Bearer ${API_KEY}` }, huge)
		assert.ok(response.status >= 400, `an 8 MB body must be refused, got ${response.status}`)
		assert.equal((await fetch(`${baseUrl}/health`)).status, 200, 'the server must survive it')
	})

	it('requires an event-stream-capable Accept header', async () => {
		const response = await fetch(`${baseUrl}/mcp`, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${API_KEY}` },
			body: JSON.stringify(INITIALIZE),
		})
		assert.equal(response.status, 406)
	})

	it('does not support session termination in stateless mode', async () => {
		const response = await fetch(`${baseUrl}/mcp`, {
			method: 'DELETE',
			headers: { Authorization: `Bearer ${API_KEY}` },
		})
		assert.equal(response.status, 405)
	})

	it('serves concurrent sessions independently', async () => {
		const responses = await Promise.all(
			Array.from({ length: 6 }, (_, index) =>
				mcp({ Authorization: `Bearer ${API_KEY}` }, { ...INITIALIZE, id: index + 1 }),
			),
		)
		for (const response of responses) assert.equal(response.status, 200)
	})

	it('survives an aborted request', async () => {
		const controller = new AbortController()
		const inFlight = fetch(`${baseUrl}/mcp`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json, text/event-stream',
				Authorization: `Bearer ${API_KEY}`,
			},
			body: JSON.stringify(INITIALIZE),
			signal: controller.signal,
		})
		controller.abort()
		await inFlight.catch(() => undefined)

		const after = await mcp({ Authorization: `Bearer ${API_KEY}` })
		assert.equal(after.status, 200, 'an aborted request must not poison the next one')
	})
})

describe('shutdown', () => {
	it('stops accepting connections once closed', async () => {
		const { createApp } = await distImport('transport', 'http.js')
		const app = createApp('127.0.0.1')
		const temporary = await new Promise((ready) => {
			const listener = app.listen(0, '127.0.0.1', () => ready(listener))
		})
		const port = temporary.address().port
		assert.equal(await isListening(port), true)

		await new Promise((closed) => temporary.close(closed))
		assert.equal(await isListening(port), false, 'the port must be free after shutdown')
	})
})
