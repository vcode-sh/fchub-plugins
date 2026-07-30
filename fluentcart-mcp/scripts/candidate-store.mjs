import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createServer } from 'node:http'
import { dirname, join, resolve } from 'node:path'
import { performance } from 'node:perf_hooks'
import { setTimeout as delay } from 'node:timers/promises'
import { fileURLToPath } from 'node:url'

const PACKAGE_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const ROUTES = join(PACKAGE_ROOT, 'tests/fixtures/routes/fluentcart-1.6.0-core-pro-1.6.0.json')

function deferred() {
	let resolvePromise
	const promise = new Promise((resolveDeferred) => {
		resolvePromise = resolveDeferred
	})
	return { promise, resolve: resolvePromise }
}

function restIndex() {
	const fixture = JSON.parse(readFileSync(ROUTES, 'utf8'))
	const routes = { '/fluent-cart/v2': { endpoints: [{ methods: ['GET'] }] } }
	for (const { method, path } of fixture.operations) {
		const route = `/fluent-cart/v2${path === '/' ? '' : path}`
		routes[route] ??= { endpoints: [{ methods: [] }] }
		routes[route].endpoints[0].methods.push(method)
	}
	return { namespaces: ['fluent-cart/v2'], routes }
}

function percentile(values, fraction) {
	const ordered = [...values].sort((left, right) => left - right)
	return ordered[Math.max(1, Math.ceil(ordered.length * fraction)) - 1]
}

function rounded(value) {
	return Number(value.toFixed(2))
}

export class CandidateStore {
	constructor() {
		this.index = restIndex()
		this.blocked = null
		this.hold = false
		this.held = new Set()
		this.pendingBlocked = new Set()
		this.sockets = new Set()
		this.timingDurations = []
		this.timingSlowest = []
		this.server = createServer((request, response) => this.respond(request, response))
		this.server.on('connection', (socket) => {
			this.sockets.add(socket)
			socket.once('close', () => this.sockets.delete(socket))
		})
	}

	async start() {
		await new Promise((ready, reject) => {
			const failed = (error) => reject(error)
			this.server.once('error', failed)
			this.server.listen(0, '127.0.0.1', () => {
				this.server.off('error', failed)
				ready()
			})
		})
		this.url = `http://host.docker.internal:${this.server.address().port}`
	}

	blockNextRead() {
		assert.equal(this.blocked, null, 'a candidate cancellation is already pending')
		const started = deferred()
		const cancelled = deferred()
		this.blocked = { started, cancelled }
		return { started: started.promise, cancelled: cancelled.promise }
	}

	holdReads() {
		this.hold = true
	}

	async waitForHeld(count) {
		for (let attempt = 0; attempt < 100; attempt += 1) {
			if (this.held.size >= count) return
			await delay(25)
		}
		throw new Error(`candidate store received ${this.held.size} held reads, expected ${count}`)
	}

	releaseHeld() {
		this.hold = false
		for (const response of this.held) response.destroy()
		this.held.clear()
	}

	respond(request, response) {
		const began = performance.now()
		const startedAt = new Date().toISOString()
		let recorded = false
		const recordTiming = (status) => {
			if (recorded) return
			recorded = true
			const row = {
				startedAt,
				durationMs: rounded(performance.now() - began),
				status,
			}
			this.timingDurations.push(row.durationMs)
			this.timingSlowest.push(row)
			this.timingSlowest.sort((left, right) => right.durationMs - left.durationMs)
			this.timingSlowest.length = Math.min(this.timingSlowest.length, 5)
		}
		response.once('finish', () => recordTiming(response.statusCode))
		response.once('close', () =>
			recordTiming(response.writableFinished ? response.statusCode : 499),
		)
		const url = new URL(request.url, 'http://candidate-store.invalid')
		if (
			request.method === 'GET' &&
			(url.pathname === '/wp-json/fluent-cart/v2' || url.pathname === '/wp-json/fluent-cart/v2/')
		) {
			return this.json(response, this.index)
		}
		if (request.method === 'GET' && url.pathname === '/wp-json/fluent-cart/v2/orders') {
			if (this.blocked) return this.block(request, response)
			if (this.hold) {
				this.held.add(response)
				response.once('close', () => this.held.delete(response))
				return
			}
			return this.json(response, {
				orders: {
					data: [{ id: 42, status: 'completed', total_amount: 1200 }],
					total: 1,
				},
			})
		}
		return this.json(response, { code: 'rest_no_route' }, 404)
	}

	timingSummary() {
		assert.ok(this.timingDurations.length > 0, 'candidate store timing evidence is missing')
		return {
			count: this.timingDurations.length,
			p95: rounded(percentile(this.timingDurations, 0.95)),
			p99: rounded(percentile(this.timingDurations, 0.99)),
			max: rounded(Math.max(...this.timingDurations)),
			slowest: this.timingSlowest.map((row) => ({ ...row })),
		}
	}

	block(request, response) {
		const pending = this.blocked
		this.blocked = null
		const connection = { request, response }
		this.pendingBlocked.add(connection)
		let observed = false
		const cancelled = () => {
			if (observed) return
			observed = true
			this.pendingBlocked.delete(connection)
			pending.cancelled.resolve()
		}
		request.once('aborted', cancelled)
		response.once('close', cancelled)
		pending.started.resolve()
	}

	json(response, body, status = 200) {
		response.writeHead(status, { 'Content-Type': 'application/json' })
		response.end(JSON.stringify(body))
	}

	async close() {
		this.releaseHeld()
		if (!this.server.listening) return
		const closing = new Promise((closed, reject) => {
			this.server.close((error) => (error ? reject(error) : closed()))
		})
		for (const { request, response } of this.pendingBlocked) {
			request.destroy()
			response.destroy()
		}
		for (const socket of this.sockets) socket.destroy()
		this.server.closeIdleConnections?.()
		this.server.closeAllConnections?.()
		await closing
	}
}

export async function openCandidateStore(externalUrl, dependencies = {}) {
	if (externalUrl) {
		return { url: externalUrl, owned: false, close: async () => undefined }
	}
	const store = (dependencies.createStore ?? (() => new CandidateStore()))()
	try {
		await store.start()
	} catch (error) {
		await store.close()
		throw error
	}
	let closed = false
	return {
		url: store.url,
		owned: true,
		close: async () => {
			if (closed) return
			closed = true
			await store.close()
		},
	}
}

export function installSignalCleanup(cleanup) {
	let closing = false
	const handlers = Object.fromEntries(
		['SIGINT', 'SIGTERM'].map((signal) => [
			signal,
			() => {
				if (closing) return
				closing = true
				remove()
				Promise.resolve()
					.then(cleanup)
					.finally(() => process.kill(process.pid, signal))
			},
		]),
	)
	const remove = () => {
		for (const [signal, handler] of Object.entries(handlers)) process.off(signal, handler)
	}
	for (const [signal, handler] of Object.entries(handlers)) process.once(signal, handler)
	return remove
}
