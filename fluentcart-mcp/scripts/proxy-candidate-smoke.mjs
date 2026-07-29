import assert from 'node:assert/strict'
import { randomBytes } from 'node:crypto'
import { copyFileSync, mkdtempSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join, resolve } from 'node:path'
import { setTimeout as delay } from 'node:timers/promises'
import { fileURLToPath } from 'node:url'
import {
	assessCandidateProxyResult,
	verifyCandidateImageIdentity,
} from './proxy-candidate-contract.mjs'
import {
	command,
	compose as composeRuntime,
	generateCertificate,
	observation,
	openRequest as openRequestRuntime,
	reservePort,
	rpc,
	topology,
	waitForProxy,
	within,
} from './proxy-candidate-runtime.mjs'

const PACKAGE_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const COMPOSE_FILE = join(PACKAGE_ROOT, 'tests/fixtures/proxy/docker-compose.yml')
const SERVER_NAME = 'mcp.fixture.test'
const API_KEY = `proxy-candidate-${randomBytes(24).toString('hex')}`
const CLIENT_INFO = { name: 'proxy-candidate-smoke', version: '1.0.0' }
const MODERN_META = {
	'io.modelcontextprotocol/protocolVersion': '2026-07-28',
	'io.modelcontextprotocol/clientInfo': CLIENT_INFO,
	'io.modelcontextprotocol/clientCapabilities': {},
}
const JSON_HEADERS = {
	Accept: 'application/json, text/event-stream',
	'Content-Type': 'application/json',
	'Mcp-Protocol-Version': '2026-07-28',
}

function compose(project, env, args) {
	return composeRuntime(PACKAGE_ROOT, COMPOSE_FILE, project, env, args)
}

function openRequest(port, cert, path, options = {}) {
	return openRequestRuntime(port, cert, SERVER_NAME, API_KEY, path, options)
}

function request(port, cert, path, options) {
	return openRequest(port, cert, path, options).result
}

export function modernProxyRequest(id, method, params = {}) {
	return {
		headers: {
			...JSON_HEADERS,
			'Mcp-Method': method,
			...(method === 'tools/call' && params.name ? { 'Mcp-Name': params.name } : {}),
		},
		body: rpc(id, method, { _meta: MODERN_META, ...params }),
	}
}

function toolCall(id) {
	return modernProxyRequest(id, 'tools/call', {
		name: 'fluentcart_execute_read_tool',
		arguments: {
			tool_name: 'fluentcart_order_list',
			input: { page: 1, per_page: 1 },
		},
	})
}

async function forwardingObservations(port, cert, discovery) {
	const correct = await request(port, cert, '/mcp', {
		method: 'POST',
		headers: discovery.headers,
		body: discovery.body,
	})
	const wrongAuth = await request(port, cert, '/mcp', {
		method: 'POST',
		headers: { ...discovery.headers, Authorization: 'Bearer definitely-wrong' },
		body: discovery.body,
	})
	const wrongHost = await request(port, cert, '/mcp', {
		method: 'POST',
		headers: { ...discovery.headers, Host: 'wrong.invalid' },
		body: discovery.body,
	})
	const wrongOrigin = await request(port, cert, '/mcp', {
		method: 'POST',
		headers: { ...discovery.headers, Origin: 'https://wrong.invalid' },
		body: discovery.body,
	})
	return { correct, wrongAuth, wrongHost, wrongOrigin }
}

