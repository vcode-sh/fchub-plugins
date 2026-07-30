#!/usr/bin/env node

import assert from 'node:assert/strict'
import { performance } from 'node:perf_hooks'
import { resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { resolveServerContext } from '../dist/server.js'
import { createAppFromContext } from '../dist/transport/http.js'
import {
	decodeJsonRpc,
	MODERN_PROTOCOL,
	modernHeaders,
	modernRequest,
} from './protocol-wire.mjs'

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

export function validateModernDiscoveryResult(payload) {
	assert.equal(payload?.jsonrpc, '2.0', 'benchmark response is not JSON-RPC 2.0')
	assert.ok(!payload.error, `benchmark discovery returned ${JSON.stringify(payload.error)}`)
	const result = payload.result
	assert.ok(
		Array.isArray(result?.supportedVersions) &&
			result.supportedVersions.includes(MODERN_PROTOCOL) &&
			result.protocolVersion === undefined,
		'benchmark response is not a modern server/discover result',
	)
	assert.equal(result.resultType, 'complete', 'benchmark discovery omitted resultType')
	assert.equal(result.ttlMs, 0, 'benchmark discovery omitted conservative ttlMs')
	assert.equal(result.cacheScope, 'private', 'benchmark discovery omitted private cacheScope')
	assert.equal(
		result._meta?.['io.modelcontextprotocol/serverInfo']?.name,
		'fluentcart-mcp',
		'benchmark discovery returned the wrong server identity',
	)
	return result
}

export async function discoverModern(url, id) {
	const startedAt = performance.now()
	const request = modernRequest({
		id,
		method: 'server/discover',
		clientInfo: { name: 'http-code-mode-benchmark', version: '1' },
	})
	const response = await fetch(url, {
		method: 'POST',
		headers: modernHeaders({ method: request.method }),
		body: JSON.stringify(request),
	})
	const text = await response.text()
	if (!response.ok) throw new Error(`server/discover returned HTTP ${response.status}`)
	const payload = decodeJsonRpc(text, response.headers.get('content-type') ?? '')
	assert.equal(payload.id, id, 'benchmark discovery response ID mismatch')
	validateModernDiscoveryResult(payload)
	return performance.now() - startedAt
}

async function measure(mode, context) {
	const { server, url } = await listen(createAppFromContext('127.0.0.1', context, mode))
	try {
		const coldMs = await discoverModern(url, 1)
		const warm = []
		for (let index = 0; index < WARM_SAMPLES; index += 1) {
			warm.push(await discoverModern(url, index + 2))
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

async function main() {
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
		if (result.coldMs > budget.coldMs) {
			exceeded.push(`cold ${result.coldMs} > ${budget.coldMs} ms`)
		}
		if (result.warmP95Ms > budget.warmP95Ms) {
			exceeded.push(`warm p95 ${result.warmP95Ms} > ${budget.warmP95Ms} ms`)
		}
		return exceeded.map((failure) => `${result.mode}: ${failure}`)
	})
	process.stdout.write(
		`${JSON.stringify({ samples: WARM_SAMPLES, budgets: BUDGETS, results, failures }, null, 2)}\n`,
	)
	if (process.argv.includes('--assert') && failures.length > 0) process.exitCode = 1
}

const direct = process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)
if (direct) {
	main().catch((error) => {
		process.stderr.write(`${error.message}\n`)
		process.exitCode = 1
	})
}
