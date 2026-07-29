import assert from 'node:assert/strict'
import { spawn, spawnSync } from 'node:child_process'
import { existsSync, mkdtempSync, readFileSync, rmSync } from 'node:fs'
import { createServer } from 'node:http'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { describe, it } from 'node:test'
import { ClientAdapters, runClientCommand } from '../../scripts/client-adapters.mjs'
import { createHandshakeRelay } from '../../scripts/client-http-observer.mjs'

const IMAGE_ID = `sha256:${'a'.repeat(64)}`
const CANDIDATE_STORE_MODULE = new URL('../../scripts/candidate-store.mjs', import.meta.url).href
const candidateImageId = process.env.FLUENTCART_ACCEPTANCE_IMAGE_ID

function listen(server) {
	return new Promise((ready, reject) => {
		server.once('error', reject)
		server.listen(0, '127.0.0.1', ready)
	})
}

function close(server) {
	return new Promise((closed, reject) => {
		server.close((error) => (error ? reject(error) : closed()))
	})
}

function waitForClose(child, timeoutMs = 5_000) {
	if (child.exitCode !== null || child.signalCode !== null) {
		return Promise.resolve({ status: child.exitCode, signal: child.signalCode })
	}
	return new Promise((settle, reject) => {
		const timer = setTimeout(
			() => reject(new Error(`process ${child.pid} did not exit`)),
			timeoutMs,
		)
		child.once('error', reject)
		child.once('close', (status, signal) => {
			clearTimeout(timer)
			settle({ status, signal })
		})
	})
}

function isRunning(pid) {
	try {
		process.kill(pid, 0)
		const state = spawnSync('ps', ['-o', 'state=', '-p', String(pid)], {
			encoding: 'utf8',
		}).stdout.trim()
		return state !== '' && !state.startsWith('Z')
	} catch {
		return false
	}
}

async function settlesWithin(promise, timeoutMs = 250) {
	return Promise.race([
		promise.then(() => true),
		new Promise((resolve) => setTimeout(() => resolve(false), timeoutMs)),
	])
}

async function waitForFile(path, child, stderr, timeoutMs = 5_000) {
	const deadline = Date.now() + timeoutMs
	while (Date.now() < deadline) {
		if (existsSync(path)) return
		if (child.exitCode !== null || child.signalCode !== null) {
			throw new Error(`signal fixture exited before readiness: ${stderr.value}`)
		}
		await new Promise((resolve) => setTimeout(resolve, 25))
	}
	throw new Error(`signal fixture did not become ready: ${stderr.value}`)
}

async function assertDockerContainerAbsent(containerId) {
	const module = await import('../../scripts/docker-container-cleanup.mjs')
	assert.equal(
		typeof module.verifyDockerContainerAbsent,
		'function',
		'strict Docker absence verifier is unavailable',
	)
	module.verifyDockerContainerAbsent(containerId)
}

