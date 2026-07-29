import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { readFileSync } from 'node:fs'
import https from 'node:https'
import { createServer } from 'node:net'
import { setTimeout as delay } from 'node:timers/promises'

export function command(binary, args, options = {}) {
	const result = spawnSync(binary, args, {
		encoding: 'utf8',
		timeout: 60_000,
		...options,
	})
	if (result.status !== 0) {
		throw new Error(`${binary} ${args.join(' ')} failed: ${result.stderr || result.stdout}`)
	}
	return result.stdout.trim()
}

export async function reservePort() {
	const server = createServer()
	await new Promise((ready, reject) => {
		server.once('error', reject)
		server.listen(0, '127.0.0.1', ready)
	})
	const address = server.address()
	assert.ok(address && typeof address !== 'string')
	await new Promise((closed) => server.close(closed))
	return address.port
}

export function generateCertificate(directory, serverName) {
	const cert = `${directory}/proxy.crt`
	const key = `${directory}/proxy.key`
	command('openssl', [
		'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
		'-subj', `/CN=${serverName}`,
		'-addext', `subjectAltName=DNS:${serverName},IP:127.0.0.1`,
		'-keyout', key, '-out', cert,
	])
	return { cert, key }
}

export function compose(packageRoot, composeFile, project, env, args, options = {}) {
	return command(
		'docker',
		['compose', '--project-name', project, '-f', composeFile, ...args],
		{ cwd: packageRoot, env, ...options },
	)
}

export function openRequest(port, cert, serverName, apiKey, path, options = {}) {
	const state = { status: null, firstChunkBeforeCompletion: false }
	let settle
	const result = new Promise((resolveResult) => {
		settle = resolveResult
	})
	const headers = {
		Host: serverName,
		Origin: `https://${serverName}`,
		Authorization: `Bearer ${apiKey}`,
		...options.headers,
	}
	const active = https.request(
		{
			hostname: '127.0.0.1',
			port,
			path,
			servername: serverName,
			ca: readFileSync(cert),
			method: options.method ?? 'GET',
			headers,
		},
		(response) => {
			state.status = response.statusCode
			state.tls = response.socket?.authorized === true
			state.headers = response.headers
			const chunks = []
			response.on('data', (chunk) => {
				state.firstChunkBeforeCompletion ||= !response.complete
				chunks.push(chunk)
			})
			response.on('end', () => {
				state.body = Buffer.concat(chunks).toString()
				settle(state)
			})
		},
	)
	active.once('error', (error) => {
		state.error = error.code ?? error.message
		settle(state)
	})
	if (options.timeoutMs !== 0) {
		active.setTimeout(options.timeoutMs ?? 10_000, () => active.destroy(new Error('timeout')))
	}
	if (options.body) active.write(options.body)
	active.end()
	return { active, result, state }
}

export async function within(promise, label, timeoutMs = 5_000) {
	return Promise.race([
		promise,
		delay(timeoutMs).then(() => {
			throw new Error(`${label} timed out`)
		}),
	])
}

export function rpc(id, method, params = undefined) {
	return JSON.stringify({ jsonrpc: '2.0', id, method, ...(params ? { params } : {}) })
}

export function observation(imageId, passed, detail = {}) {
	return { candidateImageId: imageId, passed, ...detail }
}

export async function waitForProxy(request, port, cert) {
	for (let attempt = 0; attempt < 50; attempt += 1) {
		const response = await request(port, cert, '/health').catch(() => null)
		if (response?.status === 200 && response.tls) {
			return { path: '/health', status: response.status }
		}
		await delay(100)
	}
	throw new Error('candidate health did not return 200 through the TLS proxy')
}

export function topology(compose_, project, env, expectedImageId) {
	const resolved = JSON.parse(compose_(project, env, ['config', '--format', 'json']))
	assert.deepEqual(resolved.services.backend.ports ?? [], [])
	assert.match(resolved.services.backend.command.join(' '), /--http-profile private/)
	const backendId = compose_(project, env, ['ps', '-q', 'backend'])
	const inspected = JSON.parse(command('docker', ['inspect', backendId]))[0]
	assert.equal(inspected.Image, expectedImageId, 'proxy backend is not the inspected candidate')
	const published = Object.values(inspected.NetworkSettings.Ports ?? {}).some(Boolean)
	return { privateProfile: true, published, containerId: backendId, containerInspect: inspected }
}