async function candidateBehaviours(port, cert, imageId, fixture) {
	const discovery = modernProxyRequest(1, 'server/discover')
	const forwarded = await forwardingObservations(port, cert, discovery)
	const blocked = fixture.blockNextRead()
	const read = toolCall(2)
	const pending = openRequest(port, cert, '/mcp', {
		method: 'POST',
		headers: read.headers,
		body: read.body,
		timeoutMs: 0,
	})
	await within(
		Promise.race([
			blocked.started,
			pending.result.then((response) => {
				throw new Error(
					`candidate read completed before fixture: HTTP ${response.status}`,
				)
			}),
		]),
		'candidate upstream start',
	)
	pending.active.destroy()
	await within(blocked.cancelled, 'candidate upstream cancellation')
	await pending.result
	await delay(1_200)
	const reconnect = await request(port, cert, '/mcp', {
		method: 'POST',
		headers: discovery.headers,
		body: discovery.body,
	})
	const oversizedHeaders = toolCall(3).headers
	const oversized = await request(port, cert, '/mcp', {
		method: 'POST',
		headers: oversizedHeaders,
		body: Buffer.alloc(65 * 1024, 'x'),
	})
	await delay(1_200)
	fixture.holdReads()
	const held = Array.from({ length: 6 }, (_, index) => {
		const call = toolCall(100 + index)
		return openRequest(port, cert, '/mcp', {
			method: 'POST',
			headers: call.headers,
			body: call.body,
			timeoutMs: 0,
		})
	})
	await fixture.waitForHeld(2)
	await delay(250)
	const connectionRejected = held.some(({ state }) => state.status === 509)
	for (const entry of held) entry.active.destroy()
	fixture.releaseHeld()
	await Promise.all(held.map(({ result }) => result))
	await delay(1_200)
	const burst = await Promise.all(
		Array.from({ length: 30 }, () => request(port, cert, '/mcp/health')),
	)
	const observations = {
		tls: observation(imageId, forwarded.correct.tls === true),
		forwarding: observation(
			imageId,
			forwarded.correct.status === 200 &&
				forwarded.wrongAuth.status === 401 &&
				forwarded.wrongHost.status === 403 &&
				forwarded.wrongOrigin.status === 403,
			{
				statuses: {
					correct: forwarded.correct.status,
					wrongAuth: forwarded.wrongAuth.status,
					wrongHost: forwarded.wrongHost.status,
					wrongOrigin: forwarded.wrongOrigin.status,
				},
			},
		),
		streaming: observation(imageId, forwarded.correct.firstChunkBeforeCompletion === true),
		cancellation: observation(imageId, true),
		reconnect: observation(imageId, reconnect.status === 200),
		oversizedBody: observation(imageId, oversized.status === 413, { status: oversized.status }),
		rateLimit: observation(imageId, burst.some(({ status }) => status === 429)),
		connectionLimit: observation(imageId, connectionRejected),
	}
	return observations
}

export async function verifyCandidateProxy({ image, expectedIdentity, fixture }) {
	assert.ok(image, 'candidate proxy requires FLUENTCART_ACCEPTANCE_IMAGE')
	const imageInspect = JSON.parse(command('docker', ['image', 'inspect', image]))[0]
	const identity = verifyCandidateImageIdentity(imageInspect, expectedIdentity)
	if (!fixture) return { candidateBacked: true, identity, observations: {} }
	const directory = mkdtempSync(join(tmpdir(), 'fcmcp-proxy-candidate-'))
	const project = `fcmcp-proxy-candidate-${process.pid}-${randomBytes(3).toString('hex')}`
	const port = await reservePort()
	const { cert, key } = generateCertificate(directory, SERVER_NAME)
	const nginxConfig = join(directory, 'nginx.conf')
	copyFileSync(join(PACKAGE_ROOT, 'tests/fixtures/proxy/nginx.conf'), nginxConfig)
	const env = {
		...process.env,
		FLUENTCART_PROXY_CERT: cert,
		FLUENTCART_PROXY_KEY: key,
		FLUENTCART_PROXY_NGINX_CONFIG: nginxConfig,
		FLUENTCART_PROXY_PORT: String(port),
		FLUENTCART_PROXY_BACKEND_IMAGE: image,
		FLUENTCART_URL: fixture.url,
		FLUENTCART_USERNAME: 'candidate-fixture',
		FLUENTCART_APP_PASSWORD: 'candidate-fixture',
		FLUENTCART_MCP_API_KEY: API_KEY,
	}
	try {
		compose(project, env, ['up', '-d', '--pull', 'never'])
		const readiness = await waitForProxy(request, port, cert)
		const network = topology(compose, project, env, expectedIdentity.imageId)
		assert.equal(network.privateProfile, true)
		assert.equal(network.published, false)
		const observations = await candidateBehaviours(
			port,
			cert,
			expectedIdentity.imageId,
			fixture,
		)
		const result = { candidateBacked: true, identity, readiness, observations }
		return { ...result, assessment: assessCandidateProxyResult(result, expectedIdentity) }
	} finally {
		try {
			compose(project, env, ['down', '--volumes', '--remove-orphans'])
		} finally {
			rmSync(directory, { recursive: true, force: true })
		}
	}
}
