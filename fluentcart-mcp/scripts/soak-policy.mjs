import assert from 'node:assert/strict'

export const MAX_P99_LATENCY_MS = { dynamic: 250, curated: 250, code: 250, full: 250 }
const ABSOLUTE_MAX_LATENCY_MS = 1000

const MiB = 1024 * 1024
const RELEASE_DURATION_SECONDS = 300
const RELEASE_WARMUP_SECONDS = 60

function finitePositive(value, label) {
	if (!Number.isFinite(value) || value <= 0) {
		throw new Error(`${label} must be a positive number`)
	}
	return value
}

export function resolveSoakPolicy({
	nodeEnv,
	durationSeconds,
	warmupSeconds,
	intervalMs,
	mode,
}) {
	assert.ok(Object.hasOwn(MAX_P99_LATENCY_MS, mode), `unsupported soak mode ${mode}`)
	finitePositive(durationSeconds, 'duration')
	finitePositive(warmupSeconds, 'warm-up')
	finitePositive(intervalMs, 'interval')
	if (nodeEnv !== 'test' && durationSeconds < RELEASE_DURATION_SECONDS) {
		throw new Error('release soak requires at least 300 stable seconds')
	}
	if (nodeEnv !== 'test' && warmupSeconds < RELEASE_WARMUP_SECONDS) {
		throw new Error('release soak requires at least 60 seconds of warm-up')
	}
	if (nodeEnv !== 'test' && intervalMs < 1000) {
		throw new Error('release soak interval must be at least 1000 ms')
	}
	const stableDurationMs = durationSeconds * 1000
	const warmupMs = warmupSeconds * 1000
	const totalDurationMs = stableDurationMs + warmupMs
	return {
		nodeEnv,
		mode,
		stableDurationMs,
		warmupMs,
		totalDurationMs,
		intervalMs,
	}
}

function percentile(values, fraction) {
	assert.ok(values.length > 0, 'a percentile requires samples')
	const ordered = [...values].sort((left, right) => left - right)
	return ordered[Math.max(1, Math.ceil(ordered.length * fraction)) - 1]
}

function rounded(value) {
	return Number(value.toFixed(2))
}

function normaliseReadSamples(input) {
	if (input.readSamples) return input.readSamples
	return input.latenciesMs.map((durationMs, offsetMs) => ({
		offsetMs,
		startedAt: new Date(offsetMs).toISOString(),
		durationMs,
	}))
}

function slowest(samples, field = 'durationMs') {
	return [...samples]
		.sort((left, right) => right[field] - left[field])
		.slice(0, 5)
		.map((sample) =>
			Object.fromEntries(
				Object.entries(sample).map(([key, value]) => [
					key,
					typeof value === 'number' ? rounded(value) : value,
				]),
			),
		)
}

function durationSummary(samples) {
	if (samples.length === 0) {
		return { count: 0, min: null, median: null, p95: null, p99: null, max: null }
	}
	const values = samples.map(({ durationMs }) => durationMs)
	return {
		count: values.length,
		min: rounded(Math.min(...values)),
		median: rounded(percentile(values, 0.5)),
		p95: rounded(percentile(values, 0.95)),
		p99: rounded(percentile(values, 0.99)),
		max: rounded(Math.max(...values)),
	}
}

