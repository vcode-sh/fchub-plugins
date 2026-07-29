import assert from 'node:assert/strict'
import { spawn, spawnSync } from 'node:child_process'
import { existsSync, mkdtempSync, readdirSync, readFileSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { describe, it } from 'node:test'
import { fileURLToPath } from 'node:url'

const SOURCE_SHA = '0123456789abcdef0123456789abcdef01234567'
const SOAK_SCRIPT = fileURLToPath(new URL('../../scripts/soak-http.mjs', import.meta.url))
const LIFECYCLE_MODULE = new URL('../../scripts/managed-soak-runtime.mjs', import.meta.url).href
const candidateEnvironment = [
	'FLUENTCART_ACCEPTANCE_IMAGE',
	'FLUENTCART_ACCEPTANCE_IMAGE_ID',
	'FLUENTCART_ACCEPTANCE_IMAGE_DIGEST',
]
const missingCandidate = candidateEnvironment.filter((name) => !process.env[name])

function waitForClose(child, timeoutMs = 10_000) {
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

async function waitFor(check, label, timeoutMs = 15_000) {
	const deadline = Date.now() + timeoutMs
	while (Date.now() < deadline) {
		const value = await check()
		if (value) return value
		await new Promise((resolve) => setTimeout(resolve, 50))
	}
	throw new Error(`${label} did not become ready`)
}

function isRunning(pid) {
	try {
		process.kill(pid, 0)
		return true
	} catch {
		return false
	}
}

function childrenOf(parentPid) {
	const result = spawnSync('ps', ['-axo', 'pid=,ppid='], { encoding: 'utf8' })
	assert.equal(result.status, 0, result.stderr)
	return result.stdout
		.trim()
		.split('\n')
		.map((line) => line.trim().split(/\s+/).map(Number))
		.filter(([, ppid]) => ppid === parentPid)
		.map(([pid]) => pid)
}

function dockerIds(kind, project) {
	const command = {
		container: ['ps', '-aq'],
		network: ['network', 'ls', '-q'],
		volume: ['volume', 'ls', '-q'],
	}[kind]
	assert.ok(command, `unsupported Docker resource kind: ${kind}`)
	const result = spawnSync(
		'docker',
		[...command, '--filter', `label=com.docker.compose.project=${project}`],
		{ encoding: 'utf8' },
	)
	assert.equal(result.status, 0, result.stderr)
	return result.stdout.trim().split('\n').filter(Boolean)
}

function runtimeDirectories(pid) {
	const prefixes = [`fcmcp-soak-candidate-${pid}-`, `fcmcp-soak-result-${pid}-`]
	return readdirSync(tmpdir())
		.filter((name) => prefixes.some((prefix) => name.startsWith(prefix)))
		.map((name) => join(tmpdir(), name))
}

function removeExactRuntime(project, pid) {
	const containers = dockerIds('container', project)
	if (containers.length > 0) spawnSync('docker', ['rm', '-f', ...containers])
	const volumes = dockerIds('volume', project)
	if (volumes.length > 0) spawnSync('docker', ['volume', 'rm', '-f', ...volumes])
	const networks = dockerIds('network', project)
	if (networks.length > 0) spawnSync('docker', ['network', 'rm', ...networks])
	for (const directory of runtimeDirectories(pid)) {
		rmSync(directory, { recursive: true, force: true })
	}
}

describe('managed soak process lifecycle', () => {
	it('accepts a Compose teardown failure only after exact fallback cleanup succeeds', async () => {
		const { closeManagedSoakRuntime } = await import('../../scripts/managed-soak-runtime.mjs')
		const directory = mkdtempSync(join(tmpdir(), 'fcmcp-close-runtime-test-'))
		const calls = []
		const state = {
			closed: false,
			directory,
			env: {},
			project: 'fcmcp-close-runtime-test',
			store: {
				close: async () => {
					calls.push('store')
				},
			},
		}

		await closeManagedSoakRuntime(state, {
			compose: () => {
				calls.push('compose')
				throw new Error('Compose command timed out')
			},
			removeProjectResidue: () => {
				calls.push('residue')
			},
		})

		assert.deepEqual(calls, ['compose', 'residue', 'store'])
		assert.equal(state.closed, true)
		assert.equal(existsSync(directory), false)
	})

	it('rejects a Compose teardown failure when exact fallback cleanup cannot prove removal', async () => {
		const { closeManagedSoakRuntime } = await import('../../scripts/managed-soak-runtime.mjs')
		const directory = mkdtempSync(join(tmpdir(), 'fcmcp-close-runtime-test-'))
		const state = {
			closed: false,
			directory,
			env: {},
			project: 'fcmcp-close-runtime-test',
			store: { close: async () => undefined },
		}

		await assert.rejects(
			closeManagedSoakRuntime(state, {
				compose: () => {
					throw new Error('Compose command timed out')
				},
				removeProjectResidue: () => {
					throw new Error('managed soak container survived cleanup')
				},
			}),
			/managed soak container survived cleanup/,
		)
		assert.equal(state.closed, true)
		assert.equal(existsSync(directory), false)
	})

	for (const signal of ['SIGINT', 'SIGTERM']) {
		it(`installs ${signal} ownership before startup and closes exactly once`, async () => {
			const marker = mkdtempSync(join(tmpdir(), 'fcmcp-signal-probe-'))
			const ready = join(marker, 'ready')
			const closed = join(marker, 'closed')
			const program = `
				import { writeFileSync, appendFileSync } from 'node:fs'
				import { runManagedSoakLifecycle } from ${JSON.stringify(LIFECYCLE_MODULE)}
				const [ready, closed] = process.argv.slice(1)
				const keepAlive = setInterval(() => {}, 1000)
				await runManagedSoakLifecycle({
					preserveSignal: true,
					startRuntime: async () => {
						writeFileSync(ready, 'ready')
						return {
							descriptor: {},
							close: async () => {
								clearInterval(keepAlive)
								appendFileSync(closed, 'close\\n')
							}
						}
					},
					execute: async (_descriptor, context = {}) =>
						new Promise((_resolve, reject) => {
							context.signal?.addEventListener(
								'abort',
								() => reject(context.signal.reason),
								{ once: true }
							)
						})
				})
			`
			const child = spawn(
				process.execPath,
				['--input-type=module', '--eval', program, ready, closed],
				{ stdio: 'ignore' },
			)
			try {
				await waitFor(() => existsSync(ready), 'signal probe')
				const closing = waitForClose(child)
				process.kill(child.pid, signal)
				const outcome = await closing
				assert.equal(outcome.signal, signal)
				assert.equal(outcome.status, null)
				assert.equal(readFileSync(closed, 'utf8'), 'close\n')
			} finally {
				if (isRunning(child.pid)) child.kill('SIGKILL')
				rmSync(marker, { recursive: true, force: true })
			}
		})
	}

	it('kills a timed-out worker and removes its result directory', async () => {
		const { runManagedWorker } = await import('../../scripts/soak-http.mjs')
		const resultDirectory = mkdtempSync(join(tmpdir(), 'fcmcp-worker-timeout-test-'))
		const resultPath = join(resultDirectory, 'result.json')
		let workerPid
		await assert.rejects(
			runManagedWorker({
				args: ['--input-type=module', '--eval', 'setInterval(() => {}, 1000)'],
				env: process.env,
				resultDirectory,
				resultPath,
				timeoutMs: 100,
				killGraceMs: 100,
				onSpawn: (child) => {
					workerPid = child.pid
				},
			}),
			/worker timed out/,
		)
		assert.ok(workerPid)
		assert.equal(isRunning(workerPid), false)
		assert.equal(existsSync(resultDirectory), false)
	})

	for (const signal of ['SIGINT', 'SIGTERM']) {
		it(`removes the actual managed runtime and worker after ${signal}`, {
			skip: missingCandidate.length > 0 && `BLOCKED: ${missingCandidate.join(', ')}`,
		}, async () => {
			const runDirectory = mkdtempSync(join(tmpdir(), 'fcmcp-managed-signal-test-'))
			const child = spawn(
				process.execPath,
				[
					SOAK_SCRIPT,
					'--source-sha',
					SOURCE_SHA,
					'--duration',
					'60',
					'--warmup',
					'0.1',
					'--interval-ms',
					'50',
				],
				{
					env: {
						...process.env,
						NODE_ENV: 'test',
						FLUENTCART_ACCEPTANCE_RUN_DIR: runDirectory,
					},
					stdio: ['ignore', 'ignore', 'pipe'],
				},
			)
			const projectPrefix = `fcmcp-soak-${child.pid}-`
			let project
			let workerPid
			try {
				project = await waitFor(() => {
					const result = spawnSync(
						'docker',
						['ps', '-a', '--format', '{{.Label "com.docker.compose.project"}}'],
						{ encoding: 'utf8' },
					)
					return result.stdout
						.trim()
						.split('\n')
						.find((name) => name.startsWith(projectPrefix))
				}, 'managed Compose project')
				await waitFor(
					() => runtimeDirectories(child.pid).length >= 2,
					'managed certificate and result directories',
				)
				workerPid = await waitFor(() => childrenOf(child.pid)[0], 'managed soak worker')
				const closing = waitForClose(child, 15_000)
				process.kill(child.pid, signal)
				const outcome = await closing
				assert.equal(outcome.signal, signal)
				assert.equal(outcome.status, null)
				assert.equal(isRunning(workerPid), false)
				assert.deepEqual(dockerIds('container', project), [])
				assert.deepEqual(dockerIds('volume', project), [])
				assert.deepEqual(dockerIds('network', project), [])
				assert.deepEqual(runtimeDirectories(child.pid), [])
				assert.equal(existsSync(join(runDirectory, 'soak.json')), false)
			} finally {
				if (isRunning(child.pid)) child.kill('SIGKILL')
				if (workerPid && isRunning(workerPid)) process.kill(workerPid, 'SIGKILL')
				if (project) removeExactRuntime(project, child.pid)
				rmSync(runDirectory, { recursive: true, force: true })
			}
		})
	}
})