describe('named-client candidate authentication', () => {
	it('creates a disposable private-profile key with at least 32 UTF-8 bytes', () => {
		const first = new ClientAdapters({ imageId: IMAGE_ID })
		const second = new ClientAdapters({ imageId: IMAGE_ID })
		assert.equal(typeof first.clientKey, 'string')
		assert.ok(Buffer.byteLength(first.clientKey, 'utf8') >= 32)
		assert.notEqual(first.clientKey, 'fixture-client-key')
		assert.notEqual(first.clientKey, second.clientKey)
	})

	it('forwards the supplied candidate key through the HTTP handshake relay', async () => {
		const expectedKey = 'disposable-client-key-0123456789abcdef'
		let authorization = null
		const upstream = createServer((request, response) => {
			authorization = request.headers.authorization
			response.writeHead(200, { 'Content-Type': 'application/json' })
			response.end(JSON.stringify({ result: { protocolVersion: '2026-07-28' } }))
		})
		await listen(upstream)
		const relay = await createHandshakeRelay(upstream.address().port, expectedKey)
		try {
			const response = await fetch(relay.url, { method: 'POST', body: '{}' })
			assert.equal(response.status, 200)
			assert.equal(authorization, `Bearer ${expectedKey}`)
		} finally {
			await relay.close()
			await close(upstream)
		}
	})

	it('starts the actual candidate with the complete private HTTP policy', {
		skip: !candidateImageId && 'BLOCKED: FLUENTCART_ACCEPTANCE_IMAGE_ID',
	}, async () => {
		const adapters = new ClientAdapters({ imageId: candidateImageId })
		let containerId
		try {
			await adapters.start()
			containerId = adapters.containerId
			assert.ok(adapters.httpPort > 0)
		} finally {
			await adapters.close()
		}
		await assertDockerContainerAbsent(containerId)
	})

	it('escalates an isolated client that ignores SIGTERM and waits for its exit', async () => {
		const { stopClientProcess } = await import('../../scripts/client-process.mjs')
		const directory = mkdtempSync(join(tmpdir(), 'fcmcp-stubborn-client-'))
		const ready = join(directory, 'ready')
		const child = spawn(
			process.execPath,
			[
				'--input-type=module',
				'--eval',
				`import { writeFileSync } from 'node:fs'
				process.on('SIGTERM', () => undefined)
				writeFileSync(process.argv[1], 'ready')
				setInterval(() => {}, 1000)`,
				ready,
			],
			{ detached: true, stdio: 'ignore' },
		)
		const stderr = { value: '' }
		try {
			await waitForFile(ready, child, stderr)
			await stopClientProcess(child, { graceMs: 50 })
			assert.equal(child.signalCode, 'SIGKILL')
			assert.equal(child.exitCode, null)
		} finally {
			if (child.exitCode === null && child.signalCode === null) {
				try {
					process.kill(-child.pid, 'SIGKILL')
				} catch {
					// The isolated group may already be gone.
				}
			}
			rmSync(directory, { recursive: true, force: true })
		}
	})

	it('terminates a surviving same-PGID helper after its detached leader exits', async () => {
		const { stopClientProcess } = await import('../../scripts/client-process.mjs')
		const directory = mkdtempSync(join(tmpdir(), 'fcmcp-client-process-group-'))
		const helperFile = join(directory, 'helper')
		const leader = spawn(
			process.execPath,
			[
				'--input-type=module',
				'--eval',
				`import { spawn } from 'node:child_process'
					import { writeFileSync } from 'node:fs'
					const helper = spawn(process.execPath, [
						'--input-type=module', '--eval',
						'process.on("SIGTERM", () => undefined); process.send("ready"); setInterval(() => {}, 1000)'
					], { stdio: ['ignore', 'ignore', 'ignore', 'ipc'] })
					helper.once('message', () => {
						writeFileSync(process.argv[1], 'handler-ready:' + helper.pid)
						helper.disconnect()
						helper.unref()
					})`,
				helperFile,
			],
			{ detached: true, stdio: 'ignore' },
		)
		let helperPid
		try {
			await waitForFile(helperFile, leader, { value: '' })
			const [state, pid] = readFileSync(helperFile, 'utf8').split(':')
			assert.equal(state, 'handler-ready')
			helperPid = Number(pid)
			await waitForClose(leader)
			assert.equal(isRunning(helperPid), true)
			await stopClientProcess(leader, { graceMs: 50 })
			assert.equal(isRunning(helperPid), false, 'same-PGID helper survived client cleanup')
		} finally {
			try {
				process.kill(-leader.pid, 'SIGKILL')
			} catch {
				// The isolated group may already be gone.
			}
			rmSync(directory, { recursive: true, force: true })
		}
	})

	it('waits for a timed-out command group after its leader exits on SIGTERM', async () => {
		const directory = mkdtempSync(join(tmpdir(), 'fcmcp-adapter-command-group-'))
		const helperFile = join(directory, 'helper')
		let helperPid
		try {
			await runClientCommand(
				process.execPath,
				[
					'--input-type=module',
					'--eval',
					`import { spawn } from 'node:child_process'
						import { writeFileSync } from 'node:fs'
						const helper = spawn(process.execPath, [
							'--input-type=module', '--eval',
							'process.on("SIGTERM", () => undefined); setInterval(() => {}, 1000)'
						], { stdio: 'ignore' })
						writeFileSync(process.argv[1], String(helper.pid))
						process.on('SIGTERM', () => process.exit(0))
						setInterval(() => {}, 1000)`,
					helperFile,
				],
				{ timeout: 500 },
			)
			helperPid = Number(readFileSync(helperFile, 'utf8'))
			assert.equal(isRunning(helperPid), false, 'timed-out command left its helper alive')
		} finally {
			if (helperPid && isRunning(helperPid)) process.kill(helperPid, 'SIGKILL')
			rmSync(directory, { recursive: true, force: true })
		}
	})

	it('reports a silent non-zero client exit', async () => {
		const result = await runClientCommand(process.execPath, ['--eval', 'process.exit(75)'])
		assert.equal(result.ok, false)
		assert.match(result.detail, /exit status 75/)
	})

	it('settles a timed-out command when process cleanup rejects without leaking the rejection', async () => {
		const directory = mkdtempSync(join(tmpdir(), 'fcmcp-adapter-cleanup-rejection-'))
		let childPid
		const unhandled = []
		const recordUnhandled = (reason) => unhandled.push(reason)
		process.on('unhandledRejection', recordUnhandled)
		try {
			const result = await runClientCommand(
				process.execPath,
				[
					'--input-type=module',
					'--eval',
					'setTimeout(() => process.stderr.write("child emitted output\\n"), 100); setInterval(() => {}, 1000)',
				],
				{
					timeout: 25,
					stopProcess: async (child) => {
						childPid = child.pid
						await Promise.race([
							new Promise((resolve) => child.stderr.once('data', resolve)),
							new Promise((_, reject) =>
								setTimeout(() => reject(new Error('child output fixture timed out')), 2_000),
							),
						])
						throw new Error('synthetic process cleanup refusal')
					},
				},
			)
			await new Promise((resolve) => setTimeout(resolve, 25))
			assert.equal(result.ok, false)
			assert.match(result.detail, /synthetic process cleanup refusal/)
			assert.match(result.detail, /child emitted output/)
			assert.deepEqual(unhandled, [])
		} finally {
			process.off('unhandledRejection', recordUnhandled)
			if (childPid && isRunning(childPid)) process.kill(-childPid, 'SIGKILL')
			rmSync(directory, { recursive: true, force: true })
		}
	})

	it('removes its actual container when startup fails after docker run succeeds', {
		skip: !candidateImageId && 'BLOCKED: FLUENTCART_ACCEPTANCE_IMAGE_ID',
	}, async () => {
		let containerId
		const adapters = new ClientAdapters(
			{ imageId: candidateImageId },
			{
				afterContainerStarted: (startedId) => {
					containerId = startedId
					throw new Error('controlled post-run startup failure')
				},
			},
		)
		try {
			await assert.rejects(adapters.start(), /controlled post-run startup failure/)
		} finally {
			await adapters.close()
		}
		assert.ok(containerId, 'controlled startup failure did not capture a real container ID')
		assert.equal(adapters.store.server.listening, false)
		await assertDockerContainerAbsent(containerId)
	})

	it('retains an unremoved container ID while still closing its CandidateStore', async () => {
		let storeClosed = false
		const adapters = new ClientAdapters(
			{ imageId: IMAGE_ID },
			{
				removeContainer: () => {
					throw new Error('synthetic removal failure')
				},
			},
		)
		adapters.containerId = 'candidate-container'
		adapters.storeActive = true
		adapters.store = {
			close: async () => {
				storeClosed = true
			},
		}
		await assert.rejects(adapters.close(), /synthetic removal failure/)
		assert.equal(adapters.containerId, 'candidate-container')
		assert.equal(storeClosed, true)
	})

	for (const signal of ['SIGINT', 'SIGTERM']) {
		it(`removes the actual candidate container before preserving ${signal}`, {
			skip: !candidateImageId && 'BLOCKED: FLUENTCART_ACCEPTANCE_IMAGE_ID',
		}, async () => {
			const directory = mkdtempSync(join(tmpdir(), 'fcmcp-client-adapter-signal-'))
			const ready = join(directory, 'ready')
			const program = `
				import { writeFileSync } from 'node:fs'
				import { ClientAdapters } from ${JSON.stringify(new URL('../../scripts/client-adapters.mjs', import.meta.url).href)}
				const adapters = new ClientAdapters({ imageId: process.argv[2] })
				await adapters.start()
				writeFileSync(process.argv[1], adapters.containerId)
				setInterval(() => {}, 1000)
			`
			const child = spawn(
				process.execPath,
				['--input-type=module', '--eval', program, ready, candidateImageId],
				{ stdio: ['ignore', 'ignore', 'pipe'] },
			)
			const stderr = { value: '' }
			child.stderr.on('data', (chunk) => {
				stderr.value += chunk
			})
			let containerId
			try {
				await waitForFile(ready, child, stderr, 15_000)
				containerId = readFileSync(ready, 'utf8')
				const closing = waitForClose(child, 10_000)
				process.kill(child.pid, signal)
				const outcome = await closing
				assert.equal(outcome.signal, signal)
				await assertDockerContainerAbsent(containerId)
			} finally {
				if (child.exitCode === null && child.signalCode === null) child.kill('SIGKILL')
				if (containerId) spawnSync('docker', ['rm', '-f', containerId])
				rmSync(directory, { recursive: true, force: true })
			}
		})
	}
})

