import { spawn } from 'node:child_process'

const EXACT_CONTAINER_ID = /^[0-9a-f]{64}$/
const PROBE =
	"rss_kb=$(sed -n 's/^VmRSS:[[:space:]]*\\([0-9]*\\)[[:space:]]*kB$/\\1/p' /proc/1/status) && rss=$((rss_kb * 1024)) && set -- /proc/1/fd/* && printf '%s %s\\n' \"$rss\" \"$#\""

export function executeDockerResourceProbe(
	containerId,
	{ timeoutMs = 10_000, spawnProcess = spawn } = {},
) {
	return new Promise((resolve, reject) => {
		const child = spawnProcess('docker', ['exec', containerId, 'sh', '-c', PROBE], {
			shell: false,
			stdio: ['ignore', 'pipe', 'pipe'],
		})
		let stdout = ''
		let stderr = ''
		let settled = false
		const finish = (callback, value) => {
			if (settled) return
			settled = true
			clearTimeout(timer)
			callback(value)
		}
		const timer = setTimeout(() => {
			timedOut = true
			child.kill('SIGKILL')
		}, timeoutMs)
		let timedOut = false
		child.stdout.on('data', (chunk) => {
			stdout += chunk
		})
		child.stderr.on('data', (chunk) => {
			stderr += chunk
		})
		child.once('error', (error) => finish(reject, error))
		child.once('close', (status, signal) => {
			if (timedOut) {
				finish(reject, new Error(`resource sampling timed out after ${timeoutMs} ms`))
			} else {
				finish(resolve, { status, signal, stdout, stderr })
			}
		})
	})
}

export async function sampleContainerResources(
	containerId,
	{ execute = executeDockerResourceProbe, timeoutMs = 10_000, spawnProcess } = {},
) {
	if (!EXACT_CONTAINER_ID.test(containerId ?? '')) {
		throw new Error('resource sampling requires an exact container ID')
	}
	const result = await execute(containerId, { timeoutMs, spawnProcess })
	if (result?.status !== 0 || result.signal) {
		throw new Error('candidate container resource sampling failed')
	}
	const match = /^(\d+)\s+(\d+)\s*$/.exec(result.stdout ?? '')
	if (!match) throw new Error('malformed resource sample')
	const rssBytes = Number(match[1])
	const openFds = Number(match[2])
	if (!Number.isSafeInteger(rssBytes) || rssBytes <= 0 || !Number.isSafeInteger(openFds)) {
		throw new Error('malformed resource sample')
	}
	return { rssBytes, openFds }
}