export function summariseSoak(input) {
	const {
		mode,
		warmResources,
		finalResources,
		unexpectedErrors,
		unhandledRejections,
	} = input
	const readSamples = normaliseReadSamples(input)
	const latenciesMs = readSamples.map(({ durationMs }) => durationMs)
	const totalRequests = input.totalRequests ?? latenciesMs.length + unexpectedErrors
	assert.ok(latenciesMs.length > 0, 'soak collected no stable latency samples')
	assert.ok(warmResources.length > 0, 'soak collected no warm resource samples')
	assert.ok(finalResources.length > 0, 'soak collected no final resource samples')
	const warmSnapshotRss = percentile(
		warmResources.map(({ rssBytes }) => rssBytes),
		0.5,
	)
	const finalSnapshotRss = percentile(
		finalResources.map(({ rssBytes }) => rssBytes),
		0.5,
	)
	const warmSnapshotFds = percentile(
		warmResources.map(({ openFds }) => openFds),
		0.5,
	)
	const finalFds = finalResources.at(-1).openFds
	const p95 = percentile(latenciesMs, 0.95)
	const p99 = percentile(latenciesMs, 0.99)
	const maxLatency = Math.max(...latenciesMs)
	const thresholds = {
		unexpectedErrors: 0,
		unhandledRejections: 0,
		maxFdGrowth: 5,
		maxRssGrowthBytes: Math.max(64 * MiB, warmSnapshotRss * 0.25),
		maxP99LatencyMs: MAX_P99_LATENCY_MS[mode],
		maxAbsoluteLatencyMs: ABSOLUTE_MAX_LATENCY_MS,
	}
	const resources = {
		warmSnapshotRssBytes: warmSnapshotRss,
		finalSnapshotRssBytes: finalSnapshotRss,
		rssGrowthBytes: finalSnapshotRss - warmSnapshotRss,
		warmSnapshotOpenFds: warmSnapshotFds,
		finalSnapshotOpenFds: finalFds,
		fdGrowth: finalFds - warmSnapshotFds,
	}
	const resourceSamplerDuration = durationSummary(input.resourceSamplerSamples ?? [])
	const failures = []
	if (unexpectedErrors > 0) failures.push(`unexpected errors ${unexpectedErrors} exceeded 0`)
	if (unhandledRejections > 0) {
		failures.push(`unhandled rejections ${unhandledRejections} exceeded 0`)
	}
	if (resources.fdGrowth > thresholds.maxFdGrowth) {
		failures.push(`file descriptor growth ${resources.fdGrowth} exceeded ${thresholds.maxFdGrowth}`)
	}
	if (resources.rssGrowthBytes > thresholds.maxRssGrowthBytes) {
		failures.push(
			`final snapshot rss growth ${resources.rssGrowthBytes} bytes exceeded ${thresholds.maxRssGrowthBytes} bytes`,
		)
	}
	if (p99 > thresholds.maxP99LatencyMs) {
		failures.push(
			`p99 latency ${rounded(p99)} ms exceeded ${rounded(thresholds.maxP99LatencyMs)} ms`,
		)
	}
	if (maxLatency > thresholds.maxAbsoluteLatencyMs) {
		failures.push(
			`maximum latency ${rounded(maxLatency)} ms exceeded ${thresholds.maxAbsoluteLatencyMs} ms`,
		)
	}
	const requiredStableDurationMs = input.requiredStableDurationMs ?? null
	const stableElapsedMs = input.stableElapsedMs ?? null
	if (
		requiredStableDurationMs !== null &&
		(stableElapsedMs === null || stableElapsedMs < requiredStableDurationMs)
	) {
		failures.push(
			`stable measurement ${stableElapsedMs ?? 0} ms did not reach required ${requiredStableDurationMs} ms`,
		)
	}
	return {
		requests: {
			total: totalRequests,
			succeeded: totalRequests - unexpectedErrors,
			failed: unexpectedErrors,
		},
		measurement: { requiredStableDurationMs, stableElapsedMs },
		latencyMs: {
			samples: latenciesMs.length,
			min: rounded(Math.min(...latenciesMs)),
			median: rounded(percentile(latenciesMs, 0.5)),
			p95: rounded(p95),
			p99: rounded(p99),
			p99_9: rounded(percentile(latenciesMs, 0.999)),
			max: rounded(maxLatency),
			exceededThresholdCount: latenciesMs.filter(
				(durationMs) => durationMs > thresholds.maxP99LatencyMs,
			).length,
			slowest: slowest(readSamples),
		},
		latencyPolicy: {
			kind: 'fixed-tail-ceilings',
			observed: 'stable-p99',
			p99CeilingMs: MAX_P99_LATENCY_MS[mode],
			absoluteCeilingMs: ABSOLUTE_MAX_LATENCY_MS,
		},
		resources,
		resourceSampler: {
			strategy: 'phase-boundary',
			count: resourceSamplerDuration.count,
			durationMs: {
				min: resourceSamplerDuration.min,
				median: resourceSamplerDuration.median,
				p95: resourceSamplerDuration.p95,
				max: resourceSamplerDuration.max,
			},
			slowest: slowest(input.resourceSamplerSamples ?? []),
		},
		schedulingLagMs: {
			...durationSummary(input.schedulingLagSamples ?? []),
			slowest: slowest(input.schedulingLagSamples ?? []),
		},
		eventLoopDelayMs: input.eventLoopDelayMs ?? { p95: null, p99: null, max: null },
		thresholds,
		unexpectedErrors,
		unhandledRejections,
		failures,
		outcome: failures.length === 0 ? 'PASS' : 'FAIL',
	}
}
