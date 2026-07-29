import assert from 'node:assert/strict'
import { spawn } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { existsSync, readFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { setTimeout as delay } from 'node:timers/promises'
import { fileURLToPath } from 'node:url'
import { writeJsonAtomic } from './acceptance/evidence-writer.mjs'
import { CandidateStore, installSignalCleanup } from './candidate-store.mjs'
import { createHandshakeRelay } from './client-http-observer.mjs'
import { stopClientProcess } from './client-process.mjs'
import { removeDockerContainer } from './docker-container-cleanup.mjs'
import { configurationTargetFor, isConfigurationTarget } from './client-evidence-contract.mjs'

const PACKAGE_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const BRIDGE = join(PACKAGE_ROOT, 'scripts/client-stdio-observer.mjs')

function boundedProcessDetail(value, limit = 240) {
	return String(value ?? '')
		.replaceAll(/(FLUENTCART_(?:APP_PASSWORD|MCP_API_KEY|USERNAME)=)\S+/g, '$1[redacted]')
		.trim()
		.slice(-limit)
}

export function runClientCommand(binary, args, options = {}) {
	return new Promise((resolveRun) => {
		let stdout = ''
		let stderr = ''
		let error = null
		let timedOut = false
		let stopPromise = null
		let stopError = null
		let finished = false
		let child
		try {
			child = spawn(binary, args, {
				cwd: options.cwd ?? PACKAGE_ROOT,
				env: options.env ?? process.env,
				stdio: ['ignore', 'pipe', 'pipe'],
				detached: process.platform !== 'win32',
			})
		} catch (spawnError) {
			resolveRun({ ok: false, output: '', detail: spawnError.message.slice(-500) })
			return
		}
		child.stdout.setEncoding('utf8')
		child.stderr.setEncoding('utf8')
		child.stdout.on('data', (chunk) => {
			stdout += chunk
		})
		child.stderr.on('data', (chunk) => {
			stderr += chunk
		})
		child.once('error', (spawnError) => {
			error = spawnError
		})
		const finish = async (status) => {
			if (finished) return
			finished = true
			clearTimeout(timeout)
			if (stopPromise) {
				try {
					await stopPromise
				} catch (cleanupError) {
					stopError = cleanupError
				}
			}
			const commandDetail = boundedProcessDetail(
				stderr || stdout || error?.message || (timedOut ? 'command timed out' : ''),
			)
			const cleanupDetail = boundedProcessDetail(stopError?.message)
			const detail = cleanupDetail
				? [cleanupDetail, commandDetail].filter(Boolean).join('; ').slice(0, 500)
				: commandDetail
			resolveRun({ ok: status === 0 && !timedOut && !stopError, output: stdout.trim(), detail })
		}
		const timeout = setTimeout(() => {
			timedOut = true
			stopPromise = Promise.resolve()
				.then(() => (options.stopProcess ?? stopClientProcess)(child, { graceMs: options.killGraceMs ?? 1_000 }))
				.catch((cleanupError) => {
					stopError = cleanupError
				})
			void stopPromise.then(() => finish(child.exitCode))
		}, options.timeout ?? 30_000)
		child.once('close', (status) => void finish(status))
	})
}
async function waitFor(check, timeoutMs) {
	const deadline = Date.now() + timeoutMs
	while (Date.now() < deadline) {
		const value = await check()
		if (value) return value
		await delay(150)
	}
	return null
}
function safeReason(prefix, detail) {
	const suffix = detail ? `: ${detail.replaceAll(/\s+/g, ' ').slice(0, 300)}` : ''
	return `${prefix}${suffix}`
}
function isolatedEnv(root, storeUrl, imageId) {
	return {
		PATH: process.env.PATH,
		HOME: root,
		XDG_CONFIG_HOME: join(root, 'xdg-config'),
		XDG_CACHE_HOME: join(root, 'xdg-cache'),
		CLAUDE_CONFIG_DIR: join(root, 'claude-code'),
		FCMCP_CLIENT_IMAGE_ID: imageId,
		FCMCP_CLIENT_STORE_URL: storeUrl,
	}
}
function candidateBridgeEnv(env) {
	return {
		PATH: env.PATH,
		FCMCP_CLIENT_IMAGE_ID: env.FCMCP_CLIENT_IMAGE_ID,
		FCMCP_CLIENT_STORE_URL: env.FCMCP_CLIENT_STORE_URL,
	}
}
function inspectorStdioConfig(receipt, env) {
	return {
		type: 'stdio',
		command: process.execPath,
		args: [BRIDGE, receipt],
		env: candidateBridgeEnv(env),
	}
}
function readReceipt(path, imageId) {
	if (!existsSync(path)) return null
	const receipt = JSON.parse(readFileSync(path, 'utf8'))
	assert.equal(receipt.candidateImageId, imageId, 'stdio receipt is not candidate-bound')
	return receipt.protocolVersion
}
export class ClientAdapters {
	constructor(candidate, dependencies = {}) {
		this.candidate = candidate
		this.removeContainer = dependencies.removeContainer ?? removeDockerContainer
		this.afterContainerStarted = dependencies.afterContainerStarted
		this.clientKey = `client-${randomBytes(24).toString('hex')}`
		this.store = new CandidateStore()
		this.storeActive = false
		this.containerId = null
		this.closePromise = null
		this.removeSignalCleanup = null
	}
	async start() {
		this.removeSignalCleanup = installSignalCleanup(() => this.close())
		this.storeActive = true
		try {
			await this.store.start()
			const result = await runClientCommand('docker', [
				'run', '-d', '--rm', '-p', '127.0.0.1::3000',
				'--add-host', 'host.docker.internal:host-gateway',
				'-e', `FLUENTCART_URL=${this.store.url}`,
				'-e', 'FLUENTCART_USERNAME=fixture',
				'-e', 'FLUENTCART_APP_PASSWORD=fixture',
				'-e', 'FLUENTCART_WRITE_MODE=disabled',
				'-e', `FLUENTCART_MCP_API_KEY=${this.clientKey}`,
				'-e', 'FLUENTCART_MCP_ALLOWED_HOSTS=127.0.0.1,localhost',
				'-e', 'FLUENTCART_MCP_ALLOWED_ORIGINS=127.0.0.1,localhost',
				this.candidate.imageId,
				'node', 'dist/index.js', '--transport', 'http', '--port', '3000',
				'--host', '0.0.0.0', '--http-profile', 'private',
			])
			assert.ok(result.ok, safeReason('candidate HTTP container failed', result.detail))
			this.containerId = result.output
			await this.afterContainerStarted?.(this.containerId)
			const mapping = await runClientCommand('docker', ['port', this.containerId, '3000/tcp'])
			assert.ok(mapping.ok, safeReason('candidate HTTP port lookup failed', mapping.detail))
			this.httpPort = Number(mapping.output.match(/:(\d+)$/)?.[1])
			assert.ok(this.httpPort > 0, 'candidate HTTP port was not published on loopback')
			const ready = await waitFor(async () => {
				try {
					return (await fetch(`http://127.0.0.1:${this.httpPort}/health`)).ok
				} catch {
					return false
				}
			}, 15_000)
			assert.ok(ready, 'candidate HTTP container did not become ready')
		} catch (error) {
			try {
				await this.close()
			} catch (cleanupError) {
				throw new AggregateError(
					[error, cleanupError],
					`candidate startup failed and cleanup also failed: ${cleanupError.message}`,
				)
			}
			throw error
		}
	}

	async close() {
		if (this.closePromise) return this.closePromise
		this.closePromise = (async () => {
			this.removeSignalCleanup?.()
			let failure = null
			if (this.containerId) {
				try {
					this.removeContainer(this.containerId)
					this.containerId = null
				} catch (error) {
					failure = error
				}
			}
			if (this.storeActive) {
				try {
					await this.store.close()
					this.storeActive = false
				} catch (error) {
					failure ??= error
				}
			}
			if (failure) throw failure
		})()
		try {
			return await this.closePromise
		} catch (error) {
			this.closePromise = null
			throw error
		}
	}
	async observe(cell) {
		if (isConfigurationTarget(cell)) return { outcome: 'CONFIGURATION_TARGET', ...configurationTargetFor(cell) }
		if (cell.transport === 'stdio') return this.stdio(cell)
		return this.http(cell)
	}

	async stdio(cell) {
		const receipt = join(cell.configurationRoot, 'stdio-receipt.json')
		const env = isolatedEnv(cell.configurationRoot, this.store.url, this.candidate.imageId)
		if (cell.client === 'MCP Inspector') {
			const config = join(cell.configurationRoot, 'inspector-mcp.json')
			writeJsonAtomic(config, {
				mcpServers: { fluentcartCandidate: inspectorStdioConfig(receipt, env) },
			})
			const result = await runClientCommand('npx', [
				'--yes', '@modelcontextprotocol/inspector@2.0.0', '--cli', '--config', config,
				'--server', 'fluentcartCandidate', '--method', 'tools/list', '--format', 'json',
			], { env, timeout: 60_000 })
			return this.commandObservation(result, receipt)
		}
		if (cell.client === 'Claude Code') {
			return this.claudeStdio(env, receipt)
		}
		return { outcome: 'BLOCKED', reason: `unsupported automated stdio client ${cell.client}` }
	}

	async claudeStdio(env, receipt) {
		const registration = await runClientCommand('claude', [
			'mcp', 'add', '--scope', 'user', '--transport', 'stdio',
			'fluentcartCandidate',
			'--env', `FCMCP_CLIENT_IMAGE_ID=${env.FCMCP_CLIENT_IMAGE_ID}`,
			'--env', `FCMCP_CLIENT_STORE_URL=${env.FCMCP_CLIENT_STORE_URL}`,
			'--', process.execPath, BRIDGE, receipt,
		], { env, timeout: 45_000 })
		if (!registration.ok) return this.commandObservation(registration, receipt)
		const listed = await runClientCommand('claude', ['mcp', 'list'], { env, timeout: 45_000 })
		if (!listed.ok) return this.commandObservation(listed, receipt)
		return this.commandObservation(
			await runClientCommand('claude', ['mcp', 'get', 'fluentcartCandidate'], { env, timeout: 45_000 }),
			receipt,
		)
	}

	commandObservation(result, receipt) {
		const protocolVersion = readReceipt(receipt, this.candidate.imageId)
		return protocolVersion
			? { outcome: 'PASS', protocolVersion }
			: {
					outcome: 'BLOCKED',
					reason: safeReason('named client did not complete a candidate handshake', result.detail),
				}
	}

	async http(cell) {
		const observed = await createHandshakeRelay(this.httpPort, this.clientKey)
		try {
			if (cell.client === 'Docker smoke') {
				await fetch(observed.url, {
					method: 'POST',
					headers: { Accept: 'application/json, text/event-stream', 'Content-Type': 'application/json' },
					body: JSON.stringify({
						jsonrpc: '2.0', id: 1, method: 'initialize',
						params: { protocolVersion: '2026-07-28', capabilities: {}, clientInfo: { name: 'docker-smoke', version: '2.0.0' } },
					}),
				})
			} else if (cell.client === 'MCP Inspector') {
				await runClientCommand('npx', [
					'--yes', '@modelcontextprotocol/inspector@2.0.0', '--cli', observed.url,
					'--transport', 'http', '--method', 'tools/list', '--format', 'json',
				], { env: isolatedEnv(cell.configurationRoot, this.store.url, this.candidate.imageId), timeout: 60_000 })
			} else if (cell.client === 'Claude Code') {
				const result = await this.claudeHttp(
					isolatedEnv(cell.configurationRoot, this.store.url, this.candidate.imageId),
					observed.url,
				)
				if (!result.ok) {
					return {
						outcome: 'BLOCKED',
						reason: safeReason('Claude Code HTTP configuration failed', result.detail),
					}
				}
			} else {
				return { outcome: 'BLOCKED', reason: `unsupported automated HTTP client ${cell.client}` }
			}
			const protocolVersion = await waitFor(() => observed.protocol(), 2_000)
			return protocolVersion
				? { outcome: 'PASS', protocolVersion }
				: { outcome: 'BLOCKED', reason: 'named client completed without an observed candidate HTTP handshake' }
		} finally {
			await observed.close()
		}
	}

	async claudeHttp(env, url) {
		const removal = await runClientCommand('claude', [
			'mcp', 'remove', '--scope', 'user', 'fluentcartCandidateHttp',
		], { env, timeout: 45_000 })
		if (!removal.ok && !/No MCP server named/u.test(removal.detail)) return removal
		const registration = await runClientCommand('claude', [
			'mcp', 'add', '--scope', 'user', '--transport', 'http', 'fluentcartCandidateHttp', url,
		], { env, timeout: 45_000 })
		if (!registration.ok) return registration
		const listed = await runClientCommand('claude', ['mcp', 'list'], { env, timeout: 45_000 })
		if (!listed.ok) return listed
		return runClientCommand('claude', ['mcp', 'get', 'fluentcartCandidateHttp'], { env, timeout: 45_000 })
	}
}
