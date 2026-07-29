import assert from 'node:assert/strict'
import { spawn } from 'node:child_process'
import { get } from 'node:http'
import { performance } from 'node:perf_hooks'
import { describe, it } from 'node:test'
import { setTimeout as delay } from 'node:timers/promises'
import { assertNoSecrets } from '../../scripts/acceptance/evidence-writer.mjs'
import { CandidateStore } from '../../scripts/candidate-store.mjs'
import {
	resolveSoakPolicy,
	runSoak,
	SOAK_SCHEMA_VERSION,
	summariseSoak,
} from '../../scripts/soak-http.mjs'
import { parseProxyTimingLog } from '../../scripts/soak-proxy-timing.mjs'
import { sampleContainerResources } from '../../scripts/soak-resource-sampler.mjs'

const MiB = 1024 * 1024
const CONTAINER_ID = 'a'.repeat(64)

function resources() {
	return [{ atMs: 0, rssBytes: 100 * MiB, openFds: 10 }]
}

function summaryFor(durations) {
	return summariseSoak({
		mode: 'dynamic',
		readSamples: durations.map((durationMs, offsetMs) => ({
			offsetMs,
			startedAt: new Date(offsetMs).toISOString(),
			durationMs,
		})),
		warmResources: resources(),
		finalResources: resources(),
		resourceSamplerSamples: [{ offsetMs: 0, startedAt: new Date(0).toISOString(), durationMs: 1 }],
		schedulingLagSamples: [{ offsetMs: 0, startedAt: new Date(0).toISOString(), durationMs: 0 }],
		eventLoopDelayMs: { p95: 1, p99: 1, max: 1 },
		unexpectedErrors: 0,
		unhandledRejections: 0,
	})
}

describe('schema-v4 stable read evidence', () => {
	it('excludes a read that starts before warm-up and completes after it', async () => {
		const policy = resolveSoakPolicy({
			nodeEnv: 'test',
			durationSeconds: 0.08,
			warmupSeconds: 0.04,
			intervalMs: 10,
			mode: 'dynamic',
		})
		let reads = 0
		const result = await runSoak(policy, {
			read: async () => {
				reads += 1
				if (reads === 1) await delay(50)
			},
			sampleResources: async () => ({ rssBytes: 100 * MiB, openFds: 10 }),
		})
		assert.ok(result.latencyMs.samples > 0)
		assert.ok(result.latencyMs.slowest.every(({ offsetMs }) => offsetMs >= policy.warmupMs))
		assert.equal(
			result.latencyMs.slowest.some(({ durationMs }) => durationMs >= 45),
			false,
		)
	})

	it('records literal tail percentiles, exceedances, and five payload-free slow reads', () => {
		const durations = [...Array(989).fill(10), ...Array(9).fill(20), 31, 1000]
		const result = summaryFor(durations)
		assert.equal(result.latencyMs.p99, 20)
		assert.equal(result.latencyMs.p99_9, 31)
		assert.equal(result.latencyMs.exceededThresholdCount, 1)
		assert.equal(result.latencyMs.slowest.length, 5)
		assert.deepEqual(result.latencyMs.slowest[0], {
			offsetMs: 999,
			startedAt: new Date(999).toISOString(),
			durationMs: 1000,
		})
		assert.deepEqual(Object.keys(result.latencyMs.slowest[0]), [
			'offsetMs',
			'startedAt',
			'durationMs',
		])
		assert.doesNotMatch(JSON.stringify(result.latencyMs), /payload-secret/)
	})

	it('passes the fixed p99 ceiling exactly and fails one epsilon above it', () => {
		const passing = summaryFor([...Array(98).fill(10), 250, 1000])
		assert.equal(passing.latencyMs.p99, 250)
		assert.equal(passing.outcome, 'PASS')
		const failed = summaryFor([...Array(98).fill(10), 250.01, 1000])
		assert.equal(failed.outcome, 'FAIL')
		assert.equal(failed.latencyMs.exceededThresholdCount, 2)
	})

	it('fails one pathological read even when p99 remains healthy', () => {
		const failed = summaryFor([...Array(999).fill(10), 1000.01])
		assert.equal(failed.latencyMs.p99, 10)
		assert.equal(failed.outcome, 'FAIL')
		assert.ok(failed.failures.includes('maximum latency 1000.01 ms exceeded 1000 ms'))
	})

	it('uses explicit p99 and absolute latency ceilings', () => {
		const result = summaryFor(Array(100).fill(100))
		assert.deepEqual(result.latencyPolicy, {
			kind: 'fixed-tail-ceilings',
			observed: 'stable-p99',
			p99CeilingMs: 250,
			absoluteCeilingMs: 1000,
		})
		assert.equal(result.thresholds.maxP99LatencyMs, 250)
		assert.equal(result.outcome, 'PASS')
	})
})

