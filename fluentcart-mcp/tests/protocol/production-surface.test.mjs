// Production registry acceptance over the released HTTP protocol client.
//
// The committed route captures choose capabilities; this local server supplies synthetic results.
// It is deliberately not a live-store lane and never reads a FluentCart credential.

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createServer } from 'node:http'
import { dirname, join, resolve } from 'node:path'
import { after, before, describe, it } from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { Client, StreamableHTTPClientTransport } from '@modelcontextprotocol/client'

const PACKAGE_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..')
const PROTOCOLS = ['2025-11-25', '2026-07-28']
const MODES = ['dynamic', 'curated', 'code', 'full']
const PROFILE_FILES = {
	core: 'tests/fixtures/routes/fluentcart-1.5.5-core.json',
	'core-pro': 'tests/fixtures/routes/fluentcart-1.5.5-core-pro-1.5.4.json',
}
const PRO_ONLY_TOOL = 'fluentcart_pdf_template_list'
const CODE_NAMES = ['fluentcart_execute_code', 'fluentcart_search_api']
const ENV = {
	FLUENTCART_USERNAME: 'synthetic-fixture',
	FLUENTCART_APP_PASSWORD: 'synthetic-fixture',
	FLUENTCART_WRITE_MODE: 'disabled',
	FLUENTCART_ABILITIES_MODE: 'disabled',
}

let upstream
const profileSurfaces = new Map()

function distImport(...segments) {
	return import(pathToFileURL(join(PACKAGE_ROOT, 'dist', ...segments)).href)
}

function fixture(name) {
	return JSON.parse(readFileSync(join(PACKAGE_ROOT, PROFILE_FILES[name]), 'utf8'))
}

function restIndexFor(routeFixture) {
	const routes = {
		'/fluent-cart/v2': { endpoints: [{ methods: ['GET'] }] },
	}
	for (const { method, path } of routeFixture.operations) {
		const route = `/fluent-cart/v2${path === '/' ? '' : path}`
		routes[route] ??= { endpoints: [{ methods: [] }] }
		routes[route].endpoints[0].methods.push(method)
	}
	return { namespaces: ['fluent-cart/v2'], routes }
}

function deferred() {
	let resolvePromise
	const promise = new Promise((resolveDeferred) => {
		resolvePromise = resolveDeferred
	})
	return { promise, resolve: resolvePromise }
}

function within(promise, label, timeoutMs = 8_000) {
	let timer
	return Promise.race([
		promise,
		new Promise((_, reject) => {
			timer = setTimeout(() => reject(new Error(`${label} timed out`)), timeoutMs)
		}),
	]).finally(() => clearTimeout(timer))
}

class SyntheticUpstream {
	constructor() {
		this.document = null
		this.blocked = null
		this.orderReads = 0
		this.requestLog = []
		this.openResponses = new Set()
		this.server = createServer((request, response) => this.respond(request, response))
	}

	async start() {
		await new Promise((ready) => this.server.listen(0, '127.0.0.1', ready))
		this.url = `http://127.0.0.1:${this.server.address().port}`
	}

	useProfile(name) {
		const captured = fixture(name)
		assert.equal(captured.evidenceKind, 'live-rest-index')
		this.document = restIndexFor(captured)
	}

	blockNextOrderRead(label) {
		assert.equal(this.blocked, null, 'only one synthetic cancellation may be pending')
		const started = deferred()
		const cancelled = deferred()
		const timing = { label, receivedAt: null, cancelIssuedAt: null, closedAt: null }
		this.lastCancellationTiming = timing
		this.blocked = { started, cancelled, timing }
		return { started: started.promise, cancelled: cancelled.promise, timing }
	}

	respond(request, response) {
		const url = new URL(request.url, this.url)
		this.requestLog.push(`${request.method} ${url.pathname}`)
		if (
			request.method === 'GET' &&
			(url.pathname === '/wp-json/fluent-cart/v2' || url.pathname === '/wp-json/fluent-cart/v2/')
		) {
			return this.json(response, this.document)
		}
		if (request.method === 'GET' && url.pathname === '/wp-json/fluent-cart/v2/orders') {
			this.orderReads += 1
			if (this.blocked) {
				const pending = this.blocked
				this.blocked = null
				this.openResponses.add(response)
				pending.timing.receivedAt = Date.now()
				pending.started.resolve()
				let observed = false
				const cancelled = () => {
					if (observed) return
					observed = true
					this.openResponses.delete(response)
					pending.timing.closedAt = Date.now()
					pending.cancelled.resolve()
				}
				request.once('aborted', cancelled)
				response.once('close', cancelled)
				return
			}
			return this.json(response, {
				orders: {
					data: [
						{
							id: 42,
							receipt_number: 'synthetic-42',
							status: 'completed',
							payment_status: 'paid',
							currency: 'USD',
							total_amount: 1200,
							customer: { full_name: 'Protocol Fixture' },
						},
					],
					total: 1,
				},
			})
		}
		return this.json(response, { code: 'rest_no_route' }, 404)
	}