describe('run-owned Docker acceptance store', () => {
	it('starts and closes a CandidateStore when no external URL is supplied', async () => {
		const { openCandidateStore } = await import(CANDIDATE_STORE_MODULE)
		assert.equal(typeof openCandidateStore, 'function')
		const runtime = await openCandidateStore()
		const loopbackUrl = runtime.url.replace('host.docker.internal', '127.0.0.1')
		try {
			assert.equal(runtime.owned, true)
			assert.match(runtime.url, /^http:\/\/host\.docker\.internal:\d+$/)
			const response = await fetch(`${loopbackUrl}/wp-json/fluent-cart/v2`)
			assert.equal(response.status, 200)
			const index = await response.json()
			assert.ok(index.namespaces.includes('fluent-cart/v2'))
		} finally {
			await runtime.close()
		}
		await runtime.close()
		await assert.rejects(fetch(`${loopbackUrl}/wp-json/fluent-cart/v2`))
	})

	it('preserves an explicit external store URL without owning it', async () => {
		const { openCandidateStore } = await import(CANDIDATE_STORE_MODULE)
		const runtime = await openCandidateStore('https://store.example.test')
		assert.deepEqual(
			{ url: runtime.url, owned: runtime.owned },
			{ url: 'https://store.example.test', owned: false },
		)
		await runtime.close()
	})

	it('closes a partially started owned store when startup fails', async () => {
		const { openCandidateStore } = await import(CANDIDATE_STORE_MODULE)
		let closed = 0
		await assert.rejects(
			openCandidateStore(null, {
				createStore: () => ({
					start: async () => {
						throw new Error('synthetic store startup failure')
					},
					close: async () => {
						closed += 1
					},
				}),
			}),
			/synthetic store startup failure/,
		)
		assert.equal(closed, 1)
	})

	it('closes a blocked read without waiting forever for its socket', async () => {
		const { CandidateStore } = await import(CANDIDATE_STORE_MODULE)
		const store = new CandidateStore()
		await store.start()
		const loopbackUrl = store.url.replace('host.docker.internal', '127.0.0.1')
		const blocked = store.blockNextRead()
		const request = fetch(`${loopbackUrl}/wp-json/fluent-cart/v2/orders`).catch(() => null)
		await blocked.started
		const closing = store.close()
		try {
			assert.equal(
				await settlesWithin(closing),
				true,
				'CandidateStore.close hung on a blocked read',
			)
			await blocked.cancelled
			await request
		} finally {
			store.server.closeAllConnections?.()
			await closing
		}
	})

	it('runs cleanup once and preserves SIGTERM', async () => {
		const directory = mkdtempSync(join(tmpdir(), 'fcmcp-candidate-signal-'))
		const ready = join(directory, 'ready')
		const closed = join(directory, 'closed')
		const program = `
			import { appendFileSync, writeFileSync } from 'node:fs'
			import { installSignalCleanup } from ${JSON.stringify(CANDIDATE_STORE_MODULE)}
			const [ready, closed] = process.argv.slice(1)
			installSignalCleanup(async () => appendFileSync(closed, 'close\\n'))
			writeFileSync(ready, 'ready')
			setInterval(() => {}, 1000)
		`
		const child = spawn(
			process.execPath,
			['--input-type=module', '--eval', program, ready, closed],
			{ stdio: ['ignore', 'ignore', 'pipe'] },
		)
		const stderr = { value: '' }
		child.stderr.on('data', (chunk) => {
			stderr.value += chunk
		})
		try {
			await waitForFile(ready, child, stderr)
			const closing = waitForClose(child)
			process.kill(child.pid, 'SIGTERM')
			const outcome = await closing
			assert.equal(outcome.signal, 'SIGTERM')
			assert.equal(outcome.status, null)
			assert.equal(readFileSync(closed, 'utf8'), 'close\n')
		} finally {
			if (child.exitCode === null && child.signalCode === null) child.kill('SIGKILL')
			rmSync(directory, { recursive: true, force: true })
		}
	})
})