describe('schema-v4 worker and sampler evidence', () => {
	it('keeps slow resource sampling out of read latency', async () => {
		const policy = resolveSoakPolicy({
			nodeEnv: 'test',
			durationSeconds: 0.4,
			warmupSeconds: 0.1,
			intervalMs: 5,
			mode: 'dynamic',
		})
		let samples = 0
		const result = await runSoak(policy, {
			read: async () => undefined,
			sampleResources: async () => {
				samples += 1
				await delay(35)
				return { rssBytes: 100 * MiB, openFds: 10 }
			},
		})
		assert.equal(samples, 2)
		assert.equal(result.resourceSampler.count, 2)
		assert.equal(result.resourceSampler.strategy, 'phase-boundary')
		assert.ok(result.resourceSampler.durationMs.max >= 30)
		assert.ok(result.latencyMs.max < result.resourceSampler.durationMs.max)
		assert.ok(result.schedulingLagMs.max < result.resourceSampler.durationMs.max)
		assert.deepEqual(
			new Set(result.resourceSampler.slowest.map(({ phase }) => phase)),
			new Set(['warm-boundary', 'final-boundary']),
		)
		assert.equal(result.resourceSampler.slowest.length <= 5, true)
	})

	it('takes resource snapshots only at the warm and final phase boundaries', async () => {
		const policy = resolveSoakPolicy({
			nodeEnv: 'test',
			durationSeconds: 0.12,
			warmupSeconds: 0.02,
			intervalMs: 5,
			mode: 'dynamic',
		})
		const events = []
		let readActive = false
		await runSoak(policy, {
			read: async () => {
				readActive = true
				events.push('read')
				await delay(1)
				readActive = false
			},
			sampleResources: async () => {
				assert.equal(readActive, false)
				events.push('sample')
				return { rssBytes: 100 * MiB, openFds: 10 }
			},
		})
		assert.equal(events.filter((event) => event === 'sample').length, 2)
		assert.notEqual(events[0], 'sample')
		assert.equal(events.at(-1), 'sample')
		const warmBoundary = events.indexOf('sample')
		const finalBoundary = events.lastIndexOf('sample')
		assert.ok(finalBoundary > warmBoundary + 1)
		assert.ok(events.slice(warmBoundary + 1, finalBoundary).every((event) => event === 'read'))
	})

	it('keeps resource sampling at phase boundaries instead of configuring a cadence', () => {
		const production = resolveSoakPolicy({
			nodeEnv: 'production',
			durationSeconds: 300,
			warmupSeconds: 60,
			intervalMs: 1000,
			mode: 'dynamic',
		})
		assert.equal(Object.hasOwn(production, 'resourceIntervalMs'), false)
		assert.equal(Object.hasOwn(production, 'finalWindowMs'), false)
	})

	it('excludes boundary snapshot duration from the stable measurement', async () => {
		const policy = resolveSoakPolicy({
			nodeEnv: 'test',
			durationSeconds: 0.12,
			warmupSeconds: 0.02,
			intervalMs: 5,
			mode: 'dynamic',
		})
		const began = performance.now()
		const result = await runSoak(policy, {
			read: async () => undefined,
			sampleResources: async () => {
				await delay(35)
				return { rssBytes: 100 * MiB, openFds: 10 }
			},
		})
		const wallTime = performance.now() - began
		assert.ok(result.measurement.stableElapsedMs >= policy.stableDurationMs)
		assert.ok(result.measurement.stableElapsedMs < policy.stableDurationMs + 30)
		assert.ok(wallTime >= policy.totalDurationMs + 60)
	})

	it('samples an exact container asynchronously and fails closed on bad evidence', async () => {
		const execute = async () => ({ status: 0, stdout: '104857600 17\n', stderr: '' })
		assert.deepEqual(await sampleContainerResources(CONTAINER_ID, { execute }), {
			rssBytes: 104857600,
			openFds: 17,
		})
		await assert.rejects(
			sampleContainerResources(CONTAINER_ID, {
				execute: async () => ({ status: 2, stdout: '', stderr: 'failed' }),
			}),
			/resource sampling failed/,
		)
		await assert.rejects(
			sampleContainerResources(CONTAINER_ID, {
				execute: async () => ({ status: 0, stdout: 'not numbers', stderr: '' }),
			}),
			/malformed resource sample/,
		)
		await assert.rejects(sampleContainerResources('', { execute }), /exact container ID/)
		await assert.rejects(
			sampleContainerResources('wrong-target', { execute }),
			/exact container ID/,
		)
	})

	it('owns one timeout and rejects only after the killed sampler process closes', async () => {
		let child
		let closed = false
		await assert.rejects(
			sampleContainerResources(CONTAINER_ID, {
				timeoutMs: 20,
				spawnProcess: () => {
					child = spawn(process.execPath, ['--eval', 'setInterval(() => {}, 1000)'])
					child.once('close', () => {
						closed = true
					})
					return child
				},
			}),
			/resource sampling timed out/,
		)
		assert.ok(child)
		assert.equal(closed, true)
		assert.notEqual(child.signalCode, null)
		assert.throws(() => process.kill(child.pid, 0))
	})

	it('aborts promptly after sampler failure and never reschedules it', async () => {
		const policy = resolveSoakPolicy({
			nodeEnv: 'test',
			durationSeconds: 0.2,
			warmupSeconds: 0.02,
			intervalMs: 5,
			mode: 'dynamic',
		})
		let samples = 0
		const began = performance.now()
		await assert.rejects(
			runSoak(policy, {
				read: async () => undefined,
				sampleResources: async () => {
					samples += 1
					await delay(10)
					throw new Error('sampler stopped')
				},
			}),
			/sampler stopped/,
		)
		assert.ok(performance.now() - began < 100)
		assert.equal(samples, 1)
	})

	it('fails when the final boundary snapshot fails', async () => {
		const policy = resolveSoakPolicy({
			nodeEnv: 'test',
			durationSeconds: 0.05,
			warmupSeconds: 0.01,
			intervalMs: 5,
			mode: 'dynamic',
		})
		let samples = 0
		await assert.rejects(
			runSoak(policy, {
				read: async () => undefined,
				sampleResources: async () => {
					samples += 1
					if (samples === 2) throw new Error('final snapshot failed')
					return { rssBytes: 100 * MiB, openFds: 10 }
				},
			}),
			/final snapshot failed/,
		)
		assert.equal(samples, 2)
	})
})