	json(response, body, status = 200) {
		response.writeHead(status, { 'Content-Type': 'application/json' })
		response.end(JSON.stringify(body))
	}

	async close() {
		for (const response of this.openResponses) response.destroy()
		await new Promise((closed) => this.server.close(closed))
	}
}

function clientFor(url, protocol) {
	const modern = protocol === '2026-07-28'
	const client = new Client(
		{ name: 'production-surface', version: '1.0.0' },
		{
			capabilities: {},
			supportedProtocolVersions: [protocol],
			versionNegotiation: { mode: modern ? { pin: protocol } : 'legacy' },
		},
	)
	const transport = new StreamableHTTPClientTransport(new URL(`${url}/mcp`))
	return { client, transport }
}

function textOf(result) {
	return (result.content ?? [])
		.filter((entry) => entry.type === 'text')
		.map((entry) => entry.text)
		.join('')
}

function toolCall(mode) {
	const input = { page: 1, per_page: 1 }
	if (mode === 'dynamic') {
		return {
			name: 'fluentcart_execute_read_tool',
			arguments: { tool_name: 'fluentcart_order_list', input },
		}
	}
	if (mode === 'code') {
		return {
			name: 'fluentcart_execute_code',
			arguments: {
				code: "const orders = await fluentcart.call('fluentcart_order_list', { page: 1, per_page: 1 }); return orders",
			},
		}
	}
	return { name: 'fluentcart_order_list', arguments: input }
}

function invalidCall(mode) {
	if (mode === 'dynamic') {
		return { name: 'fluentcart_search_tools', arguments: { query: 42 } }
	}
	if (mode === 'code') {
		return { name: 'fluentcart_search_api', arguments: { query: 42 } }
	}
	return { name: 'fluentcart_order_list', arguments: { per_page: 'many' } }
}

async function observeSurface(client) {
	const [tools, resources, prompts] = await Promise.all([
		client.listTools(),
		client.listResources(),
		client.listPrompts(),
	])
	return {
		tools: tools.tools
			.map(({ name, inputSchema, annotations }) => ({ name, inputSchema, annotations }))
			.sort((left, right) => left.name.localeCompare(right.name)),
		resources: resources.resources.map(({ name }) => name).sort(),
		prompts: prompts.prompts.map(({ name }) => name).sort(),
	}
}

async function assertInvalidIsBounded(client, mode) {
	const readsBefore = upstream.orderReads
	let result
	let thrown
	try {
		result = await client.callTool(invalidCall(mode))
	} catch (error) {
		thrown = error
	}
	assert.ok(thrown || result?.isError === true, `${mode} invalid arguments must return an error`)
	const message = thrown instanceof Error ? thrown.message : textOf(result)
	assert.ok(message.length > 0 && message.length <= 1_024, `${mode} error length ${message.length}`)
	assert.equal(upstream.orderReads, readsBefore, `${mode} invalid arguments reached the store`)
}

async function assertUpstreamCancellation(client, mode) {
	const event = upstream.blockNextOrderRead(mode)
	const controller = new AbortController()
	let rejection
	const call = client.callTool(toolCall(mode), { signal: controller.signal }).then(
		() => undefined,
		(error) => {
			rejection = error
		},
	)
	await within(event.started, `${mode} upstream start`)
	event.timing.cancelIssuedAt = Date.now()
	controller.abort(new Error('synthetic cancellation'))
	await within(event.cancelled, `${mode} upstream cancellation`, 2_000)
	await within(call, `${mode} client cancellation`)
	assert.ok(rejection, `${mode} client call did not report cancellation`)
	return event.timing
}

async function proCapabilityVisible(client, mode, surface) {
	if (mode === 'full') return surface.tools.some(({ name }) => name === PRO_ONLY_TOOL)
	if (mode === 'curated') return false
	const result = await client.callTool({
		name: mode === 'dynamic' ? 'fluentcart_search_tools' : 'fluentcart_search_api',
		arguments: { query: 'PDF templates', limit: mode === 'dynamic' ? 10 : 5 },
	})
	const payload = JSON.parse(textOf(result))
	const names =
		mode === 'dynamic'
			? (payload.tools ?? []).map(({ name }) => name)
			: (payload.operations ?? []).map(({ operation }) => operation)
	return names.includes(PRO_ONLY_TOOL)
}

async function openService(mode) {
	const { resolveHttpExposure } = await distImport('transport', 'http-config.js')
	const { startHttpService } = await distImport('transport', 'http.js')
	const { createMcpServerFactory, resolveRuntimeContext } = await distImport('server.js')
	const runtime = await resolveRuntimeContext(mode)
	return startHttpService(
		createMcpServerFactory(runtime, mode),
		0,
		resolveHttpExposure({ profile: 'local', host: '127.0.0.1' }),
		{ drainMs: 50 },
	)
}

