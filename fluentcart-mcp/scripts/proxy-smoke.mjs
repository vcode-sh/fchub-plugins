#!/usr/bin/env node

import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { copyFileSync, mkdtempSync, readFileSync, rmSync } from 'node:fs'
import { createServer } from 'node:net'
import { tmpdir } from 'node:os'
import { dirname, isAbsolute, join, relative, resolve } from 'node:path'
import { setTimeout as delay } from 'node:timers/promises'
import { fileURLToPath } from 'node:url'
import https from 'node:https'
import { verifyCandidateProxy } from './proxy-candidate-smoke.mjs'
export {
	assessCandidateProxyResult,
	verifyCandidateImageIdentity,
} from './proxy-candidate-contract.mjs'
export { verifyCandidateProxy }

export const PROXY_IMAGE =
	'nginx:1.29.4-alpine@sha256:4870c12cd2ca986de501a804b4f506ad3875a0b1874940ba0a2c7f763f1855b2'

const PACKAGE_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const COMPOSE_FILE = join(PACKAGE_ROOT, 'tests/fixtures/proxy/docker-compose.yml')
const SERVER_NAME = 'mcp.fixture.test', API_KEY = `proxy-smoke-${randomBytes(24).toString('hex')}`

function command(binary, args, options = {}) {
	const result = spawnSync(binary, args, { cwd: PACKAGE_ROOT, encoding: 'utf8', timeout: 60_000, ...options })
	if (result.status !== 0) throw new Error(`${binary} ${args.join(' ')} failed: ${result.stderr || result.stdout}`)
	return result.stdout.trim()
}

async function reservePort() {
	const server = createServer()
	await new Promise((ready, reject) => {
		server.once('error', reject); server.listen(0, '127.0.0.1', ready)
	})
	const address = server.address()
	assert.ok(address && typeof address !== 'string')
	await new Promise((closed) => server.close(closed))
	return address.port
}

function generateCertificate(directory) {
	const cert = join(directory, 'proxy.crt'), key = join(directory, 'proxy.key')
	command('openssl', [
		'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
		'-subj', `/CN=${SERVER_NAME}`, '-addext', `subjectAltName=DNS:${SERVER_NAME}`,
		'-keyout', key, '-out', cert,
	])
	return { cert, key }
}

function composeEnvironment({ cert, key, nginxConfig, port, backendImage }) {
	return {
		...process.env,
		FLUENTCART_PROXY_CERT: cert,
		FLUENTCART_PROXY_KEY: key,
		FLUENTCART_PROXY_NGINX_CONFIG: nginxConfig,
		FLUENTCART_PROXY_PORT: String(port),
		FLUENTCART_PROXY_BACKEND_IMAGE: backendImage,
		FLUENTCART_URL: process.env.FLUENTCART_URL ?? 'https://fixture.invalid',
		FLUENTCART_USERNAME: process.env.FLUENTCART_USERNAME ?? 'fixture',
		FLUENTCART_APP_PASSWORD: process.env.FLUENTCART_APP_PASSWORD ?? 'fixture',
		FLUENTCART_MCP_API_KEY: API_KEY,
	}
}

function compose(files, project, env, args) {
	const fileArgs = files.flatMap((file) => ['-f', file])
	return command('docker', ['compose', '--project-name', project, ...fileArgs, ...args], { env })
}

function tlsOptions(port, cert, path, headers = {}) {
	return {
		hostname: '127.0.0.1', port, path, servername: SERVER_NAME, ca: readFileSync(cert),
		headers: {
			Host: SERVER_NAME, Origin: `https://${SERVER_NAME}`,
			Authorization: `Bearer ${API_KEY}`, ...headers,
		},
	}
}

function request(port, cert, path, options = {}) {
	return new Promise((settle, reject) => {
		const request = https.request(
			{
				...tlsOptions(port, cert, path, options.headers),
				method: options.method ?? 'GET',
			},
			(response) => {
				const tlsAuthorized = response.socket?.authorized === true
				const chunks = []
				response.on('data', (chunk) => chunks.push(chunk))
				response.on('end', () => settle({
					status: response.statusCode, body: Buffer.concat(chunks).toString(), tls: tlsAuthorized,
				}))
			},
		)
		request.once('error', reject)
		if (options.body) request.write(options.body)
		request.end()
	})
}

async function waitForProxy(port, cert) {
	let lastStatus = null
	for (let attempt = 0; attempt < 40; attempt += 1) {
		try {
			const response = await request(port, cert, '/health')
			lastStatus = response.status
			if (response.status === 200 && response.tls) {
				return { path: '/health', status: response.status }
			}
		} catch {
		}
		await delay(100)
	}
	throw new Error(`candidate-shaped health did not return 200 through the TLS proxy (last ${lastStatus})`)
}

function inspectTopology(files, project, env) {
	const resolved = JSON.parse(compose(files, project, env, ['config', '--format', 'json']))
	assert.equal(resolved.services.proxy.image, PROXY_IMAGE)
	assert.deepEqual(resolved.services.backend.ports ?? [], [])
	const command = resolved.services.backend.command.join(' ')
	assert.match(command, /--http-profile private/)
	const backendId = compose(files, project, env, ['ps', '-q', 'backend'])
	const inspect = JSON.parse(commandDocker(['inspect', backendId]))[0]
	const published = Object.values(inspect.NetworkSettings.Ports ?? {}).some(Boolean)
	return { privateProfile: true, published }
}

