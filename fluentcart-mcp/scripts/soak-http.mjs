#!/usr/bin/env node

import assert from 'node:assert/strict'
import { spawn } from 'node:child_process'
import { mkdtempSync, readFileSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { writeJsonAtomic } from './acceptance/evidence-writer.mjs'
import {
	createManagedSoakRuntime,
	runManagedSoakLifecycle,
} from './managed-soak-runtime.mjs'
import { verifyCandidateImageIdentity } from './proxy-candidate-contract.mjs'
import { expectedReleaseIdentity } from './release-identity.mjs'
import { runSoak } from './soak-runner.mjs'
import { runSoakWorker } from './soak-worker.mjs'
import {
	MAX_P99_LATENCY_MS,
	resolveSoakPolicy,
	summariseSoak,
} from './soak-policy.mjs'

export { MAX_P99_LATENCY_MS, resolveSoakPolicy, summariseSoak }
export { runSoak }
export const SOAK_SCHEMA_VERSION = 4

export function verifySoakCandidateIdentity({
	expected,
	imageInspect,
	containerInspect,
}) {
	const inspectedIdentity = verifyCandidateImageIdentity(imageInspect, expected)
	assert.equal(
		containerInspect?.Image,
		inspectedIdentity.imageId,
		'running container image differs from the inspected candidate',
	)
	return inspectedIdentity
}

function argument(name, fallback) {
	const prefix = `--${name}=`
	const inline = process.argv.find((value) => value.startsWith(prefix))
	if (inline) return inline.slice(prefix.length)
	const index = process.argv.indexOf(`--${name}`)
	return index === -1 ? fallback : process.argv[index + 1]
}

function policyFromArguments() {
	const nodeEnv = process.env.NODE_ENV
	return resolveSoakPolicy({
		nodeEnv,
		durationSeconds: Number(argument('duration', nodeEnv === 'test' ? '2' : '300')),
		warmupSeconds: Number(argument('warmup', nodeEnv === 'test' ? '0.5' : '60')),
		intervalMs: Number(argument('interval-ms', nodeEnv === 'test' ? '50' : '1000')),
		mode: argument('mode', 'dynamic'),
	})
}

function terminationReason(signal) {
	return signal?.reason instanceof Error ? signal.reason : new Error('managed soak worker aborted')
}

export function runManagedWorker({
	args,
	env,
	resultDirectory,
	resultPath,
	timeoutMs,
	killGraceMs = 2_000,
	onSpawn = () => undefined,
	signal,
}) {
	return new Promise((settle, reject) => {
		const child = spawn(process.execPath, args, {
			env,
			shell: false,
			stdio: ['ignore', 'ignore', 'pipe'],
		})
		onSpawn(child)
		let tail = ''
		let forcedTimer
		let timeout
		let stoppedBy
		let finished = false
		const clean = () => {
			clearTimeout(timeout)
			clearTimeout(forcedTimer)
			signal?.removeEventListener('abort', abort)
			rmSync(resultDirectory, { recursive: true, force: true })
		}
		const finish = (callback, value) => {
			if (finished) return
			finished = true
			clean()
			callback(value)
		}
		const stop = (reason, requestedSignal = 'SIGTERM') => {
			if (stoppedBy) return
			stoppedBy = reason
			if (child.exitCode === null && child.signalCode === null) {
				child.kill(requestedSignal)
				forcedTimer = setTimeout(() => {
					if (child.exitCode === null && child.signalCode === null) child.kill('SIGKILL')
				}, killGraceMs)
			}
		}
		const abort = () => {
			const reason = terminationReason(signal)
			stop(reason, reason.signal ?? 'SIGTERM')
		}
		child.stderr.on('data', (chunk) => {
			tail = `${tail}${chunk}`.slice(-4000)
		})
		child.once('error', (error) => finish(reject, error))
		child.once('close', (status, childSignal) => {
			if (stoppedBy) return finish(reject, stoppedBy)
			try {
				const result = JSON.parse(readFileSync(resultPath, 'utf8'))
				if (childSignal) throw new Error(`managed soak worker was killed by ${childSignal}`)
				if (status !== 0 && result.outcome !== 'FAIL') {
					throw new Error(`managed soak worker failed: ${tail}`)
				}
				finish(settle, result)
			} catch (error) {
				finish(
					reject,
					error?.code === 'ENOENT'
						? new Error(`managed soak worker produced no result: ${tail}`)
						: error,
				)
			}
		})
		if (signal?.aborted) abort()
		else signal?.addEventListener('abort', abort, { once: true })
		timeout = setTimeout(
			() => stop(new Error(`managed soak worker timed out after ${timeoutMs} ms`)),
			timeoutMs,
		)
	})
}

function executeWorker(descriptor, expectedIdentity, { signal, timeoutMs }) {
	const resultDirectory = mkdtempSync(join(tmpdir(), `fcmcp-soak-result-${process.pid}-`))
	const resultPath = join(resultDirectory, 'result.json')
	const args = [
		fileURLToPath(import.meta.url),
		'--worker',
		...process.argv.slice(2).filter((value) => value !== '--worker'),
	]
	const env = {
		...process.env,
		NODE_EXTRA_CA_CERTS: descriptor.caPath,
		FLUENTCART_SOAK_RESULT_PATH: resultPath,
		FLUENTCART_SOAK_IMAGE: descriptor.image,
		FLUENTCART_SOAK_CONTAINER: descriptor.containerId,
		FLUENTCART_SOAK_URL: descriptor.url,
		FLUENTCART_SOAK_API_KEY: descriptor.apiKey,
		FLUENTCART_SOAK_CA_PATH: descriptor.caPath,
		FLUENTCART_SOAK_HOST: descriptor.host,
		FLUENTCART_SOAK_EXPECTED_IDENTITY: JSON.stringify(expectedIdentity),
	}
	return runManagedWorker({
		args,
		env,
		resultDirectory,
		resultPath,
		timeoutMs,
		signal,
	})
}

async function main() {
	const acceptanceSourceSha = argument('source-sha', process.env.FLUENTCART_ACCEPTANCE_SOURCE_SHA)
	const image = process.env.FLUENTCART_ACCEPTANCE_IMAGE
	const imageId = process.env.FLUENTCART_ACCEPTANCE_IMAGE_ID
	const imageDigest = process.env.FLUENTCART_ACCEPTANCE_IMAGE_DIGEST
	const runDirectory = process.env.FLUENTCART_ACCEPTANCE_RUN_DIR
	for (const [name, value] of Object.entries({
		acceptanceSourceSha,
		image,
		imageId,
		imageDigest,
		runDirectory,
	})) {
		if (!value) throw new Error(`soak requires ${name}`)
	}
	assert.match(acceptanceSourceSha, /^[0-9a-f]{40}$/)
	const releaseIdentity = expectedReleaseIdentity()
	const expectedIdentity = {
		imageId,
		imageDigest,
		candidateContentDigest: releaseIdentity.candidateContentDigest,
		sourceSha: releaseIdentity.sourceSha,
	}
	const policy = policyFromArguments()
	let runtimeDescriptor
	const summary = await runManagedSoakLifecycle({
		preserveSignal: true,
		startRuntime: async () => createManagedSoakRuntime({ image, expectedIdentity }),
		execute: async (descriptor, context) => {
			runtimeDescriptor = descriptor
			return executeWorker(descriptor, expectedIdentity, {
				...context,
				timeoutMs: policy.totalDurationMs + 120_000,
			})
		},
	})
	assert.deepEqual(summary.candidate, runtimeDescriptor.identity)
	writeJsonAtomic(join(runDirectory, 'soak.json'), {
		schemaVersion: SOAK_SCHEMA_VERSION,
		acceptanceSourceSha,
		candidateImage: image,
		candidate: summary.candidate,
		candidateContainer: runtimeDescriptor.containerId,
		runtime: {
			ownership: 'run-managed',
			tls: true,
			host: runtimeDescriptor.host,
			path: '/mcp',
			readiness: runtimeDescriptor.readiness,
			topology: runtimeDescriptor.topology,
		},
		...summary,
	})
	if (summary.outcome !== 'PASS') process.exitCode = 1
}

const direct = process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)
if (direct) {
	if (process.argv.includes('--worker')) await runSoakWorker(policyFromArguments())
	else await main()
}