function assertCapabilities(client) {
	const capabilities = client.getServerCapabilities()
	assert.equal(capabilities.tools?.listChanged, false)
	assert.equal(capabilities.resources?.listChanged, false)
	assert.equal(capabilities.prompts?.listChanged, false)
}

function assertReadOnlySurface(surface, mode) {
	for (const tool of surface.tools) {
		assert.equal(tool.annotations?.readOnlyHint, true, `${tool.name} widened`)
		assert.notEqual(tool.annotations?.destructiveHint, true, `${tool.name} destructive`)
	}
	if (mode === 'code') {
		assert.deepEqual(
			surface.tools.map(({ name }) => name),
			CODE_NAMES,
		)
	}
}

async function captureCancellation(client, mode, profile, protocol, t, failures) {
	try {
		const timing = await assertUpstreamCancellation(client, mode)
		t.diagnostic(`${profile}/${mode}/${protocol} cancellation ${JSON.stringify(timing)}`)
	} catch (error) {
		const timing = upstream.openResponses.size > 0 ? 'upstream-open' : 'upstream-closed'
		failures.push(
			`${protocol}: ${error instanceof Error ? error.message : String(error)} (${timing}; ${JSON.stringify(upstream.lastCancellationTiming)})`,
		)
	}
}

async function observeProtocolCell({ profile, mode, protocol, service, t, surfaces, failures }) {
	const requestOffset = upstream.requestLog.length
	const { client, transport } = clientFor(service.url, protocol)
	await client.connect(transport)
	try {
		assert.equal(client.getNegotiatedProtocolVersion(), protocol)
		assertCapabilities(client)

		const surface = await observeSurface(client)
		assertReadOnlySurface(surface, mode)

		const result = await client.callTool(toolCall(mode))
		assert.notEqual(result.isError, true, textOf(result))
		assert.match(textOf(result), /synthetic-42/)
		await assertInvalidIsBounded(client, mode)

		const proVisible = await proCapabilityVisible(client, mode, surface)
		assert.equal(
			proVisible,
			profile === 'core-pro' && mode !== 'curated',
			`${profile}/${mode}/${protocol} capability visibility`,
		)
		surfaces.set(protocol, surface)
		profileSurfaces.set(`${profile}:${mode}:${protocol}`, surface)
		await captureCancellation(client, mode, profile, protocol, t, failures)
		t.diagnostic(
			`${profile}/${mode}/${protocol} upstream ${upstream.requestLog.slice(requestOffset).join(' -> ')}`,
		)
	} finally {
		await client.close()
	}
}

before(async () => {
	upstream = new SyntheticUpstream()
	await upstream.start()
	Object.assign(process.env, ENV, { FLUENTCART_URL: upstream.url })
})

after(async () => {
	await upstream.close()
	for (const key of [...Object.keys(ENV), 'FLUENTCART_URL']) delete process.env[key]
})

describe('synthetic production surface', () => {
	for (const profile of Object.keys(PROFILE_FILES)) {
		for (const mode of MODES) {
			it(`${profile} ${mode} is identical, read-only and cancellable across both eras`, {
				timeout: 120_000,
			}, async (t) => {
				upstream.useProfile(profile)
				const startupOffset = upstream.requestLog.length
				const service = await openService(mode)
				const startupSequence = upstream.requestLog.slice(startupOffset)
				const surfaces = new Map()
				const failures = []
				try {
					for (const protocol of PROTOCOLS) {
						await observeProtocolCell({
							profile,
							mode,
							protocol,
							service,
							t,
							surfaces,
							failures,
						})
					}

					assert.deepEqual(surfaces.get(PROTOCOLS[1]), surfaces.get(PROTOCOLS[0]))
					assert.deepEqual(startupSequence, ['GET /wp-json/fluent-cart/v2'])
					t.diagnostic(`${profile}/${mode}: synthetic route fixture, not live runtime proof`)
					assert.deepEqual(failures, [])
				} finally {
					await service.close()
				}
			})
		}
	}

	it('derives Pro-only full-mode presence from the profile, never from protocol era', () => {
		for (const protocol of PROTOCOLS) {
			const core = profileSurfaces.get(`core:full:${protocol}`).tools.map(({ name }) => name)
			const pro = profileSurfaces.get(`core-pro:full:${protocol}`).tools.map(({ name }) => name)
			assert.ok(!core.includes(PRO_ONLY_TOOL), `${PRO_ONLY_TOOL} leaked into Core-only`)
			assert.ok(pro.includes(PRO_ONLY_TOOL), `${PRO_ONLY_TOOL} missing from Core+Pro`)
			assert.ok(pro.length > core.length, 'Core+Pro must expose its fixture-proven additions')
		}
	})
})
