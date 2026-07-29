import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'

const MARKER = 'FCMCP_TIMING_V4'
const TIMING_ROW =
	/^FCMCP_TIMING_V4\|(\d+(?:\.\d+)?)\|([1-5]\d{2})\|(\d+(?:\.\d+)?)\|(\d+(?:\.\d+)?|-)$/
const EXACT_CONTAINER_ID = /^[0-9a-f]{64}$/

function percentile(values, fraction) {
	assert.ok(values.length > 0, 'component timing evidence is missing')
	const ordered = [...values].sort((left, right) => left - right)
	return ordered[Math.max(1, Math.ceil(ordered.length * fraction)) - 1]
}

function rounded(value) {
	return Number(value.toFixed(2))
}

function aggregate(rows, field) {
	const values = rows.map((row) => row[field])
	return {
		count: values.length,
		p95: rounded(percentile(values, 0.95)),
		p99: rounded(percentile(values, 0.99)),
		max: rounded(Math.max(...values)),
	}
}

export function parseProxyTimingLog(log) {
	assert.equal(typeof log, 'string', 'proxy timing evidence must be text')
	if (!log.endsWith('\n')) throw new Error('proxy timing evidence lacks terminal newline')
	const lines = log.slice(0, -1).split('\n')
	assert.ok(lines.length > 0 && lines.every(Boolean), 'proxy timing evidence is missing')
	const rows = lines.map((line) => {
		const fields = TIMING_ROW.exec(line)
		if (!fields || line.split('|')[0] !== MARKER) throw new Error('malformed proxy timing evidence')
		const completedEpochMs = Number(fields[1]) * 1000
		const status = Number(fields[2])
		const durationMs = Number(fields[3]) * 1000
		const upstreamDurationMs = fields[4] === '-' ? null : Number(fields[4]) * 1000
		if (
			![completedEpochMs, durationMs].every(
				(value) => Number.isFinite(value) && value >= 0,
			) ||
			(upstreamDurationMs !== null &&
				(!Number.isFinite(upstreamDurationMs) || upstreamDurationMs < 0)) ||
			!Number.isInteger(status) ||
			status < 100 ||
			status > 599
		) {
			throw new Error('malformed proxy timing evidence')
		}
		return {
			startedAt: new Date(completedEpochMs - durationMs).toISOString(),
			durationMs: rounded(durationMs),
			upstreamDurationMs:
				upstreamDurationMs === null ? null : rounded(upstreamDurationMs),
			status,
		}
	})
	const slowest = [...rows]
		.sort((left, right) => right.durationMs - left.durationMs)
		.slice(0, 5)
	const upstreamRows = rows.filter((row) => row.upstreamDurationMs !== null)
	return {
		proxy: {
			...aggregate(rows, 'durationMs'),
			slowest,
		},
		upstream: {
			...aggregate(upstreamRows, 'upstreamDurationMs'),
			slowest: [...upstreamRows]
				.sort((left, right) => right.upstreamDurationMs - left.upstreamDurationMs)
				.slice(0, 5)
				.map((row) => ({ ...row })),
		},
	}
}

export function captureProxyTimingLog(containerId) {
	if (!EXACT_CONTAINER_ID.test(containerId ?? '')) {
		throw new Error('proxy timing capture requires an exact container ID')
	}
	try {
		return execFileSync(
			'docker',
			['exec', containerId, 'cat', '/tmp/fcmcp-timing.log'],
			{ encoding: 'utf8', timeout: 10_000 },
		)
	} catch {
		throw new Error('proxy timing capture failed')
	}
}