describe('schema-v4 component boundaries and privacy', () => {
	it('parses complete payload-free proxy timing and rejects malformed or truncated rows', () => {
		const log = [
			'FCMCP_TIMING_V4|1750000000.250|200|0.125|0.100',
			'FCMCP_TIMING_V4|1750000001.500|429|0.250|-',
			'FCMCP_TIMING_V4|1750000002.750|200|0.050|0.040',
			'',
		].join('\n')
		const result = parseProxyTimingLog(log)
		assert.equal(result.proxy.count, 3)
		assert.equal(result.proxy.p95, 250)
		assert.equal(result.upstream.count, 2)
		assert.equal(result.upstream.p99, 100)
		assert.deepEqual(result.proxy.slowest[0], {
			startedAt: '2025-06-15T15:06:41.250Z',
			durationMs: 250,
			upstreamDurationMs: null,
			status: 429,
		})
		assert.equal(
			result.upstream.slowest.some(({ upstreamDurationMs }) => upstreamDurationMs === null),
			false,
		)
		assert.doesNotThrow(() => assertNoSecrets({ componentTimings: result }, 'soak.json'))
		assert.doesNotMatch(JSON.stringify(result), /raw|wp-json|authorization|payload/i)
		assert.throws(
			() => parseProxyTimingLog('FCMCP_TIMING_V4||200||\n'),
			/malformed proxy timing evidence/,
		)
		assert.throws(
			() => parseProxyTimingLog('FCMCP_TIMING_V4|   |200| \t | \n'),
			/malformed proxy timing evidence/,
		)
		assert.throws(
			() => parseProxyTimingLog('FCMCP_TIMING_V4|1750000000.250|200|0.125|0.100'),
			/terminal newline/,
		)
		assert.throws(
			() => parseProxyTimingLog(`${log}GET /wp-json/customer Authorization: secret\n`),
			/malformed proxy timing evidence/,
		)
		assert.throws(
			() => parseProxyTimingLog('FCMCP_TIMING_V4|1750000000.250|200|0.125\n'),
			/malformed proxy timing evidence/,
		)
		for (const malformed of [
			'FCMCP_TIMING_V4|-|200|0.125|0.100\n',
			'FCMCP_TIMING_V4|1750000000.250|-|0.125|0.100\n',
			'FCMCP_TIMING_V4|1750000000.250|200|-|0.100\n',
			'FCMCP_TIMING_V4|1750000000.250|200|0.125|--\n',
			'FCMCP_TIMING_V4|1750000000.250|200|0.125|n/a\n',
			'FCMCP_TIMING_V4|1750000000.250|200|0.125| \n',
			'FCMCP_TIMING_V4|1750000000.250|200|0.125|-|junk\n',
		]) {
			assert.throws(() => parseProxyTimingLog(malformed), /malformed proxy timing evidence/)
		}
		assert.throws(
			() => parseProxyTimingLog('FCMCP_TIMING_V4|1750000000.250|429|0.125|-\n'),
			/component timing evidence is missing/,
		)
	})

	it('keeps CandidateStore timing bounded and aggregate-only', async () => {
		const store = new CandidateStore()
		try {
			await store.start()
			const port = store.server.address().port
			await new Promise((resolve, reject) => {
				get(
					{
						host: '127.0.0.1',
						port,
						path: '/wp-json/fluent-cart/v2/orders?customer=private',
						headers: { Authorization: 'Bearer private', 'X-Payload': 'private' },
					},
					(response) => {
						response.resume()
						response.once('end', resolve)
					},
				).once('error', reject)
			})
			const summary = store.timingSummary()
			assert.equal(summary.count, 1)
			assert.equal(summary.slowest.length, 1)
			assert.deepEqual(Object.keys(summary.slowest[0]), ['startedAt', 'durationMs', 'status'])
			assert.doesNotMatch(
				JSON.stringify(summary),
				/authorization|private|customer|payload|query|wp-json/i,
			)
		} finally {
			await store.close()
		}
	})

	it('records an aborted held CandidateStore response honestly and bounds more than five rows', async () => {
		const store = new CandidateStore()
		try {
			await store.start()
			store.holdReads()
			const port = store.server.address().port
			const aborted = get({
				host: '127.0.0.1',
				port,
				path: '/wp-json/fluent-cart/v2/orders',
			})
			aborted.once('error', () => undefined)
			await store.waitForHeld(1)
			store.releaseHeld()
			await new Promise((resolve) => aborted.once('close', resolve))
			await delay(10)
			const afterAbort = store.timingSummary()
			assert.equal(afterAbort.count, 1)
			assert.equal(afterAbort.slowest[0].status, 499)

			for (let index = 0; index < 7; index += 1) {
				await new Promise((resolve, reject) => {
					get(
						{
							host: '127.0.0.1',
							port,
							path: '/wp-json/fluent-cart/v2/orders',
						},
						(response) => {
							response.resume()
							response.once('end', resolve)
						},
					).once('error', reject)
				})
			}
			const summary = store.timingSummary()
			assert.equal(summary.count, 8)
			assert.equal(summary.slowest.length, 5)
			assert.ok(summary.slowest.every(({ status }) => status === 200 || status === 499))
		} finally {
			await store.close()
		}
	})

	it('serialises schema 4 evidence without forbidden request data', () => {
		const result = {
			schemaVersion: SOAK_SCHEMA_VERSION,
			...summaryFor([10, 11, 12]),
			componentTimings: parseProxyTimingLog('FCMCP_TIMING_V4|1750000000.250|200|0.125|0.100\n'),
		}
		assert.equal(result.schemaVersion, 4)
		assert.doesNotMatch(
			JSON.stringify(result),
			/authorization|password|customer|payload|query|\/wp-json/i,
		)
	})

	it('keeps every positive runtime summary count paired with bounded slowest rows', async () => {
		const store = new CandidateStore()
		try {
			await store.start()
			const port = store.server.address().port
			await new Promise((resolve, reject) => {
				get({ host: '127.0.0.1', port, path: '/wp-json/fluent-cart/v2/orders' }, (response) => {
					response.resume()
					response.once('end', resolve)
				}).once('error', reject)
			})

			const summary = summaryFor([10, 11, 12])
			const componentTimings = parseProxyTimingLog(
				[
					'FCMCP_TIMING_V4|1750000000.250|200|0.125|0.100',
					'FCMCP_TIMING_V4|1750000001.500|200|0.250|0.200',
					'',
				].join('\n'),
			)
			const counted = [
				{ count: summary.latencyMs.samples, slowest: summary.latencyMs.slowest },
				summary.resourceSampler,
				summary.schedulingLagMs,
				componentTimings.proxy,
				componentTimings.upstream,
				store.timingSummary(),
			]
			for (const section of counted) {
				assert.equal(section.slowest.length, Math.min(section.count, 5))
			}
		} finally {
			await store.close()
		}
	})
})