function commandDocker(args) { return command('docker', args) }

async function streamAndCancel(port, cert, files, project, env) {
	let firstChunkBeforeCompletion = false
	await new Promise((settle, reject) => {
		const active = https.request(tlsOptions(port, cert, '/mcp/stream'))
		active.once('response', (response) => {
			response.once('data', () => {
				firstChunkBeforeCompletion = !response.complete
				response.destroy()
				active.destroy()
				settle()
			})
		})
		active.once('error', (error) => {
			if (error.code === 'ECONNRESET') settle()
			else reject(error)
		})
		active.end()
	})

	let cancelledUpstream = false
	for (let attempt = 0; attempt < 30; attempt += 1) {
		const logs = compose(files, project, env, ['logs', '--no-color', 'backend'])
		const timing = /\/mcp\/stream 200 ([0-9.]+)/.exec(logs)
		if (timing) {
			cancelledUpstream = Number(timing[1]) < 5
			break
		}
		await delay(100)
	}
	await delay(1200)
	const reconnected = (await request(port, cert, '/mcp/ping')).status === 204
	return { firstChunkBeforeCompletion, cancelledUpstream, reconnected }
}

function openHeldRequest(port, cert) {
	return new Promise((settle, reject) => {
		const active = https.request(tlsOptions(port, cert, '/mcp/hold'))
		active.once('response', (response) => {
			response.pause()
			settle({ status: response.statusCode, close: () => active.destroy() })
		})
		active.once('error', reject)
		active.end()
	})
}

async function exerciseLimits(port, cert) {
	const oversized = await request(port, cert, '/mcp/headers', {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: Buffer.alloc(65 * 1024, 'x'),
	})
	await delay(1200)
	const held = await Promise.all(Array.from({ length: 6 }, () => openHeldRequest(port, cert)))
	const connectionRejected = held.some(({ status }) => status === 509)
	for (const connection of held) connection.close()
	await delay(1200)
	const burst = await Promise.all(
		Array.from({ length: 30 }, () => request(port, cert, '/mcp/ping').catch(() => ({ status: 0 }))),
	)
	return {
		oversizedStatus: oversized.status,
		connectionRejected,
		rateRejected: burst.some(({ status }) => status === 429),
	}
}

export async function runProxySmoke({ prepareFixture } = {}) {
	if (!prepareFixture) throw new Error('candidate proxy certification requires a candidate-specific image fixture')
	const directory = mkdtempSync(join(tmpdir(), 'fcmcp-proxy-'))
	const port = await reservePort()
	const project = `fcmcp-proxy-${process.pid}-${randomBytes(3).toString('hex')}`
	const { cert, key } = generateCertificate(directory)
	const nginxConfig = join(directory, 'nginx.conf')
	copyFileSync(join(PACKAGE_ROOT, 'tests/fixtures/proxy/nginx.conf'), nginxConfig)
	const override = prepareFixture(directory)
	const files = [COMPOSE_FILE, override]
	const env = composeEnvironment({ cert, key, nginxConfig, port, backendImage: PROXY_IMAGE })
	try {
		compose(files, project, env, ['up', '-d', '--pull', 'never'])
		const readiness = await waitForProxy(port, cert)
		const topology = inspectTopology([COMPOSE_FILE], project, env)
		const headers = await request(port, cert, '/mcp/headers')
		const expected = `Bearer ${API_KEY}|${SERVER_NAME}|https://${SERVER_NAME}|https`
		return {
			schemaVersion: 1,
			readiness,
			proxyImage: PROXY_IMAGE,
			tlsVerified: headers.tls,
			certificateInTrackedSource: isAbsolute(cert) && !relative(PACKAGE_ROOT, cert).startsWith('..'),
			backendPrivateProfileConfigured: topology.privateProfile,
			backendHostPublished: topology.published,
			forwarded: {
				authorization: headers.body.includes(`Bearer ${API_KEY}`),
				host: headers.body.includes(`|${SERVER_NAME}|`),
				origin: headers.body.includes(`|https://${SERVER_NAME}|`),
				proto: headers.body === expected,
			},
			streaming: await streamAndCancel(port, cert, files, project, env),
			limits: await exerciseLimits(port, cert),
		}
	} finally {
		try {
			compose(files, project, env, ['down', '--volumes', '--remove-orphans'])
		} finally {
			rmSync(directory, { recursive: true, force: true })
		}
	}
}

const direct = process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)
if (direct) {
	const result = await verifyCandidateProxy({
		image: process.env.FLUENTCART_ACCEPTANCE_IMAGE,
		expectedIdentity: {
			imageId: process.env.FLUENTCART_ACCEPTANCE_IMAGE_ID,
			imageDigest: process.env.FLUENTCART_ACCEPTANCE_IMAGE_DIGEST,
			candidateContentDigest: process.env.FLUENTCART_ACCEPTANCE_CONTENT_DIGEST,
			sourceSha: process.env.FLUENTCART_ACCEPTANCE_SOURCE_SHA ?? null,
		},
	})
	process.stdout.write(`${JSON.stringify(result, null, process.argv.includes('--json') ? 0 : 2)}\n`)
}
