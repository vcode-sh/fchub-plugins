#!/usr/bin/env node
/**
 * Deterministic measurement of the MCP tool-definition payload.
 *
 * Measures the real `tools/list` result the *built* server hands to its transport, not the source
 * tool objects: only the wire shape costs a caller context, and only the built server proves what
 * that shape is. A deliberate 2025 compatibility connection runs in memory and the outgoing
 * JSON-RPC message is
 * recorded verbatim. No network call is made; the client points at an unresolvable fixture host.
 */

import { execFileSync } from 'node:child_process'
import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs'
import { createRequire } from 'node:module'
import { dirname, join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { encode as encodeCl100k } from 'gpt-tokenizer/encoding/cl100k_base'
import { encode as encodeO200k } from 'gpt-tokenizer/encoding/o200k_base'

const require = createRequire(import.meta.url)
const PACKAGE_ROOT = dirname(dirname(fileURLToPath(import.meta.url)))

export const SERIALIZER = 'mcp-tools-list-v1'
export const TOKENIZER = 'gpt-tokenizer@3.4.0'
export const MEASURED_MODES = ['dynamic', 'curated', 'code', 'full']
export const LARGEST_TOOLS_LIMIT = 10
export const MAX_DESCRIPTION_CHARACTERS = 800
export const DESCRIPTION_EXCEPTIONS_PATH = join(PACKAGE_ROOT, 'tool-description-exceptions.json')

/** Token ceiling per mode. `null` means measured and reported, never a gate. */
export const DEFINITION_BUDGETS = { dynamic: 1500, code: 1200, curated: 12000, full: null }

/**
 * Wire measurements from the 2026-07-27 built server. They predate the write-policy exposure
 * filter, the unsupported-route pruning and the risk-split dynamic executors, so today's numbers
 * differ; they are kept so drift is explained out loud rather than quietly absorbed.
 */
export const REGRESSION_BASELINES = {
	full: { toolCount: 274, characters: 168127, cl100kTokens: 36680, o200kTokens: 37856 },
	dynamic: { toolCount: 3, characters: 2094, cl100kTokens: 447, o200kTokens: 456 },
}

/**
 * Reversible mode is the widest public registry. A narrower policy would understate every mode's
 * real ceiling.
 */
const FIXTURE_ENV = {
	FLUENTCART_URL: 'https://fixture.invalid',
	FLUENTCART_USERNAME: 'fixture',
	FLUENTCART_APP_PASSWORD: 'fixture',
	FLUENTCART_WRITE_MODE: 'reversible',
}

function applyFixtureEnv() {
	// A developer's own FLUENTCART_* variables would silently change the registry.
	for (const key of Object.keys(process.env)) {
		if (key.startsWith('FLUENTCART_')) delete process.env[key]
	}
	Object.assign(process.env, FIXTURE_ENV)
}

function assertPinnedTokenizer() {
	const { version } = require('gpt-tokenizer/package.json')
	if (`gpt-tokenizer@${version}` !== TOKENIZER) {
		throw new Error(`Expected ${TOKENIZER}, found gpt-tokenizer@${version}. Token counts are only comparable across runs when the tokenizer is pinned.`)
	}
}

function newestMtime(directory) {
	let newest = 0
	for (const entry of readdirSync(directory, { withFileTypes: true })) {
		const path = join(directory, entry.name)
		newest = Math.max(newest, entry.isDirectory() ? newestMtime(path) : statSync(path).mtimeMs)
	}
	return newest
}

function ensureFreshBuild({ allowStale = false } = {}) {
	const dist = join(PACKAGE_ROOT, 'dist')
	const built = existsSync(join(dist, 'server.js'))
	if (built && newestMtime(join(PACKAGE_ROOT, 'src')) <= newestMtime(dist)) return

	if (allowStale && built) {
		process.stderr.write('warning: dist/ is older than src/; measuring the existing build.\n')
		return
	}
	// tsc chatter goes to stderr so stdout carries the measurement JSON alone.
	execFileSync('npm', ['run', 'build'], { cwd: PACKAGE_ROOT, stdio: ['ignore', 2, 2] })
}

let serverModulePromise = null

export async function loadServerModule(options = {}) {
	if (serverModulePromise === null) {
		ensureFreshBuild(options)
		applyFixtureEnv()
		serverModulePromise = import(pathToFileURL(join(PACKAGE_ROOT, 'dist', 'server.js')).href)
	}
	return serverModulePromise
}

/**
 * Resolve every mode through one place. A build that predates a mode reports it unavailable rather
 * than guess: `createServerFromContext` treats any unrecognised name as the full registry, so
 * measuring an unimplemented mode by name would publish full numbers under a curated heading.
 */
export function resolveMode(serverModule, mode) {
	const declared = Array.isArray(serverModule.TOOLSET_MODES) ? serverModule.TOOLSET_MODES : []
	if (declared.includes(mode)) return mode
	if (declared.length > 0) return null

	// Builds before the mode split exposed exactly two, and called the full registry `static`.
	if (mode === 'dynamic') return 'dynamic'
	return mode === 'full' ? 'static' : null
}

/** Record the outgoing `tools/list` result exactly as the SDK hands it to the transport. */
async function collectWireTools(serverModule, resolvedMode) {
	const { Client } = await import('@modelcontextprotocol/client')
	const { InMemoryTransport } = await import('@modelcontextprotocol/server')

	const context = serverModule.resolveServerContext()
	// Code mode starts a WebAssembly sandbox, so construction is asynchronous.
	const server = serverModule.createServerFromContextAsync
		? await serverModule.createServerFromContextAsync(context, resolvedMode)
		: serverModule.createServerFromContext(context, resolvedMode)
	const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair()

	const sent = []
	const send = serverTransport.send.bind(serverTransport)
	serverTransport.send = async (message, options) => {
		sent.push(message)
		return send(message, options)
	}

	const client = new Client({ name: 'measure-tool-context', version: '1' }, { capabilities: {} })
	await Promise.all([server.connect(serverTransport), client.connect(clientTransport)])

	let cursor
	do {
		const page = await client.listTools(cursor === undefined ? {} : { cursor })
		cursor = page.nextCursor
	} while (cursor !== undefined)

	await client.close()
	await server.close()

	const tools = []
	for (const message of sent) {
		if (message.result && Array.isArray(message.result.tools)) tools.push(...message.result.tools)
	}
	return tools
}

/**
 * `mcp-tools-list-v1`: the JSON text of the `tools` array exactly as the SDK sends it — name,
 * title, description, input schema, output schema, annotations, execution hints and `_meta`, in
 * the SDK's own key order. No reordering, no pretty-printing, no field selection.
 */
export function serializeToolsList(value) {
	return JSON.stringify(value)
}

function measureText(text) {
	return {
		characters: text.length,
		cl100kTokens: encodeCl100k(text).length,
		o200kTokens: encodeO200k(text).length,
	}
}

export async function measureMode(mode, options = {}) {
	const serverModule = options.serverModule ?? (await loadServerModule(options))
	const resolvedMode = resolveMode(serverModule, mode)
	if (resolvedMode === null) return { mode, available: false, resolvedMode: null, tools: [] }

	// A mode the build declares but refuses to construct is unavailable, not zero-cost. Recording
	// it as an empty measurement would let an unwired mode silently pass its token budget.
	let wireTools
	try {
		wireTools = await collectWireTools(serverModule, resolvedMode)
	} catch (error) {
		return {
			mode,
			available: false,
			resolvedMode,
			tools: [],
			reason: error instanceof Error ? error.message : String(error),
		}
	}
	const totals = measureText(serializeToolsList(wireTools))
	const tools = wireTools.map((tool) => ({
		name: tool.name,
		...measureText(serializeToolsList(tool)),
		descriptionCharacters: (tool.description ?? '').length,
	}))

	return { mode, available: true, resolvedMode, toolCount: wireTools.length, ...totals, tools }
}

export async function measureAllModes(options = {}) {
	const serverModule = await loadServerModule(options)
	const measurements = []
	for (const mode of MEASURED_MODES) {
		measurements.push(await measureMode(mode, { ...options, serverModule }))
	}
	return measurements
}

export function toReport(measurement, limit = LARGEST_TOOLS_LIMIT) {
	const head = { serializer: SERIALIZER, tokenizer: TOKENIZER, mode: measurement.mode }
	if (!measurement.available) {
		const empty = { toolCount: null, characters: null, cl100kTokens: null, o200kTokens: null }
		return { ...head, ...empty, largestTools: [], unavailable: true }
	}

	// Sorted without locale rules so the ordering is identical on every machine.
	const largestTools = [...measurement.tools]
		.sort((a, b) => b.cl100kTokens - a.cl100kTokens || (a.name < b.name ? -1 : 1))
		.slice(0, limit)

	return {
		...head,
		toolCount: measurement.toolCount,
		characters: measurement.characters,
		cl100kTokens: measurement.cl100kTokens,
		o200kTokens: measurement.o200kTokens,
		largestTools,
	}
}

/** Tools allowed to exceed the description limit, each named by a reviewer. Absent file: none. */
export function loadDescriptionExceptions(path = DESCRIPTION_EXCEPTIONS_PATH) {
	if (!existsSync(path)) return new Map()
	const parsed = JSON.parse(readFileSync(path, 'utf8'))
	const entries = Array.isArray(parsed.exceptions) ? parsed.exceptions : []
	return new Map(entries.map((entry) => [entry.tool, entry]))
}

function summaryLine(measurement) {
	const { mode } = measurement
	if (!measurement.available) return `${mode.padEnd(8)} unavailable in this build`

	const budget = DEFINITION_BUDGETS[mode]
	const worst = Math.max(measurement.cl100kTokens, measurement.o200kTokens)
	const verdict = budget === null ? 'reported, not gated' : `${worst <= budget ? 'within' : 'OVER'} ${budget}`
	// The four-characters-per-token figure is a sanity check on the encoders, never a gate.
	const cells = [
		`${String(measurement.toolCount).padStart(4)} tools`,
		`${String(measurement.characters).padStart(7)} chars`,
		`${String(measurement.cl100kTokens).padStart(6)} cl100k`,
		`${String(measurement.o200kTokens).padStart(6)} o200k`,
		`~${Math.round(measurement.characters / 4)} by 4-char estimate`,
	]
	return `${mode.padEnd(8)} ${cells.join('  ')}  ${verdict}`
}

function writeSummary(measurements) {
	const lines = [`serializer ${SERIALIZER}, tokenizer ${TOKENIZER}, write mode ${FIXTURE_ENV.FLUENTCART_WRITE_MODE}`]
	for (const measurement of measurements) lines.push(summaryLine(measurement))

	const exceptions = loadDescriptionExceptions()
	for (const measurement of measurements) {
		for (const tool of measurement.tools) {
			if (tool.descriptionCharacters > MAX_DESCRIPTION_CHARACTERS && !exceptions.has(tool.name)) {
				lines.push(`${measurement.mode}: ${tool.name} description is ${tool.descriptionCharacters} characters, over ${MAX_DESCRIPTION_CHARACTERS} and unreviewed`)
			}
		}
	}

	for (const [mode, baseline] of Object.entries(REGRESSION_BASELINES)) {
		const measurement = measurements.find((entry) => entry.mode === mode)
		if (!measurement?.available || measurement.toolCount === baseline.toolCount) continue
		lines.push(`${mode}: ${measurement.toolCount} tools now against a ${baseline.toolCount}-tool baseline (${measurement.cl100kTokens} vs ${baseline.cl100kTokens} cl100k)`)
	}

	process.stderr.write(`${lines.join('\n')}\n`)
}

if (process.argv[1] && pathToFileURL(process.argv[1]).href === import.meta.url) {
	assertPinnedTokenizer()
	const measurements = await measureAllModes({ allowStale: process.argv.includes('--no-build') })
	process.stdout.write(`${JSON.stringify(measurements.map((entry) => toReport(entry)), null, 2)}\n`)
	if (!process.argv.includes('--quiet')) writeSummary(measurements)
}
