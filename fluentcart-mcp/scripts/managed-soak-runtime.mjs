import assert from 'node:assert/strict'
import { randomBytes } from 'node:crypto'
import { copyFileSync, existsSync, mkdtempSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { CandidateStore } from './candidate-store.mjs'
import { verifyCandidateImageIdentity } from './proxy-candidate-contract.mjs'
import {
	command,
	compose as composeRuntime,
	generateCertificate,
	openRequest,
	reservePort,
	topology,
	waitForProxy,
} from './proxy-candidate-runtime.mjs'
import {
	captureProxyTimingLog,
	parseProxyTimingLog,
} from './soak-proxy-timing.mjs'

const PACKAGE_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const COMPOSE_FILE = join(PACKAGE_ROOT, 'tests/fixtures/proxy/docker-compose.yml')
const NGINX_CONFIG = join(PACKAGE_ROOT, 'tests/fixtures/proxy/nginx.conf')
export const SOAK_PROXY_HOST = 'mcp.fixture.test'

function inspectImage(image) {
	return JSON.parse(command('docker', ['image', 'inspect', image]))[0]
}

function compose(project, env, args, options) {
	return composeRuntime(PACKAGE_ROOT, COMPOSE_FILE, project, env, args, options)
}

function projectIds(kind, project) {
	const args = {
		container: ['ps', '-aq'],
		network: ['network', 'ls', '-q'],
		volume: ['volume', 'ls', '-q'],
	}[kind]
	assert.ok(args, `unsupported Docker resource kind: ${kind}`)
	const output = command('docker', [
		...args,
		'--filter',
		`label=com.docker.compose.project=${project}`,
	], { timeout: 10_000 })
	return output.split('\n').filter(Boolean)
}

function removeProjectResidue(project) {
	const containers = projectIds('container', project)
	if (containers.length > 0) command('docker', ['rm', '-f', ...containers], { timeout: 10_000 })
	const volumes = projectIds('volume', project)
	if (volumes.length > 0) command('docker', ['volume', 'rm', '-f', ...volumes], { timeout: 10_000 })
	const networks = projectIds('network', project)
	if (networks.length > 0) command('docker', ['network', 'rm', ...networks], { timeout: 10_000 })
	assert.deepEqual(projectIds('container', project), [], 'managed soak containers survived cleanup')
	assert.deepEqual(projectIds('volume', project), [], 'managed soak volume survived cleanup')
	assert.deepEqual(projectIds('network', project), [], 'managed soak network survived cleanup')
}

function bounded(promise, label, timeoutMs = 5_000) {
	let timer
	return Promise.race([
		promise,
		new Promise((_, reject) => {
			timer = setTimeout(() => reject(new Error(`${label} timed out`)), timeoutMs)
		}),
	]).finally(() => clearTimeout(timer))
}

export async function closeManagedSoakRuntime(state, dependencies = {}) {
	const compose_ = dependencies.compose ?? compose
	const removeProjectResidue_ = dependencies.removeProjectResidue ?? removeProjectResidue
	if (state.closed) return
	state.closed = true
	let failure
	try {
		if (state.env) {
			compose_(
				state.project,
				state.env,
				['down', '--volumes', '--remove-orphans', '--timeout', '5'],
				{ timeout: 10_000 },
			)
		}
	} catch {
		// The exact fallback proof below owns the cleanup result. Compose may time out
		// after removing the resources, which is not a surviving-runtime failure.
	}
	try {
		removeProjectResidue_(state.project)
	} catch (error) {
		failure = error
	}
	try {
		if (state.store) await bounded(state.store.close(), 'managed store cleanup')
	} catch (error) {
		failure ??= error
	}
	if (state.directory) rmSync(state.directory, { recursive: true, force: true })
	assert.equal(existsSync(state.directory), false, 'managed certificate directory survived cleanup')
	if (failure) throw failure
}

export async function createManagedSoakRuntime({ image, expectedIdentity }) {
	assert.ok(image, 'managed soak requires an exact candidate image')
	const state = {
		closed: false,
		directory: mkdtempSync(join(tmpdir(), `fcmcp-soak-candidate-${process.pid}-`)),
		env: null,
		project: `fcmcp-soak-${process.pid}-${randomBytes(3).toString('hex')}`,
		proxyContainerId: null,
		store: new CandidateStore(),
	}
	try {
		const imageInspect = inspectImage(image)
		const identity = verifyCandidateImageIdentity(imageInspect, expectedIdentity)
		await state.store.start()
		const port = await reservePort()
		const { cert, key } = generateCertificate(state.directory, SOAK_PROXY_HOST)
		const nginxConfig = join(state.directory, 'nginx.conf')
		copyFileSync(NGINX_CONFIG, nginxConfig)
		const apiKey = `soak-candidate-${randomBytes(24).toString('hex')}`
		state.env = {
			...process.env,
			FLUENTCART_PROXY_CERT: cert,
			FLUENTCART_PROXY_KEY: key,
			FLUENTCART_PROXY_NGINX_CONFIG: nginxConfig,
			FLUENTCART_PROXY_PORT: String(port),
			FLUENTCART_PROXY_BACKEND_IMAGE: image,
			FLUENTCART_URL: state.store.url,
			FLUENTCART_USERNAME: 'candidate-fixture',
			FLUENTCART_APP_PASSWORD: 'candidate-fixture',
			FLUENTCART_MCP_API_KEY: apiKey,
			FLUENTCART_PROXY_ALLOWED_HOSTS: `127.0.0.1,${SOAK_PROXY_HOST}`,
			FLUENTCART_PROXY_ALLOWED_ORIGINS: `127.0.0.1,${SOAK_PROXY_HOST}`,
		}
		compose(state.project, state.env, ['up', '-d', '--pull', 'never'])
		const request = (requestPort, requestCert, path) =>
			openRequest(
				requestPort,
				requestCert,
				SOAK_PROXY_HOST,
				apiKey,
				path,
			).result
		const readiness = await waitForProxy(request, port, cert)
		const network = topology(compose, state.project, state.env, identity.imageId)
		state.proxyContainerId = compose(state.project, state.env, ['ps', '-q', 'proxy'])
		assert.match(state.proxyContainerId, /^[0-9a-f]{64}$/, 'managed soak proxy identity is ambiguous')
		assert.equal(network.privateProfile, true)
		assert.equal(network.published, false)
		return {
			descriptor: {
				image,
				identity,
				imageInspect,
				containerId: network.containerId,
				containerInspect: network.containerInspect,
				url: `https://127.0.0.1:${port}/mcp`,
				apiKey,
				caPath: cert,
				host: '127.0.0.1',
				proxyHost: SOAK_PROXY_HOST,
				readiness,
				topology: { privateProfile: true, published: false },
			},
			captureEvidence: () => {
				const proxyInspect = JSON.parse(
					command('docker', ['inspect', state.proxyContainerId], { timeout: 10_000 }),
				)[0]
				assert.equal(proxyInspect.Id, state.proxyContainerId, 'managed soak proxy identity changed')
				assert.equal(
					proxyInspect.Config?.Labels?.['com.docker.compose.project'],
					state.project,
					'managed soak proxy project identity changed',
				)
				assert.equal(
					proxyInspect.Config?.Labels?.['com.docker.compose.service'],
					'proxy',
					'managed soak proxy service identity changed',
				)
				const proxy = parseProxyTimingLog(
					captureProxyTimingLog(state.proxyContainerId),
				)
				return {
					proxy: proxy.proxy,
					upstream: proxy.upstream,
					candidateStore: state.store.timingSummary(),
				}
			},
			close: () => closeManagedSoakRuntime(state),
		}
	} catch (error) {
		try {
			await closeManagedSoakRuntime(state)
		} catch {
			// Preserve the startup failure; it identifies the prerequisite that never became ready.
		}
		throw error
	}
}

export class ManagedSoakSignalError extends Error {
	constructor(signal) {
		super(`managed soak received ${signal}`)
		this.signal = signal
	}
}

export async function runManagedSoakLifecycle({ startRuntime, execute, preserveSignal = false }) {
	const controller = new AbortController()
	let receivedSignal
	const handlers = Object.fromEntries(
		['SIGINT', 'SIGTERM'].map((signal) => [
			signal,
			() => {
				receivedSignal ??= signal
				if (!controller.signal.aborted) {
					controller.abort(new ManagedSoakSignalError(signal))
				}
			},
		]),
	)
	for (const [signal, handler] of Object.entries(handlers)) process.once(signal, handler)
	let runtime
	let result
	let failure
	try {
		runtime = await startRuntime({ signal: controller.signal })
		if (controller.signal.aborted) throw controller.signal.reason
		result = await execute(runtime.descriptor, { signal: controller.signal })
		if (runtime.captureEvidence) {
			result = {
				...result,
				componentTimings: {
					...(result.componentTimings ?? {}),
					...(await runtime.captureEvidence()),
				},
			}
		}
	} catch (error) {
		failure = error
	} finally {
		try {
			if (runtime) await runtime.close()
		} catch (error) {
			failure ??= error
		}
		for (const [signal, handler] of Object.entries(handlers)) {
			process.off(signal, handler)
		}
	}
	if (receivedSignal && preserveSignal) {
		process.kill(process.pid, receivedSignal)
		await new Promise(() => undefined)
	}
	if (failure) throw failure
	return result
}
