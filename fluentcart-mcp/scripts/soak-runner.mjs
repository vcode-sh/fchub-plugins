import { monitorEventLoopDelay, performance } from 'node:perf_hooks'
import { setTimeout as delay } from 'node:timers/promises'
import { summariseSoak } from './soak-policy.mjs'

function timestamp(runStartedAtMs, offsetMs) {
	return new Date(runStartedAtMs + offsetMs).toISOString()
}

function eventLoopSummary(histogram) {
	const milliseconds = (nanoseconds) => Number((nanoseconds / 1e6).toFixed(2))
	return {
		p95: milliseconds(histogram.percentile(95)),
		p99: milliseconds(histogram.percentile(99)),
		max: milliseconds(histogram.max),
	}
}

export async function runSoak(policy, { read, sampleResources }) {
	const began = performance.now()
	const runStartedAtMs = Date.now()
	const readSamples = []
	const resourceSamplerSamples = []
	const schedulingLagSamples = []
	let totalRequests = 0
	let unexpectedErrors = 0
	let unhandledRejections = 0
	const onUnhandled = () => {
		unhandledRejections += 1
	}
	const eventLoop = monitorEventLoopDelay({ resolution: 10 })
	process.on('unhandledRejection', onUnhandled)
	eventLoop.enable()

	const captureResources = async (phase) => {
		const sampleStarted = performance.now()
		const sampleOffsetMs = sampleStarted - began
		const sample = await sampleResources()
		const measured = { atMs: sampleOffsetMs, ...sample }
		resourceSamplerSamples.push({
			phase,
			offsetMs: sampleOffsetMs,
			startedAt: timestamp(runStartedAtMs, sampleOffsetMs),
			durationMs: performance.now() - sampleStarted,
		})
		return measured
	}

	const runReadPhase = async (durationMs, recordLatency) => {
		const phaseBegan = performance.now()
		let nextRequestAtMs = 0
		while (true) {
			const waitMs = nextRequestAtMs - (performance.now() - phaseBegan)
			if (waitMs > 0) await delay(waitMs)
			const requestStarted = performance.now()
			const phaseOffsetMs = requestStarted - phaseBegan
			if (phaseOffsetMs >= durationMs) break
			const globalOffsetMs = requestStarted - began
			schedulingLagSamples.push({
				offsetMs: globalOffsetMs,
				startedAt: timestamp(runStartedAtMs, globalOffsetMs),
				durationMs: Math.max(0, phaseOffsetMs - nextRequestAtMs),
			})
			totalRequests += 1
			try {
				await read()
				if (recordLatency) {
					readSamples.push({
						offsetMs: globalOffsetMs,
						startedAt: timestamp(runStartedAtMs, globalOffsetMs),
						durationMs: performance.now() - requestStarted,
					})
				}
			} catch {
				unexpectedErrors += 1
			}
			nextRequestAtMs += policy.intervalMs
		}
		return performance.now() - phaseBegan
	}

	let warmResource
	let finalResource
	let stableElapsedMs
	try {
		await runReadPhase(policy.warmupMs, false)
		warmResource = await captureResources('warm-boundary')
		stableElapsedMs = await runReadPhase(policy.stableDurationMs, true)
		finalResource = await captureResources('final-boundary')
	} finally {
		eventLoop.disable()
		process.off('unhandledRejection', onUnhandled)
	}
	return summariseSoak({
		mode: policy.mode,
		readSamples,
		warmResources: [warmResource],
		finalResources: [finalResource],
		resourceSamplerSamples,
		schedulingLagSamples,
		eventLoopDelayMs: eventLoopSummary(eventLoop),
		unexpectedErrors,
		unhandledRejections,
		totalRequests,
		requiredStableDurationMs: policy.stableDurationMs,
		stableElapsedMs,
	})
}