describe('HTTP relay cleanup', () => {
	it('aborts a nonresponding upstream request before closing its listener', async () => {
		let acceptRequest
		const accepted = new Promise((resolve) => {
			acceptRequest = resolve
		})
		const upstream = createServer((request) => acceptRequest(request))
		await listen(upstream)
		const relay = await createHandshakeRelay(
			upstream.address().port,
			'disposable-relay-key-0123456789abcdef',
		)
		const controller = new AbortController()
		const request = fetch(relay.url, {
			method: 'POST',
			body: '{}',
			signal: controller.signal,
		}).catch(() => null)
		await accepted
		const closing = relay.close()
		try {
			assert.equal(await settlesWithin(closing), true, 'relay close hung on its active upstream')
			await request
		} finally {
			controller.abort()
			upstream.closeAllConnections?.()
			await closing
			await close(upstream)
		}
	})
})

describe('Docker container cleanup contract', () => {
	it('rejects an ambiguous nonzero inspect result as absence proof', async () => {
		const { verifyDockerContainerAbsent } = await import(
			'../../scripts/docker-container-cleanup.mjs'
		)
		assert.throws(
			() =>
				verifyDockerContainerAbsent('candidate-container', {
					runDocker: () => ({ status: 1, stdout: '', stderr: 'permission denied' }),
				}),
			/could not verify absence.*permission denied/,
		)
	})

	it('surfaces removal failure when inspect proves the container remains', async () => {
		const { removeDockerContainer } = await import('../../scripts/docker-container-cleanup.mjs')
		const calls = []
		const runDocker = (args) => {
			calls.push(args)
			return args[0] === 'rm'
				? { status: 1, stdout: '', stderr: 'synthetic rm failure' }
				: { status: 0, stdout: '[{}]', stderr: '' }
		}
		assert.throws(
			() => removeDockerContainer('candidate-container', { runDocker }),
			/synthetic rm failure.*still exists/,
		)
		assert.deepEqual(calls, [
			['rm', '-f', 'candidate-container'],
			['inspect', 'candidate-container'],
		])
	})
})
