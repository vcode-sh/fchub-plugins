#!/usr/bin/env node

import { performance } from 'node:perf_hooks'
import { resolveServerContext } from '../dist/server.js'
import { createAppFromContext } from '../dist/transport/http.js'

const MODES = ['dynamic', 'curated', 'code']
const WARM_SAMPLES = Number(process.env.FLUENTCART_BENCHMARK_SAMPLES ?? 7)
// Roughly three times the stable post-change p95 on the audit machine. These are regression
// ceilings, not performance claims: enough headroom for shared CI while still catching repeated
// Code Mode module loading and self-testing (observed at 30-74 ms warm p95 before reuse).
const BUDGETS = {
	dynamic: { coldMs: 150, warmP95Ms: 25 },
	curated: { coldMs: 50, warmP95Ms: 15 },
	code: { coldMs: 250, warmP95Ms: 30 },
}

function percentile(values, fraction) {
	const sorted = [...values].sort((a, b) => a - b)
	return sorted[Math.min(sorted.length - 1, Math.floor(sorted.length * fraction))]
}

function listen(app) {
	return new Promise((resolve, reject) => {
		const server = app.listen(0, '127.0.0.1', () => {
			const address = server.address()
			if (!address || typeof address === 'string') {
				reject(new Error('benchmark server did not expose a TCP address'))
				return
			}
			resolve({ server, url: `http://127.0.0.1:${address.port}/mcp` })
		})
	})
}

function close(server) {
	return new Promise((resolve, reject) => {
		server.close((error) => (error ? reject(error) : resolve()))
	})
}

async function initialise(url, id) {
	const startedAt = performance.now()
	const response = await fetch(url, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			Accept: 'application/json, text/event-stream',
		},
		body: JSON.stringify({
			jsonrpc: '2.0',
			id,
			method: 'initialize',
			params: {
				protocolVersion: '2026-07-28',
				capabilities: {},
				clientInfo: { name: 'http-code-mode-benchmark', version: '1' },
			},
		}),
	})
	await response.text()
	if (!response.ok) throw new Error(`initialize returned HTTP ${response.status}`)
	return performance.now() - startedAt
}

async function measure(mode, context) {
	const { server, url } = await listen(createAppFromContext('127.0.0.1', context, mode))
	try {
		const coldMs = await initialise(url, 1)
		const warm = []
		for (let index = 0; index < WARM_SAMPLES; index += 1) {
			warm.push(await initialise(url, index + 2))
		}
		return {
			mode,
			coldMs: Number(coldMs.toFixed(2)),
			warmSamples: warm.length,
			warmMedianMs: Number(percentile(warm, 0.5).toFixed(2)),
			warmP95Ms: Number(percentile(warm, 0.95).toFixed(2)),
			warmMinMs: Number(Math.min(...warm).toFixed(2)),
			warmMaxMs: Number(Math.max(...warm).toFixed(2)),
		}
	} finally {
		await close(server)
	}
}

process.env.FLUENTCART_URL = 'https://benchmark.invalid'
process.env.FLUENTCART_USERNAME = 'benchmark'
process.env.FLUENTCART_APP_PASSWORD = 'benchmark'
process.env.FLUENTCART_WRITE_MODE = 'disabled'

const context = resolveServerContext()
const results = []
for (const mode of MODES) results.push(await measure(mode, context))
const failures = results.flatMap((result) => {
	const budget = BUDGETS[result.mode]
	const exceeded = []
	if (result.coldMs > budget.coldMs) exceeded.push(`cold ${result.coldMs} > ${budget.coldMs} ms`)
	if (result.warmP95Ms > budget.warmP95Ms) {
		exceeded.push(`warm p95 ${result.warmP95Ms} > ${budget.warmP95Ms} ms`)
	}
	return exceeded.map((failure) => `${result.mode}: ${failure}`)
})
process.stdout.write(
	`${JSON.stringify({ samples: WARM_SAMPLES, budgets: BUDGETS, results, failures }, null, 2)}\n`,
)
if (process.argv.includes('--assert') && failures.length > 0) process.exitCode = 1
